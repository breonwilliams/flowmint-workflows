<?php
/**
 * Workflow executor.
 *
 * Runs the steps of a workflow against a context. Handles per-step
 * interpolation, skip_if evaluation, error policy, and run_step recording.
 *
 * Throws FMW_Step_Exception if a non-recoverable failure occurs (the job
 * handler catches and decides retry vs fail).
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Workflow_Executor {

    /**
     * Execute a workflow against a context.
     *
     * @param FMW_Workflow         $workflow
     * @param FMW_Workflow_Context $context
     * @throws FMW_Step_Exception On step failure that should fail the run.
     */
    public function execute( FMW_Workflow $workflow, FMW_Workflow_Context $context ) {
        $steps    = $workflow->steps();
        $registry = FMW_Step_Registry::instance();
        $interp   = new FMW_Interpolator( $context );
        $expr     = new FMW_Expression( $interp );

        foreach ( $steps as $idx => $step_def ) {
            $step_name = $step_def['name'] ?? "step_{$idx}";
            $step_type = $step_def['type'] ?? '';

            FMW_Logger::debug( 'Executing step', [
                'run_id'     => $context->get_run_id(),
                'step_name'  => $step_name,
                'step_type'  => $step_type,
                'step_index' => $idx,
            ] );

            // Evaluate skip_if.
            if ( ! empty( $step_def['skip_if'] ) ) {
                $should_skip = $expr->evaluate( $step_def['skip_if'] );
                if ( $should_skip ) {
                    FMW_Run_Step_Repository::record_skipped(
                        $context->get_run_id(),
                        $idx,
                        $step_name,
                        $step_type,
                        'skip_if evaluated truthy: ' . $step_def['skip_if']
                    );
                    FMW_Logger::info( 'Step skipped', [
                        'run_id'    => $context->get_run_id(),
                        'step_name' => $step_name,
                        'reason'    => 'skip_if',
                    ] );
                    continue;
                }
            }

            // Verify type is registered.
            $class = $registry->get_class( $step_type );
            if ( ! $class ) {
                throw new FMW_Step_Exception(
                    'config_error',
                    "Step '{$step_name}' references unknown type '{$step_type}'. Workflow JSON is invalid."
                );
            }

            // Interpolate the step's config.
            $raw_config        = $step_def['config'] ?? [];
            $interpolated_config = $interp->interpolate( $raw_config );

            // Build the step instance.
            $step_def_with_interpolated_config = array_merge( $step_def, [ 'config' => $interpolated_config ] );

            /** @var FMW_Step_Base $step */
            $step = new $class( $step_def_with_interpolated_config );

            // Record step start.
            $step_record_id = FMW_Run_Step_Repository::create_pending(
                $context->get_run_id(),
                $idx,
                $step_name,
                $step_type,
                wp_json_encode( $interpolated_config )
            );

            $started_at = microtime( true );

            try {
                $output = $step->execute( $context );
            } catch ( FMW_Step_Exception $e ) {
                $duration_ms = (int) ( ( microtime( true ) - $started_at ) * 1000 );
                FMW_Run_Step_Repository::mark_failure(
                    $step_record_id,
                    $duration_ms,
                    $e->get_error_code(),
                    $e->getMessage()
                );

                /**
                 * Fires when a step fails.
                 */
                do_action( 'fmw_step_failed',
                    $context->get_run_id(), $step_name, $step_type, $e->get_error_code(), $e->getMessage() );

                // Apply on_error policy.
                $policy = $step->get_on_error();
                if ( $policy === 'continue' ) {
                    FMW_Logger::warning( 'Step failed but on_error=continue', [
                        'run_id'    => $context->get_run_id(),
                        'step_name' => $step_name,
                        'error'     => $e->getMessage(),
                    ] );
                    // Empty output for downstream references.
                    $context->set_step_output( $step_name, [ 'failed' => true, 'error' => $e->get_error_code() ] );
                    continue;
                }

                // 'fail' or 'retry' both end up rethrowing so the job handler can decide.
                throw $e;
            } catch ( Exception $e ) {
                // Unexpected exception — wrap in FMW_Step_Exception.
                $duration_ms = (int) ( ( microtime( true ) - $started_at ) * 1000 );
                $wrapped = new FMW_Step_Exception( 'unexpected', $e->getMessage(), [ 'previous' => get_class( $e ) ] );

                FMW_Run_Step_Repository::mark_failure(
                    $step_record_id,
                    $duration_ms,
                    $wrapped->get_error_code(),
                    $wrapped->getMessage()
                );

                do_action( 'fmw_step_failed',
                    $context->get_run_id(), $step_name, $step_type, 'unexpected', $e->getMessage() );

                if ( $step->get_on_error() === 'continue' ) {
                    $context->set_step_output( $step_name, [ 'failed' => true, 'error' => 'unexpected' ] );
                    continue;
                }

                throw $wrapped;
            }

            // Step succeeded.
            if ( ! is_array( $output ) ) {
                $output = [ 'value' => $output ];
            }

            $duration_ms = (int) ( ( microtime( true ) - $started_at ) * 1000 );
            FMW_Run_Step_Repository::mark_success(
                $step_record_id,
                $duration_ms,
                wp_json_encode( $output )
            );

            // Allow filter to modify output before storing in context.
            $output = apply_filters( 'fmw_step_output', $output, $step, $context );

            $context->set_step_output( $step_name, $output );

            do_action( 'fmw_step_completed',
                $context->get_run_id(), $step_name, $step_type, $output );
        }
    }
}
