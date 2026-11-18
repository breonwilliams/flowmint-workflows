<?php
/**
 * The daily reconcile replaces a tick only when it is missing or no longer
 * matches the workflow's schedule. A tick that matches keeps its ID and its
 * next run.
 *
 * It used to cancel every tick and schedule a new one from "now" each day.
 * Hourly ticks restart at now + 1 hour and twice-daily ones at now + 12
 * hours, so on every install an hourly workflow lost up to an hour once a
 * day and a twice-daily one could be pushed past a run.
 *
 * Action Scheduler and the workflows table are faked in memory.
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
class DailyReconcileTicksTest extends UnitTestCase {

	const TICK = 'fmw_scheduled_workflow_tick';

	/** @var array<int,array> action_id => [hook, args, group, status, due, interval] */
	public static $actions = [];

	/** @var int */
	public static $next_id = 100;

	/** @var array[] workflow rows */
	public static $rows = [];

	/** @var callable[][] add_action registrations by hook */
	private $hooks = [];

	protected function set_up() {
		parent::set_up();

		foreach ( [ 'HOUR_IN_SECONDS' => 3600, 'DAY_IN_SECONDS' => 86400, 'WEEK_IN_SECONDS' => 604800, 'ARRAY_A' => 'ARRAY_A' ] as $name => $value ) {
			if ( ! defined( $name ) ) {
				define( $name, $value );
			}
		}
		if ( ! class_exists( 'ActionScheduler', false ) ) {
			eval( 'class ActionScheduler { public static function is_initialized( $f = null ) { return true; } public static function store() { return $GLOBALS["__fmw_fake_tick_store"]; } }' );
		}
		$GLOBALS['__fmw_fake_tick_store'] = new class {
			public function cancel_action( $id ) {
				DailyReconcileTicksTest::$actions[ $id ]['status'] = 'canceled';
			}
		};

		self::$actions = [];
		self::$rows    = [];
		self::$next_id = 100;

		Functions\when( 'wp_timezone' )->alias( function () {
			return new \DateTimeZone( 'UTC' );
		} );
		Functions\when( 'as_get_scheduled_actions' )->alias( function ( $args, $format = 'OBJECT' ) {
			$statuses = isset( $args['status'] ) ? (array) $args['status'] : [];
			$ids      = [];
			foreach ( self::$actions as $id => $a ) {
				if ( $a['hook'] !== $args['hook'] || ( isset( $args['group'] ) && $a['group'] !== $args['group'] ) ) {
					continue;
				}
				if ( array_key_exists( 'args', $args ) && null !== $args['args'] && $a['args'] !== $args['args'] ) {
					continue;
				}
				if ( $statuses && ! in_array( $a['status'], $statuses, true ) ) {
					continue;
				}
				$ids[] = $id;
			}
			if ( isset( $args['orderby'] ) && 'date' === $args['orderby'] ) {
				usort( $ids, function ( $x, $y ) {
					return [ self::$actions[ $x ]['due'], $x ] <=> [ self::$actions[ $y ]['due'], $y ];
				} );
				if ( isset( $args['order'] ) && 'DESC' === strtoupper( $args['order'] ) ) {
					$ids = array_reverse( $ids );
				}
			}
			if ( 'ids' === $format ) {
				return $ids;
			}
			$out = [];
			foreach ( $ids as $id ) {
				$out[ $id ] = self::action( $id );
			}
			return $out;
		} );
		Functions\when( 'as_has_scheduled_action' )->alias( function ( $hook, $args = null, $group = '' ) {
			foreach ( self::$actions as $a ) {
				if ( $a['hook'] === $hook && ( null === $args || $a['args'] === $args ) && in_array( $a['status'], [ 'pending', 'in-progress' ], true ) ) {
					return true;
				}
			}
			return false;
		} );
		Functions\when( 'as_schedule_recurring_action' )->alias( function ( $ts, $interval, $hook, $args = [], $group = '' ) {
			$id                   = ++self::$next_id;
			self::$actions[ $id ] = [ 'hook' => $hook, 'args' => $args, 'group' => $group, 'status' => 'pending', 'due' => $ts, 'interval' => $interval ];
			return $id;
		} );
		Functions\when( 'as_unschedule_all_actions' )->alias( function ( $hook, $args = [], $group = '' ) {
			foreach ( self::$actions as $id => $a ) {
				if ( $a['hook'] === $hook && $a['args'] === $args && 'pending' === $a['status'] ) {
					self::$actions[ $id ]['status'] = 'canceled';
				}
			}
		} );

		$GLOBALS['wpdb'] = new class {
			public $prefix = 'wp_';
			public function prepare( $sql, ...$args ) { return $sql; }
			public function get_results( $sql, $output = null ) { return DailyReconcileTicksTest::$rows; }
		};

		$hooks = &$this->hooks;
		Functions\when( 'add_action' )->alias( function ( $hook, $callback ) use ( &$hooks ) {
			$hooks[ $hook ][] = $callback;
		} );
	}

	protected function tear_down() {
		unset( $GLOBALS['wpdb'], $GLOBALS['__fmw_fake_tick_store'] );
		parent::tear_down();
	}

	/** A fake Action Scheduler action for one stored row. */
	public static function action( $id ) {
		$a = self::$actions[ $id ];
		return new class( $a ) {
			private $a;
			public function __construct( $a ) { $this->a = $a; }
			public function get_args() { return $this->a['args']; }
			public function get_schedule() {
				$a = $this->a;
				return new class( $a ) {
					private $a;
					public function __construct( $a ) { $this->a = $a; }
					public function is_recurring() { return true; }
					public function get_recurrence() { return $this->a['interval']; }
					public function get_date() { return new \DateTime( '@' . $this->a['due'] ); }
				};
			}
		};
	}

	private function workflow( $id, array $trigger, $enabled = true ) {
		self::$rows[] = [
			'id'           => $id,
			'title'        => $id,
			'enabled'      => $enabled ? 1 : 0,
			'trigger_type' => 'schedule',
			'config'       => json_encode( [ 'trigger' => array_merge( [ 'type' => 'schedule' ], $trigger ), 'steps' => [] ] ),
		];
	}

	private function tick( $id, $workflow_id, $due_in, $interval ) {
		$this->tick_at( $id, $workflow_id, time() + $due_in, $interval );
	}

	private function tick_at( $id, $workflow_id, $ts, $interval ) {
		self::$actions[ $id ] = [ 'hook' => self::TICK, 'args' => [ $workflow_id ], 'group' => 'fmw', 'status' => 'pending', 'due' => $ts, 'interval' => $interval ];
	}

	private function pending_ticks( $workflow_id ) {
		return array_keys( array_filter( self::$actions, function ( $a ) use ( $workflow_id ) {
			return self::TICK === $a['hook'] && [ $workflow_id ] === $a['args'] && 'pending' === $a['status'];
		} ) );
	}

	private function run_daily_reconcile() {
		$listener = new \FMW_Schedule_Listener();
		$listener->init();
		$this->assertNotEmpty( $this->hooks[ \FMW_Schedule_Listener::RECONCILE_HOOK ] ?? [] );
		foreach ( $this->hooks[ \FMW_Schedule_Listener::RECONCILE_HOOK ] as $callback ) {
			call_user_func( $callback );
		}
	}

	/** Next H:M (and ISO day of week) in UTC from now, as Action Scheduler would hold it. */
	private function next_at( $hour, $minute, $dow = null ) {
		$now  = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
		$next = ( clone $now )->setTime( $hour, $minute, 0 );
		if ( null === $dow ) {
			if ( $next <= $now ) {
				$next->modify( '+1 day' );
			}
			return $next->getTimestamp();
		}
		$days = ( $dow - (int) $next->format( 'N' ) + 7 ) % 7;
		if ( 0 === $days && $next <= $now ) {
			$days = 7;
		}
		$next->modify( "+{$days} day" );
		return $next->getTimestamp();
	}

	public function test_matching_ticks_keep_their_id_and_next_run() {
		$this->workflow( 'hourly-digest', [ 'interval' => 'hourly' ] );
		$this->workflow( 'twice-daily-sync', [ 'interval' => 'twicedaily' ] );
		$this->workflow( 'nightly-cleanup', [ 'interval' => 'daily', 'hour' => 3, 'minute' => 0 ] );
		$this->workflow( 'weekly-report', [ 'interval' => 'weekly', 'day_of_week' => 3, 'hour' => 4, 'minute' => 30 ] );
		$this->tick( 50, 'hourly-digest', 600, 3600 );
		$this->tick( 51, 'twice-daily-sync', 3600, 43200 );
		$this->tick_at( 52, 'nightly-cleanup', $this->next_at( 3, 0 ), 86400 );
		$this->tick_at( 53, 'weekly-report', $this->next_at( 4, 30, 3 ), 604800 );
		$before = array_map( function ( $a ) { return $a['due']; }, self::$actions );

		$this->run_daily_reconcile();

		foreach ( [ 'hourly-digest' => 50, 'twice-daily-sync' => 51, 'nightly-cleanup' => 52, 'weekly-report' => 53 ] as $workflow_id => $id ) {
			$this->assertSame( [ $id ], $this->pending_ticks( $workflow_id ), "$workflow_id keeps its tick" );
			$this->assertSame( $before[ $id ], self::$actions[ $id ]['due'], "$workflow_id keeps its next run" );
		}
	}

	public function test_a_tick_that_no_longer_matches_is_replaced() {
		$this->workflow( 'hourly-digest', [ 'interval' => 'hourly' ] );
		$this->workflow( 'nightly-cleanup', [ 'interval' => 'daily', 'hour' => 3, 'minute' => 0 ] );
		$this->workflow( 'weekly-report', [ 'interval' => 'weekly', 'day_of_week' => 3, 'hour' => 4, 'minute' => 30 ] );
		$this->tick( 50, 'hourly-digest', 600, 86400 );                          // Wrong interval.
		$this->tick_at( 52, 'nightly-cleanup', $this->next_at( 2, 0 ), 86400 );    // Wrong time of day.
		$this->tick_at( 53, 'weekly-report', $this->next_at( 4, 30, 5 ), 604800 ); // Wrong day.

		$this->run_daily_reconcile();

		$hourly = $this->pending_ticks( 'hourly-digest' );
		$this->assertCount( 1, $hourly );
		$this->assertNotSame( 50, $hourly[0] );
		$this->assertSame( 3600, self::$actions[ $hourly[0] ]['interval'] );

		$nightly = $this->pending_ticks( 'nightly-cleanup' );
		$this->assertCount( 1, $nightly );
		$this->assertSame( $this->next_at( 3, 0 ), self::$actions[ $nightly[0] ]['due'] );

		$weekly = $this->pending_ticks( 'weekly-report' );
		$this->assertCount( 1, $weekly );
		$this->assertSame( $this->next_at( 4, 30, 3 ), self::$actions[ $weekly[0] ]['due'] );
	}

	public function test_missing_ticks_are_added_and_disabled_workflows_lose_theirs() {
		$this->workflow( 'nightly-cleanup', [ 'interval' => 'daily', 'hour' => 3, 'minute' => 0 ] );
		$this->workflow( 'paused-report', [ 'interval' => 'hourly' ], false );
		$this->tick( 60, 'paused-report', 600, 3600 );

		$this->run_daily_reconcile();

		$this->assertCount( 1, $this->pending_ticks( 'nightly-cleanup' ) );
		$this->assertSame( [], $this->pending_ticks( 'paused-report' ) );
	}

	public function test_duplicate_ticks_are_cut_to_the_one_due_soonest() {
		$this->workflow( 'hourly-digest', [ 'interval' => 'hourly' ] );
		$this->tick( 70, 'hourly-digest', 2400, 3600 );
		$this->tick( 71, 'hourly-digest', 600, 3600 );
		$due = self::$actions[71]['due'];

		$this->run_daily_reconcile();

		$this->assertSame( [ 71 ], $this->pending_ticks( 'hourly-digest' ) );
		$this->assertSame( $due, self::$actions[71]['due'] );
	}
}
