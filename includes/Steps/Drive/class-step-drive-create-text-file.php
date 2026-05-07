<?php
/**
 * Step: drive_create_text_file
 *
 * Creates a small text/markdown/HTML file in Drive from an in-memory string.
 *
 * Differs from drive_upload_file (which uploads from a FRE entry's file
 * field) — this step takes a `content` string directly, typically built via
 * `{{ template('...') }}` interpolation or composed inline. Lets a workflow
 * drop a structured submission record into the same Drive folder that
 * holds the design file, so the folder is self-describing without needing
 * to cross-reference other systems (Printavo Quote, email inbox, etc.).
 *
 * Hard cap: 1MB content. For larger payloads, generate a file on disk and
 * use drive_upload_file's chunked path instead.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Step_Drive_Create_Text_File extends FMW_Step_Base {

    public static function type(): string { return 'drive_create_text_file'; }
    public static function display_name(): string { return 'Drive: Create Text File'; }
    public static function category(): string { return 'Google Drive'; }
    public static function description(): string { return 'Creates a small text, markdown, or HTML file in a Drive folder from a string. Use for submission records, formatted summaries, or any generated document. Cap: 1MB.'; }
    public static function has_side_effects(): bool { return true; }

    public static function config_schema(): array {
        return [
            'type'     => 'object',
            'required' => [ 'parent_id', 'name', 'content' ],
            'properties' => [
                'parent_id' => [ 'type' => 'string', 'description' => 'Drive folder ID where the file will live.' ],
                'name'      => [ 'type' => 'string', 'description' => 'Filename including extension (e.g. "submission.txt", "summary.md").' ],
                'content'   => [ 'type' => 'string', 'description' => 'File body. Cap: 1MB. Typically built via {{ template(...) }} or {{ data.* }} interpolation.' ],
                'mime_type' => [ 'type' => 'string', 'description' => 'Optional. Defaults to text/plain. Common alternatives: text/markdown, text/html, application/json.' ],
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
            ],
        ];
    }

    public function execute( FMW_Workflow_Context $context ): array {
        $parent_id = (string) ( $this->config['parent_id'] ?? '' );
        $name      = (string) ( $this->config['name'] ?? '' );
        $content   = (string) ( $this->config['content'] ?? '' );
        $mime_type = (string) ( $this->config['mime_type'] ?? 'text/plain' );

        if ( $parent_id === '' ) {
            throw new FMW_Step_Exception( 'config_error', 'drive_create_text_file: parent_id is required.' );
        }
        if ( $name === '' ) {
            throw new FMW_Step_Exception( 'config_error', 'drive_create_text_file: name is required.' );
        }
        // content may legitimately be empty (some callers want a placeholder file),
        // so we don't reject empty content here.

        $client = FMW_Drive_Client::from_credentials();
        return $client->create_text_file( $parent_id, $name, $content, $mime_type );
    }
}
