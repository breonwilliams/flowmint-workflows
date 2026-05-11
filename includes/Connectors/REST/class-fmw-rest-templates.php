<?php
/**
 * REST: /templates endpoints.
 *
 * Manage the per-site fmw-templates/ directory under wp-content/uploads/.
 * Templates are plain .txt or .html files with `{{ }}` interpolation
 * placeholders, referenced by name (without extension) from
 * `send_email_template` step config and from `{{ template('name') }}` calls
 * inside any interpolated string.
 *
 * Endpoints:
 *   GET    /templates                 — list templates currently in the directory
 *   GET    /templates/{name}          — read a template's content
 *   PUT    /templates/{name}          — write/overwrite a template (body: { "content": "...", "extension": "txt"|"html" })
 *   DELETE /templates/{name}          — delete a template
 *
 * Security:
 *   - All endpoints require the `fmw_manage` capability via FMW_REST_Auth::require_manage().
 *   - Template name is sanitized to `[a-z0-9_-]+` and length-capped (≤ 64 chars).
 *   - Extension is restricted to `txt` or `html` only.
 *   - Files are written with WordPress's WP_Filesystem abstraction so the
 *     mode + ownership match what `wp_upload_dir()` produces — same as
 *     uploaded media. No raw `file_put_contents` with surprise umasks.
 *   - Content size capped at 256KB (templates that big are a misuse).
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_REST_Templates {

    /**
     * Maximum template name length.
     */
    const NAME_MAX_LENGTH = 64;

    /**
     * Maximum template body size (bytes). 256KB is generous for any reasonable
     * email template or Quote description; protects against accidental uploads
     * of full HTML pages, image-data-URI strings, etc.
     */
    const CONTENT_MAX_BYTES = 262144;

    /**
     * Allowed extensions. .txt for plain text, .html for HTML email bodies.
     */
    const ALLOWED_EXTENSIONS = [ 'txt', 'html' ];

    public function register() {
        $base = '/' . FMW_REST_Api::base() . '/templates';

        register_rest_route( FMW_REST_Api::ns(), $base, [
            'methods'             => 'GET',
            'callback'            => [ $this, 'list' ],
            'permission_callback' => [ 'FMW_REST_Auth', 'require_manage' ],
        ] );

        register_rest_route( FMW_REST_Api::ns(), $base . '/(?P<name>[a-z0-9\-_]+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get' ],
                'permission_callback' => [ 'FMW_REST_Auth', 'require_manage' ],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ $this, 'set' ],
                'permission_callback' => [ 'FMW_REST_Auth', 'require_manage' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ $this, 'delete' ],
                'permission_callback' => [ 'FMW_REST_Auth', 'require_manage' ],
            ],
        ] );
    }

    /**
     * GET /templates — list current templates.
     */
    public function list( $request ) {
        $dir = self::ensure_dir();
        if ( is_wp_error( $dir ) ) {
            return FMW_REST_Auth::error( $dir->get_error_code(), $dir->get_error_message(), 500 );
        }

        $items = [];
        foreach ( self::ALLOWED_EXTENSIONS as $ext ) {
            $matches = glob( $dir . '*.' . $ext );
            if ( ! is_array( $matches ) ) {
                continue;
            }
            foreach ( $matches as $path ) {
                $name = basename( $path, '.' . $ext );
                $items[] = [
                    'name'      => $name,
                    'extension' => $ext,
                    'size'      => filesize( $path ) ?: 0,
                    'modified'  => filemtime( $path ) ?: 0,
                ];
            }
        }

        // Sort by name for deterministic output.
        usort( $items, function ( $a, $b ) {
            return strcmp( $a['name'], $b['name'] ) ?: strcmp( $a['extension'], $b['extension'] );
        } );

        return rest_ensure_response( FMW_REST_Auth::success( $items, [
            'directory' => self::dir_path(),
        ] ) );
    }

    /**
     * GET /templates/{name} — read content.
     */
    public function get( $request ) {
        $name = self::sanitize_name( $request['name'] );
        if ( $name === '' ) {
            return FMW_REST_Auth::error( 'invalid_name', 'Template name must match [a-z0-9_-]{1,64}.', 400 );
        }

        $dir = self::ensure_dir();
        if ( is_wp_error( $dir ) ) {
            return FMW_REST_Auth::error( $dir->get_error_code(), $dir->get_error_message(), 500 );
        }

        // Prefer .html, fall back to .txt — same precedence the email step uses.
        foreach ( self::ALLOWED_EXTENSIONS as $ext ) {
            $path = $dir . $name . '.' . $ext;
            if ( file_exists( $path ) && is_readable( $path ) ) {
                $content = file_get_contents( $path );
                if ( $content === false ) {
                    return FMW_REST_Auth::error( 'read_failed', 'Failed to read template file.', 500 );
                }
                return rest_ensure_response( FMW_REST_Auth::success( [
                    'name'      => $name,
                    'extension' => $ext,
                    'content'   => $content,
                    'size'      => strlen( $content ),
                ] ) );
            }
        }

        return FMW_REST_Auth::error( 'template_not_found', "Template '{$name}' not found.", 404 );
    }

    /**
     * PUT /templates/{name} — write/overwrite.
     */
    public function set( $request ) {
        $name = self::sanitize_name( $request['name'] );
        if ( $name === '' ) {
            return FMW_REST_Auth::error( 'invalid_name', 'Template name must match [a-z0-9_-]{1,64}.', 400 );
        }

        $body = $request->get_json_params();
        if ( ! is_array( $body ) || ! isset( $body['content'] ) ) {
            return FMW_REST_Auth::error( 'invalid_payload', 'Request body must be {"content": "...", "extension": "txt"|"html"}.', 400 );
        }

        $content = $body['content'];
        if ( ! is_string( $content ) ) {
            return FMW_REST_Auth::error( 'invalid_payload', 'content must be a string.', 400 );
        }

        if ( strlen( $content ) > self::CONTENT_MAX_BYTES ) {
            return FMW_REST_Auth::error(
                'content_too_large',
                sprintf( 'content exceeds maximum size of %d bytes (got %d).', self::CONTENT_MAX_BYTES, strlen( $content ) ),
                413
            );
        }

        $extension = isset( $body['extension'] ) ? strtolower( (string) $body['extension'] ) : 'txt';
        if ( ! in_array( $extension, self::ALLOWED_EXTENSIONS, true ) ) {
            return FMW_REST_Auth::error(
                'invalid_extension',
                'extension must be one of: ' . implode( ', ', self::ALLOWED_EXTENSIONS ),
                400
            );
        }

        $dir = self::ensure_dir();
        if ( is_wp_error( $dir ) ) {
            return FMW_REST_Auth::error( $dir->get_error_code(), $dir->get_error_message(), 500 );
        }

        $path = $dir . $name . '.' . $extension;

        // Use WP_Filesystem so file mode + ownership match the upload context.
        $written = self::wp_filesystem_put( $path, $content );
        if ( is_wp_error( $written ) ) {
            return FMW_REST_Auth::error( $written->get_error_code(), $written->get_error_message(), 500 );
        }

        // If the OPPOSITE extension exists for the same name, remove it so
        // the email step's resolution order stays unambiguous.
        $other_ext = $extension === 'txt' ? 'html' : 'txt';
        $other_path = $dir . $name . '.' . $other_ext;
        if ( file_exists( $other_path ) ) {
            wp_delete_file( $other_path );
        }

        FMW_Logger::info( 'Template saved', [ 'name' => $name, 'extension' => $extension, 'size' => strlen( $content ) ] );

        return rest_ensure_response( FMW_REST_Auth::success( [
            'name'      => $name,
            'extension' => $extension,
            'size'      => strlen( $content ),
            'path'      => $path,
        ] ) );
    }

    /**
     * DELETE /templates/{name} — delete both .txt and .html if present.
     */
    public function delete( $request ) {
        $name = self::sanitize_name( $request['name'] );
        if ( $name === '' ) {
            return FMW_REST_Auth::error( 'invalid_name', 'Template name must match [a-z0-9_-]{1,64}.', 400 );
        }

        $dir = self::ensure_dir();
        if ( is_wp_error( $dir ) ) {
            return FMW_REST_Auth::error( $dir->get_error_code(), $dir->get_error_message(), 500 );
        }

        $deleted = [];
        foreach ( self::ALLOWED_EXTENSIONS as $ext ) {
            $path = $dir . $name . '.' . $ext;
            if ( file_exists( $path ) ) {
                wp_delete_file( $path );
                if ( ! file_exists( $path ) ) {
                    $deleted[] = $ext;
                }
            }
        }

        if ( empty( $deleted ) ) {
            return FMW_REST_Auth::error( 'template_not_found', "Template '{$name}' not found.", 404 );
        }

        FMW_Logger::info( 'Template deleted', [ 'name' => $name, 'extensions' => $deleted ] );

        return rest_ensure_response( FMW_REST_Auth::success( [
            'name'    => $name,
            'deleted' => $deleted,
        ] ) );
    }

    /**
     * Sanitize a template name. Returns '' if invalid.
     */
    private static function sanitize_name( $raw ) {
        $name = strtolower( (string) $raw );
        $name = preg_replace( '/[^a-z0-9\-_]/', '', $name );
        if ( $name === '' || strlen( $name ) > self::NAME_MAX_LENGTH ) {
            return '';
        }
        return $name;
    }

    /**
     * Resolve the templates directory path (with trailing slash).
     */
    private static function dir_path() {
        $upload_dir = wp_upload_dir();
        return trailingslashit( $upload_dir['basedir'] ) . 'fmw-templates/';
    }

    /**
     * Ensure the templates directory exists. Returns the path (with trailing
     * slash) on success or WP_Error on failure.
     */
    private static function ensure_dir() {
        $dir = self::dir_path();
        if ( is_dir( $dir ) ) {
            return $dir;
        }
        if ( ! wp_mkdir_p( $dir ) ) {
            return new WP_Error( 'mkdir_failed', "Could not create templates directory at {$dir}." );
        }
        // Drop a defensive index.html so directory listing is harmless even
        // if the host serves directory indexes.
        $index = $dir . 'index.html';
        if ( ! file_exists( $index ) ) {
            @file_put_contents( $index, '' );
        }
        return $dir;
    }

    /**
     * Write a file via WP_Filesystem (preferred over file_put_contents because
     * mode + ownership follow the configured filesystem method — direct, ssh2,
     * ftpext, etc.). Falls back to file_put_contents if WP_Filesystem isn't
     * usable, which is what wp_upload_bits does internally too.
     */
    private static function wp_filesystem_put( $path, $content ) {
        if ( ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        global $wp_filesystem;
        if ( ! WP_Filesystem() || ! $wp_filesystem ) {
            // Fallback: direct write. We've already vetted the path is inside
            // wp-content/uploads/, so this is safe.
            $bytes = @file_put_contents( $path, $content );
            if ( $bytes === false ) {
                return new WP_Error( 'write_failed', "Failed to write template at {$path}." );
            }
            // This branch runs only when WP_Filesystem is unavailable (we fell back
            // to direct file_put_contents above). Apply WP's standard file mode
            // to keep ownership/permissions consistent with media uploads. No
            // WP_Filesystem chmod equivalent is reliably available in this
            // fallback path.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
            @chmod( $path, FS_CHMOD_FILE );
            return true;
        }
        $ok = $wp_filesystem->put_contents( $path, $content, FS_CHMOD_FILE );
        if ( ! $ok ) {
            return new WP_Error( 'write_failed', "Failed to write template at {$path} via WP_Filesystem." );
        }
        return true;
    }
}
