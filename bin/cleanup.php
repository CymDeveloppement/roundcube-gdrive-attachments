#!/usr/bin/env php
<?php

/**
 * Moves to the Google Drive trash the files uploaded by the plugin whose
 * retention period (gdrive_attachments_retention_days) is over.
 *
 * Run it daily from cron, as the user running Roundcube:
 *   0 3 * * * php /path/to/roundcube/plugins/roundcube_gdrive_attachments/bin/cleanup.php
 *
 * Options:
 *   --dry-run  list the expired files without trashing them
 */

define('INSTALL_PATH', realpath(__DIR__ . '/../../..') . '/');

require_once INSTALL_PATH . 'program/include/clisetup.php';
require_once __DIR__ . '/../lib/roundcube_gdrive_client.php';

$dry_run = in_array('--dry-run', array_slice($argv, 1));

$rc = rcube::get_instance();
$plugin_dir = dirname(__DIR__);

foreach (array('config.inc.php.dist', 'config.inc.php') as $file) {
    if (is_file("$plugin_dir/$file")) {
        $rc->config->load_from_file("$plugin_dir/$file");
    }
}

$key_file = (string) $rc->config->get('gdrive_attachments_key_file');
if ($key_file === '') {
    fwrite(STDERR, "gdrive_attachments_key_file is not set\n");
    exit(1);
}
if ($key_file[0] != '/') {
    $key_file = "$plugin_dir/$key_file";
}

$client = roundcube_gdrive_client::from_key_file($key_file, $rc->get_http_client(),
    $rc->config->get('gdrive_attachments_subject'));

$now = time();
$trashed = $errors = 0;

try {
    $files = $client->list_by_property('roundcube_gdrive_attachments', '1',
        $rc->config->get('gdrive_attachments_drive_id') ?: null);

    foreach ($files as $file) {
        $expires = (int) ($file['appProperties']['roundcube_expires'] ?? 0);

        if (!$expires || $expires > $now) {
            continue;
        }

        $user = $file['appProperties']['roundcube_user'] ?? '?';
        echo ($dry_run ? '[dry-run] ' : '') . "{$file['name']} ({$file['id']}, $user, expired "
            . date('Y-m-d', $expires) . ")\n";

        if ($dry_run) {
            continue;
        }

        try {
            $client->trash($file['id']);
            $trashed++;
        } catch (Exception $e) {
            fwrite(STDERR, "Cannot trash {$file['id']}: {$e->getMessage()}\n");
            $errors++;
        }
    }
} catch (Exception $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

if (!$dry_run) {
    echo "$trashed file(s) moved to the trash" . ($errors ? ", $errors error(s)" : '') . "\n";
}

exit($errors ? 1 : 0);
