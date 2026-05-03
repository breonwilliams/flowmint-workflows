<?php
/**
 * Step: fre_get_file
 *
 * Resolves a file field to its full file metadata. Used when downstream
 * steps need both the URL and the local path.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Step_Fre_Get_File extends FMW_Step_Base {

    public static function type(): string {
        return 'fre_get_file';
    }

    public static function display_name(): string {
        return 'FormEngine: Get File';
    }

    public static function category(): string {
        return 'FormEngine';
    }

    public static function description(): string {
        return 'Resolves a file field key to its file metadata (path, URL, size, MIME). Sets exists=false if no file uploaded.';
    }

    public static function config_schema(): array {
        return [
            'type'     => 'object',
            'required' => [ 'field_key' ],
            'properties' => [
                'field_key' => [ 'type' => 'string' ],
            ],
        ];
    }

    public static function output_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'field_key' => [ 'type' => 'string' ],
                'exists'    => [ 'type' => 'boolean' ],
                'file_name' => [ 'type' => 'string' ],
                'file_path' => [ 'type' => 'string' ],
                'file_url'  => [ 'type' => 'string' ],
                'file_size' => [ 'type' => 'integer' ],
                'mime_type' => [ 'type' => 'string' ],
            ],
        ];
    }

    public static function has_side_effects(): bool {
        return false;
    }

    public function execute( FMW_Workflow_Context $context ): array {
        $field_key = (string) ( $this->config['field_key'] ?? '' );
        if ( $field_key === '' ) {
            throw new FMW_Step_Exception( 'config_error', "fre_get_file: 'field_key' is required." );
        }

        $entry_files = $context->get_entry_files();

        if ( empty( $entry_files[ $field_key ] ) ) {
            return [
                'field_key' => $field_key,
                'exists'    => false,
            ];
        }

        $file_row = $entry_files[ $field_key ];

        // If multi-file (array of arrays), grab the first file. Multi-file handling
        // for downstream steps would require iteration — out of scope for v1.
        if ( isset( $file_row[0] ) && is_array( $file_row[0] ) ) {
            $file_row = $file_row[0];
        }

        // FE stores file_path relative to wp-content/uploads. Build absolute path.
        $relative_path = $file_row['file_path'] ?? '';
        $absolute_path = '';
        $file_url      = '';

        if ( $relative_path ) {
            $upload_dir = wp_upload_dir();
            // FE stores paths in different formats. Normalize.
            if ( strpos( $relative_path, $upload_dir['basedir'] ) === 0 ) {
                $absolute_path = $relative_path;
                $file_url      = str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $relative_path );
            } else {
                // Try as relative.
                $candidate = trailingslashit( $upload_dir['basedir'] ) . ltrim( $relative_path, '/' );
                if ( file_exists( $candidate ) ) {
                    $absolute_path = $candidate;
                    $file_url      = trailingslashit( $upload_dir['baseurl'] ) . ltrim( $relative_path, '/' );
                }
            }
        }

        return [
            'field_key' => $field_key,
            'exists'    => true,
            'file_name' => $file_row['file_name'] ?? '',
            'file_path' => $absolute_path,
            'file_url'  => $file_url,
            'file_size' => isset( $file_row['file_size'] ) ? (int) $file_row['file_size'] : 0,
            'mime_type' => $file_row['mime_type'] ?? '',
        ];
    }
}
