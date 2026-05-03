<?php
/**
 * REST authentication helpers.
 *
 * Capability checks for connector REST endpoints. All endpoints require
 * the 'manage_options' capability — FlowMint operates the plugin, not clients.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_REST_Auth {

    /**
     * Standard permission callback for FMW REST routes.
     *
     * @return bool|WP_Error
     */
    public static function require_manage() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return new WP_Error(
                'permission_denied',
                'You need manage_options capability to access this endpoint.',
                [ 'status' => rest_authorization_required_code() ]
            );
        }
        return true;
    }

    /**
     * Build a standard error response.
     *
     * @param string $code
     * @param string $message
     * @param int    $status
     * @param array  $data
     * @return WP_Error
     */
    public static function error( $code, $message, $status = 400, array $data = [] ) {
        return new WP_Error( $code, $message, array_merge( [ 'status' => $status ], $data ) );
    }

    /**
     * Build a success response envelope.
     *
     * @param mixed $data
     * @param array $meta
     * @return array
     */
    public static function success( $data, array $meta = [] ) {
        $response = [ 'success' => true, 'data' => $data ];
        if ( ! empty( $meta ) ) {
            $response['meta'] = $meta;
        }
        return $response;
    }
}
