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
     * The step whose failure ended the last execute() call: index, name,
     * type and its on_error policy. Null when execute() returned normally.
     * The job reads it to decide retry vs fail (only `on_error: retry`
     * retries) and to resume at this index.
     *
     * @var array|null
     */
    private $failed_step = null;

    /**
     * Execute a workflow against a context.
     *
     * Options (top-level runs only — control-flow steps call execute() for
     * their nested lists without options, so nested steps never checkpoint
     * or resume on their own):
     *   - start_index  (int)      Skip the steps before this index; a retry
     *                             resumes at the step that failed.
     *   - on_step_done (callable) Called as fn( int $next_index ) after each
     *                             step finishes (success, skip, or
     *                             on_error:continue), so the caller can
     *                             checkpoint the context.
     *
     * @param FMW_Workflow         $workflow
     * @param FMW_Workflow_Context $context
     * @param array                $options
     * @throws FMW_Step_Exception On step failure that should fail the run.
     */
    public function execute( FMW_Workflow $workflow, FMW_Workflow_Context $context, array $options = [] ) {
        $steps       = $workflow->steps();
        $registry    = FMW_Step_Registry::instance();
        $interp      = new FMW_Interpolator( $context );
        $expr        = new FMW_Expression( $interp );
        $start_index = isset( $options['start_index'] ) ? max( 0, (int) $options['start_index'] ) : 0;
        $on_done     = isset( $options['on_step_done'] ) && is_callable( $options['on_step_done'] ) ? $options['on_step_done'] : null;

        $this->failed_step = null;

        foreach ( $steps as $idx => $step_def ) {
            if ( $idx < $start_index ) {
                continue;
            }

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
                    if ( $on_done ) {
                        $on_done( $idx + 1 );
                    }
                    continue;
                }
            }

            // Verify type is registered.
            $class = $registry->get_class( $step_type );
            if ( ! $class ) {
                throw new FMW_Step_Exception(
                    'config_error',
                    sprintf(
                        "Step '%s' references unknown type '%s'. Workflow JSON is invalid.",
                        esc_html( $step_name ),
                        esc_html( $step_type )
                    )
                );
            }

            // Interpolate the step's config.
            $raw_config        = $step_def['config'] ?? [];
            $interpolated_config = $interp->interpolate( $raw_config );

            // Build the step instance. Pass BOTH the interpolated config (for
            // most step types — they want the resolved values) AND the raw
            // config (for control-flow steps whose config contains
            // expression-typed fields or nested step lists that must be
            // interpolated per-iteration). Conditional reads raw_config['if']
            // so the comparison operators survive into the expression
            // evaluator instead of being pre-resolved (and mangled) by the
            // string interpolator. Added in v0.6.4 after pressure testing
            // proved the previous "Workaround" comment in the conditional
            // step was hiding a real bug — every conditional was taking the
            // else branch regardless of the if value, because the if string
            // was empty/garbage by the time the step received it.
            $step_def_with_interpolated_config = array_merge( $step_def, [
                'config'     => $interpolated_config,
                'raw_config' => $raw_config,
            ] );

            /** @var FMW_Step_Base $step */
            $step = new $class( $step_def_with_interpolated_config );

            // Record step start.
            $step_record_id = FMW_Run_Step_Repository::create_pending(
                $context->get_run_id(),
                $idx,
                $step_name,
                $step_type,
                wp_json_encode( self::config_snapshot( $class, $raw_config, $interpolated_config ) )
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
                    if ( $on_done ) {
                        $on_done( $idx + 1 );
                    }
                    continue;
                }

                // 'fail' or 'retry': the job decides, from failed_step().
                $this->failed_step = self::failure_record( $idx, $step_name, $step_type, $policy );
                throw $e;
            } catch ( Throwable $e ) {
                // Anything else a step throws. An Exception is 'unexpected'
                // (retryable — a library's transport error, say); a PHP
                // Error (TypeError, ValueError…) is a bug in the step and
                // 'php_error', which no retry can fix. Before 0.10.0 Errors
                // were not caught here at all: the step record stayed
                // `pending` and the run stayed `running` for good.
                $duration_ms = (int) ( ( microtime( true ) - $started_at ) * 1000 );
                $code        = $e instanceof Error ? 'php_error' : 'unexpected';
                $wrapped     = new FMW_Step_Exception( $code, $e->getMessage(), [ 'previous' => get_class( $e ) ] );

                FMW_Run_Step_Repository::mark_failure(
                    $step_record_id,
                    $duration_ms,
                    $wrapped->get_error_code(),
                    $wrapped->getMessage()
                );

                do_action( 'fmw_step_failed',
                    $context->get_run_id(), $step_name, $step_type, $code, $e->getMessage() );

                if ( $step->get_on_error() === 'continue' ) {
                    $context->set_step_output( $step_name, [ 'failed' => true, 'error' => $code ] );
                    if ( $on_done ) {
                        $on_done( $idx + 1 );
                    }
                    continue;
                }

                $this->failed_step = self::failure_record( $idx, $step_name, $step_type, $step->get_on_error() );
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

            if ( $on_done ) {
                $on_done( $idx + 1 );
            }
        }
    }

    /**
     * The step whose failure ended the last execute(), or null.
     *
     * @return array|null { index, name, type, on_error }
     */
    public function failed_step() {
        return $this->failed_step;
    }

    /**
     * @param int    $idx
     * @param string $name
     * @param string $type
     * @param string $on_error
     * @return array
     */
    private static function failure_record( $idx, $name, $type, $on_error ) {
        return [
            'index'    => (int) $idx,
            'name'     => (string) $name,
            'type'     => (string) $type,
            'on_error' => (string) $on_error,
        ];
    }

    /**
     * What the run history records as a step's config: the interpolated
     * values — so a person sees the actual recipient, subject and URL a run
     * used — except for the keys the step evaluates itself, which are kept
     * as written (an expression, nested step lists, a per-record template).
     *
     * @param string $class               Step class.
     * @param array  $raw_config          Config as written in the workflow.
     * @param array  $interpolated_config Config after interpolation.
     * @return array
     */
    public static function config_snapshot( $class, array $raw_config, array $interpolated_config ): array {
        $snapshot = $interpolated_config;
        $keys     = is_callable( [ $class, 'raw_config_keys' ] ) ? $class::raw_config_keys() : [];
        foreach ( $keys as $key ) {
            if ( array_key_exists( $key, $raw_config ) ) {
                $snapshot[ $key ] = $raw_config[ $key ];
            }
        }
        return $snapshot;
    }
}
