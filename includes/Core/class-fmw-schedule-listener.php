<?php
/**
 * Schedule listener.
 *
 * The schedule-triggered counterpart to FMW_Submission_Listener.
 *
 * Per docs/DESIGN_SCHEDULED_TRIGGERS.md §5.3, this class has three
 * lifecycle responsibilities:
 *
 *   (a) Cron event registration — on plugin init (via the daily
 *       reconciliation hook), ensure every enabled scheduled
 *       workflow has an Action Scheduler recurring event.
 *
 *   (b) Workflow save / disable / delete hooks — re-register or
 *       unregister AS events in real time as workflow state changes.
 *
 *   (c) Tick handler — on each cron fire, create a queued run row
 *       and enqueue an async job. Downstream the executor handles
 *       it identically to a form-triggered run (same job class,
 *       same retry policy, same logging).
 *
 * @package FlowMintWorkflows
 * @since   0.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Schedule_Listener {

    /**
     * Action Scheduler hook fired by each per-workflow recurring event.
     */
    const TICK_HOOK = 'fmw_scheduled_workflow_tick';

    /**
     * Action Scheduler hook for the daily reconciliation pass.
     *
     * Drift insurance: even if a save/disable/delete hook were missed
     * (e.g., a direct DB write bypassing FMW_Workflow_Repository), the
     * daily reconciliation pass brings AS state back into sync with
     * the workflows table.
     */
    const RECONCILE_HOOK = 'fmw_reconcile_scheduled_events';

    /**
     * Action Scheduler group. Shared with the submission listener so
     * everything FMW-related is one bucket in the AS admin UI and the
     * housekeeping job.
     */
    const ACTION_GROUP = 'fmw';

    /**
     * Register the listener.
     */
    public function init() {
        add_action( 'fmw_workflow_saved', [ $this, 'on_workflow_saved' ], 10, 1 );
        add_action( 'fmw_workflow_disabled', [ $this, 'on_workflow_disabled' ], 10, 1 );
        add_action( 'fmw_workflow_deleted', [ $this, 'on_workflow_deleted' ], 10, 2 );

        // Action Scheduler tick — fired by each per-workflow recurring event.
        add_action( self::TICK_HOOK, [ $this, 'on_scheduled_tick' ], 10, 1 );

        // Daily reconciliation — drift correction.
        add_action( self::RECONCILE_HOOK, [ $this, 'ensure_recurring_events_registered' ], 10, 0 );
    }

    // -----------------------------------------------------------------
    // Lifecycle handlers
    // -----------------------------------------------------------------

    /**
     * Workflow created or updated. Reconcile its AS event.
     *
     * Unschedule first regardless of trigger_type — handles the case
     * where a workflow was previously schedule-triggered and is being
     * changed to form-triggered. Then if it's now schedule + enabled,
     * register a fresh recurring event.
     *
     * @param FMW_Workflow $workflow
     */
    public function on_workflow_saved( $workflow ) {
        if ( ! ( $workflow instanceof FMW_Workflow ) ) {
            return;
        }

        $this->unschedule_for_workflow( $workflow->id() );

        if ( $workflow->trigger_type() === 'schedule' && $workflow->is_enabled() ) {
            $this->schedule_for_workflow( $workflow );
        }
    }

    /**
     * Workflow disabled (enabled 1 → 0 transition). Drop its cron events.
     *
     * @param string $workflow_id
     */
    public function on_workflow_disabled( $workflow_id ) {
        $this->unschedule_for_workflow( $workflow_id );
    }

    /**
     * Workflow deleted. Drop its cron events.
     *
     * @param string $workflow_id
     * @param array  $row Snapshot of the deleted row (unused).
     */
    public function on_workflow_deleted( $workflow_id, $row = [] ) {
        $this->unschedule_for_workflow( $workflow_id );
    }

    /**
     * Reconcile AS recurring events with the DB.
     *
     * Iterates every workflow with trigger_type='schedule', unschedules
     * any existing recurring event for it, then re-schedules if the
     * workflow is enabled. This guarantees that:
     *
     *   - Enabled scheduled workflows have exactly one recurring event.
     *   - Disabled scheduled workflows have none.
     *   - Workflows whose interval changed get the new interval applied.
     *
     * Called daily via the RECONCILE_HOOK Action Scheduler action.
     * Also called once at v0.6.0 bootstrap (see
     * FlowMint_Workflows::maybe_schedule_reconciliation) so existing
     * scheduled workflows pre-dating Phase 2's listener catch up.
     */
    public function ensure_recurring_events_registered() {
        if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
            FMW_Logger::warning( 'Cannot reconcile scheduled events — Action Scheduler not available' );
            return;
        }

        $rows = FMW_Workflow_Repository::get_all_by_trigger_type( 'schedule' );

        $reviewed     = 0;
        $unscheduled  = 0;
        $rescheduled  = 0;

        foreach ( $rows as $row ) {
            $workflow = new FMW_Workflow( $row );

            $unscheduled += $this->unschedule_for_workflow( $workflow->id() );

            if ( $workflow->is_enabled() ) {
                $this->schedule_for_workflow( $workflow );
                $rescheduled++;
            }

            $reviewed++;
        }

        FMW_Logger::info( 'Reconciled scheduled workflow events', [
            'workflows_reviewed'      => $reviewed,
            'recurring_events_dropped' => $unscheduled,
            'recurring_events_added'   => $rescheduled,
        ] );
    }

    // -----------------------------------------------------------------
    // Tick handler
    // -----------------------------------------------------------------

    /**
     * Handle a scheduled cron tick.
     *
     * Validates that the workflow still exists, is enabled, and is
     * still schedule-triggered. Creates a queued run via
     * FMW_Run_Repository::create_pending_scheduled, then enqueues
     * the same `fmw_run_workflow` async action that form submissions
     * use. Downstream the workflow job builds a synthetic context
     * (no FE entry) and the executor runs the steps.
     *
     * Audit-fix-I1 pattern (matches FMW_Submission_Listener): if
     * as_enqueue_async_action returns 0, we mark the run failed so
     * it doesn't sit forever in 'queued' state with no scheduled job
     * to pick it up.
     *
     * @param string $workflow_id
     */
    public function on_scheduled_tick( $workflow_id ) {
        $row = FMW_Workflow_Repository::get( $workflow_id );

        if ( ! $row ) {
            // Workflow was deleted between when this tick was scheduled
            // and when AS fired it. Unschedule remaining ticks (defense
            // in depth — on_workflow_deleted should have done this).
            $this->unschedule_for_workflow( $workflow_id );
            FMW_Logger::info( 'Scheduled tick for unknown workflow — unscheduled', [
                'workflow_id' => $workflow_id,
            ] );
            return;
        }

        $workflow = new FMW_Workflow( $row );

        if ( ! $workflow->is_enabled() || $workflow->trigger_type() !== 'schedule' ) {
            // Workflow was disabled or re-typed to form-triggered.
            // Skip the run and unschedule remaining ticks.
            $this->unschedule_for_workflow( $workflow_id );
            FMW_Logger::info( 'Scheduled tick for ineligible workflow — unscheduled', [
                'workflow_id'  => $workflow_id,
                'enabled'      => $workflow->is_enabled(),
                'trigger_type' => $workflow->trigger_type(),
            ] );
            return;
        }

        if ( ! function_exists( 'as_enqueue_async_action' ) ) {
            FMW_Logger::error( 'Cannot dispatch scheduled run — Action Scheduler not loaded', [
                'workflow_id' => $workflow_id,
            ] );
            return;
        }

        $run_id = FMW_Run_Repository::create_pending_scheduled( $workflow_id );

        if ( is_wp_error( $run_id ) ) {
            FMW_Logger::error( 'Failed to create scheduled run record', [
                'workflow_id' => $workflow_id,
                'error'       => $run_id->get_error_message(),
            ] );
            return;
        }

        $action_id = as_enqueue_async_action(
            'fmw_run_workflow',
            [ $run_id ],
            self::ACTION_GROUP
        );

        if ( ! $action_id ) {
            FMW_Run_Repository::mark_failed(
                $run_id,
                'enqueue_failed',
                'Action Scheduler returned 0 from as_enqueue_async_action — the scheduled tick could not be enqueued for async execution. Check the Action Scheduler log for context.'
            );
            FMW_Logger::error( 'Scheduled workflow enqueue failed', [
                'run_id'      => $run_id,
                'workflow_id' => $workflow_id,
            ] );
            return;
        }

        FMW_Logger::info( 'Scheduled workflow run enqueued', [
            'run_id'      => $run_id,
            'workflow_id' => $workflow_id,
            'action_id'   => $action_id,
        ] );
    }

    // -----------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------

    /**
     * Register a recurring AS action for a workflow.
     *
     * Idempotent — if an action with the same hook+args+group already
     * exists, returns without scheduling a duplicate. Combined with
     * unschedule_for_workflow(), the typical flow is "unschedule, then
     * schedule" so interval/timing changes always propagate.
     *
     * @param FMW_Workflow $workflow Must be schedule-triggered + enabled.
     */
    private function schedule_for_workflow( FMW_Workflow $workflow ) {
        if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
            return;
        }

        $hook  = self::TICK_HOOK;
        $args  = [ $workflow->id() ];
        $group = self::ACTION_GROUP;

        if ( as_has_scheduled_action( $hook, $args, $group ) ) {
            return;
        }

        $first    = $this->compute_next_run_timestamp( $workflow );
        $interval = $this->compute_interval_seconds( $workflow );

        $action_id = as_schedule_recurring_action( $first, $interval, $hook, $args, $group );

        FMW_Logger::info( 'Scheduled recurring workflow event', [
            'workflow_id'     => $workflow->id(),
            'interval'        => $workflow->schedule_interval(),
            'first_run_at'    => gmdate( 'Y-m-d H:i:s', $first ) . ' UTC',
            'interval_seconds' => $interval,
            'action_id'       => $action_id,
        ] );
    }

    /**
     * Unschedule every recurring + pending AS action for a workflow.
     *
     * Uses `as_unschedule_all_actions` (not `as_unschedule_action`)
     * to clear any duplicates that might have accumulated across
     * lifecycle events.
     *
     * @param string $workflow_id
     * @return int Number of actions that were pending before the unschedule.
     */
    private function unschedule_for_workflow( $workflow_id ) {
        if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
            return 0;
        }

        $hook  = self::TICK_HOOK;
        $args  = [ $workflow_id ];
        $group = self::ACTION_GROUP;

        $count = 0;
        if ( function_exists( 'as_get_scheduled_actions' ) ) {
            $existing = as_get_scheduled_actions( [
                'hook'     => $hook,
                'args'     => $args,
                'group'    => $group,
                'status'   => 'pending',
                'per_page' => 50,
            ] );
            $count = is_array( $existing ) ? count( $existing ) : 0;
        }

        as_unschedule_all_actions( $hook, $args, $group );

        if ( $count > 0 ) {
            FMW_Logger::info( 'Unscheduled recurring workflow events', [
                'workflow_id' => $workflow_id,
                'count'       => $count,
            ] );
        }

        return $count;
    }

    /**
     * Compute the first-run timestamp for a workflow's recurring event.
     *
     * Site timezone semantics — uses `wp_timezone()` for daily/weekly
     * intervals so the configured hour/minute means "site-local",
     * matching what an admin would expect from `Settings → General`.
     *
     * For hourly/twicedaily we just project forward from now; the
     * exact minute of the hour drifts but the interval is stable.
     *
     * @param FMW_Workflow $workflow
     * @return int Unix timestamp (UTC seconds) for the first tick.
     */
    private function compute_next_run_timestamp( FMW_Workflow $workflow ) {
        $interval = $workflow->schedule_interval();
        $hour     = $workflow->schedule_hour();
        $minute   = $workflow->schedule_minute();
        $dow      = $workflow->schedule_day_of_week();

        $tz  = wp_timezone();
        $now = new DateTime( 'now', $tz );

        switch ( $interval ) {
            case 'hourly':
                // First run an hour from now. AS won't accept a past
                // timestamp; the hour buffer is also a courtesy so the
                // user has time to disable a misconfigured workflow
                // before it fires.
                return $now->getTimestamp() + HOUR_IN_SECONDS;

            case 'twicedaily':
                return $now->getTimestamp() + 12 * HOUR_IN_SECONDS;

            case 'daily':
                $next = ( clone $now )->setTime( $hour, $minute, 0 );
                if ( $next <= $now ) {
                    $next->modify( '+1 day' );
                }
                return $next->getTimestamp();

            case 'weekly':
                $next        = ( clone $now )->setTime( $hour, $minute, 0 );
                $current_dow = (int) $next->format( 'N' ); // ISO-8601: 1=Mon … 7=Sun
                $days_until  = ( $dow - $current_dow + 7 ) % 7;
                if ( $days_until === 0 && $next <= $now ) {
                    $days_until = 7;
                }
                if ( $days_until > 0 ) {
                    $next->modify( "+{$days_until} day" );
                }
                return $next->getTimestamp();

            default:
                // Shouldn't reach here — validator rejects unknown
                // intervals at save time. Conservative fallback to
                // 1 hour from now so the workflow at least eventually
                // tries to run instead of going into a tight loop.
                return $now->getTimestamp() + HOUR_IN_SECONDS;
        }
    }

    /**
     * Interval (in seconds) for a workflow's recurring AS action.
     *
     * @param FMW_Workflow $workflow
     * @return int
     */
    private function compute_interval_seconds( FMW_Workflow $workflow ) {
        switch ( $workflow->schedule_interval() ) {
            case 'hourly':
                return HOUR_IN_SECONDS;
            case 'twicedaily':
                return 12 * HOUR_IN_SECONDS;
            case 'daily':
                return DAY_IN_SECONDS;
            case 'weekly':
                return WEEK_IN_SECONDS;
            default:
                return HOUR_IN_SECONDS;
        }
    }
}
