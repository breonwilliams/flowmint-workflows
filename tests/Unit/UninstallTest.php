<?php
/**
 * Deleting FlowMint keeps workflows, runs and credentials unless the owner
 * opted in with FMW_REMOVE_ALL_DATA.
 *
 * Up to 0.9.0 uninstall.php dropped the tables and deleted the credentials on
 * every deletion — and left the connector switch behind. Housekeeping now
 * always goes, data only with the constant. The credential install nonce is
 * part of the encryption key, so it is kept whenever the credentials are.
 *
 * Runs the real uninstall.php against a recording $wpdb, one process per test
 * (the file declares a function and runs at include time; constants persist).
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
class UninstallTest extends UnitTestCase {

	/** @var string[] options deleted by name */
	private $deleted_options = [];

	/** @var bool */
	private $unscheduled = false;

	private function run_uninstall() {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'flowmint-workflows/flowmint-workflows.php' );
		}
		$db = new class {
			public $prefix   = 'wp_';
			public $options  = 'wp_options';
			public $usermeta = 'wp_usermeta';
			public $queries  = [];
			public function query( $sql ) { $this->queries[] = preg_replace( '/\s+/', ' ', trim( $sql ) ); return 1; }
			public function prepare( $sql, ...$args ) { return vsprintf( str_replace( '%s', "'%s'", $sql ), $args ); }
			public function esc_like( $s ) { return addcslashes( $s, '_%\\' ); }
			public function delete( $t, $w ) { $this->queries[] = "DELETE {$t} " . json_encode( $w ); return 1; }
		};
		$GLOBALS['wpdb'] = $db;

		Functions\when( 'delete_option' )->alias( function ( $name ) { $this->deleted_options[] = $name; return true; } );
		Functions\when( 'as_unschedule_all_actions' )->alias( function () { $this->unscheduled = true; } );
		Functions\when( 'plugin_dir_path' )->justReturn( FMW_TEST_PLUGIN_DIR );
		Functions\when( 'wp_roles' )->justReturn( null );

		include FMW_TEST_PLUGIN_DIR . 'uninstall.php';
		return implode( "\n", $db->queries );
	}

	public function test_by_default_workflows_runs_and_credentials_are_kept() {
		$sql = $this->run_uninstall();

		$this->assertStringNotContainsString( 'DROP TABLE', $sql );
		$this->assertStringNotContainsString( "LIKE 'fmw\\_%'", $sql, 'credentials and the install nonce stay together' );
		$this->assertNotContains( 'fmw_credential_install_nonce', $this->deleted_options );

		// Housekeeping still happens.
		$this->assertTrue( $this->unscheduled, 'Action Scheduler jobs in the fmw group are removed' );
		$this->assertContains( 'fmw_connector_enabled', $this->deleted_options, 'the connector switch no longer survives uninstall' );
		$this->assertStringContainsString( '_transient_fmw_', $sql );
	}

	public function test_with_the_constant_everything_goes() {
		define( 'FMW_REMOVE_ALL_DATA', true );
		$sql = $this->run_uninstall();

		foreach ( [ 'fmw_workflow_run_steps', 'fmw_workflow_runs', 'fmw_workflows' ] as $table ) {
			$this->assertStringContainsString( "DROP TABLE IF EXISTS `wp_{$table}`", $sql );
		}
		$this->assertStringContainsString( "LIKE 'fmw\\_%'", $sql, 'every FlowMint option, credentials included' );
	}
}
