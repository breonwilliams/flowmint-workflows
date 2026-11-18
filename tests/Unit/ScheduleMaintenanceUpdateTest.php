<?php
/**
 * The first check after an update also rebuilds workflow ticks once.
 *
 * Versions before 0.12.3 lost every tick on a reactivation and rebuilt them
 * only at the next daily reconcile, which could itself be missing or
 * duplicated. A site updated from that state can still hold its daily
 * reconcile (so nothing looks missing) while its ticks are gone. The
 * once-per-version check therefore queues the one-off reconcile whatever it
 * finds; it is idempotent and Action Scheduler keeps it unique.
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
class ScheduleMaintenanceUpdateTest extends UnitTestCase {

	/** @var array[] */
	public static $enqueued = [];

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
			eval( 'class ActionScheduler { public static function is_initialized( $function_name = null ) { return true; } public static function store() { return null; } }' );
		}

		self::$enqueued = [];

		// Both maintenance actions present, one each: nothing looks missing.
		Functions\when( 'as_get_scheduled_actions' )->alias( function ( $args, $format = 'OBJECT' ) {
			$statuses = (array) $args['status'];
			return in_array( 'pending', $statuses, true ) && in_array( $args['hook'], [ 'fmw_sweep_interrupted_runs', 'fmw_reconcile_scheduled_events' ], true ) ? [ 7 ] : [];
		} );
		Functions\when( 'as_schedule_recurring_action' )->justReturn( 0 );
		Functions\when( 'as_enqueue_async_action' )->alias( function ( ...$args ) {
			self::$enqueued[] = $args;
			return 1;
		} );
	}

	public function test_the_first_check_after_an_update_queues_one_reconcile() {
		$this->options['fmw_maintenance_checked_version'] = '0.12.2';

		\FMW_Schedule_Maintenance::maybe_ensure_after_update();

		$this->assertCount( 1, self::$enqueued, 'ticks lost under an older version come back now' );
		$this->assertSame( [ \FMW_Schedule_Listener::RECONCILE_NOW_HOOK, [], 'fmw', true ], self::$enqueued[0] );
		$this->assertSame( FMW_VERSION, $this->options['fmw_maintenance_checked_version'] );
	}

	public function test_a_fresh_install_queues_one_reconcile_too() {
		\FMW_Schedule_Maintenance::maybe_ensure_after_update();

		$this->assertCount( 1, self::$enqueued );
	}

	public function test_later_page_views_and_queue_starts_queue_none() {
		$this->options['fmw_maintenance_checked_version'] = FMW_VERSION;

		\FMW_Schedule_Maintenance::maybe_ensure_after_update();
		\FMW_Schedule_Maintenance::ensure();

		$this->assertSame( [], self::$enqueued, 'with both actions present, only an update queues a reconcile' );
	}
}
