<?php
/**
 * Step: drive_upload_file
 *
 * Uploads a FormEngine entry file to a Drive folder. Supports chunked
 * resumable uploads for files > 5MB.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Step_Drive_Upload_File extends FMW_Step_Base {

    public static function type(): string { return 'drive_upload_file'; }
    public static function display_name(): string { return 'Drive: Upload File'; }
    public static function category(): string { return 'Google Drive'; }
    public static function description(): string { return 'Uploads a FormEngine entry file to a Drive folder. Resumable upload for files > 5MB.'; }
    public static function has_side_effects(): bool { return true; }

    public static function config_schema(): array {
        return [
            'type'     => 'object',
            'required' => [ 'parent_id', 'file_field' ],
            'properties' => [
                'parent_id'  => [ 'type' => 'string' ],
                'file_field' => [
                    'type'        => 'string',
                    'description' => 'FE field key (must be a file field).',
                ],
                'rename_to'  => [
                    'type'        => 'string',
                    'description' => 'Override filename. Defaults to original.',
                ],
            ],
        ];
    }

    public static function output_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'id'            => [ 'type' => 'string' ],
                'name'          => [ 'type' => 'string' ],
                'web_view_link' => [ 'type' => 'string' ],
                'size'          => [ 'type' => 'integer' ],
                'mime_type'     => [ 'type' => 'string' ],
                'skipped'       => [ 'type' => 'boolean' ],
                'reason'        => [ 'type' => 'string' ],
            ],
        ];
    }

    public function execute( FMW_Workflow_Context $context ): array {
        $parent_id  = (string) ( $this->config['parent_id'] ?? '' );
        $field_key  = (string) ( $this->config['file_field'] ?? '' );
        $rename_to  = isset( $this->config['rename_to'] ) ? (string) $this->config['rename_to'] : null;

        if ( $parent_id === '' || $field_key === '' ) {
            throw new FMW_Step_Exception( 'config_error', 'drive_upload_file: parent_id and file_field are required.' );
        }

        // Look up the file in the entry's files array.
        $entry_files = $context->get_entry_files();
        $file_row    = $entry_files[ $field_key ] ?? null;

        if ( ! $file_row ) {
            return [
                'skipped' => true,
                'reason'  => 'no_file',
            ];
        }

        // Multi-file fields: take first file. Multi-file iteration is a future enhancement.
        if ( isset( $file_row[0] ) && is_array( $file_row[0] ) ) {
            $file_row = $file_row[0];
        }

        // Resolve to absolute path.
        $relative_path = $file_row['file_path'] ?? '';
        if ( empty( $relative_path ) ) {
            return [
                'skipped' => true,
                'reason'  => 'no_file_path',
            ];
        }

        $upload_dir = wp_upload_dir();
        if ( strpos( $relative_path, $upload_dir['basedir'] ) === 0 ) {
            $absolute_path = $relative_path;
        } else {
            $absolute_path = trailingslashit( $upload_dir['basedir'] ) . ltrim( $relative_path, '/' );
        }

        if ( ! file_exists( $absolute_path ) ) {
            throw new FMW_Step_Exception(
                'file_not_found',
                "drive_upload_file: file not found on disk: {$absolute_path}"
            );
        }

        $original_name = $file_row['file_name'] ?? basename( $absolute_path );
        $upload_name   = $rename_to ?: $original_name;
        $mime_type     = $file_row['mime_type'] ?? null;

        $client = FMW_Drive_Client::from_credentials();
        return $client->upload_file( $parent_id, $absolute_path, $upload_name, $mime_type );
    }
}
