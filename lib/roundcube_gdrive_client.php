<?php

/**
 * Minimal Google Drive API v3 client authenticated with a service account.
 *
 * Only what the plugin needs: folders, resumable uploads, "anyone with the
 * link" sharing, trashing and listing of the files uploaded by the plugin.
 * Every call supports shared drives.
 *
 * @license   MIT License: <http://opensource.org/licenses/MIT>
 * @author    Yann Challet (CymDeveloppement)
 */
class roundcube_gdrive_client
{
    const SCOPE = 'https://www.googleapis.com/auth/drive';
    const TOKEN_URI = 'https://oauth2.googleapis.com/token';
    const API = 'https://www.googleapis.com/drive/v3';
    const UPLOAD_API = 'https://www.googleapis.com/upload/drive/v3';
    const FOLDER_MIME = 'application/vnd.google-apps.folder';

    /** Upload chunk size, must be a multiple of 256 KiB */
    const CHUNK_SIZE = 8388608;

    /** Retries of a failed chunk before giving up */
    const MAX_RETRIES = 3;

    /** @var array Service account key (decoded JSON key file) */
    private $key;

    /** @var string|null Account impersonated with domain-wide delegation */
    private $subject;

    /** @var \GuzzleHttp\Client */
    private $http;

    /** @var string|null */
    private $token;

    /** @var int */
    private $token_expires = 0;

    /**
     * @param array                $key     Decoded service account key
     * @param \GuzzleHttp\Client   $http    HTTP client
     * @param string|null          $subject User to impersonate (domain-wide delegation), if any
     */
    public function __construct(array $key, $http, $subject = null)
    {
        if (empty($key['client_email']) || empty($key['private_key'])) {
            throw new roundcube_gdrive_exception('Invalid service account key: client_email or private_key missing');
        }

        $this->key = $key;
        $this->http = $http;
        $this->subject = $subject ?: null;
    }

    /**
     * Builds a client from the path of a JSON key file
     */
    public static function from_key_file($path, $http, $subject = null)
    {
        if (!is_readable($path)) {
            throw new roundcube_gdrive_exception("Service account key file not readable: $path");
        }

        $key = json_decode(file_get_contents($path), true);

        if (!is_array($key)) {
            throw new roundcube_gdrive_exception("Service account key file is not valid JSON: $path");
        }

        return new self($key, $http, $subject);
    }

    /**
     * Returns the id of the folder at $path (e.g. "user@example.com/2026-09")
     * below $parent, creating the missing folders
     */
    public function resolve_folder($parent, $path)
    {
        foreach (explode('/', $path) as $name) {
            $name = trim($name);
            if ($name === '' || $name === '.' || $name === '..') {
                continue;
            }

            $parent = $this->find_folder($parent, $name) ?: $this->create_folder($parent, $name);
        }

        return $parent;
    }

    /**
     * Returns the id of the folder named $name directly below $parent, or null
     */
    public function find_folder($parent, $name)
    {
        $q = sprintf("name = '%s' and '%s' in parents and mimeType = '%s' and trashed = false",
            self::escape($name), self::escape($parent), self::FOLDER_MIME);

        $result = $this->json('GET', self::API . '/files', [
            'query' => [
                'q' => $q,
                'fields' => 'files(id)',
                'pageSize' => 1,
                'supportsAllDrives' => 'true',
                'includeItemsFromAllDrives' => 'true',
            ],
        ]);

        return $result['files'][0]['id'] ?? null;
    }

    /**
     * Creates a folder below $parent and returns its id
     */
    public function create_folder($parent, $name)
    {
        $result = $this->json('POST', self::API . '/files', [
            'query' => ['supportsAllDrives' => 'true', 'fields' => 'id'],
            'json' => ['name' => $name, 'mimeType' => self::FOLDER_MIME, 'parents' => [$parent]],
        ]);

        return $result['id'];
    }

    /**
     * Uploads a local file with the resumable protocol, in chunks, so large
     * files never have to fit in memory
     *
     * @param string $path          Local file
     * @param string $name          File name on Drive
     * @param string $mimetype      Content type
     * @param string $parent        Id of the destination folder
     * @param array  $app_properties Private key/value pairs attached to the file
     *
     * @return array File resource (id, name, size, webViewLink)
     */
    public function upload($path, $name, $mimetype, $parent, array $app_properties = [])
    {
        $size = filesize($path);
        $mimetype = $mimetype ?: 'application/octet-stream';

        $metadata = ['name' => $name, 'mimeType' => $mimetype, 'parents' => [$parent]];
        if ($app_properties) {
            $metadata['appProperties'] = $app_properties;
        }

        $response = $this->request('POST', self::UPLOAD_API . '/files', [
            'query' => [
                'uploadType' => 'resumable',
                'supportsAllDrives' => 'true',
                'fields' => 'id,name,size,webViewLink',
            ],
            'headers' => [
                'X-Upload-Content-Type' => $mimetype,
                'X-Upload-Content-Length' => (string) $size,
            ],
            'json' => $metadata,
        ]);

        $session = $response->getHeaderLine('Location');

        if ($response->getStatusCode() != 200 || !$session) {
            throw self::error($response, 'Cannot start the upload');
        }

        if (!($fh = fopen($path, 'rb'))) {
            throw new roundcube_gdrive_exception("Cannot read $path");
        }

        try {
            $offset = 0;
            $retries = 0;

            while (true) {
                $length = min(self::CHUNK_SIZE, $size - $offset);
                fseek($fh, $offset);
                $chunk = $length > 0 ? stream_get_contents($fh, $length) : '';
                $range = $size > 0 ? sprintf('bytes %d-%d/%d', $offset, $offset + $length - 1, $size) : 'bytes */0';

                try {
                    $response = $this->http->request('PUT', $session, [
                        'headers' => ['Content-Length' => (string) $length, 'Content-Range' => $range],
                        'body' => $chunk,
                        'http_errors' => false,
                    ]);
                    $status = $response->getStatusCode();
                } catch (\GuzzleHttp\Exception\TransferException $e) {
                    $response = null;
                    $status = 0;
                }

                if ($status == 200 || $status == 201) {
                    return json_decode((string) $response->getBody(), true);
                }

                if ($status == 308) {
                    $next = self::next_offset($response);

                    if ($next > $offset) {
                        $offset = $next;
                        $retries = 0;
                        continue;
                    }

                    // No progress: retry the chunk, not forever
                    if ($retries++ < self::MAX_RETRIES) {
                        $offset = $next;
                        continue;
                    }
                }

                // Network or server error: ask Google how much it received, then resume
                if (($status == 0 || $status >= 500) && $retries++ < self::MAX_RETRIES) {
                    sleep($retries);
                    $offset = $this->upload_offset($session, $size);
                    continue;
                }

                throw $response ? self::error($response, 'Upload failed') : new roundcube_gdrive_exception('Upload failed: ' . $e->getMessage());
            }
        } finally {
            fclose($fh);
        }
    }

    /**
     * Lets anyone who has the link view (and download) the file.
     * The file is not listed in searches (allowFileDiscovery = false).
     */
    public function share_with_link($file_id)
    {
        return $this->json('POST', self::API . '/files/' . rawurlencode($file_id) . '/permissions', [
            'query' => ['supportsAllDrives' => 'true', 'sendNotificationEmail' => 'false'],
            'json' => ['type' => 'anyone', 'role' => 'reader', 'allowFileDiscovery' => false],
        ]);
    }

    /**
     * Moves a file to the trash. Content managers of a shared drive may trash
     * files but not delete them; the trash of a shared drive is emptied by
     * Google after 30 days.
     */
    public function trash($file_id)
    {
        return $this->json('PATCH', self::API . '/files/' . rawurlencode($file_id), [
            'query' => ['supportsAllDrives' => 'true', 'fields' => 'id'],
            'json' => ['trashed' => true],
        ]);
    }

    /**
     * Lists the files (not trashed) holding the given app property
     *
     * @param string      $key      App property name
     * @param string      $value    App property value
     * @param string|null $drive_id Shared drive to search (null: the account's own files)
     *
     * @return Generator File resources (id, name, createdTime, appProperties)
     */
    public function list_by_property($key, $value, $drive_id = null)
    {
        $query = [
            'q' => sprintf("appProperties has { key='%s' and value='%s' } and trashed = false",
                self::escape($key), self::escape($value)),
            'fields' => 'nextPageToken,files(id,name,createdTime,appProperties)',
            'pageSize' => 1000,
            'supportsAllDrives' => 'true',
        ];

        if ($drive_id) {
            $query += ['corpora' => 'drive', 'driveId' => $drive_id, 'includeItemsFromAllDrives' => 'true'];
        }

        do {
            $result = $this->json('GET', self::API . '/files', ['query' => $query]);

            foreach ($result['files'] ?? [] as $file) {
                yield $file;
            }

            $query['pageToken'] = $result['nextPageToken'] ?? null;
        } while ($query['pageToken']);
    }

    /**
     * Sends an API request and returns the decoded JSON response
     */
    private function json($method, $url, array $options)
    {
        $response = $this->request($method, $url, $options);
        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            throw self::error($response, "$method $url failed");
        }

        return json_decode((string) $response->getBody(), true) ?: [];
    }

    /**
     * Sends an authenticated API request
     */
    private function request($method, $url, array $options)
    {
        $options['headers']['Authorization'] = 'Bearer ' . $this->token();
        $options['http_errors'] = false;

        try {
            return $this->http->request($method, $url, $options);
        } catch (\GuzzleHttp\Exception\TransferException $e) {
            throw new roundcube_gdrive_exception("$method $url failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Returns an access token, requesting one with a signed JWT when needed
     */
    private function token()
    {
        if ($this->token && $this->token_expires > time() + 60) {
            return $this->token;
        }

        $now = time();
        $token_uri = $this->key['token_uri'] ?? self::TOKEN_URI;
        $claims = [
            'iss' => $this->key['client_email'],
            'scope' => self::SCOPE,
            'aud' => $token_uri,
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        if ($this->subject) {
            $claims['sub'] = $this->subject;
        }

        $jwt = self::base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']))
            . '.' . self::base64url(json_encode($claims));

        if (!openssl_sign($jwt, $signature, $this->key['private_key'], 'sha256WithRSAEncryption')) {
            throw new roundcube_gdrive_exception('Cannot sign the token request: ' . openssl_error_string());
        }

        try {
            $response = $this->http->request('POST', $token_uri, [
                'form_params' => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt . '.' . self::base64url($signature),
                ],
                'http_errors' => false,
            ]);
        } catch (\GuzzleHttp\Exception\TransferException $e) {
            throw new roundcube_gdrive_exception('Token request failed: ' . $e->getMessage(), 0, $e);
        }

        $result = json_decode((string) $response->getBody(), true);

        if ($response->getStatusCode() != 200 || empty($result['access_token'])) {
            $reason = $result['error_description'] ?? $result['error'] ?? $response->getReasonPhrase();
            throw new roundcube_gdrive_exception('Token request failed: ' . $reason);
        }

        $this->token = $result['access_token'];
        $this->token_expires = $now + (int) ($result['expires_in'] ?? 3600);

        return $this->token;
    }

    /**
     * Asks the upload session how many bytes were received
     */
    private function upload_offset($session, $size)
    {
        try {
            $response = $this->http->request('PUT', $session, [
                'headers' => ['Content-Length' => '0', 'Content-Range' => "bytes */$size"],
                'http_errors' => false,
            ]);
        } catch (\GuzzleHttp\Exception\TransferException $e) {
            return 0;
        }

        return $response->getStatusCode() == 308 ? self::next_offset($response) : 0;
    }

    /**
     * First byte to send after a 308 response ("Range: bytes=0-1234")
     */
    private static function next_offset($response)
    {
        $range = $response->getHeaderLine('Range');

        return $range ? (int) substr($range, strrpos($range, '-') + 1) + 1 : 0;
    }

    /**
     * Builds an exception from a Google error response
     */
    private static function error($response, $context)
    {
        $body = json_decode((string) $response->getBody(), true);
        $message = $body['error']['message'] ?? $response->getReasonPhrase();
        $reason = $body['error']['errors'][0]['reason'] ?? null;

        return new roundcube_gdrive_exception(
            "$context: HTTP {$response->getStatusCode()} $message" . ($reason ? " ($reason)" : ''),
            $response->getStatusCode()
        );
    }

    /**
     * Escapes a value for a string literal of a Drive search query
     */
    private static function escape($value)
    {
        return str_replace(["\\", "'"], ["\\\\", "\\'"], $value);
    }

    private static function base64url($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

class roundcube_gdrive_exception extends Exception
{
}
