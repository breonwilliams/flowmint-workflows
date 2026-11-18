<?php
/**
 * Keeps FlowMint's two maintenance actions scheduled — exactly one of each.
 *
 * FlowMint owns two recurring Action Scheduler actions that nothing else
 * schedules for it:
 *
 *   - fmw_reconcile_scheduled_events (daily) rebuilds every scheduled
 *     workflow's tick from the workflows table;
 *   - fmw_sweep_interrupted_runs (hourly) recovers runs the server killed
 *     part-way.
 *
 * Until 0.12.3 each was checked with as_has_scheduled_action() on EVERY
 * request (front end included): two queries per page view that no object
 * cache can absorb, because Action Scheduler queries its tables directly.
 * The check-then-schedule on every request also created duplicates, in two
 * ways reproduced on a local site:
 *
 *   1. After both actions vanish at once (deactivate/reactivate, an Action
 *      Scheduler table reset, a restore), several requests arriving
 *      together each see "not scheduled" and each schedule one.
 *   2. Action Scheduler marks a recurring action complete and only then
 *      schedules its next instance. A request landing in between sees
 *      neither and schedules another.
 *
 * Duplicates never went away: a recurring action reschedules itself
 * forever. (Per-workflow ticks did not accumulate, because the daily
 * reconcile unschedules each tick before scheduling it again.)
 *
 * Now the check runs where scheduled work happens, not on page views:
 *
 *   - at the start of every Action Scheduler queue run (WP-Cron, a system
 *     cron calling wp-cron.php, WP-CLI, the async runner). A missing action
 *     could only run during a queue run anyway, so nothing is lost by
 *     checking there, and the check happens before the run claims anything.
 *     Action Scheduler 4.1 made the same move for its own recurring check;
 *   - when one of FlowMint's own admin screens loads;
 *   - once after an install, an update or a reactivation (an autoloaded
 *     version stamp, so steady-state page views make no query). That check
 *     also rebuilds workflow ticks once, since older versions could leave a
 *     site with its reconcile in place and its ticks gone;
 *   - on every WP-CLI load, because `wp action-scheduler run` fires the
 *     queue-start hook only when something is due.
 *
 * One process checks at a time (a MySQL named lock), so the requests that
 * arrive together after a reactivation cannot each schedule a copy.
 *
 * Each check also repairs: more than one pending copy is cut back to the
 * one due soonest, so duplicates from any cause — including sites upgraded
 * from versions that created them — collapse at the next check, long before
 * the extra copy would be due. Only these two hooks are ever touched;
 * per-workflow ticks are the reconcile's business.
 *
 * @package FlowMintWorkflows
 * @since   0.12.3
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Schedule_Maintenance {

    /**
     * Action Scheduler group shared by everything FlowMint schedules.
     */
    const GROUP = 'fmw';

    /**
     * Autoloaded option holding the FlowMint version whose post-update
     * check has run. Deleted on activation so a reactivated site checks on
     * its next request.
     */
    const CHECKED_VERSION_OPTION = 'fmw_maintenance_checked_version';

    /**
     * Seconds after a maintenance action last started during which Action
     * Scheduler's own runner may still be scheduling its next instance.
     * Inside this window a missing action is left alone; the next check
     * after it schedules one if the runner did not.
     */
    const RESCHEDULE_GRACE = 600;

    /**
     * Most pending copies listed per check. Any beyond this are collapsed
     * by the next check.
     */
    const MAX_LISTED = 100;

    /**
     * Hook the checks in. Called from FMW_Workflow_Job::register().
     */
    public static function register() {
        add_action( 'action_scheduler_before_process_queue', [ __CLASS__, 'ensure' ], 10, 0 );
        add_action( 'action_scheduler_init', [ __CLASS__, 'maybe_ensure_after_update' ], 10, 0 );
        add_action( 'current_screen', [ __CLASS__, 'on_current_screen' ], 10, 1 );
    }

    /**
     * The maintenance actions and their intervals in seconds.
     *
     * @return array<string,int>
     */
    public static function anchors() {
        return [
            FMW_Schedule_Listener::RECONCILE_HOOK => DAY_IN_SECONDS,
            FMW_Workflow_Job::SWEEP_HOOK          => HOUR_IN_SECONDS,
        ];
    }

    /**
     * Run the check once per FlowMint version: after a fresh install, an
     * update, or a reactivation (activation deletes the stamp). Costs no
     * query when the stamp is current — the option is autoloaded.
     *
     * Under WP-CLI it runs on every load. `wp action-scheduler run` fires
     * action_scheduler_before_process_queue only when it has claimed due
     * actions, so on a host whose only queue runner is that command, a site
     * with nothing due would otherwise never be checked. A CLI process is
     * never a page view.
     */
    public static function maybe_ensure_after_update() {
        $cli = defined( 'WP_CLI' ) && WP_CLI;
        if ( ! $cli && FMW_VERSION === get_option( self::CHECKED_VERSION_OPTION ) ) {
            return;
        }
        if ( ! self::scheduler_ready() ) {
            return;
        }

        if ( ! self::ensure() ) {
            return;
        }

        if ( FMW_VERSION !== get_option( self::CHECKED_VERSION_OPTION ) ) {
            // Once per version, rebuild workflow ticks whatever the check
            // found. Versions before 0.12.3 lost every tick on a
            // reactivation and waited for the daily reconcile, so a site can
            // arrive here with its reconcile in place and its ticks gone.
            // Idempotent, and unique in Action Scheduler.
            as_enqueue_async_action( FMW_Schedule_Listener::RECONCILE_NOW_HOOK, [], self::GROUP, true );
            update_option( self::CHECKED_VERSION_OPTION, FMW_VERSION, true );
        }
    }

    /**
     * Run the check when one of FlowMint's own admin screens loads.
     *
     * @param WP_Screen|object|null $screen Current screen.
     */
    public static function on_current_screen( $screen ) {
        if ( ! is_object( $screen ) || empty( $screen->id ) ) {
            return;
        }
        $id = (string) $screen->id;
        if ( false === strpos( $id, 'fmw-' ) && false === strpos( $id, 'flowmint' ) ) {
            return;
        }

        self::ensure();
    }

    /**
     * Make sure each maintenance action is scheduled exactly once.
     *
     * When the daily reconcile had to be scheduled again, scheduled
     * workflows may have lost their ticks too (whatever removed one usually
     * removed both), so a one-off reconcile is queued to run now through
     * Action Scheduler rather than waiting a day. During a queue run it is
     * due immediately and runs in that same run.
     *
     * Only one process checks at a time (a MySQL named lock, not waited
     * for): the first requests after a reactivation arrive together, and
     * each would otherwise schedule its own copy. Where the lock is not
     * available the check runs anyway; `unique` and the collapse still
     * apply.
     *
     * @return bool True when the check ran; false when Action Scheduler was
     *              not ready or another process was checking.
     */
    public static function ensure() {
        if ( ! self::scheduler_ready() ) {
            return false;
        }
        if ( ! self::acquire_lock() ) {
            return false;
        }

        // This runs inside action_scheduler_before_process_queue: an
        // exception escaping here would abort the queue run for every plugin
        // on the site, and again on the next run. A store error is logged
        // and the check retried at the next run instead.
        try {
            self::ensure_all();
        } catch ( Throwable $e ) {
            FMW_Logger::warning( 'Maintenance-action check skipped: Action Scheduler raised an error', [
                'error' => $e->getMessage(),
            ] );
            return false;
        } finally {
            self::release_lock();
        }

        return true;
    }

    /**
     * The check itself, under the lock.
     */
    private static function ensure_all() {
        $reconcile_scheduled = false;
        foreach ( self::anchors() as $hook => $interval ) {
            $scheduled = self::ensure_one( $hook, $interval );
            if ( $scheduled && FMW_Schedule_Listener::RECONCILE_HOOK === $hook ) {
                $reconcile_scheduled = true;
            }
        }

        if ( $reconcile_scheduled ) {
            as_enqueue_async_action( FMW_Schedule_Listener::RECONCILE_NOW_HOOK, [], self::GROUP, true );
        }

        // Read by versions before 0.12.3: without it, a downgrade would run
        // an inline reconcile on its first page view.
        if ( '1' !== get_option( 'fmw_reconciliation_bootstrapped' ) ) {
            update_option( 'fmw_reconciliation_bootstrapped', '1' );
        }
    }

    /**
     * Keep exactly one pending copy of one maintenance action.
     *
     * @param string $hook     Maintenance hook.
     * @param int    $interval Recurrence in seconds.
     * @return bool True when a new copy was scheduled.
     */
    private static function ensure_one( $hook, $interval ) {
        $pending = self::find( $hook, [
            'status'   => ActionScheduler_Store::STATUS_PENDING,
            'per_page' => self::MAX_LISTED,
        ] );

        if ( count( $pending ) > 1 ) {
            self::collapse( $hook, $pending );
            return false;
        }
        if ( count( $pending ) === 1 ) {
            return false;
        }

        // Running now: Action Scheduler schedules the next copy when it ends.
        if ( self::find( $hook, [ 'status' => ActionScheduler_Store::STATUS_RUNNING, 'per_page' => 1 ] ) ) {
            return false;
        }

        // Ended moments ago: its runner may be scheduling the next copy
        // right now (cause 2 in the class comment) — unless a later copy
        // already exists. A later copy that was then cancelled (a
        // deactivation does that) means the repeat already happened and
        // nothing is coming.
        $recent = self::find( $hook, [
            'status'           => [ ActionScheduler_Store::STATUS_COMPLETE, ActionScheduler_Store::STATUS_FAILED ],
            'modified'         => time() - self::RESCHEDULE_GRACE,
            'modified_compare' => '>=',
            'per_page'         => self::MAX_LISTED,
        ] );
        if ( $recent ) {
            $cancelled = self::find( $hook, [
                'status'   => ActionScheduler_Store::STATUS_CANCELED,
                'per_page' => self::MAX_LISTED,
                'orderby'  => 'date',
                'order'    => 'DESC',
            ] );
            $repeated = $cancelled && max( array_map( 'intval', $cancelled ) ) > max( array_map( 'intval', $recent ) );
            if ( ! $repeated ) {
                return false;
            }
        }

        // `unique` makes Action Scheduler insert only if no pending or
        // running copy exists, in one statement, which narrows the window
        // for two checks running at once (cause 1). Never use it for
        // per-workflow ticks: Action Scheduler's uniqueness ignores args,
        // so it would refuse every workflow after the first.
        $action_id = as_schedule_recurring_action( time() + $interval, $interval, $hook, [], self::GROUP, true );

        if ( $action_id ) {
            FMW_Logger::info( 'Scheduled missing maintenance action', [
                'hook'      => $hook,
                'action_id' => $action_id,
            ] );
        }

        return (bool) $action_id;
    }

    /**
     * Cancel every pending copy but the one due soonest (lowest ID on a tie,
     * so concurrent checks agree on which one stays).
     *
     * @param string $hook Maintenance hook.
     * @param int[]  $ids  Pending action IDs (more than one).
     */
    private static function collapse( $hook, array $ids ) {
        $store = ActionScheduler::store();

        $due = [];
        foreach ( $ids as $id ) {
            $id = (int) $id;
            $ts = PHP_INT_MAX;
            try {
                $action = $store->fetch_action( $id );
                $date   = $action ? $action->get_schedule()->get_date() : null;
                if ( $date instanceof DateTimeInterface ) {
                    $ts = $date->getTimestamp();
                }
            } catch ( Throwable $e ) {
                // Unreadable: sorts last, so it is cancelled rather than kept.
            }
            $due[ $id ] = $ts;
        }

        uksort( $due, function ( $a, $b ) use ( $due ) {
            return [ $due[ $a ], $a ] <=> [ $due[ $b ], $b ];
        } );

        $keep      = (int) array_key_first( $due );
        $cancelled = [];
        foreach ( array_keys( $due ) as $id ) {
            if ( $id === $keep ) {
                continue;
            }
            try {
                $store->cancel_action( $id );
                $cancelled[] = $id;
            } catch ( Throwable $e ) {
                // Already cancelled or claimed by a concurrent check: fine.
            }
        }

        FMW_Logger::info( 'Cancelled duplicate maintenance actions', [
            'hook'      => $hook,
            'kept'      => $keep,
            'cancelled' => $cancelled,
        ] );
    }

    /**
     * List this group's copies of a hook (args `[]`) matching a query.
     *
     * @param string $hook  Hook.
     * @param array  $query Extra query args.
     * @return int[] Action IDs.
     */
    private static function find( $hook, array $query ) {
        // Until FlowMint schedules its first action there is no "fmw" group
        // (fresh install, table reset, restore). Action Scheduler 3.9.3 and
        // 4.1 answer a query for it with nothing; claiming by a missing
        // group throws, and a store could throw here too. Either way it
        // means no copies exist yet.
        try {
            $ids = as_get_scheduled_actions( array_merge( [
                'hook'    => $hook,
                'args'    => [],
                'group'   => self::GROUP,
                'orderby' => 'none',
            ], $query ), 'ids' );
        } catch ( InvalidArgumentException $e ) {
            if ( false === stripos( $e->getMessage(), 'does not exist' ) ) {
                throw $e;
            }
            return [];
        }

        return is_array( $ids ) ? array_values( $ids ) : [];
    }

    /**
     * Name of the MySQL lock: one per site (table prefix), within MySQL's
     * 64-character limit.
     *
     * @return string
     */
    private static function lock_name() {
        global $wpdb;
        return 'fmw_maintenance_' . substr( md5( DB_NAME . '|' . $wpdb->prefix ), 0, 16 );
    }

    /**
     * Take the lock without waiting.
     *
     * @return bool False only when another connection holds it.
     */
    private static function acquire_lock() {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! defined( 'DB_NAME' ) ) {
            return true;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a lock, not data.
        $got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::lock_name() ) );

        // NULL: the server could not take a lock (or does not support it).
        return null === $got || '1' === (string) $got;
    }

    private static function release_lock() {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! defined( 'DB_NAME' ) ) {
            return;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a lock, not data.
        $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name() ) );
    }

    /**
     * Action Scheduler's API is loaded and its data store is ready.
     *
     * @return bool
     */
    private static function scheduler_ready() {
        return function_exists( 'as_get_scheduled_actions' )
            && function_exists( 'as_schedule_recurring_action' )
            && function_exists( 'as_enqueue_async_action' )
            && class_exists( 'ActionScheduler' )
            && class_exists( 'ActionScheduler_Store' )
            && ActionScheduler::is_initialized();
    }
}
