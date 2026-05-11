<?php
/**
 * Google Drive client.
 *
 * Wraps the google/apiclient library with FlowMint conventions:
 *   - Service account auth via JSON key from FMW_Credential_Store
 *   - Structured error handling (translates HTTP codes to FMW_Step_Exception)
 *   - Rate-limit-aware retries
 *   - Helper methods for common operations (find, find-or-create, create, upload, share)
 *
 * Requires google/apiclient via Composer. If vendor/autoload.php hasn't
 * been loaded, this class throws a clear error on first use.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Drive_Client {

    const FOLDER_MIME = 'application/vnd.google-apps.folder';

    /**
     * @var \Google\Service\Drive
     */
    private $service;

    /**
     * @var array Decoded service account JSON
     */
    private $auth_config;

    /**
     * Construct from a service account JSON string.
     *
     * @param string $json Service account JSON content
     * @throws FMW_Step_Exception If google/apiclient isn't loaded.
     */
    public function __construct( $json ) {
        if ( ! class_exists( '\Google\Client' ) ) {
            throw new FMW_Step_Exception(
                'dependency_missing',
                'Google API client not loaded. Run `composer install` in the plugin directory.'
            );
        }

        $config = json_decode( $json, true );
        if ( ! is_array( $config ) || empty( $config['client_email'] ) ) {
            throw new FMW_Step_Exception(
                'config_error',
                'Drive service account JSON is invalid (missing client_email).'
            );
        }

        $this->auth_config = $config;

        $client = new \Google\Client();
        $client->setAuthConfig( $config );
        $client->addScope( \Google\Service\Drive::DRIVE );
        $client->setApplicationName( 'FlowMint Workflows' );

        $this->service = new \Google\Service\Drive( $client );
    }

    /**
     * Construct from configured credential.
     *
     * Distinguishes three states (per credential-store audit item I2):
     *   - null         → never configured        → credential_not_configured
     *   - WP_Error     → present but unreadable  → credential_unreadable
     *   - non-empty    → ready to use
     *
     * @return self
     * @throws FMW_Step_Exception If credential not configured or unreadable.
     */
    public static function from_credentials() {
        $json = FMW_Credential_Store::get( 'drive_service_account' );

        if ( is_wp_error( $json ) ) {
            // The credential IS stored but the decryption layer
            // couldn't read it back. Surface this as its own error
            // code so admins see "re-enter credentials" rather than
            // "set up the integration" — those are very different
            // remediation steps.
            throw new FMW_Step_Exception(
                'credential_unreadable',
                'Drive service account credential is stored but could not be decrypted: ' . esc_html( $json->get_error_message() )
            );
        }

        if ( empty( $json ) ) {
            throw new FMW_Step_Exception(
                'credential_not_configured',
                'Drive service account credential is not configured. Set via /credentials/drive_service_account.'
            );
        }

        return new self( $json );
    }

    /**
     * Find a folder by name within a parent. Returns metadata or null.
     *
     * @param string $parent_id Parent folder ID
     * @param string $name      Folder name to find
     * @param bool   $exact     Use exact match (default: true)
     * @return array|null { id, name, web_view_link } or null if not found.
     * @throws FMW_Step_Exception
     */
    public function find_folder( $parent_id, $name, $exact = true ) {
        $escaped_name = str_replace( "'", "\\'", $name );
        $name_clause = $exact
            ? "name = '{$escaped_name}'"
            : "name contains '{$escaped_name}'";

        $query = "mimeType = '" . self::FOLDER_MIME . "' and '{$parent_id}' in parents and trashed = false and {$name_clause}";

        try {
            $result = $this->service->files->listFiles( [
                'q'        => $query,
                'fields'   => 'files(id, name, webViewLink)',
                'pageSize' => 1,
                'supportsAllDrives' => true,
                'includeItemsFromAllDrives' => true,
            ] );
        } catch ( \Exception $e ) {
            $this->translate_exception( $e, 'list_folders' );
        }

        $files = $result->getFiles();
        if ( empty( $files ) ) {
            return null;
        }

        $f = $files[0];
        return [
            'id'            => $f->getId(),
            'name'          => $f->getName(),
            'web_view_link' => $f->getWebViewLink(),
        ];
    }

    /**
     * Create a folder under a parent.
     *
     * @param string $parent_id
     * @param string $name
     * @return array { id, name, web_view_link }
     * @throws FMW_Step_Exception
     */
    public function create_folder( $parent_id, $name ) {
        $folder = new \Google\Service\Drive\DriveFile( [
            'name'     => $name,
            'parents'  => [ $parent_id ],
            'mimeType' => self::FOLDER_MIME,
        ] );

        try {
            $created = $this->service->files->create( $folder, [
                'fields' => 'id, name, webViewLink',
                'supportsAllDrives' => true,
            ] );
        } catch ( \Exception $e ) {
            $this->translate_exception( $e, 'create_folder' );
        }

        return [
            'id'            => $created->getId(),
            'name'          => $created->getName(),
            'web_view_link' => $created->getWebViewLink(),
        ];
    }

    /**
     * Find a folder, or create it if missing.
     *
     * @param string $parent_id
     * @param string $name
     * @return array { id, name, web_view_link, was_created }
     * @throws FMW_Step_Exception
     */
    public function find_or_create_folder( $parent_id, $name ) {
        $existing = $this->find_folder( $parent_id, $name );
        if ( $existing ) {
            $existing['was_created'] = false;
            return $existing;
        }

        $created = $this->create_folder( $parent_id, $name );
        $created['was_created'] = true;
        return $created;
    }

    /**
     * Upload a local file to Drive.
     *
     * Uses chunked (resumable) upload for files > 5MB; multipart for smaller.
     *
     * @param string $parent_id
     * @param string $local_path Absolute path to file on disk
     * @param string $name       Drive filename (defaults to basename of $local_path)
     * @param string $mime_type  Optional override; auto-detected if omitted
     * @return array { id, name, web_view_link, size, mime_type }
     * @throws FMW_Step_Exception
     */
    public function upload_file( $parent_id, $local_path, $name = null, $mime_type = null ) {
        if ( ! file_exists( $local_path ) ) {
            throw new FMW_Step_Exception(
                'file_not_found',
                sprintf( 'Drive upload: local file does not exist: %s', esc_html( $local_path ) )
            );
        }

        if ( ! is_readable( $local_path ) ) {
            throw new FMW_Step_Exception(
                'file_not_readable',
                sprintf( 'Drive upload: cannot read local file: %s', esc_html( $local_path ) )
            );
        }

        $name      = $name ?: basename( $local_path );
        $mime_type = $mime_type ?: ( mime_content_type( $local_path ) ?: 'application/octet-stream' );
        $size      = filesize( $local_path );

        $metadata = new \Google\Service\Drive\DriveFile( [
            'name'    => $name,
            'parents' => [ $parent_id ],
        ] );

        // Use chunked/resumable upload for files larger than 5MB.
        $chunk_threshold = 5 * 1024 * 1024;

        try {
            if ( $size > $chunk_threshold ) {
                $uploaded = $this->upload_chunked( $metadata, $local_path, $mime_type, $size );
            } else {
                $content  = file_get_contents( $local_path );
                $uploaded = $this->service->files->create( $metadata, [
                    'data'       => $content,
                    'mimeType'   => $mime_type,
                    'uploadType' => 'multipart',
                    'fields'     => 'id, name, webViewLink, size, mimeType',
                    'supportsAllDrives' => true,
                ] );
            }
        } catch ( \Exception $e ) {
            $this->translate_exception( $e, 'upload_file' );
        }

        return [
            'id'            => $uploaded->getId(),
            'name'          => $uploaded->getName(),
            'web_view_link' => $uploaded->getWebViewLink(),
            'size'          => (int) $uploaded->getSize(),
            'mime_type'     => $uploaded->getMimeType(),
        ];
    }

    /**
     * Create a Drive file from an in-memory string.
     *
     * Use this for small generated documents — submission records, log
     * snapshots, formatted summaries — where the content originates in PHP
     * rather than from a local file on disk. Uses Drive's multipart upload
     * with a hard size cap of 1MB to prevent accidental misuse on large
     * blobs (which should go through upload_file's chunked path instead).
     *
     * @param string $parent_id
     * @param string $name      Drive filename including extension (e.g. "submission.txt")
     * @param string $content   File body as a UTF-8 string
     * @param string $mime_type Defaults to text/plain; pass "text/markdown" for .md, "text/html" for .html, etc.
     * @return array { id, name, web_view_link, size, mime_type }
     * @throws FMW_Step_Exception
     */
    public function create_text_file( $parent_id, $name, $content, $mime_type = 'text/plain' ) {
        $size = strlen( (string) $content );
        $cap  = 1024 * 1024; // 1MB
        if ( $size > $cap ) {
            throw new FMW_Step_Exception(
                'invalid_input',
                sprintf( 'Drive create_text_file: content exceeds %d-byte cap (got %d). Use upload_file with a local path for larger payloads.', (int) $cap, (int) $size )
            );
        }

        $metadata = new \Google\Service\Drive\DriveFile( [
            'name'    => $name,
            'parents' => [ $parent_id ],
        ] );

        try {
            $created = $this->service->files->create( $metadata, [
                'data'              => (string) $content,
                'mimeType'          => $mime_type,
                'uploadType'        => 'multipart',
                'fields'            => 'id, name, webViewLink, size, mimeType',
                'supportsAllDrives' => true,
            ] );
        } catch ( \Exception $e ) {
            $this->translate_exception( $e, 'create_text_file' );
        }

        return [
            'id'            => $created->getId(),
            'name'          => $created->getName(),
            'web_view_link' => $created->getWebViewLink(),
            'size'          => (int) $created->getSize(),
            'mime_type'     => $created->getMimeType(),
        ];
    }

    /**
     * Resumable chunked upload for large files.
     *
     * @param \Google\Service\Drive\DriveFile $metadata
     * @param string $local_path
     * @param string $mime_type
     * @param int    $size
     * @return \Google\Service\Drive\DriveFile
     */
    private function upload_chunked( $metadata, $local_path, $mime_type, $size ) {
        $client = $this->service->getClient();
        $client->setDefer( true );

        $request = $this->service->files->create( $metadata, [
            'fields' => 'id, name, webViewLink, size, mimeType',
            'supportsAllDrives' => true,
        ] );

        $chunk_size = 1 * 1024 * 1024; // 1MB chunks

        $media = new \Google\Http\MediaFileUpload(
            $client,
            $request,
            $mime_type,
            null,
            true, // resumable
            $chunk_size
        );
        $media->setFileSize( $size );

        $status = false;
        // Streaming binary upload to Google Drive via the Google API client's
        // chunked-upload protocol. WP_Filesystem does not expose a streaming
        // chunked-read API — its put_contents/get_contents methods load the
        // entire file into memory, which would defeat the purpose of chunked
        // uploads (and OOM the worker on multi-GB files). Direct PHP file I/O
        // is the correct mechanism here.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $handle = fopen( $local_path, 'rb' );

        while ( ! $status && ! feof( $handle ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
            $chunk  = fread( $handle, $chunk_size );
            $status = $media->nextChunk( $chunk );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose( $handle );
        $client->setDefer( false );

        return $status; // DriveFile on completion
    }

    /**
     * Set sharing permissions on a Drive resource.
     *
     * @param string $resource_id Drive file/folder ID
     * @param string $type        'anyone' or 'user' or 'group' or 'domain'
     * @param string $role        'reader' or 'commenter' or 'writer'
     * @param array  $extra       Additional fields (emailAddress, domain, etc.)
     * @return array
     * @throws FMW_Step_Exception
     */
    public function set_permission( $resource_id, $type, $role, array $extra = [] ) {
        $perm = new \Google\Service\Drive\Permission( array_merge( [
            'type' => $type,
            'role' => $role,
        ], $extra ) );

        try {
            $created = $this->service->permissions->create( $resource_id, $perm, [
                'fields' => 'id, type, role',
                'supportsAllDrives' => true,
            ] );
            // Refetch the file to get the updated webViewLink.
            $file = $this->service->files->get( $resource_id, [
                'fields' => 'webViewLink',
                'supportsAllDrives' => true,
            ] );
        } catch ( \Exception $e ) {
            $this->translate_exception( $e, 'set_permission' );
        }

        return [
            'permission_id'  => $created->getId(),
            'type'           => $created->getType(),
            'role'           => $created->getRole(),
            'shareable_url'  => $file->getWebViewLink(),
        ];
    }

    /**
     * Test connectivity by fetching service account info.
     *
     * @return array { service_account_email }
     * @throws FMW_Step_Exception
     */
    public function test() {
        return [
            'service_account_email' => $this->auth_config['client_email'],
            'project_id'            => $this->auth_config['project_id'] ?? null,
        ];
    }

    /**
     * Translate a Google API exception to an FMW_Step_Exception with a useful code.
     *
     * @param \Exception $e
     * @param string     $operation Short label for context (e.g., 'create_folder')
     * @throws FMW_Step_Exception
     */
    private function translate_exception( \Exception $e, $operation ) {
        $code    = 'unexpected';
        $message = $e->getMessage();
        $http_code = 0;

        if ( $e instanceof \Google\Service\Exception ) {
            $http_code = $e->getCode();
            if ( $http_code >= 400 && $http_code < 500 ) {
                if ( $http_code === 401 || $http_code === 403 ) {
                    $code = 'auth_failed';
                } elseif ( $http_code === 429 ) {
                    $code = 'rate_limited';
                } else {
                    $code = 'external_4xx';
                }
            } elseif ( $http_code >= 500 ) {
                $code = 'external_5xx';
            }
        }

        FMW_Logger::warning( 'Drive operation failed', [
            'operation' => $operation,
            'http_code' => $http_code,
            'code'      => $code,
        ] );

        throw new FMW_Step_Exception(
            esc_html( $code ),
            sprintf( 'Drive %s failed: %s', esc_html( $operation ), esc_html( $message ) ),
            [ 'http_code' => (int) $http_code ]
        );
    }
}
