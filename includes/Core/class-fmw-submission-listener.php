<?php
/**
 * Submission listener.
 *
 * Listens to FormEngine's `fre_submission_complete` action. When fired,
 * checks if a workflow is registered for the form_id, and if so, enqueues
 * an Action Scheduler async job to run the workflow.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Submission_Listener {

    /**
     * Register the listener.
     */
    public function init() {
        // Late priority so any other fre_submission_complete listeners that
        // modify entry data have already run.
        add_action( 'pforms_submission_complete', [ $this, 'on_submission_complete' ], 100, 3 );
    }

    /**
     * Handle a FormEngine submission.
     *
     * @param int    $entry_id
     * @param string $form_id
     * @param array  $sanitized_data
     */
    public function on_submission_complete( $entry_id, $form_id, $sanitized_data ) {
        // Find a workflow for this form.
        $workflow = fmw()->registry->get_for_form( $form_id );
        if ( ! $workflow ) {
            // No workflow registered — silently no-op.
            return;
        }

        if ( ! $workflow->is_enabled() ) {
            FMW_Logger::debug( 'Workflow exists but is disabled', [
                'form_id'     => $form_id,
                'workflow_id' => $workflow->id(),
                'entry_id'    => $entry_id,
            ] );
            return;
        }

        // Verify Action Scheduler is available.
        if ( ! function_exists( 'as_enqueue_async_action' ) ) {
            FMW_Logger::error( 'Cannot enqueue workflow — Action Scheduler not loaded', [
                'workflow_id' => $workflow->id(),
                'entry_id'    => $entry_id,
            ] );
            return;
        }

        // Create the run record in queued state.
        $run_id = FMW_Run_Repository::create_pending(
            $workflow->id(),
            $form_id,
            (int) $entry_id
        );

        if ( is_wp_error( $run_id ) ) {
            FMW_Logger::error( 'Failed to create run record', [
                'workflow_id' => $workflow->id(),
                'entry_id'    => $entry_id,
                'error'       => $run_id->get_error_message(),
            ] );
            return;
        }

        // Enqueue. Audit fix (item I1): Action Scheduler returns the
        // action ID on success or 0 on failure. We must check —
        // otherwise an enqueue failure leaves the run row stuck in
        // 'queued' state forever with no scheduled job to pick it up,
        // which presents in the admin UI as a confusing orphan that
        // looks superficially identical to a queued-but-not-yet-
        // processed run.
        $action_id = as_enqueue_async_action(
            'fmw_run_workflow',
            [ $run_id ],
            'fmw' // group
        );

        if ( ! $action_id ) {
            FMW_Run_Repository::mark_failed(
                $run_id,
                'enqueue_failed',
                'Action Scheduler returned 0 from as_enqueue_async_action — the workflow could not be enqueued for async execution. This is rare; check the Action Scheduler log for context.'
            );

            FMW_Logger::error( 'Workflow enqueue failed', [
                'run_id'      => $run_id,
                'workflow_id' => $workflow->id(),
                'form_id'     => $form_id,
                'entry_id'    => $entry_id,
            ] );

            return;
        }

        FMW_Logger::info( 'Workflow run enqueued', [
            'run_id'      => $run_id,
            'workflow_id' => $workflow->id(),
            'form_id'     => $form_id,
            'entry_id'    => $entry_id,
            'action_id'   => $action_id,
        ] );
    }
}
