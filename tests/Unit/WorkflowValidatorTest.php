<?php
/**
 * Unit tests for FMW_Workflow_Validator.
 *
 * The validator is the gatekeeper for every workflow JSON definition
 * that gets persisted — admin-saved, MCP-created, REST API-pushed.
 * If a malformed definition slips through, the executor encounters
 * it at run time when remediation is harder. Tests pin every
 * documented rejection path so the contract is observable.
 *
 * Coverage focus (security + correctness):
 *   - Required-field enforcement (steps array)
 *   - Type checking (settings is object, config is object, etc.)
 *   - Step uniqueness (duplicate names rejected)
 *   - Step type registration check (unknown types rejected)
 *   - Enum validation (on_error must be in allow-list)
 *   - Soft-warning surface (file step after delete_entry)
 *
 * @package FlowMintWorkflows\Tests\Unit
 */

namespace FMW\Tests\Unit;

use Brain\Monkey\Functions;

class WorkflowValidatorTest extends UnitTestCase {

    protected function set_up() {
        parent::set_up();

        // The validator references FMW_Step_Registry::instance(). The
        // registry's exists() method needs to be answerable for our
        // test step types. Stub the registry minimally — a singleton
        // that returns true for known types and false for the rest.
        //
        // The class_exists check passes `false` as the second argument
        // to disable autoload — without that, class_exists triggers
        // the autoloader, the real (empty) registry gets loaded, and
        // our test stub silently never runs. Disabling autoload means
        // we control which version of FMW_Step_Registry the validator
        // sees during tests.
        if ( ! class_exists( '\\FMW_Step_Registry', false ) ) {
            eval( 'class FMW_Step_Registry {
                private static $instance;
                private $known = [
                    "set_variable", "conditional", "delay",
                    "log_info", "log_warning", "log_error",
                    "fre_get_entry", "fre_get_file", "fre_update_entry_status", "fre_delete_entry",
                    "drive_find_folder", "drive_find_or_create_folder", "drive_create_folder", "drive_upload_file",
                    "send_email", "send_email_template",
                    "printavo_find_customer", "printavo_create_customer", "printavo_find_or_create_customer", "printavo_create_quote",
                    "http_get", "http_post", "http_request",
                ];
                public static function instance() {
                    if ( ! self::$instance ) self::$instance = new self();
                    return self::$instance;
                }
                public function exists( $type ) { return in_array( $type, $this->known, true ); }
            }' );
        }

        require_once FMW_TEST_PLUGIN_DIR . 'includes/Core/class-fmw-workflow-validator.php';
    }

    /**
     * Helper to build a minimal valid workflow config.
     */
    private function valid_config( array $overrides = array() ) {
        return array_merge( array(
            'steps' => array(
                array( 'name' => 'log_it', 'type' => 'log_info', 'config' => array() ),
            ),
        ), $overrides );
    }

    // -----------------------------------------------------------------
    // Required-field enforcement
    // -----------------------------------------------------------------

    public function test_missing_steps_field_fails_validation() {
        $result = \FMW_Workflow_Validator::validate( array() );

        $this->assertFalse( $result['valid'] );
        $this->assertNotEmpty( $result['errors'] );
        $this->assertStringContainsString( 'steps', $result['errors'][0] );
    }

    public function test_non_array_steps_fails_validation() {
        $result = \FMW_Workflow_Validator::validate( array( 'steps' => 'not-an-array' ) );

        $this->assertFalse( $result['valid'] );
        $this->assertStringContainsString( 'steps', $result['errors'][0] );
    }

    public function test_settings_must_be_object_when_provided() {
        $result = \FMW_Workflow_Validator::validate( $this->valid_config( array(
            'settings' => 'not-an-object',
        ) ) );

        $this->assertFalse( $result['valid'] );
        $this->assertStringContainsString( 'settings', implode( ' ', $result['errors'] ) );
    }

    // -----------------------------------------------------------------
    // Step structural validation
    // -----------------------------------------------------------------

    public function test_step_must_be_array() {
        $result = \FMW_Workflow_Validator::validate( array(
            'steps' => array( 'not-an-object' ),
        ) );

        $this->assertFalse( $result['valid'] );
        $this->assertStringContainsString( 'must be an object', $result['errors'][0] );
    }

    public function test_step_missing_name_fails() {
        $result = \FMW_Workflow_Validator::validate( array(
            'steps' => array(
                array( 'type' => 'log_info' ),  // no name
            ),
        ) );

        $this->assertFalse( $result['valid'] );
        $this->assertStringContainsString( 'name', $result['errors'][0] );
    }

    public function test_step_missing_type_fails() {
        $result = \FMW_Workflow_Validator::validate( array(
            'steps' => array(
                array( 'name' => 'unnamed_type' ),  // no type
            ),
        ) );

        $this->assertFalse( $result['valid'] );
        $this->assertStringContainsString( 'type', $result['errors'][0] );
    }

    public function test_unknown_step_type_fails() {
        $result = \FMW_Workflow_Validator::validate( array(
            'steps' => array(
                array(
                    'name'   => 'do_something',
                    'type'   => 'this_step_type_was_never_registered',
                    'config' => array(),
                ),
            ),
        ) );

        $this->assertFalse( $result['valid'] );
        $this->assertStringContainsString( 'unknown step type', $result['errors'][0] );
    }

    public function test_duplicate_step_names_fail() {
        $result = \FMW_Workflow_Validator::validate( array(
            'steps' => array(
                array( 'name' => 'shared', 'type' => 'log_info', 'config' => array() ),
                array( 'name' => 'shared', 'type' => 'log_warning', 'config' => array() ),
            ),
        ) );

        $this->assertFalse( $result['valid'] );
        $this->assertStringContainsString( 'Duplicate', $result['errors'][0] );
    }

    public function test_invalid_on_error_value_fails() {
        $result = \FMW_Workflow_Validator::validate( array(
            'steps' => array(
                array(
                    'name'     => 'risky',
                    'type'     => 'log_info',
                    'config'   => array(),
                    'on_error' => 'silently_swallow',  // not in fail/continue/retry
                ),
            ),
        ) );

        $this->assertFalse( $result['valid'] );
        $this->assertStringContainsString( 'on_error', $result['errors'][0] );
    }

    public function test_each_allowed_on_error_value_passes() {
        foreach ( array( 'fail', 'continue', 'retry' ) as $allowed ) {
            $result = \FMW_Workflow_Validator::validate( array(
                'steps' => array(
                    array(
                        'name'     => 'step',
                        'type'     => 'log_info',
                        'config'   => array(),
                        'on_error' => $allowed,
                    ),
                ),
            ) );

            $this->assertTrue( $result['valid'], "on_error='{$allowed}' must be accepted." );
        }
    }

    public function test_non_object_config_fails() {
        $result = \FMW_Workflow_Validator::validate( array(
            'steps' => array(
                array(
                    'name'   => 'step',
                    'type'   => 'log_info',
                    'config' => 'not-an-object',  // must be array
                ),
            ),
        ) );

        $this->assertFalse( $result['valid'] );
        $this->assertStringContainsString( 'config', implode( ' ', $result['errors'] ) );
    }

    // -----------------------------------------------------------------
    // Happy paths
    // -----------------------------------------------------------------

    public function test_minimal_valid_workflow_validates_cleanly() {
        $result = \FMW_Workflow_Validator::validate( $this->valid_config() );

        $this->assertTrue( $result['valid'] );
        $this->assertEmpty( $result['errors'] );
    }

    public function test_multi_step_valid_workflow_validates_cleanly() {
        $result = \FMW_Workflow_Validator::validate( array(
            'settings' => array( 'max_retries' => 3 ),
            'steps'    => array(
                array( 'name' => 'a', 'type' => 'log_info', 'config' => array() ),
                array( 'name' => 'b', 'type' => 'send_email', 'config' => array() ),
                array( 'name' => 'c', 'type' => 'fre_delete_entry', 'config' => array() ),
            ),
        ) );

        $this->assertTrue( $result['valid'] );
    }

    // -----------------------------------------------------------------
    // Soft warnings — surface ordering issues without rejecting
    //
    // The author may legitimately want to delete the entry before some
    // non-file step runs (e.g., logging). Hard-rejecting any step
    // after fre_delete_entry would be too strict. But if a file-reading
    // step appears after delete, that's almost certainly a mistake —
    // surface it as a warning so the author can fix or override.
    // -----------------------------------------------------------------

    public function test_warning_when_file_step_appears_after_delete_entry() {
        $result = \FMW_Workflow_Validator::validate( array(
            'steps' => array(
                array( 'name' => 'cleanup', 'type' => 'fre_delete_entry', 'config' => array() ),
                array( 'name' => 'too_late', 'type' => 'drive_upload_file', 'config' => array() ),
            ),
        ) );

        // It's still "valid" (no hard error), but warnings surface the issue.
        $this->assertTrue( $result['valid'], 'File-after-delete is a warning, not a hard rejection.' );
        $this->assertNotEmpty( $result['warnings'] );
        $this->assertStringContainsString( 'too_late', $result['warnings'][0] );
    }

    public function test_no_warning_when_file_step_precedes_delete_entry() {
        $result = \FMW_Workflow_Validator::validate( array(
            'steps' => array(
                array( 'name' => 'upload', 'type' => 'drive_upload_file', 'config' => array() ),
                array( 'name' => 'cleanup', 'type' => 'fre_delete_entry', 'config' => array() ),
            ),
        ) );

        $this->assertTrue( $result['valid'] );
        $this->assertEmpty( $result['warnings'], 'Correct ordering must NOT trigger the warning.' );
    }

    // -----------------------------------------------------------------
    // validate_full() — id format + JSON parsing
    // -----------------------------------------------------------------

    public function test_validate_full_rejects_malformed_workflow_id() {
        $result = \FMW_Workflow_Validator::validate_full( array(
            'id'     => 'Bad ID With Spaces!',  // must match ^[a-z0-9-_]+$
            'config' => $this->valid_config(),
        ) );

        $this->assertFalse( $result['valid'] );
        $this->assertStringContainsString( 'workflow id', implode( ' ', $result['errors'] ) );
    }

    public function test_validate_full_rejects_malformed_json_config() {
        $result = \FMW_Workflow_Validator::validate_full( array(
            'id'     => 'good-id',
            'config' => '{"steps": [malformed json',  // missing closing
        ) );

        $this->assertFalse( $result['valid'] );
        $this->assertStringContainsString( 'JSON', implode( ' ', $result['errors'] ) );
    }

    public function test_validate_full_accepts_string_json_config() {
        // Storage uses LONGTEXT JSON; sometimes the validator gets the
        // raw string instead of an already-decoded array. Pin that
        // both shapes work.
        $result = \FMW_Workflow_Validator::validate_full( array(
            'id'     => 'good-id',
            'config' => json_encode( $this->valid_config() ),
        ) );

        $this->assertTrue( $result['valid'] );
    }
}
