<?php
/**
 * REST: /preflight
 *
 * Health check endpoint.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_REST_Preflight {

    public function register() {
        register_rest_route( FMW_REST_Api::ns(), '/' . FMW_REST_Api::base() . '/preflight', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'handle' ],
            'permission_callback' => [ 'FMW_REST_Auth', 'require_manage' ],
        ] );
    }

    public function handle( $request ) {
        $health = FMW_Schema::check_health();

        $credentials = [
            'drive_service_account' => FMW_Credential_Store::is_configured( 'drive_service_account' ),
            'printavo_api_token'    => FMW_Credential_Store::is_configured( 'printavo_api_token' ),
            'slack_webhook'         => FMW_Credential_Store::is_configured( 'slack_webhook' ),
        ];

        return rest_ensure_response( FMW_REST_Auth::success( [
            'plugin_version'         => FMW_VERSION,
            'connector_api_version'  => 'v1',
            'connector_enabled'      => true,
            'fre_active'             => defined( 'FRE_VERSION' ),
            'fre_version'            => defined( 'FRE_VERSION' ) ? FRE_VERSION : null,
            'action_scheduler_active' => function_exists( 'as_enqueue_async_action' ),
            'authenticated_as'       => wp_get_current_user()->user_login,
            'user_capabilities'      => [
                'fmw_manage'       => current_user_can( 'manage_options' ),
            ],
            'schema_document_url' => FMW_PLUGIN_URL . 'docs/CONNECTOR_API.md',
            'diagnostics' => [
                'stored_plugin_version' => get_option( 'fmw_db_version', '0.0.0' ),
                'database_health' => [
                    'ok'             => empty( $health ),
                    'tables_present' => array_values( FMW_Schema::get_table_names() ),
                    'tables_missing' => $health,
                ],
                'credentials_configured' => $credentials,
            ],
        ] ) );
    }
}
