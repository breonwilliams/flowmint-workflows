<?php
/**
 * Action Scheduler job handler.
 *
 * Registers the `fmw_run_workflow` hook that Action Scheduler dispatches.
 * Loads the workflow, builds the context, runs the executor, records run state.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Workflow_Job {

    /**
     * Register the Action Scheduler hook.
     *
     * Called once at plugin init.
     */
    public static function register() {
        add_action( 'fmw_run_workflow', [ __CLASS__, 'handle' ], 10, 1 );
    }

    /**
     * Action Scheduler invokes this.
     *
     * @param int $run_id The run ID to execute.
     */
    public static function handle( $run_id ) {
        $run_id = (int) $run_id;
        if ( $run_id <= 0 ) {
            FMW_Logger::error( 'Invalid run_id passed to job handler', [ 'run_id' => $run_id ] );
            return;
        }

        $run = FMW_Run_Repository::get( $run_id );
        if ( ! $run ) {
            FMW_Logger::error( 'Run not found', [ 'run_id' => $run_id ] );
            return;
        }

        // If the run is already completed (e.g., a duplicate retry from AS), skip.
        if ( in_array( $run['status'], [ 'completed', 'failed', 'cancelled' ], true ) ) {
            FMW_Logger::info( 'Skipping already-finalized run', [
                'run_id' => $run_id,
                'status' => $run['status'],
            ] );
            return;
        }

        // Mark running.
        FMW_Run_Repository::mark_running( $run_id );

        do_action( 'fmw_workflow_run_started', $run_id, $run['workflow_id'], (int) $run['entry_id'] );

        $started_at = microtime( true );

        try {
            $context = self::build_context( $run );
            $workflow = fmw()->registry->get( $run['workflow_id'] );

            if ( ! $workflow ) {
                throw new FMW_Step_Exception(
                    'workflow_not_found',
                    "Workflow {$run['workflow_id']} no longer exists. Cannot execute run {$run_id}."
                );
            }

            // Allow filter to veto the run.
            if ( ! apply_filters( 'fmw_should_run_workflow', true, $workflow->id(), (int) $run['entry_id'] ) ) {
                FMW_Run_Repository::mark_completed( $run_id, 0, wp_json_encode( [ 'vetoed' => true ] ) );
                return;
            }

            $executor = new FMW_Workflow_Executor();
            $executor->execute( $workflow, $context );

            // Success.
            $duration_ms = (int) ( ( microtime( true ) - $started_at ) * 1000 );
            FMW_Run_Repository::mark_completed(
                $run_id,
                $duration_ms,
                wp_json_encode( $context->snapshot() )
            );

            do_action( 'fmw_workflow_run_completed', $run_id, $run['workflow_id'], (int) $run['entry_id'], $context );

            FMW_Logger::info( 'Workflow run completed', [
                'run_id'      => $run_id,
                'workflow_id' => $run['workflow_id'],
                'duration_ms' => $duration_ms,
            ] );
        } catch ( FMW_Step_Exception $e ) {
            self::handle_failure( $run_id, $run, $e, $started_at );
        } catch ( Exception $e ) {
            $wrapped = new FMW_Step_Exception( 'unexpected', $e->getMessage() );
            self::handle_failure( $run_id, $run, $wrapped, $started_at );
        }
    }

    /**
     * Build the workflow context for a run.
     *
     * For form-triggered runs, loads the FormEngine entry and populates
     * $context->data / $context->entry / $context->entry_files.
     *
     * For scheduled runs (entry_id === 0, per
     * docs/DESIGN_SCHEDULED_TRIGGERS.md §5.2), skips the FE entry fetch
     * entirely. The context's entry/data/entry_files stay empty arrays;
     * the interpolator already handles missing variables by returning
     * empty string, so existing step implementations that touch
     * `{{ data.* }}` keep working — they just produce empty output.
     * The validator emits a warning when scheduled workflows reference
     * data/entry vars (see FMW_Workflow_Validator::check_for_entry_refs).
     *
     * @param array $run DB row from wp_fmw_workflow_runs
     * @return FMW_Workflow_Context
     */
    private static function build_context( array $run ) {
        $context = new FMW_Workflow_Context(
            (int) $run['id'],
            (int) $run['entry_id'],
            $run['workflow_id'],
            $run['form_id']
        );

        // Scheduled run sentinel: entry_id === 0 means "no entry".
        // Skip the FE fetch — there's nothing to load.
        if ( (int) $run['entry_id'] <= 0 ) {
            return $context;
        }

        if ( class_exists( 'FRE_Entry' ) ) {
            $entry_repo   = new FRE_Entry();
            $entry_record = $entry_repo->get( (int) $run['entry_id'] );
            if ( $entry_record ) {
                $context->set_entry( $entry_record );
                $context->set_data( $entry_record['fields'] ?? [] );
            }
        }

        return $context;
    }

    /**
     * Handle a failed run.
     *
     * Decides whether to mark final failure or let Action Scheduler retry.
     * Action Scheduler's retry mechanism: throwing an exception from this
     * handler causes AS to reschedule with backoff (up to its configured limit).
     *
     * For non-retryable errors, we mark final failure and DON'T throw —
     * AS treats the action as "completed" and moves on.
     *
     * @param int               $run_id
     * @param array             $run
     * @param FMW_Step_Exception $e
     * @param float             $started_at
     * @throws FMW_Step_Exception For retryable errors (re-thrown so AS retries)
     */
    private static function handle_failure( $run_id, array $run, FMW_Step_Exception $e, $started_at ) {
        $duration_ms = (int) ( ( microtime( true ) - $started_at ) * 1000 );
        $retry_count = (int) $run['retry_count'];
        $max_retries = self::get_max_retries( $run['workflow_id'] );

        $should_retry = $e->is_retryable() && $retry_count < $max_retries;

        if ( $should_retry ) {
            FMW_Run_Repository::increment_retry_count( $run_id );
            FMW_Logger::warning( 'Workflow run failed — will retry', [
                'run_id'      => $run_id,
                'error_code'  => $e->get_error_code(),
                'retry_count' => $retry_count + 1,
                'max_retries' => $max_retries,
            ] );

            // Reset status to queued so AS re-runs it.
            FMW_Run_Repository::update_status( $run_id, 'queued' );

            // Re-throw so Action Scheduler retries with backoff.
            throw $e;
        }

        // Final failure.
        FMW_Run_Repository::mark_failed(
            $run_id,
            $e->get_error_code(),
            $e->getMessage(),
            null, // failed_step is recorded at the step level, not here
            null  // context_snapshot — we don't have it accessible here
        );

        do_action( 'fmw_workflow_run_failed', $run_id, $run['workflow_id'], (int) $run['entry_id'], $e->get_error_code(), $e->getMessage() );

        FMW_Logger::error( 'Workflow run failed permanently', [
            'run_id'      => $run_id,
            'error_code'  => $e->get_error_code(),
            'message'     => $e->getMessage(),
            'retry_count' => $retry_count,
        ] );
    }

    /**
     * Get the max_retries setting for a workflow (or default).
     *
     * @param string $workflow_id
     * @return int
     */
    private static function get_max_retries( $workflow_id ) {
        $workflow = fmw()->registry->get( $workflow_id );
        if ( $workflow ) {
            $settings = $workflow->settings();
            if ( isset( $settings['max_retries'] ) ) {
                return max( 0, (int) $settings['max_retries'] );
            }
        }
        return 3; // Default.
    }
}
