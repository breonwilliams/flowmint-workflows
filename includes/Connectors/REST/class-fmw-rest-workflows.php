<?php
/**
 * REST: /workflows endpoints (CRUD).
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_REST_Workflows {

    public function register() {
        $base = '/' . FMW_REST_Api::base() . '/workflows';

        register_rest_route( FMW_REST_Api::ns(), $base, [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'list' ],
                'permission_callback' => [ 'FMW_REST_Auth', 'require_manage' ],
                'args'                => [
                    'form_id'      => [ 'type' => 'string' ],
                    'trigger_type' => [
                        'type' => 'string',
                        'enum' => [ 'form', 'schedule' ],
                    ],
                    'enabled'      => [ 'type' => 'boolean' ],
                    'managed_by'   => [ 'type' => 'string' ],
                    'page'         => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
                    'per_page'     => [ 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ],
                ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'create' ],
                'permission_callback' => [ 'FMW_REST_Auth', 'require_manage' ],
            ],
        ] );

        register_rest_route( FMW_REST_Api::ns(), $base . '/(?P<id>[a-z0-9\-_]+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get' ],
                'permission_callback' => [ 'FMW_REST_Auth', 'require_manage' ],
            ],
            [
                'methods'             => 'PATCH',
                'callback'            => [ $this, 'update' ],
                'permission_callback' => [ 'FMW_REST_Auth', 'require_manage' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ $this, 'delete' ],
                'permission_callback' => [ 'FMW_REST_Auth', 'require_manage' ],
                'args'                => [
                    'cascade' => [ 'type' => 'boolean', 'default' => false ],
                ],
            ],
        ] );

        register_rest_route( FMW_REST_Api::ns(), $base . '/(?P<id>[a-z0-9\-_]+)/test', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'test' ],
            'permission_callback' => [ 'FMW_REST_Auth', 'require_manage' ],
        ] );
    }

    public function list( $request ) {
        $args = [
            'form_id'      => $request->get_param( 'form_id' ),
            'trigger_type' => $request->get_param( 'trigger_type' ),
            'enabled'      => $request->get_param( 'enabled' ),
            'managed_by'   => $request->get_param( 'managed_by' ),
            'page'         => (int) $request->get_param( 'page' ),
            'per_page'     => (int) $request->get_param( 'per_page' ),
        ];

        $result = FMW_Workflow_Repository::list( $args );

        // Strip 'config' from list view (use GET on a single workflow to retrieve).
        $items = array_map( function ( $row ) {
            unset( $row['config'] );
            return $row;
        }, $result['items'] );

        return rest_ensure_response( FMW_REST_Auth::success( $items, [
            'total'    => $result['total'],
            'page'     => $args['page'],
            'per_page' => $args['per_page'],
            'has_more' => ( $args['page'] * $args['per_page'] ) < $result['total'],
        ] ) );
    }

    public function get( $request ) {
        $id  = $request['id'];
        $row = FMW_Workflow_Repository::get( $id );
        if ( ! $row ) {
            return FMW_REST_Auth::error( 'workflow_not_found', "Workflow {$id} not found.", 404 );
        }
        return rest_ensure_response( FMW_REST_Auth::success( $row ) );
    }

    public function create( $request ) {
        $body = $request->get_json_params();
        if ( ! is_array( $body ) ) {
            return FMW_REST_Auth::error( 'invalid_json', 'Request body must be JSON.' );
        }

        // config must be a JSON STRING per docs.
        if ( isset( $body['config'] ) && ! is_string( $body['config'] ) ) {
            return FMW_REST_Auth::error( 'invalid_json', 'config must be a JSON string, not an object.' );
        }

        // Validate.
        $validation = FMW_Workflow_Validator::validate_full( $body );
        if ( ! $validation['valid'] ) {
            return FMW_REST_Auth::error( 'invalid_workflow', 'Workflow validation failed.', 400, [
                'errors'   => $validation['errors'],
                'warnings' => $validation['warnings'],
            ] );
        }

        // Auto-tag managed_by based on user_agent or explicit override.
        if ( empty( $body['managed_by'] ) ) {
            $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
            $body['managed_by'] = strpos( $ua, 'Cowork' ) !== false ? 'connector:cowork' : 'admin';
        }

        $created = FMW_Workflow_Repository::create( $body );
        if ( is_wp_error( $created ) ) {
            return FMW_REST_Auth::error(
                $created->get_error_code(),
                $created->get_error_message(),
                $created->get_error_code() === 'already_exists' ? 409 : 400
            );
        }

        $response = rest_ensure_response( FMW_REST_Auth::success( $created ) );
        $response->set_status( 201 );
        return $response;
    }

    public function update( $request ) {
        $id   = $request['id'];
        $body = $request->get_json_params();
        if ( ! is_array( $body ) ) {
            return FMW_REST_Auth::error( 'invalid_json', 'Request body must be JSON.' );
        }

        if ( isset( $body['config'] ) && ! is_string( $body['config'] ) ) {
            return FMW_REST_Auth::error( 'invalid_json', 'config must be a JSON string.' );
        }

        // Re-validate the updated config.
        if ( ! empty( $body['config'] ) || ! empty( $body['form_id'] ) ) {
            $for_validation = array_merge(
                [ 'id' => $id ],
                $body
            );
            $validation = FMW_Workflow_Validator::validate_full( $for_validation );
            if ( ! $validation['valid'] ) {
                return FMW_REST_Auth::error( 'invalid_workflow', 'Workflow validation failed.', 400, [
                    'errors'   => $validation['errors'],
                    'warnings' => $validation['warnings'],
                ] );
            }
        }

        $updated = FMW_Workflow_Repository::update( $id, $body );
        if ( is_wp_error( $updated ) ) {
            return FMW_REST_Auth::error(
                $updated->get_error_code(),
                $updated->get_error_message(),
                $updated->get_error_code() === 'workflow_not_found' ? 404 : 400
            );
        }

        return rest_ensure_response( FMW_REST_Auth::success( $updated ) );
    }

    public function delete( $request ) {
        $id      = $request['id'];
        $cascade = (bool) $request->get_param( 'cascade' );

        $result = FMW_Workflow_Repository::delete( $id, $cascade );
        if ( is_wp_error( $result ) ) {
            return FMW_REST_Auth::error( $result->get_error_code(), $result->get_error_message(), 404 );
        }

        return rest_ensure_response( FMW_REST_Auth::success( [ 'deleted' => true, 'cascade' => $cascade ] ) );
    }

    /**
     * POST /workflows/{id}/test — validate without executing.
     */
    public function test( $request ) {
        $id   = $request['id'];
        $body = $request->get_json_params() ?: [];

        $config_string = $body['config'] ?? null;
        if ( $config_string === null ) {
            $existing = FMW_Workflow_Repository::get( $id );
            if ( ! $existing ) {
                return FMW_REST_Auth::error( 'workflow_not_found', "Workflow {$id} not found.", 404 );
            }
            $config_string = $existing['config'];
        }

        $config = is_string( $config_string ) ? json_decode( $config_string, true ) : $config_string;
        if ( ! is_array( $config ) ) {
            return FMW_REST_Auth::error( 'invalid_json', 'config is not valid JSON.' );
        }

        $validation = FMW_Workflow_Validator::validate( $config );

        return rest_ensure_response( FMW_REST_Auth::success( [
            'valid'    => $validation['valid'],
            'errors'   => $validation['errors'],
            'warnings' => $validation['warnings'],
        ] ) );
    }
}
