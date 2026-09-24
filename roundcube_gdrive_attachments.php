<?php

/**
 * Roundcube Google Drive Attachments
 *
 * Attachments larger than the message size limit are uploaded to a Google
 * shared drive with a service account of the company, shared with "anyone
 * with the link", and replaced in the message by a download link.
 *
 * Based on the design of nextcloud_attachments by Bennet Becker (MIT).
 *
 * @license   MIT License: <http://opensource.org/licenses/MIT>
 * @author    Yann Challet (CymDeveloppement)
 * @category  Plugin for RoundCube WebMail
 */
class roundcube_gdrive_attachments extends rcube_plugin
{
    public $task = 'mail';

    /** Value of the _target upload parameter for files sent to Drive */
    const TARGET = 'gdrive';

    /** App property set on every file uploaded by the plugin (used by the cleanup) */
    const APP_PROPERTY = 'roundcube_gdrive_attachments';

    /** Temporary content type marking Drive links between attachment_get and message_ready */
    const LINK_MIMETYPE = 'application/x-roundcube-gdrive-link';

    /** @var rcmail */
    private $rc;

    function init()
    {
        $this->rc = rcmail::get_instance();
        $this->load_config('config.inc.php.dist');
        $this->load_config('config.inc.php');

        if (!$this->is_enabled()) {
            return;
        }

        $this->add_hook('ready', array($this, 'ready'));
        $this->add_hook('attachment_upload', array($this, 'attachment_upload'));
        $this->add_hook('attachment_get', array($this, 'attachment_get'));
        $this->add_hook('attachment_delete', array($this, 'attachment_delete'));
        $this->add_hook('message_ready', array($this, 'message_ready'));
    }

    /**
     * Adds the client script to the compose screen, and lets files sent to
     * Drive pass the message size check of the upload action
     */
    function ready($args)
    {
        if ($args['action'] == 'compose') {
            $this->add_texts('localization/', array(
                'file_too_big', 'file_too_big_explain', 'file_big', 'file_big_explain',
                'upload_to_drive', 'attach_anyway', 'link_inserted', 'file_too_big_for_server', 'available_until',
                'remove_title', 'remove_question', 'remove_from_drive', 'keep_on_drive',
            ));
            $this->include_script('roundcube_gdrive_attachments.js');

            $limit = parse_bytes($this->rc->config->get('max_message_size'));
            $softlimit = parse_bytes($this->rc->config->get('gdrive_attachments_softlimit'));

            $this->rc->output->set_env('gdrive_attachments', array(
                'behavior' => $this->rc->config->get('gdrive_attachments_behavior') == 'upload' ? 'upload' : 'prompt',
                'softlimit' => $softlimit && (!$limit || $softlimit < $limit) ? $softlimit : null,
                // Files going to Drive still go through the Roundcube server
                'max_upload' => rcube_utils::max_upload_size(),
                'max_upload_text' => rcmail_action::show_bytes(rcube_utils::max_upload_size()),
            ));
        } else if ($args['action'] == 'upload' && $this->is_drive_upload() && !empty($_FILES['_attachments']['size'])) {
            // rcmail_action_mail_attachment_upload checks the size of the file
            // against max_message_size before the attachment_upload hook runs.
            // The file will be replaced by a link of a few KB.
            $_FILES['_attachments']['size'] = array_map(function () {
                return 0;
            }, (array) $_FILES['_attachments']['size']);
        }

        return $args;
    }

    /**
     * Uploads the file to Drive and replaces the attachment with a small HTML
     * file holding the link
     */
    function attachment_upload($args)
    {
        if (!$this->is_drive_upload() || empty($args['path'])) {
            return $args;
        }

        // Large files take time to reach Google
        @set_time_limit(0);

        $this->add_texts('localization/');

        $path = $args['path'];
        $size = filesize($path);
        $file = null;

        try {
            $client = $this->client();
            $folder = $client->resolve_folder($this->root_folder(), $this->folder_path());

            $expires = $this->expiration_time();
            $properties = array(
                self::APP_PROPERTY => '1',
                'roundcube_user' => (string) $this->rc->get_user_name(),
            );
            if ($expires) {
                $properties['roundcube_expires'] = (string) $expires;
            }

            $file = $client->upload($path, $args['name'], $args['mimetype'], $folder, $properties);
            $client->share_with_link($file['id']);
        } catch (Exception $e) {
            rcube::raise_error(array(
                'code' => 500, 'file' => __FILE__, 'line' => __LINE__,
                'message' => 'roundcube_gdrive_attachments: ' . $this->rc->get_user_name()
                    . ' could not upload ' . $args['name'] . ': ' . $e->getMessage(),
            ), true, false);

            // Do not leave an unshared file behind
            if ($file && isset($client)) {
                try {
                    $client->trash($file['id']);
                } catch (Exception $e2) {
                }
            }

            return array('status' => false, 'abort' => true, 'error' => $this->gettext('upload_failed'));
        }

        $link = array(
            'name' => $args['name'],
            'url' => $file['webViewLink'] ?? 'https://drive.google.com/file/d/' . rawurlencode($file['id']) . '/view',
            'size' => $size,
            'size_text' => rcmail_action::show_bytes($size),
            'expires' => $expires,
            'expires_text' => $expires ? $this->rc->format_date($expires, $this->rc->config->get('date_format', 'Y-m-d')) : null,
        );

        // The uploaded file now holds a small HTML page with the link, and is
        // then stored by filesystem_attachments like any other attachment.
        // The extra keys are kept in the upload metadata for the other hooks.
        $html = $this->link_page($link);
        file_put_contents($path, $html);

        // Inserts the link in the message body
        $this->rc->output->command('plugin.gdrive_attachments.uploaded', $link + array('attachment' => $args['name'] . '.html'));

        return array_merge($args, array(
            'name' => $args['name'] . '.html',
            'mimetype' => 'text/html',
            'size' => strlen($html),
            'gdrive_id' => $file['id'],
            'gdrive_url' => $link['url'],
            'gdrive_attach' => (bool) $this->rc->config->get('gdrive_attachments_attach_html', true),
        ));
    }

    /**
     * Marks the HTML link files of the message being sent, to set their
     * headers in message_ready
     */
    function attachment_get($args)
    {
        if (!empty($args['gdrive_url']) && $this->rc->action == 'send') {
            $args['mimetype'] = self::LINK_MIMETYPE . '; url=' . $args['gdrive_url']
                . (empty($args['gdrive_attach']) ? '; skip=1' : '');
        }

        return $args;
    }

    /**
     * Sets the headers of the HTML link files (X-Mozilla-Cloud-Part, as
     * Thunderbird does), or removes them when they must not be attached
     */
    function message_ready($args)
    {
        $draft = !empty($_POST['_draft']);

        $fix = function (array $parts) use ($draft) {
            foreach ($parts as $i => $part) {
                if (!is_array($part) || strpos($part['c_type'] ?? '', self::LINK_MIMETYPE) !== 0) {
                    continue;
                }

                // Drafts keep the file, so the link stays in the attachment list
                if (strpos($part['c_type'], '; skip=1') && !$draft) {
                    unset($parts[$i]);
                    continue;
                }

                preg_match('/; url=([^;]+)/', $part['c_type'], $m);

                $part['c_type'] = 'text/html';
                $part['charset'] = RCUBE_CHARSET;
                $part['encoding'] = 'quoted-printable';
                $part['add_headers'] = array('X-Mozilla-Cloud-Part' => 'cloudFile; url=' . ($m[1] ?? ''));
                $parts[$i] = $part;
            }

            return array_values($parts);
        };

        // The parts of Mail_mime are protected: edit them in the object's scope
        if ($args['message'] instanceof Mail_mime) {
            Closure::bind(function () use ($fix) {
                $this->parts = $fix($this->parts);
            }, $args['message'], Mail_mime::class)();
        }

        return $args;
    }

    /**
     * Moves the file to the Drive trash when the user removes the attachment
     * and asks for it
     */
    function attachment_delete($args)
    {
        if (!empty($args['gdrive_id']) && rcube_utils::get_input_value('_gdrive_remove', rcube_utils::INPUT_POST)) {
            try {
                $this->client()->trash($args['gdrive_id']);
            } catch (Exception $e) {
                rcube::raise_error(array(
                    'code' => 500, 'file' => __FILE__, 'line' => __LINE__,
                    'message' => 'roundcube_gdrive_attachments: cannot trash ' . $args['gdrive_id'] . ': ' . $e->getMessage(),
                ), true, false);

                $this->add_texts('localization/');
                $this->rc->output->show_message($this->gettext('remove_failed'), 'warning');
            }
        }

        return $args;
    }

    /**
     * Builds the Drive client from the configuration
     */
    function client()
    {
        require_once __DIR__ . '/lib/roundcube_gdrive_client.php';

        $key_file = (string) $this->rc->config->get('gdrive_attachments_key_file');
        if ($key_file !== '' && $key_file[0] != '/') {
            $key_file = __DIR__ . '/' . $key_file;
        }

        $http = $this->rc->get_http_client(array(
            'timeout' => (int) $this->rc->config->get('gdrive_attachments_timeout', 300),
            'read_timeout' => (int) $this->rc->config->get('gdrive_attachments_timeout', 300),
        ));

        return roundcube_gdrive_client::from_key_file($key_file, $http,
            $this->rc->config->get('gdrive_attachments_subject'));
    }

    /**
     * Folder receiving the user folders: the configured folder, the root of
     * the shared drive, or the root of the impersonated account's drive
     */
    private function root_folder()
    {
        return $this->rc->config->get('gdrive_attachments_folder_id')
            ?: $this->rc->config->get('gdrive_attachments_drive_id')
            ?: 'root';
    }

    /**
     * Path of the destination folder below the root folder
     */
    private function folder_path()
    {
        $user = $this->rc->user;
        $vars = array(
            '{username}' => $user->get_username(),
            '{local}' => $user->get_username('local'),
            '{domain}' => $user->get_username('domain'),
            '{year}' => date('Y'),
            '{month}' => date('m'),
            '{day}' => date('d'),
        );

        // A value must not create sub-folders
        $vars = array_map(function ($value) {
            return str_replace('/', '_', (string) $value);
        }, $vars);

        return strtr((string) $this->rc->config->get('gdrive_attachments_folder'), $vars);
    }

    /**
     * Date after which the cleanup script trashes the file, or null
     */
    private function expiration_time()
    {
        $days = (int) $this->rc->config->get('gdrive_attachments_retention_days');

        return $days > 0 ? time() + $days * 86400 : null;
    }

    /**
     * Content of the HTML file attached in place of the uploaded file
     */
    private function link_page(array $link)
    {
        $lines = array(
            html::tag('p', array('style' => 'margin:0 0 12px'), rcube::Q($this->gettext(array(
                'name' => 'page_intro', 'vars' => array('name' => $link['name']))))),
            html::tag('p', array('style' => 'margin:0 0 4px;font-size:16px'),
                html::a(array('href' => $link['url'], 'style' => 'color:#1a73e8'), rcube::Q($link['name']))
                . ' <span style="color:#666">(' . rcube::Q($link['size_text']) . ')</span>'),
            html::tag('p', array('style' => 'margin:0 0 12px;word-break:break-all'),
                html::a(array('href' => $link['url'], 'style' => 'color:#666;font-size:13px'), rcube::Q($link['url']))),
        );

        if ($link['expires_text']) {
            $lines[] = html::tag('p', array('style' => 'margin:0;color:#666;font-size:13px'), rcube::Q($this->gettext(array(
                'name' => 'available_until', 'vars' => array('date' => $link['expires_text'])))));
        }

        return '<!DOCTYPE html>' . "\n"
            . '<html><head><meta charset="UTF-8"><title>' . rcube::Q($link['name']) . '</title></head>'
            . '<body style="font-family:sans-serif;margin:2em">'
            . html::div(array('style' => 'max-width:560px;padding:16px;border:1px solid #ddd;border-radius:8px'), implode('', $lines))
            . '</body></html>';
    }

    /**
     * Whether the current upload request targets Drive
     */
    private function is_drive_upload()
    {
        return rcube_utils::get_input_string('_target', rcube_utils::INPUT_GPC) === self::TARGET;
    }

    /**
     * Whether the plugin is configured and allowed for the current user
     */
    private function is_enabled()
    {
        if (empty($this->rc->user->ID) || !$this->rc->config->get('gdrive_attachments_key_file')) {
            return false;
        }

        $excluded = array_map('strtolower', (array) $this->rc->config->get('gdrive_attachments_excluded_users', array()));

        return !in_array(strtolower((string) $this->rc->get_user_name()), $excluded);
    }
}
