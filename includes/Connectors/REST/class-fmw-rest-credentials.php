<?php
/**
 * REST: /credentials endpoints.
 *
 * Encrypted credential management. Never returns plaintext values — only
 * accepts them via PUT and exposes whether each credential is configured.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_REST_Credentials {

    /**
     * Map of credential keys → test client class names.
     *
     * Used by /credentials/{key}/test to instantiate the appropriate client
     * and call its test() method.
     */
    private static $test_clients = [
        'drive_service_account' => 'FMW_Drive_Client',
        'printavo_api_token'    => 'FMW_Printavo_Client',
    ];

    /**
     * Known credential keys (used to validate the {key} URL parameter).
     */
    private static $known_keys = [
        'drive_service_account',
        'printavo_api_token',
        'slack_webhook',
        'notification_email',
    ];

    public function register() {
        $base = '/' . FMW_REST_Api::base() . '/credentials';

        register_rest_route( FMW_REST_Api::ns(), $base, [
            'methods'             => 'GET',
            'callback'            => [ $this, 'list' ],
            'permission_callback' => [ 'FMW_REST_Auth', 'require_manage' ],
        ] );

        register_rest_route( FMW_REST_Api::ns(), $base . '/(?P<key>[a-z0-9_]+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_status' ],
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

        register_rest_route( FMW_REST_Api::ns(), $base . '/(?P<key>[a-z0-9_]+)/test', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'test' ],
            'permission_callback' => [ 'FMW_REST_Auth', 'require_manage' ],
        ] );
    }

    public function list( $request ) {
        $items = [];
        foreach ( self::$known_keys as $key ) {
            $items[] = [
                'key'        => $key,
                'configured' => FMW_Credential_Store::is_configured( $key ),
                'testable'   => isset( self::$test_clients[ $key ] ),
            ];
        }
        return rest_ensure_response( FMW_REST_Auth::success( $items ) );
    }

    public function get_status( $request ) {
        $key = (string) $request['key'];
        if ( ! in_array( $key, self::$known_keys, true ) ) {
            return FMW_REST_Auth::error( 'unknown_credential_key', "Credential key '{$key}' is not recognized.", 404 );
        }
        return rest_ensure_response( FMW_REST_Auth::success( [
            'key'        => $key,
            'configured' => FMW_Credential_Store::is_configured( $key ),
            'testable'   => isset( self::$test_clients[ $key ] ),
        ] ) );
    }

    public function set( $request ) {
        $key = (string) $request['key'];
        if ( ! in_array( $key, self::$known_keys, true ) ) {
            return FMW_REST_Auth::error( 'unknown_credential_key', "Credential key '{$key}' is not recognized.", 404 );
        }

        $body = $request->get_json_params();
        if ( ! is_array( $body ) || ! isset( $body['value'] ) ) {
            return FMW_REST_Auth::error( 'invalid_payload', 'Request body must be {"value": "<credential>"}' );
        }

        $value = $body['value'];
        if ( ! is_string( $value ) ) {
            return FMW_REST_Auth::error( 'invalid_payload', 'value must be a string.' );
        }

        $ok = FMW_Credential_Store::set( $key, $value );
        if ( ! $ok ) {
            return FMW_REST_Auth::error( 'storage_failed', 'Failed to encrypt or store credential.', 500 );
        }

        FMW_Logger::info( 'Credential stored', [ 'key' => $key ] );

        return rest_ensure_response( FMW_REST_Auth::success( [
            'key'        => $key,
            'configured' => true,
        ] ) );
    }

    public function delete( $request ) {
        $key = (string) $request['key'];
        if ( ! in_array( $key, self::$known_keys, true ) ) {
            return FMW_REST_Auth::error( 'unknown_credential_key', "Credential key '{$key}' is not recognized.", 404 );
        }

        $ok = FMW_Credential_Store::delete( $key );

        return rest_ensure_response( FMW_REST_Auth::success( [
            'key'        => $key,
            'deleted'    => (bool) $ok,
            'configured' => false,
        ] ) );
    }

    public function test( $request ) {
        $key = (string) $request['key'];
        if ( ! in_array( $key, self::$known_keys, true ) ) {
            return FMW_REST_Auth::error( 'unknown_credential_key', "Credential key '{$key}' is not recognized.", 404 );
        }

        if ( ! FMW_Credential_Store::is_configured( $key ) ) {
            return FMW_REST_Auth::error( 'credential_not_configured', "Credential '{$key}' is not configured.", 400 );
        }

        if ( ! isset( self::$test_clients[ $key ] ) ) {
            return FMW_REST_Auth::error( 'not_testable', "Credential '{$key}' has no test client configured.", 400 );
        }

        $client_class = self::$test_clients[ $key ];

        if ( ! class_exists( $client_class ) ) {
            return FMW_REST_Auth::error(
                'dependency_missing',
                "Test client '{$client_class}' not found. Run `composer install` if this is a vendor dependency.",
                500
            );
        }

        try {
            $client = call_user_func( [ $client_class, 'from_credentials' ] );
            $details = $client->test();

            return rest_ensure_response( FMW_REST_Auth::success( [
                'key'         => $key,
                'test_result' => 'ok',
                'details'     => $details,
            ] ) );
        } catch ( FMW_Step_Exception $e ) {
            return rest_ensure_response( FMW_REST_Auth::success( [
                'key'         => $key,
                'test_result' => 'failed',
                'error_code'  => $e->get_error_code(),
                'error'       => $e->getMessage(),
            ] ) );
        } catch ( Exception $e ) {
            return rest_ensure_response( FMW_REST_Auth::success( [
                'key'         => $key,
                'test_result' => 'failed',
                'error_code'  => 'unexpected',
                'error'       => $e->getMessage(),
            ] ) );
        }
    }
}
