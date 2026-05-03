<?php
/**
 * Step: drive_find_folder
 *
 * Looks up a folder by name within a parent. Returns metadata or empty if not found.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Step_Drive_Find_Folder extends FMW_Step_Base {

    public static function type(): string { return 'drive_find_folder'; }
    public static function display_name(): string { return 'Drive: Find Folder'; }
    public static function category(): string { return 'Google Drive'; }
    public static function description(): string { return 'Looks up a folder by name within a parent. Returns metadata or { found: false } if not present.'; }
    public static function has_side_effects(): bool { return false; }

    public static function config_schema(): array {
        return [
            'type'     => 'object',
            'required' => [ 'parent_id', 'name' ],
            'properties' => [
                'parent_id'   => [ 'type' => 'string' ],
                'name'        => [ 'type' => 'string' ],
                'exact_match' => [ 'type' => 'boolean', 'default' => true ],
            ],
        ];
    }

    public static function output_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'found'         => [ 'type' => 'boolean' ],
                'id'            => [ 'type' => 'string' ],
                'name'          => [ 'type' => 'string' ],
                'web_view_link' => [ 'type' => 'string' ],
            ],
        ];
    }

    public function execute( FMW_Workflow_Context $context ): array {
        $parent_id = (string) ( $this->config['parent_id'] ?? '' );
        $name      = (string) ( $this->config['name'] ?? '' );
        $exact     = isset( $this->config['exact_match'] ) ? (bool) $this->config['exact_match'] : true;

        if ( $parent_id === '' || $name === '' ) {
            throw new FMW_Step_Exception( 'config_error', 'drive_find_folder: parent_id and name are required.' );
        }

        $client = FMW_Drive_Client::from_credentials();
        $found  = $client->find_folder( $parent_id, $name, $exact );

        if ( ! $found ) {
            return [ 'found' => false ];
        }

        return array_merge( [ 'found' => true ], $found );
    }
}
