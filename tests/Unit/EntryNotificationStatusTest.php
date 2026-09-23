<?php
/**
 * What FlowMint tells Promptless Forms' Entries screen about an entry.
 *
 * Forms' Email column can only report Forms' own notification. When a workflow
 * sends the team email instead — the right setup, so nobody gets two — the
 * column had nothing to show, and a site owner read that as a delivery failure
 * against a real lead. We answer Forms' `pforms_entry_notification_status`
 * filter with our own record so the screen can say what actually happened.
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
class EntryNotificationStatusTest extends UnitTestCase {

    /** @var array The run row the fake repository returns. */
    public static $run = null;

    /** @var array[] The step rows the fake repository returns. */
    public static $steps = [];

    /** Forms' own status for an entry whose notification is switched off. */
    private $forms_status = [
        'state'       => 'off',
        'label'       => 'Off',
        'description' => "This form's own email notification is turned off.",
        'source'      => '',
        'url'         => '',
    ];

    protected function set_up() {
        parent::set_up();

        require_once FMW_TEST_PLUGIN_DIR . 'includes/Admin/class-fmw-entry-notification-status.php';

        self::$run   = null;
        self::$steps = [];

        Functions\when( 'admin_url' )->alias( function ( $path ) {
            return 'https://example.test/wp-admin/' . $path;
        } );
        Functions\when( '__' )->returnArg( 1 );
        Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults ) {
            return array_merge( $defaults, (array) $args );
        } );

        if ( ! defined( 'ARRAY_A' ) ) {
            define( 'ARRAY_A', 'ARRAY_A' );
        }

        // Stub the database rather than the repositories, so the real
        // queries in FMW_Run_Repository / FMW_Run_Step_Repository run.
        $GLOBALS['wpdb'] = new class {
            public $prefix = 'wp_';
            public function prepare( $sql, ...$args ) {
                if ( count( $args ) === 1 && is_array( $args[0] ) ) {
                    $args = $args[0];
                }
                return vsprintf( str_replace( [ '%d', '%s' ], [ '%d', "'%s'" ], $sql ), $args );
            }
            public function get_results( $sql, $output = null ) {
                if ( false !== strpos( $sql, 'run_steps' ) ) {
                    return EntryNotificationStatusTest::$steps;
                }
                return EntryNotificationStatusTest::$run ? [ EntryNotificationStatusTest::$run ] : [];
            }
            public function get_var( $sql ) {
                return 1;
            }
        };

        require_once FMW_TEST_PLUGIN_DIR . 'includes/Database/class-fmw-run-repository.php';
        require_once FMW_TEST_PLUGIN_DIR . 'includes/Database/class-fmw-run-step-repository.php';

        \FMW_Entry_Notification_Status::flush_cache();
    }

    protected function tear_down() {
        unset( $GLOBALS['wpdb'] );
        \FMW_Entry_Notification_Status::flush_cache();
        parent::tear_down();
    }

    private function report() {
        return ( new \FMW_Entry_Notification_Status() )->report( $this->forms_status, [ 'id' => 195 ] );
    }

    private function email_step( $status ) {
        return [ 'step_name' => 'team_notify', 'step_type' => 'send_email', 'status' => $status ];
    }

    // ── What we claim ───────────────────────────────────────────────────

    public function test_a_completed_run_whose_email_step_succeeded_reports_sent() {
        self::$run   = [ 'id' => 236, 'status' => 'completed' ];
        self::$steps = [ [ 'step_type' => 'drive_upload_file', 'status' => 'success' ], $this->email_step( 'success' ) ];

        $status = $this->report();

        $this->assertSame( 'external', $status['state'], 'our claim, not Forms own record' );
        $this->assertSame( 'Sent by workflow', $status['label'] );
        $this->assertSame( 'FlowMint Workflows', $status['source'], 'the screen attributes it to us' );
        $this->assertStringContainsString( 'run_id=236', $status['url'] );
    }

    public function test_a_failed_run_that_was_going_to_email_says_so() {
        // The entry is stored and the team email never went out. Before this,
        // the screen showed nothing at all for it.
        self::$run   = [ 'id' => 240, 'status' => 'failed' ];
        self::$steps = [ $this->email_step( 'pending' ) ];

        $status = $this->report();

        $this->assertSame( 'external', $status['state'] );
        $this->assertSame( 'Workflow failed', $status['label'] );
        $this->assertStringContainsString( 'run_id=240', $status['url'] );
    }

    public function test_a_run_still_going_says_so() {
        self::$run   = [ 'id' => 241, 'status' => 'running' ];
        self::$steps = [ $this->email_step( 'pending' ) ];

        $this->assertSame( 'Workflow running', $this->report()['label'] );
    }

    // ── What we must NOT claim ──────────────────────────────────────────

    public function test_a_completed_run_whose_email_step_failed_is_not_reported_as_sent() {
        self::$run   = [ 'id' => 242, 'status' => 'completed' ];
        self::$steps = [ $this->email_step( 'failure' ) ];

        $this->assertSame( $this->forms_status, $this->report(), 'a failed email step is not a send' );
    }

    public function test_a_workflow_that_sends_no_email_leaves_the_column_alone() {
        // A workflow that only files things in Drive has nothing to say about
        // the Email column, in success or in failure.
        foreach ( [ 'completed', 'failed', 'running' ] as $state ) {
            \FMW_Entry_Notification_Status::flush_cache();
            self::$run   = [ 'id' => 243, 'status' => $state ];
            self::$steps = [ [ 'step_type' => 'drive_upload_file', 'status' => 'success' ] ];

            $this->assertSame( $this->forms_status, $this->report(), "run state: {$state}" );
        }
    }

    public function test_an_entry_with_no_run_leaves_the_column_alone() {
        self::$run = null;

        $this->assertSame( $this->forms_status, $this->report() );
    }

    public function test_an_entry_id_we_cannot_read_leaves_the_column_alone() {
        self::$run = [ 'id' => 244, 'status' => 'completed' ];

        $this->assertSame(
            $this->forms_status,
            ( new \FMW_Entry_Notification_Status() )->report( $this->forms_status, [] )
        );
    }

    public function test_the_filter_is_registered_not_the_other_way_round() {
        // The coupling is one-way: we listen to Forms. Forms must never need
        // to know this class exists.
        $hooks = [];
        Functions\when( 'add_filter' )->alias( function ( $hook ) use ( &$hooks ) { $hooks[] = $hook; } );

        ( new \FMW_Entry_Notification_Status() )->init();

        $this->assertSame( [ 'pforms_entry_notification_status' ], $hooks );
    }
}
