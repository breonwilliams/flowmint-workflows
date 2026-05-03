<?php
/**
 * REST: /runs endpoints (read + replay).
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_REST_Runs {

    public function register() {
        $base = '/' . FMW_REST_Api::base() . '/runs';

        register_rest_route( FMW_REST_Api::ns(), $base, [
            'methods'             => 'GET',
            'callback'            => [ $this, 'list' ],
            'permission_callback' => [ 'FMW_REST_Auth', 'require_manage' ],
            'args' => [
                'workflow_id' => [ 'type' => 'string' ],
                'form_id'     => [ 'type' => 'string' ],
                'entry_id'    => [ 'type' => 'integer' ],
                'status'      => [ 'type' => 'string' ],
                'date_from'   => [ 'type' => 'string' ],
                'date_to'     => [ 'type' => 'string' ],
                'page'        => [ 'type' => 'integer', 'default' => 1 ],
                'per_page'    => [ 'type' => 'integer', 'default' => 20, 'maximum' => 100 ],
            ],
        ] );

        register_rest_route( FMW_REST_Api::ns(), $base . '/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get' ],
            'permission_callback' => [ 'FMW_REST_Auth', 'require_manage' ],
        ] );

        register_rest_route( FMW_REST_Api::ns(), $base . '/(?P<id>\d+)/replay', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'replay' ],
            'permission_callback' => [ 'FMW_REST_Auth', 'require_manage' ],
        ] );
    }

    public function list( $request ) {
        $args = [
            'workflow_id' => $request->get_param( 'workflow_id' ),
            'form_id'     => $request->get_param( 'form_id' ),
            'entry_id'    => $request->get_param( 'entry_id' ),
            'status'      => $request->get_param( 'status' ),
            'date_from'   => $request->get_param( 'date_from' ),
            'date_to'     => $request->get_param( 'date_to' ),
            'page'        => (int) $request->get_param( 'page' ),
            'per_page'    => (int) $request->get_param( 'per_page' ),
        ];

        $result = FMW_Run_Repository::list( $args );

        return rest_ensure_response( FMW_REST_Auth::success( $result['items'], [
            'total'    => $result['total'],
            'page'     => $args['page'],
            'per_page' => $args['per_page'],
            'has_more' => ( $args['page'] * $args['per_page'] ) < $result['total'],
        ] ) );
    }

    public function get( $request ) {
        $id  = (int) $request['id'];
        $run = FMW_Run_Repository::get( $id );
        if ( ! $run ) {
            return FMW_REST_Auth::error( 'run_not_found', "Run {$id} not found.", 404 );
        }

        $run['steps'] = FMW_Run_Step_Repository::list_for_run( $id );

        return rest_ensure_response( FMW_REST_Auth::success( $run ) );
    }

    public function replay( $request ) {
        $id   = (int) $request['id'];
        $run  = FMW_Run_Repository::get( $id );
        if ( ! $run ) {
            return FMW_REST_Auth::error( 'run_not_found', "Run {$id} not found.", 404 );
        }

        // Only failed/cancelled runs can be replayed in v1.
        if ( ! in_array( $run['status'], [ 'failed', 'cancelled', 'completed' ], true ) ) {
            return FMW_REST_Auth::error(
                'cannot_replay',
                "Run {$id} is in status '{$run['status']}'. Only finalized runs (failed, cancelled, completed) can be replayed.",
                400
            );
        }

        if ( ! function_exists( 'as_enqueue_async_action' ) ) {
            return FMW_REST_Auth::error(
                'action_scheduler_missing',
                'Action Scheduler is not loaded. Cannot enqueue replay.',
                500
            );
        }

        // Create a new run row referencing the parent.
        $new_run_id = FMW_Run_Repository::create_pending(
            $run['workflow_id'],
            $run['form_id'],
            (int) $run['entry_id'],
            $id
        );

        if ( is_wp_error( $new_run_id ) ) {
            return FMW_REST_Auth::error( 'db_error', $new_run_id->get_error_message(), 500 );
        }

        as_enqueue_async_action( 'fmw_run_workflow', [ $new_run_id ], 'fmw' );

        return rest_ensure_response( FMW_REST_Auth::success( [
            'new_run_id'    => $new_run_id,
            'parent_run_id' => $id,
            'status'        => 'queued',
        ] ) );
    }
}
