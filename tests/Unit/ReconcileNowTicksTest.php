<?php
/**
 * The one-off reconcile (fmw_reconcile_scheduled_events_now) only adds
 * missing ticks; every existing tick keeps its ID and its next run.
 *
 * It runs once after every update and whenever the daily reconcile had to be
 * scheduled again. It used to call the full reconcile, which cancels every
 * tick and schedules a new one from "now": an hourly workflow due in ten
 * minutes was pushed an hour out, a twice-daily one twelve hours, so each
 * update could cost a run.
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
class ReconcileNowTicksTest extends UnitTestCase {

	const TICK = 'fmw_scheduled_workflow_tick';

	/** @var array<int,array> action_id => [hook, args, group, status, due, interval] */
	public static $actions = [];

	/** @var int */
	public static $next_id = 100;

	/** @var array[] workflow rows */
	public static $rows = [];

	/** @var callable[] add_action registrations by hook */
	private $hooks = [];

	protected function set_up() {
		parent::set_up();

		foreach ( [ 'HOUR_IN_SECONDS' => 3600, 'DAY_IN_SECONDS' => 86400, 'WEEK_IN_SECONDS' => 604800, 'ARRAY_A' => 'ARRAY_A' ] as $name => $value ) {
			if ( ! defined( $name ) ) {
				define( $name, $value );
			}
		}

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
			public function get_results( $sql, $output = null ) { return ReconcileNowTicksTest::$rows; }
		};

		$hooks = &$this->hooks;
		Functions\when( 'add_action' )->alias( function ( $hook, $callback ) use ( &$hooks ) {
			$hooks[ $hook ][] = $callback;
		} );
	}

	protected function tear_down() {
		unset( $GLOBALS['wpdb'] );
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
		self::$actions[ $id ] = [ 'hook' => self::TICK, 'args' => [ $workflow_id ], 'group' => 'fmw', 'status' => 'pending', 'due' => time() + $due_in, 'interval' => $interval ];
	}

	private function pending_ticks( $workflow_id ) {
		return array_keys( array_filter( self::$actions, function ( $a ) use ( $workflow_id ) {
			return self::TICK === $a['hook'] && [ $workflow_id ] === $a['args'] && 'pending' === $a['status'];
		} ) );
	}

	private function run_reconcile_now() {
		$listener = new \FMW_Schedule_Listener();
		$listener->init();
		$this->assertNotEmpty( $this->hooks[ \FMW_Schedule_Listener::RECONCILE_NOW_HOOK ] ?? [] );
		foreach ( $this->hooks[ \FMW_Schedule_Listener::RECONCILE_NOW_HOOK ] as $callback ) {
			call_user_func( $callback );
		}
	}

	public function test_existing_ticks_keep_their_id_and_next_run() {
		$this->workflow( 'hourly-digest', [ 'interval' => 'hourly' ] );
		$this->workflow( 'twice-daily-sync', [ 'interval' => 'twicedaily' ] );
		$this->tick( 50, 'hourly-digest', 600, 3600 );
		$this->tick( 51, 'twice-daily-sync', 3600, 43200 );
		$due = [ 50 => self::$actions[50]['due'], 51 => self::$actions[51]['due'] ];

		$this->run_reconcile_now();

		$this->assertSame( [ 50 ], $this->pending_ticks( 'hourly-digest' ), 'the hourly tick is the same action' );
		$this->assertSame( [ 51 ], $this->pending_ticks( 'twice-daily-sync' ) );
		$this->assertSame( $due[50], self::$actions[50]['due'], 'and still runs in ten minutes, not an hour' );
		$this->assertSame( $due[51], self::$actions[51]['due'] );
	}

	public function test_missing_ticks_are_added_and_disabled_workflows_get_none() {
		$this->workflow( 'hourly-digest', [ 'interval' => 'hourly' ] );
		$this->workflow( 'nightly-cleanup', [ 'interval' => 'daily', 'hour' => 3, 'minute' => 0 ] );
		$this->workflow( 'paused-report', [ 'interval' => 'daily' ], false );
		$this->tick( 50, 'hourly-digest', 600, 3600 );

		$this->run_reconcile_now();

		$this->assertSame( [ 50 ], $this->pending_ticks( 'hourly-digest' ) );
		$this->assertCount( 1, $this->pending_ticks( 'nightly-cleanup' ), 'the missing tick is added' );
		$this->assertSame( [], $this->pending_ticks( 'paused-report' ) );
	}

	public function test_even_a_tick_that_no_longer_matches_is_left_for_the_daily_reconcile() {
		$this->workflow( 'hourly-digest', [ 'interval' => 'hourly' ] );
		$this->tick( 50, 'hourly-digest', 600, 86400 ); // Interval changed by a direct database edit.

		$this->run_reconcile_now();

		$this->assertSame( [ 50 ], $this->pending_ticks( 'hourly-digest' ), 'adding missing ticks never replaces one' );
	}
}
