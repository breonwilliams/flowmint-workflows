<?php
/**
 * Step: drive_share_link
 *
 * Sets sharing permissions on a Drive resource and returns the shareable URL.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Step_Drive_Share_Link extends FMW_Step_Base {

    public static function type(): string { return 'drive_share_link'; }
    public static function display_name(): string { return 'Drive: Share Link'; }
    public static function category(): string { return 'Google Drive'; }
    public static function description(): string { return 'Sets sharing permissions on a Drive file or folder. Returns the shareable web view URL.'; }
    public static function has_side_effects(): bool { return true; }

    public static function config_schema(): array {
        return [
            'type'     => 'object',
            'required' => [ 'resource_id', 'permission_type', 'role' ],
            'properties' => [
                'resource_id'     => [ 'type' => 'string' ],
                'permission_type' => [
                    'type' => 'string',
                    'enum' => [ 'anyone_with_link', 'user', 'group', 'domain' ],
                ],
                'role' => [
                    'type' => 'string',
                    'enum' => [ 'reader', 'commenter', 'writer' ],
                ],
                'email'  => [ 'type' => 'string' ],
                'domain' => [ 'type' => 'string' ],
            ],
        ];
    }

    public static function output_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'permission_id'  => [ 'type' => 'string' ],
                'shareable_url'  => [ 'type' => 'string' ],
            ],
        ];
    }

    public function execute( FMW_Workflow_Context $context ): array {
        $resource_id = (string) ( $this->config['resource_id'] ?? '' );
        $type        = (string) ( $this->config['permission_type'] ?? '' );
        $role        = (string) ( $this->config['role'] ?? '' );

        if ( $resource_id === '' || $type === '' || $role === '' ) {
            throw new FMW_Step_Exception( 'config_error', 'drive_share_link: resource_id, permission_type, and role are required.' );
        }

        // Map our friendly type names to Drive API types.
        $extra = [];
        switch ( $type ) {
            case 'anyone_with_link':
                $api_type = 'anyone';
                break;
            case 'user':
                $api_type = 'user';
                $extra['emailAddress'] = $this->config['email'] ?? '';
                if ( empty( $extra['emailAddress'] ) ) {
                    throw new FMW_Step_Exception( 'config_error', 'drive_share_link: email is required when permission_type=user.' );
                }
                break;
            case 'group':
                $api_type = 'group';
                $extra['emailAddress'] = $this->config['email'] ?? '';
                if ( empty( $extra['emailAddress'] ) ) {
                    throw new FMW_Step_Exception( 'config_error', 'drive_share_link: email is required when permission_type=group.' );
                }
                break;
            case 'domain':
                $api_type = 'domain';
                $extra['domain'] = $this->config['domain'] ?? '';
                if ( empty( $extra['domain'] ) ) {
                    throw new FMW_Step_Exception( 'config_error', 'drive_share_link: domain is required when permission_type=domain.' );
                }
                break;
            default:
                throw new FMW_Step_Exception( 'config_error', sprintf( "drive_share_link: invalid permission_type '%s'.", esc_html( $type ) ) );
        }

        $client = FMW_Drive_Client::from_credentials();
        return $client->set_permission( $resource_id, $api_type, $role, $extra );
    }
}
