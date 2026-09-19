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
     * Seconds before retry 1, 2, 3… The last value repeats for later
     * attempts. Filterable per run with `fmw_retry_delay_seconds`.
     */
    const RETRY_DELAYS = [ 60, 300, 900 ];

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
        $executor   = null;

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

            // A retry resumes at the step that failed, with the context the
            // completed steps left, instead of re-running them (their emails,
            // records and quotes already happened).
            $workflow_hash = self::steps_hash( $workflow );
            $start_index   = self::resume_point( $run, $context, $workflow_hash );

            $executor = new FMW_Workflow_Executor();
            $executor->execute( $workflow, $context, [
                'start_index'  => $start_index,
                'on_step_done' => static function ( $next_index ) use ( $run_id, $context, $workflow_hash ) {
                    FMW_Run_Repository::save_checkpoint(
                        $run_id,
                        $next_index,
                        wp_json_encode( [
                            'context'       => $context->snapshot(),
                            'workflow_hash' => $workflow_hash,
                        ] )
                    );
                },
            ] );

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
            self::handle_failure( $run_id, $run, $e, $started_at, $executor ? $executor->failed_step() : null );
        } catch ( Throwable $e ) {
            // Throwable, not Exception: a PHP Error outside a step (context
            // building, a filter) used to escape here and leave the run
            // `running` for good.
            $wrapped = new FMW_Step_Exception( $e instanceof Error ? 'php_error' : 'unexpected', $e->getMessage() );
            self::handle_failure( $run_id, $run, $wrapped, $started_at, null );
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

        // Titles for {{ workflow.title }} / {{ form.title }} — documented in
        // ARCHITECTURE.md and SCHEDULED_WORKFLOWS.md, and always empty until
        // 2026-09-19 because nothing called these setters. Additive: a
        // workflow that never referenced them renders exactly as before.
        if ( class_exists( 'FMW_Workflow_Repository' ) ) {
            $workflow_record = FMW_Workflow_Repository::get( $run['workflow_id'] );
            if ( is_array( $workflow_record ) ) {
                $context->set_workflow_metadata( $workflow_record );
            }
        }
        // fre()->registry->get() is on the INTEGRATION_FRE.md allowlist.
        if ( ! empty( $run['form_id'] ) && function_exists( 'pforms' ) && pforms()->registry ) {
            $form_config = pforms()->registry->get( $run['form_id'] );
            if ( is_array( $form_config ) ) {
                $context->set_form_metadata( [
                    'id'    => $run['form_id'],
                    'title' => isset( $form_config['title'] ) ? (string) $form_config['title'] : '',
                ] );
            }
        }

        // Scheduled run sentinel: entry_id === 0 means "no entry".
        // Skip the FE fetch — there's nothing to load.
        if ( (int) $run['entry_id'] <= 0 ) {
            return $context;
        }

        if ( class_exists( 'PForms_Entry' ) ) {
            $entry_repo   = new PForms_Entry();
            $entry_record = $entry_repo->get( (int) $run['entry_id'] );
            if ( $entry_record ) {
                $context->set_entry( $entry_record );
                $context->set_data( $entry_record['fields'] ?? [] );
            }
        }

        return $context;
    }

    /**
     * Handle a failed run: schedule a retry, or fail it for good.
     *
     * A run is retried only when the step that failed says `on_error:
     * "retry"`, the error is retryable (a timeout, a 5xx — not a bad config
     * or a 4xx) and retries remain (`settings.max_retries`, default 3). That
     * is the contract ARCHITECTURE.md has always stated: `fail`, the
     * default, fails the run. Before 0.10.0 every retryable error was
     * "retried" by rethrowing into Action Scheduler, which never retries a
     * one-off action, so the run sat in `queued` forever with no alert.
     *
     * The retry is its own scheduled action, and it resumes at the failed
     * step (see resume_point()). If it cannot be scheduled, the run fails
     * for good so the alert still goes out.
     *
     * @param int                $run_id
     * @param array              $run
     * @param FMW_Step_Exception $e
     * @param float              $started_at
     * @param array|null         $failed_step FMW_Workflow_Executor::failed_step().
     */
    private static function handle_failure( $run_id, array $run, FMW_Step_Exception $e, $started_at, $failed_step ) {
        $duration_ms = (int) ( ( microtime( true ) - $started_at ) * 1000 );
        $retry_count = (int) $run['retry_count'];
        $max_retries = self::get_max_retries( $run['workflow_id'] );
        $on_error    = is_array( $failed_step ) ? $failed_step['on_error'] : 'fail';
        $step_name   = is_array( $failed_step ) ? $failed_step['name'] : null;
        $message     = $e->getMessage();

        if ( self::should_retry( $on_error, $e->is_retryable(), $retry_count, $max_retries ) ) {
            $attempt = $retry_count + 1;
            $delay   = self::retry_delay( $attempt, $run_id );
            $action  = function_exists( 'as_schedule_single_action' )
                ? as_schedule_single_action( time() + $delay, 'fmw_run_workflow', [ $run_id ], 'fmw' )
                : 0;

            if ( $action ) {
                FMW_Run_Repository::increment_retry_count( $run_id );
                FMW_Run_Repository::mark_waiting_retry( $run_id, $e->get_error_code(), $message, $step_name );

                do_action( 'fmw_workflow_run_retry_scheduled', $run_id, $run['workflow_id'], $attempt, $delay, $e->get_error_code() );

                FMW_Logger::warning( 'Workflow run failed — retry scheduled', [
                    'run_id'      => $run_id,
                    'error_code'  => $e->get_error_code(),
                    'step'        => $step_name,
                    'attempt'     => $attempt,
                    'max_retries' => $max_retries,
                    'delay'       => $delay,
                ] );
                return;
            }

            $message .= ' (A retry was due but could not be scheduled.)';
        }

        // Final failure. The checkpoint stays on the row: it shows how far
        // the run got.
        FMW_Run_Repository::mark_failed(
            $run_id,
            $e->get_error_code(),
            $message,
            $step_name,
            null
        );

        do_action( 'fmw_workflow_run_failed', $run_id, $run['workflow_id'], (int) $run['entry_id'], $e->get_error_code(), $message );

        FMW_Logger::error( 'Workflow run failed permanently', [
            'run_id'      => $run_id,
            'error_code'  => $e->get_error_code(),
            'message'     => $message,
            'step'        => $step_name,
            'retry_count' => $retry_count,
            'duration_ms' => $duration_ms,
        ] );
    }

    /**
     * Whether a failure is retried. Pure, so the rule is unit-tested.
     *
     * @param string $on_error    The failed step's on_error policy.
     * @param bool   $retryable   FMW_Step_Exception::is_retryable().
     * @param int    $retry_count Retries already used.
     * @param int    $max_retries settings.max_retries.
     * @return bool
     */
    public static function should_retry( $on_error, $retryable, $retry_count, $max_retries ) {
        return 'retry' === $on_error && $retryable && (int) $retry_count < (int) $max_retries;
    }

    /**
     * Seconds to wait before a given retry attempt (1-based).
     *
     * @param int $attempt
     * @param int $run_id
     * @return int
     */
    public static function retry_delay( $attempt, $run_id = 0 ) {
        $delays = self::RETRY_DELAYS;
        $delay  = $delays[ min( max( 1, (int) $attempt ), count( $delays ) ) - 1 ];

        /**
         * Filter the wait before a retry.
         *
         * @param int $delay   Seconds.
         * @param int $attempt Retry number, 1-based.
         * @param int $run_id  Run ID.
         */
        return max( 1, (int) apply_filters( 'fmw_retry_delay_seconds', $delay, (int) $attempt, (int) $run_id ) );
    }

    /**
     * Where this attempt starts, restoring the checkpointed context when it
     * resumes. A first attempt, or a retry of a run whose first step failed
     * (nothing to skip), starts at 0.
     *
     * @param array                $run
     * @param FMW_Workflow_Context $context
     * @param string               $workflow_hash
     * @return int
     * @throws FMW_Step_Exception When the workflow's steps changed since the checkpoint.
     */
    private static function resume_point( array $run, FMW_Workflow_Context $context, $workflow_hash ) {
        if ( (int) $run['retry_count'] < 1 || empty( $run['checkpoint'] ) || null === $run['resume_step_index'] ) {
            return 0;
        }

        $checkpoint = json_decode( (string) $run['checkpoint'], true );
        if ( ! is_array( $checkpoint ) || ! isset( $checkpoint['context'] ) ) {
            return 0;
        }

        // Step indexes only mean the same thing in the same workflow. If it
        // was edited while the retry waited, resuming could skip or repeat
        // the wrong steps — fail instead; the owner can replay the run.
        if ( ( $checkpoint['workflow_hash'] ?? '' ) !== $workflow_hash ) {
            throw new FMW_Step_Exception(
                'workflow_changed',
                'The workflow\'s steps were edited while this run waited to retry, so it cannot resume where it stopped. Replay the run to run the current version from the start.'
            );
        }

        $context->restore( $checkpoint['context'] );
        return (int) $run['resume_step_index'];
    }

    /**
     * Identity of a workflow's step list, for checkpoints.
     *
     * @param FMW_Workflow $workflow
     * @return string
     */
    private static function steps_hash( FMW_Workflow $workflow ) {
        return md5( (string) wp_json_encode( $workflow->steps() ) );
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
