<?php
/**
 * REST: /step-types endpoints.
 *
 * Read-only listing of registered step types. Used by Claude/MCP to know
 * what's available when generating a workflow.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_REST_Step_Types {

    public function register() {
        $base = '/' . FMW_REST_Api::base() . '/step-types';

        register_rest_route( FMW_REST_Api::ns(), $base, [
            'methods'             => 'GET',
            'callback'            => [ $this, 'list' ],
            'permission_callback' => [ 'FMW_REST_Auth', 'require_manage' ],
        ] );

        register_rest_route( FMW_REST_Api::ns(), $base . '/(?P<type>[a-z0-9_]+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get' ],
            'permission_callback' => [ 'FMW_REST_Auth', 'require_manage' ],
        ] );
    }

    public function list( $request ) {
        $registry = FMW_Step_Registry::instance();
        return rest_ensure_response( FMW_REST_Auth::success( $registry->describe_all() ) );
    }

    public function get( $request ) {
        $registry = FMW_Step_Registry::instance();
        $type     = (string) $request['type'];
        $info     = $registry->describe( $type );
        if ( ! $info ) {
            return FMW_REST_Auth::error( 'step_type_not_found', "Step type '{$type}' not registered.", 404 );
        }
        return rest_ensure_response( FMW_REST_Auth::success( $info ) );
    }
}
