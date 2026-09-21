<?php
/**
 * Runs the server kills part-way are recovered, not left "running".
 *
 * A PHP time limit, a fatal error or a memory limit ends the request before
 * FMW_Workflow_Job::handle() reaches its catch blocks. Until 0.11.0 such a
 * run stayed "running" forever: no retry, no failure alert, and none of the
 * later steps. Reproduced on a mirror of 725 Print Lab's quote workflow: a
 * 21 MB artwork upload cut off by a 30-second limit left the run "running"
 * and the shop's quote email unsent, with nothing to tell anyone.
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
class InterruptedRunTest extends UnitTestCase {

	/** @var array[] do_action calls */
	public static $actions = [];

	/** @var array[] as_schedule_single_action calls */
	public static $scheduled = [];

	protected function set_up() {
		parent::set_up();
		foreach ( [
			'includes/Core/class-fmw-logger.php',
			'includes/Core/class-fmw-step-exception.php',
			'includes/Core/class-fmw-workflow.php',
			'includes/Core/class-fmw-workflow-job.php',
			'includes/Database/class-fmw-run-repository.php',
		] as $file ) {
			require_once FMW_TEST_PLUGIN_DIR . $file;
		}

		if ( ! defined( 'ARRAY_A' ) ) {
			define( 'ARRAY_A', 'ARRAY_A' );
		}
		if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
			define( 'HOUR_IN_SECONDS', 3600 );
		}

		self::$actions   = [];
		self::$scheduled = [];

		Functions\when( 'current_time' )->justReturn( '2026-09-21 12:00:00' );
		Functions\when( 'do_action' )->alias( function ( ...$args ) {
			self::$actions[] = $args;
		} );
		Functions\when( 'as_schedule_single_action' )->alias( function ( ...$args ) {
			self::$scheduled[] = $args;
			return 99;
		} );
		Functions\when( 'apply_filters' )->alias( function ( $hook, $value ) {
			return $value;
		} );

		$GLOBALS['wpdb'] = new class {
			public $prefix = 'wp_';
			public $row    = null;
			public function prepare( $sql, ...$args ) { return vsprintf( str_replace( [ '%d', '%s' ], [ '%d', "'%s'" ], $sql ), $args ); }
			public function get_row( $sql, $output = null ) { return $this->row; }
			public function update( $table, $data, $where ) { $this->row = array_merge( $this->row, $data ); return 1; }
			public function query( $sql ) {
				if ( false !== strpos( $sql, 'retry_count = retry_count + 1' ) ) {
					$this->row['retry_count'] = (int) $this->row['retry_count'] + 1;
				}
				return 1;
			}
		};

		$workflow = new \FMW_Workflow( [
			'id'     => '725-small-order-request',
			'title'  => 'Quote',
			'config' => json_encode( [
				'settings' => [ 'max_retries' => 3 ],
				'steps'    => [
					[ 'name' => 'month_folder', 'type' => 'drive_find_or_create_folder', 'on_error' => 'retry', 'config' => [] ],
					[ 'name' => 'upload_design', 'type' => 'drive_upload_file', 'on_error' => 'retry', 'config' => [] ],
					[ 'name' => 'team_notify', 'type' => 'send_email', 'config' => [] ],
				],
			] ),
		] );
		$GLOBALS['__fmw_test_registry'] = new class( $workflow ) {
			private $w;
			public function __construct( $w ) { $this->w = $w; }
			public function get( $id ) { return $this->w; }
		};
		if ( ! function_exists( 'fmw' ) ) {
			eval( 'function fmw() { return (object) [ "registry" => $GLOBALS["__fmw_test_registry"] ]; }' );
		}
	}

	protected function tear_down() {
		unset( $GLOBALS['wpdb'], $GLOBALS['__fmw_test_registry'] );
		parent::tear_down();
	}

	private function run_row( array $overrides = [] ) {
		$GLOBALS['wpdb']->row = array_merge( [
			'id'                => 42,
			'workflow_id'       => '725-small-order-request',
			'entry_id'          => 7,
			'status'            => 'running',
			'retry_count'       => 0,
			'resume_step_index' => 1, // month_folder done; upload_design was running.
			'checkpoint'        => '{"context":{}}',
		], $overrides );
	}

	private function fired( $hook ) {
		return array_values( array_filter( self::$actions, function ( $a ) use ( $hook ) { return $a[0] === $hook; } ) );
	}

	public function test_interrupted_at_a_retry_step_is_retried_from_that_step() {
		$this->run_row();

		$this->assertTrue( \FMW_Workflow_Job::recover_interrupted( 42 ) );

		$row = $GLOBALS['wpdb']->row;
		$this->assertSame( 'queued', $row['status'], 'waiting for its retry, not "running"' );
		$this->assertSame( 'interrupted', $row['error_code'] );
		$this->assertSame( 'upload_design', $row['failed_step'] );
		$this->assertSame( 1, $row['retry_count'] );
		$this->assertCount( 1, self::$scheduled, 'the retry is scheduled' );
		$this->assertSame( 'fmw_run_workflow', self::$scheduled[0][1] );
		$this->assertEmpty( $this->fired( 'fmw_workflow_run_failed' ), 'no failure alert while a retry is pending' );
	}

	public function test_interrupted_at_a_fail_step_fails_and_alerts() {
		$this->run_row( [ 'resume_step_index' => 2 ] ); // team_notify has no on_error.

		\FMW_Workflow_Job::recover_interrupted( 42 );

		$this->assertSame( 'failed', $GLOBALS['wpdb']->row['status'] );
		$this->assertSame( 'interrupted', $GLOBALS['wpdb']->row['error_code'] );
		$failed = $this->fired( 'fmw_workflow_run_failed' );
		$this->assertCount( 1, $failed, 'the failure alert goes out' );
		$this->assertSame( 42, $failed[0][1] );
	}

	public function test_retries_run_out_then_it_fails_and_alerts() {
		$this->run_row( [ 'retry_count' => 3 ] );

		\FMW_Workflow_Job::recover_interrupted( 42 );

		$this->assertSame( 'failed', $GLOBALS['wpdb']->row['status'] );
		$this->assertCount( 1, $this->fired( 'fmw_workflow_run_failed' ) );
	}

	public function test_a_run_that_is_not_running_is_left_alone() {
		$this->run_row( [ 'status' => 'completed' ] );

		$this->assertFalse( \FMW_Workflow_Job::recover_interrupted( 42 ) );
		$this->assertSame( 'completed', $GLOBALS['wpdb']->row['status'] );
		$this->assertEmpty( self::$scheduled );
	}

	public function test_interrupted_is_a_retryable_error() {
		$this->assertTrue( ( new \FMW_Step_Exception( 'interrupted', 'x' ) )->is_retryable() );
	}

	public function test_only_workflow_actions_are_recovered() {
		eval( 'class ActionScheduler { public static $hook = ""; public static function store() { return new class { public function fetch_action( $id ) { return new class { public function get_hook() { return ActionScheduler::$hook; } public function get_args() { return [ 42 ]; } }; } }; } }' );
		$this->run_row();

		\ActionScheduler::$hook = 'woocommerce_cleanup_sessions';
		\FMW_Workflow_Job::on_action_died( 5 );
		$this->assertSame( 'running', $GLOBALS['wpdb']->row['status'], 'another plugin\'s action is none of our business' );

		\ActionScheduler::$hook = 'fmw_run_workflow';
		\FMW_Workflow_Job::on_action_died( 5 );
		$this->assertSame( 'queued', $GLOBALS['wpdb']->row['status'] );
	}

	public function test_sweep_cutoff_is_in_site_local_time() {
		// started_at is stored with current_time( 'mysql' ) (site-local). A
		// UTC cut-off would sweep hours early or late outside UTC.
		$seen = [];
		Functions\when( 'wp_date' )->alias( function ( $format, $ts ) use ( &$seen ) {
			$seen[] = $ts;
			return '2026-09-21 07:30:00';
		} );
		$sql = null;
		$GLOBALS['wpdb'] = new class( $sql ) {
			public $prefix = 'wp_';
			public $last   = '';
			public function __construct( &$s ) {}
			public function prepare( $q, ...$a ) { return vsprintf( str_replace( '%s', "'%s'", $q ), $a ); }
			public function get_col( $q ) { $this->last = $q; return []; }
		};

		$this->assertSame( 0, \FMW_Workflow_Job::sweep_interrupted_runs() );
		$this->assertCount( 1, $seen, 'the cut-off comes from wp_date()' );
		$this->assertEqualsWithDelta( time() - 1800, $seen[0], 5 );
		$this->assertStringContainsString( "started_at < '2026-09-21 07:30:00'", $GLOBALS['wpdb']->last );
	}

	public function test_listeners_are_registered() {
		$hooks = [];
		Functions\when( 'add_action' )->alias( function ( $hook ) use ( &$hooks ) { $hooks[] = $hook; } );

		\FMW_Workflow_Job::register();

		foreach ( [ 'fmw_run_workflow', 'action_scheduler_unexpected_shutdown', 'action_scheduler_failed_execution', 'fmw_sweep_interrupted_runs', 'action_scheduler_init' ] as $hook ) {
			$this->assertContains( $hook, $hooks );
		}
	}
}
