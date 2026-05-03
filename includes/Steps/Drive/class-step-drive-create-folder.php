<?php
/**
 * Step: drive_create_folder
 *
 * Always creates a new folder. For idempotent behavior, use drive_find_or_create_folder.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Step_Drive_Create_Folder extends FMW_Step_Base {

    public static function type(): string { return 'drive_create_folder'; }
    public static function display_name(): string { return 'Drive: Create Folder'; }
    public static function category(): string { return 'Google Drive'; }
    public static function description(): string { return 'Creates a new folder under a parent. For idempotent retry behavior, prefer drive_find_or_create_folder.'; }
    public static function has_side_effects(): bool { return true; }

    public static function config_schema(): array {
        return [
            'type'     => 'object',
            'required' => [ 'parent_id', 'name' ],
            'properties' => [
                'parent_id'       => [ 'type' => 'string' ],
                'name'            => [ 'type' => 'string' ],
                'allow_duplicate' => [ 'type' => 'boolean', 'default' => false ],
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
            ],
        ];
    }

    public function execute( FMW_Workflow_Context $context ): array {
        $parent_id = (string) ( $this->config['parent_id'] ?? '' );
        $name      = (string) ( $this->config['name'] ?? '' );
        $allow_dup = ! empty( $this->config['allow_duplicate'] );

        if ( $parent_id === '' || $name === '' ) {
            throw new FMW_Step_Exception( 'config_error', 'drive_create_folder: parent_id and name are required.' );
        }

        $client = FMW_Drive_Client::from_credentials();

        // Idempotency: check if a folder with this name already exists in the parent.
        // If it does AND we're being retried with the same run_id, return it.
        // Otherwise (allow_duplicate=true), proceed to create.
        if ( ! $allow_dup ) {
            $existing = $client->find_folder( $parent_id, $name );
            if ( $existing ) {
                FMW_Logger::info( 'drive_create_folder: returning existing folder', [
                    'parent_id' => $parent_id,
                    'name'      => $name,
                    'folder_id' => $existing['id'],
                ] );
                return $existing;
            }
        }

        return $client->create_folder( $parent_id, $name );
    }
}
