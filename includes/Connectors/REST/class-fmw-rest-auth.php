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
     * Two-gate check (matches the FRE / Promptless connector pattern):
     *   1. The connector kill switch must be on. Default off — site
     *      administrator opts in via FlowMint Workflows → Claude Connection.
     *   2. The authenticated user must hold manage_options.
     *
     * The /preflight endpoint is intentionally exempt from gate 1 so
     * Claude Cowork can introspect the site state ("is the connector on?")
     * without first turning the connector on. /preflight still requires
     * gate 2.
     *
     * @param WP_REST_Request|null $request The current REST request (passed
     *                                      automatically by WP when this is
     *                                      registered as a permission_callback).
     *                                      Optional so existing callers that
     *                                      invoke this without a request still
     *                                      work — falls back to inspecting
     *                                      $_SERVER['REQUEST_URI'] in that
     *                                      case for the preflight-exemption
     *                                      check.
     * @return bool|WP_Error
     */
    public static function require_manage( $request = null ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return new WP_Error(
                'permission_denied',
                'You need manage_options capability to access this endpoint.',
                [ 'status' => rest_authorization_required_code() ]
            );
        }

        // Gate 1: connector must be enabled — except for /preflight, which
        // is the discovery endpoint Claude calls to check whether the
        // connector is enabled in the first place.
        if ( ! self::is_preflight_request( $request ) && ! self::connector_enabled() ) {
            return new WP_Error(
                'connector_disabled',
                'The FlowMint Cowork connector is disabled for this site. Enable it in WordPress admin → FlowMint Workflows → Claude Connection.',
                [ 'status' => 403 ]
            );
        }

        return true;
    }

    /**
     * Is the connector enabled in admin settings?
     *
     * Defers to FMW_Connector_Settings when available; falls back to a
     * direct option read if the settings class hasn't loaded for some
     * reason (defensive — should always be available in practice).
     *
     * @return bool
     */
    private static function connector_enabled() {
        if ( class_exists( 'FMW_Connector_Settings' ) ) {
            return FMW_Connector_Settings::is_enabled();
        }
        return (bool) get_option( 'fmw_connector_enabled', false );
    }

    /**
     * Is the current REST request hitting /preflight?
     *
     * Used to exempt /preflight from the connector-enabled gate. Prefers
     * the WP_REST_Request route (the canonical source) when available;
     * falls back to inspecting $_SERVER['REQUEST_URI'] / $_GET['rest_route']
     * for callers that didn't pass the request through.
     *
     * @param WP_REST_Request|null $request Optional request object.
     * @return bool
     */
    private static function is_preflight_request( $request = null ) {
        // Preferred: use the request object's resolved route. That is the
        // canonical "what endpoint did WP route this to" answer and avoids
        // false positives from query strings or stray substrings in the URI.
        if ( $request instanceof WP_REST_Request ) {
            $route = $request->get_route();
            if ( $route && substr( $route, -strlen( '/preflight' ) ) === '/preflight' ) {
                return true;
            }
        }

        // Fallback: inspect REQUEST_URI / rest_route. Used when callers
        // invoke this method directly without a request.
        $uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
        if ( strpos( $uri, '/connector/preflight' ) !== false ) {
            return true;
        }
        if ( isset( $_GET['rest_route'] ) ) {
            $route = (string) $_GET['rest_route'];
            if ( strpos( $route, '/connector/preflight' ) !== false ) {
                return true;
            }
        }
        return false;
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
