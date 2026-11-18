<?php
/**
 * FlowMint's two maintenance actions are checked off the page-view path and
 * kept at exactly one copy each.
 *
 * Until 0.12.3 every request — front end included — asked Action Scheduler
 * whether the daily reconcile and the hourly sweep were scheduled: two
 * queries per page view that no object cache absorbs. The same
 * check-then-schedule created duplicates that never went away (several
 * requests at once after both actions vanished, or one request landing
 * between Action Scheduler completing a recurring action and scheduling its
 * next instance). Duplicates were seen on a production install: three of
 * each where one belongs.
 *
 * Action Scheduler is faked here: an in-memory table of actions, the
 * as_* functions that read and write it, and the two classes the
 * maintenance code touches. Every as_* call is recorded.
 *
 * @package FlowMintWorkflows\Tests\Unit
 */

namespace FMW\Tests\Unit;

require_once __DIR__ . '/UnitTestCase.php';

use Brain\Monkey\Functions;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ScheduleMaintenanceTest extends UnitTestCase {

	const SWEEP     = 'fmw_sweep_interrupted_runs';
	const RECONCILE = 'fmw_reconcile_scheduled_events';
	const TICK      = 'fmw_scheduled_workflow_tick';

	/** @var array<int,array> action_id => [hook, args, group, status, due, modified] */
	public static $actions = [];

	/** @var array[] every as_* call: [function, args] */
	public static $calls = [];

	/** @var int[] */
	public static $cancelled = [];

	/** @var int */
	public static $next_id = 1000;

	/** @var array[] add_action registrations: [hook, callback] */
	private $registered = [];

	protected function set_up() {
		parent::set_up();

		foreach ( [ 'HOUR_IN_SECONDS' => 3600, 'DAY_IN_SECONDS' => 86400 ] as $name => $value ) {
			if ( ! defined( $name ) ) {
				define( $name, $value );
			}
		}

		if ( ! class_exists( 'ActionScheduler_Store', false ) ) {
			eval( 'abstract class ActionScheduler_Store { const STATUS_COMPLETE = "complete"; const STATUS_PENDING = "pending"; const STATUS_RUNNING = "in-progress"; const STATUS_FAILED = "failed"; const STATUS_CANCELED = "canceled"; }' );
		}
		if ( ! class_exists( 'ActionScheduler', false ) ) {
			eval( 'class ActionScheduler { public static $ready = true; public static function is_initialized( $function_name = null ) { return self::$ready; } public static function store() { return $GLOBALS["__fmw_fake_as_store"]; } }' );
		}
		\ActionScheduler::$ready = true;

		$GLOBALS['__fmw_fake_as_store'] = new class {
			public function fetch_action( $id ) {
				$due = ScheduleMaintenanceTest::$actions[ $id ]['due'];
				return new class( $due ) {
					private $due;
					public function __construct( $due ) { $this->due = $due; }
					public function get_schedule() {
						$due = $this->due;
						return new class( $due ) {
							private $due;
							public function __construct( $due ) { $this->due = $due; }
							public function get_date() { return new \DateTime( '@' . $this->due ); }
						};
					}
				};
			}
			public function cancel_action( $id ) {
				ScheduleMaintenanceTest::$actions[ $id ]['status'] = 'canceled';
				ScheduleMaintenanceTest::$cancelled[]               = (int) $id;
			}
		};

		self::$actions   = [];
		self::$calls     = [];
		self::$cancelled = [];
		self::$next_id   = 1000;

		Functions\when( 'as_get_scheduled_actions' )->alias( function ( $args, $format = 'OBJECT' ) {
			self::$calls[] = [ 'as_get_scheduled_actions', $args ];
			$statuses      = isset( $args['status'] ) ? (array) $args['status'] : [];
			$ids           = [];
			foreach ( self::$actions as $id => $a ) {
				if ( isset( $args['hook'] ) && $a['hook'] !== $args['hook'] ) {
					continue;
				}
				if ( isset( $args['group'] ) && $a['group'] !== $args['group'] ) {
					continue;
				}
				if ( isset( $args['args'] ) && $a['args'] !== $args['args'] ) {
					continue;
				}
				if ( $statuses && ! in_array( $a['status'], $statuses, true ) ) {
					continue;
				}
				if ( isset( $args['modified'] ) && $a['modified'] < (int) $args['modified'] ) {
					continue;
				}
				$ids[] = $id;
			}
			$limit = isset( $args['per_page'] ) ? (int) $args['per_page'] : 5;
			return $limit > 0 ? array_slice( $ids, 0, $limit ) : $ids;
		} );
		Functions\when( 'as_has_scheduled_action' )->alias( function ( ...$args ) {
			self::$calls[] = [ 'as_has_scheduled_action', $args ];
			return true;
		} );
		Functions\when( 'as_schedule_recurring_action' )->alias( function ( ...$args ) {
			self::$calls[] = [ 'as_schedule_recurring_action', $args ];
			$id            = ++self::$next_id;
			self::$actions[ $id ] = [ 'hook' => $args[2], 'args' => $args[3] ?? [], 'group' => $args[4] ?? '', 'status' => 'pending', 'due' => $args[0], 'modified' => 0 ];
			return $id;
		} );
		Functions\when( 'as_enqueue_async_action' )->alias( function ( ...$args ) {
			self::$calls[] = [ 'as_enqueue_async_action', $args ];
			return ++self::$next_id;
		} );

		$registered = &$this->registered;
		Functions\when( 'add_action' )->alias( function ( $hook, $callback ) use ( &$registered ) {
			$registered[] = [ $hook, $callback ];
		} );
	}

	protected function tear_down() {
		unset( $GLOBALS['__fmw_fake_as_store'] );
		parent::tear_down();
	}

	private function seed( $id, $hook, $status = 'pending', $due_in = 3600, array $args = [], $modified_ago = 7200 ) {
		self::$actions[ $id ] = [
			'hook'     => $hook,
			'args'     => $args,
			'group'    => 'fmw',
			'status'   => $status,
			'due'      => time() + $due_in,
			'modified' => time() - $modified_ago,
		];
	}

	private function calls_to( $function ) {
		return array_values( array_filter( self::$calls, function ( $c ) use ( $function ) {
			return $c[0] === $function;
		} ) );
	}

	private function callbacks_for( $hook ) {
		$out = [];
		foreach ( $this->registered as $r ) {
			if ( $r[0] === $hook ) {
				$out[] = $r[1];
			}
		}
		return $out;
	}

	private function pending( $hook ) {
		return array_keys( array_filter( self::$actions, function ( $a ) use ( $hook ) {
			return $a['hook'] === $hook && 'pending' === $a['status'];
		} ) );
	}

	public function test_a_page_view_makes_no_action_scheduler_call() {
		$this->options['fmw_maintenance_checked_version'] = FMW_VERSION;
		$this->seed( 1, self::SWEEP );
		$this->seed( 2, self::RECONCILE );

		\FMW_Workflow_Job::register();

		// Everything a page view fires that FlowMint's job registration hooks.
		foreach ( [ 'plugins_loaded', 'init', 'action_scheduler_init', 'wp_loaded', 'wp' ] as $hook ) {
			foreach ( $this->callbacks_for( $hook ) as $callback ) {
				call_user_func( $callback );
			}
		}

		$this->assertSame( [], self::$calls, 'a page view with the version stamp current asks Action Scheduler nothing' );
	}

	public function test_the_check_runs_at_queue_start_and_on_flowmint_screens_only() {
		\FMW_Workflow_Job::register();

		$this->assertContains( [ 'FMW_Schedule_Maintenance', 'ensure' ], $this->callbacks_for( 'action_scheduler_before_process_queue' ) );
		$this->assertSame( [], $this->callbacks_for( 'init' ), 'nothing on init' );

		$this->seed( 1, self::SWEEP );
		$this->seed( 2, self::RECONCILE );
		$on_screen = $this->callbacks_for( 'current_screen' );
		$this->assertCount( 1, $on_screen );

		foreach ( [ 'edit-post', 'dashboard', 'plugins', 'toplevel_page_pforms-forms' ] as $other ) {
			call_user_func( $on_screen[0], (object) [ 'id' => $other ] );
		}
		$this->assertSame( [], self::$calls, 'other admin screens make no call' );

		foreach ( [ 'toplevel_page_fmw-runs', 'flowmint-workflows_page_fmw-workflows', 'flowmint-workflows_page_flowmint-connector' ] as $own ) {
			self::$calls = [];
			call_user_func( $on_screen[0], (object) [ 'id' => $own ] );
			$this->assertNotEmpty( $this->calls_to( 'as_get_scheduled_actions' ), "$own runs the check" );
		}
	}

	public function test_duplicates_collapse_to_the_copy_due_soonest_and_ticks_are_untouched() {
		$this->seed( 11, self::SWEEP, 'pending', 300 );
		$this->seed( 12, self::SWEEP, 'pending', 100 );
		$this->seed( 13, self::SWEEP, 'pending', 100 );
		$this->seed( 21, self::RECONCILE, 'pending', 5000 );
		$this->seed( 22, self::RECONCILE, 'pending', 4000 );
		$this->seed( 31, self::TICK, 'pending', 200, [ 'weekly-report' ] );
		$this->seed( 32, self::TICK, 'pending', 900, [ 'weekly-report' ] );

		\FMW_Schedule_Maintenance::ensure();

		sort( self::$cancelled );
		$this->assertSame( [ 11, 13, 21 ], self::$cancelled, 'the copy due soonest stays; on a tie, the lower ID' );
		$this->assertSame( [ 12 ], $this->pending( self::SWEEP ) );
		$this->assertSame( [ 22 ], $this->pending( self::RECONCILE ) );
		$this->assertSame( [ 31, 32 ], $this->pending( self::TICK ), 'per-workflow ticks are not ours to collapse' );
		$this->assertSame( [], $this->calls_to( 'as_schedule_recurring_action' ) );
		$this->assertSame( [], $this->calls_to( 'as_enqueue_async_action' ) );
		foreach ( $this->calls_to( 'as_get_scheduled_actions' ) as $call ) {
			$this->assertContains( $call[1]['hook'], [ self::SWEEP, self::RECONCILE ], 'only the two maintenance hooks are queried' );
			$this->assertSame( 'fmw', $call[1]['group'] );
			$this->assertSame( [], $call[1]['args'] );
		}

		// Collapsed: the next check changes nothing.
		self::$cancelled = [];
		\FMW_Schedule_Maintenance::ensure();
		$this->assertSame( [], self::$cancelled );
	}

	public function test_missing_actions_are_scheduled_once_and_a_reconcile_is_queued_now() {
		$this->seed( 31, self::TICK, 'pending', 200, [ 'weekly-report' ] );

		$before = time();
		\FMW_Schedule_Maintenance::ensure();

		$scheduled = $this->calls_to( 'as_schedule_recurring_action' );
		$this->assertCount( 2, $scheduled );
		$by_hook = [];
		foreach ( $scheduled as $call ) {
			$by_hook[ $call[1][2] ] = $call[1];
		}
		foreach ( [ self::RECONCILE => 86400, self::SWEEP => 3600 ] as $hook => $interval ) {
			$this->assertArrayHasKey( $hook, $by_hook );
			list( $first, $every, , $args, $group, $unique ) = $by_hook[ $hook ];
			$this->assertSame( $interval, $every, "$hook interval" );
			$this->assertGreaterThanOrEqual( $before + $interval, $first );
			$this->assertLessThanOrEqual( time() + $interval, $first );
			$this->assertSame( [], $args );
			$this->assertSame( 'fmw', $group );
			$this->assertTrue( $unique, "$hook is scheduled unique" );
		}

		$queued = $this->calls_to( 'as_enqueue_async_action' );
		$this->assertCount( 1, $queued, 'the reconcile runs now, through Action Scheduler' );
		$this->assertSame( [ \FMW_Schedule_Listener::RECONCILE_NOW_HOOK, [], 'fmw', true ], $queued[0][1] );
		$this->assertNotSame( self::RECONCILE, \FMW_Schedule_Listener::RECONCILE_NOW_HOOK, 'the one-off never counts as a copy of the daily action' );
		$this->assertSame( '1', $this->options['fmw_reconciliation_bootstrapped'] ?? null );

		self::$calls = [];
		\FMW_Schedule_Maintenance::ensure();
		$this->assertSame( [], $this->calls_to( 'as_schedule_recurring_action' ), 'scheduled once' );
		$this->assertSame( [], $this->calls_to( 'as_enqueue_async_action' ) );
	}

	public function test_a_missing_sweep_alone_queues_no_reconcile() {
		$this->seed( 2, self::RECONCILE );

		\FMW_Schedule_Maintenance::ensure();

		$scheduled = $this->calls_to( 'as_schedule_recurring_action' );
		$this->assertCount( 1, $scheduled );
		$this->assertSame( self::SWEEP, $scheduled[0][1][2] );
		$this->assertSame( [], $this->calls_to( 'as_enqueue_async_action' ) );
	}

	public function test_a_running_or_just_finished_copy_is_not_replaced() {
		$this->seed( 1, self::SWEEP, 'in-progress', -30, [], 30 );
		$this->seed( 2, self::RECONCILE, 'complete', -60, [], 60 );

		\FMW_Schedule_Maintenance::ensure();

		$this->assertSame( [], $this->calls_to( 'as_schedule_recurring_action' ), 'Action Scheduler is about to schedule the next copy itself' );
		$recent = array_values( array_filter( $this->calls_to( 'as_get_scheduled_actions' ), function ( $c ) {
			return isset( $c[1]['modified'] );
		} ) );
		$this->assertNotEmpty( $recent );
		$this->assertSame( '>=', $recent[0][1]['modified_compare'] );

		// Long finished and nothing pending: the chain broke, so it is replaced.
		self::$actions[2]['modified'] = time() - 3600;
		self::$calls                  = [];
		\FMW_Schedule_Maintenance::ensure();
		$scheduled = $this->calls_to( 'as_schedule_recurring_action' );
		$this->assertCount( 1, $scheduled );
		$this->assertSame( self::RECONCILE, $scheduled[0][1][2] );
	}

	public function test_the_post_update_check_runs_once_per_version() {
		\FMW_Workflow_Job::register();
		$after_update = $this->callbacks_for( 'action_scheduler_init' );
		$this->assertCount( 1, $after_update );

		call_user_func( $after_update[0] );
		$this->assertNotEmpty( $this->calls_to( 'as_get_scheduled_actions' ), 'first request after an update checks' );
		$this->assertSame( FMW_VERSION, $this->options['fmw_maintenance_checked_version'] ?? null );
		$this->assertCount( 1, $this->pending( self::SWEEP ) );
		$this->assertCount( 1, $this->pending( self::RECONCILE ) );

		self::$calls = [];
		call_user_func( $after_update[0] );
		$this->assertSame( [], self::$calls, 'later requests do not' );
	}

	public function test_nothing_happens_before_action_scheduler_is_ready() {
		\ActionScheduler::$ready = false;

		\FMW_Schedule_Maintenance::ensure();
		\FMW_Schedule_Maintenance::on_current_screen( (object) [ 'id' => 'toplevel_page_fmw-runs' ] );
		\FMW_Schedule_Maintenance::maybe_ensure_after_update();

		$this->assertSame( [], self::$calls );
		$this->assertArrayNotHasKey( 'fmw_maintenance_checked_version', $this->options, 'the stamp waits until a check actually ran' );
	}

	public function test_the_queued_reconcile_runs_the_reconcile() {
		$listener = new \FMW_Schedule_Listener();
		$listener->init();

		$callbacks = $this->callbacks_for( \FMW_Schedule_Listener::RECONCILE_NOW_HOOK );
		$this->assertCount( 1, $callbacks );
		$this->assertSame( [ $listener, 'ensure_recurring_events_registered' ], $callbacks[0] );
	}
}
