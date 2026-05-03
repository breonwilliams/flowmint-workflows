<?php
/**
 * Step: drive_find_or_create_folder
 *
 * Returns an existing folder if it exists, otherwise creates one. Idempotent.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Step_Drive_Find_Or_Create_Folder extends FMW_Step_Base {

    public static function type(): string { return 'drive_find_or_create_folder'; }
    public static function display_name(): string { return 'Drive: Find or Create Folder'; }
    public static function category(): string { return 'Google Drive'; }
    public static function description(): string { return 'Returns an existing folder by name within a parent, or creates one if missing. Safely idempotent on retry.'; }
    public static function has_side_effects(): bool { return true; }

    public static function config_schema(): array {
        return [
            'type'     => 'object',
            'required' => [ 'parent_id', 'name' ],
            'properties' => [
                'parent_id' => [ 'type' => 'string' ],
                'name'      => [ 'type' => 'string' ],
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
                'was_created'   => [ 'type' => 'boolean' ],
            ],
        ];
    }

    public function execute( FMW_Workflow_Context $context ): array {
        $parent_id = (string) ( $this->config['parent_id'] ?? '' );
        $name      = (string) ( $this->config['name'] ?? '' );

        if ( $parent_id === '' || $name === '' ) {
            throw new FMW_Step_Exception( 'config_error', 'drive_find_or_create_folder: parent_id and name are required.' );
        }

        $client = FMW_Drive_Client::from_credentials();
        return $client->find_or_create_folder( $parent_id, $name );
    }
}
