<?php
/**
 * Steps that evaluate their own config — an expression, nested step lists,
 * a per-record template — must read it RAW, and the run history must record
 * it raw.
 *
 * Two defects of one class, both found by the 2026-09-19 pressure test:
 *
 *  - try_catch ran its nested steps from the PRE-interpolated config, so a
 *    step inside the try that referred to a value set earlier in the same
 *    try got an empty string ("var={{ vars.a }}" after set_variable a=hello
 *    logged "var="). The conditional had exactly this bug until v0.6.4.
 *  - every step's config_snapshot was the interpolated config, so a
 *    conditional's "if" was recorded as "" and a pre_upsert_records map as
 *    blanks — the run history could not show what was tested or mapped.
 *
 * @package FlowMintWorkflows\Tests\Unit
 */

namespace FMW\Tests\Unit;

require_once __DIR__ . '/UnitTestCase.php';

class RawConfigTest extends UnitTestCase {

    protected function set_up() {
        parent::set_up();
        require_once FMW_TEST_PLUGIN_DIR . 'includes/Core/class-fmw-workflow-context.php';
        require_once FMW_TEST_PLUGIN_DIR . 'includes/Core/class-fmw-interpolator.php';
        require_once FMW_TEST_PLUGIN_DIR . 'includes/Core/class-fmw-step-exception.php';
        require_once FMW_TEST_PLUGIN_DIR . 'includes/Core/class-fmw-step-base.php';
        require_once FMW_TEST_PLUGIN_DIR . 'includes/Core/class-fmw-workflow-executor.php';
        require_once FMW_TEST_PLUGIN_DIR . 'includes/Steps/Core/class-step-conditional.php';
        require_once FMW_TEST_PLUGIN_DIR . 'includes/Steps/Core/class-step-try-catch.php';
        require_once FMW_TEST_PLUGIN_DIR . 'includes/Steps/Pre/class-step-pre-upsert-records.php';
    }

    public function test_the_evaluating_steps_declare_their_raw_keys() {
        $this->assertSame( [ 'if', 'then', 'else' ], \FMW_Step_Conditional::raw_config_keys() );
        $this->assertSame( [ 'try', 'catch' ], \FMW_Step_Try_Catch::raw_config_keys() );
        $this->assertSame( [ 'map' ], \FMW_Step_Pre_Upsert_Records::raw_config_keys() );
        $this->assertSame( [], \FMW_Step_Base::raw_config_keys(), 'other steps record resolved values' );
    }

    public function test_the_snapshot_keeps_declared_keys_as_written_and_resolves_the_rest() {
        $raw = [
            'if'   => "{{ data.service == 'pothole' }}",
            'then' => [ [ 'name' => 'mail', 'type' => 'send_email', 'config' => [ 'to' => '{{ data.email }}' ] ] ],
        ];
        $interpolated = [ 'if' => '', 'then' => [ [ 'name' => 'mail', 'type' => 'send_email', 'config' => [ 'to' => 'pat@example.test' ] ] ] ];

        $snap = \FMW_Workflow_Executor::config_snapshot( 'FMW_Step_Conditional', $raw, $interpolated );
        $this->assertSame( "{{ data.service == 'pothole' }}", $snap['if'], 'the expression that was tested is visible' );
        $this->assertSame( '{{ data.email }}', $snap['then'][0]['config']['to'], 'nested steps record their own resolved config when they run' );

        $upsert = \FMW_Workflow_Executor::config_snapshot(
            'FMW_Step_Pre_Upsert_Records',
            [ 'post_type' => 'meeting', 'records' => '{{ steps.fetch.body }}', 'map' => [ 'title' => '{{ item.name }}' ] ],
            [ 'post_type' => 'meeting', 'records' => [ [ 'name' => 'A' ] ], 'map' => [ 'title' => '' ] ]
        );
        $this->assertSame( '{{ item.name }}', $upsert['map']['title'] );
        $this->assertSame( [ [ 'name' => 'A' ] ], $upsert['records'], 'resolved input stays resolved' );
    }

    public function test_a_step_without_declared_keys_records_the_resolved_config() {
        require_once FMW_TEST_PLUGIN_DIR . 'includes/Steps/Email/class-step-email-send.php';
        $snap = \FMW_Workflow_Executor::config_snapshot( 'FMW_Step_Email_Send', [ 'to' => '{{ data.email }}' ], [ 'to' => 'pat@example.test' ] );
        $this->assertSame( [ 'to' => 'pat@example.test' ], $snap );
    }

    public function test_try_catch_runs_its_nested_steps_from_the_raw_lists() {
        // Source guard: the sub-executor cannot be stood up in a unit test,
        // so pin the one line that decides which list runs.
        $src = file_get_contents( FMW_TEST_PLUGIN_DIR . 'includes/Steps/Core/class-step-try-catch.php' );
        $this->assertMatchesRegularExpression( "/\\\$try_steps\s*=\s*\\\$this->raw_config\['try'\]/", $src );
        $this->assertMatchesRegularExpression( "/\\\$catch_steps\s*=\s*\\\$this->raw_config\['catch'\]/", $src );
    }
}
