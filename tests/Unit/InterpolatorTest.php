<?php
/**
 * Unit tests for FMW_Interpolator.
 *
 * The interpolator is the {{ variable }} substitution layer that runs
 * over every step's config before execution. It's the bridge between
 * untrusted form-submitted data and connector calls — every workflow
 * passes user input through this code. The audit specifically called
 * it out as worth focused unit testing.
 *
 * Coverage focus:
 *   - Path resolution against context
 *   - Missing-variable defaults (empty string, NOT undefined)
 *   - Fallback `||` operator
 *   - Type preservation when entire string is one {{ expr }}
 *   - String literals, numeric, boolean, null literals
 *   - Function calls (now, has_file, is_empty, length, contains, equals_ci)
 *   - Recursive array interpolation
 *
 * @package FlowMintWorkflows\Tests\Unit
 */

namespace FMW\Tests\Unit;

use Brain\Monkey\Functions;

class InterpolatorTest extends UnitTestCase {

    /**
     * Real FMW_Workflow_Context. We use the real class rather than a
     * mock because the interpolator's interaction with the context is
     * non-trivial (resolve_path) and stubbing it correctly is more
     * fragile than just constructing a real one.
     *
     * @var \FMW_Workflow_Context
     */
    private $context;

    /**
     * @var \FMW_Interpolator
     */
    private $interpolator;

    protected function set_up() {
        parent::set_up();

        // current_time is used by the now() function — mock to a
        // deterministic value so test assertions are stable.
        Functions\when( 'current_time' )->alias( function ( $format = 'Y-m-d H:i:s' ) {
            return date( $format, 1715342400 );  // 2024-05-10 12:00:00 UTC
        } );

        require_once FMW_TEST_PLUGIN_DIR . 'includes/Core/class-fmw-workflow-context.php';
        require_once FMW_TEST_PLUGIN_DIR . 'includes/Core/class-fmw-interpolator.php';

        $this->context      = new \FMW_Workflow_Context( 1, 100, 'test-workflow', 'test-form' );
        $this->interpolator = new \FMW_Interpolator( $this->context );
    }

    // -----------------------------------------------------------------
    // Basic path resolution
    // -----------------------------------------------------------------

    public function test_simple_path_resolves_to_context_value() {
        $this->context->set_data( array( 'email' => 'alice@example.com' ) );

        $result = $this->interpolator->interpolate( '{{ data.email }}' );

        $this->assertSame( 'alice@example.com', $result );
    }

    public function test_inline_path_in_string_substitutes_correctly() {
        $this->context->set_data( array( 'name' => 'Alice' ) );

        $result = $this->interpolator->interpolate( 'Hi {{ data.name }}, welcome!' );

        $this->assertSame( 'Hi Alice, welcome!', $result );
    }

    public function test_multiple_paths_in_one_string_all_substitute() {
        $this->context->set_data( array(
            'first' => 'Alice',
            'last'  => 'Wonderland',
        ) );

        $result = $this->interpolator->interpolate( '{{ data.first }} {{ data.last }}' );

        $this->assertSame( 'Alice Wonderland', $result );
    }

    // -----------------------------------------------------------------
    // Missing-variable defaults — security boundary
    //
    // The contract: missing variables resolve to empty string, not
    // undefined or PHP-warning-emitting null access. This is what
    // prevents a typo in workflow JSON from crashing the executor.
    // -----------------------------------------------------------------

    public function test_missing_path_resolves_to_empty_string_not_warning() {
        // No data set on context — every path is "missing".
        $result = $this->interpolator->interpolate( '{{ data.does_not_exist }}' );

        $this->assertSame(
            '',
            $result,
            'Missing variable must resolve to empty string — never null, never an error.'
        );
    }

    public function test_inline_missing_path_substitutes_empty_string() {
        $result = $this->interpolator->interpolate( 'Hello, {{ data.missing }}!' );

        $this->assertSame( 'Hello, !', $result );
    }

    // -----------------------------------------------------------------
    // Fallback `||` operator
    //
    // Documented use case: {{ data.company || data.full_name }} —
    // returns the first truthy value, or empty string if all falsy.
    // This is the primary way workflow authors handle optional fields.
    // -----------------------------------------------------------------

    public function test_fallback_returns_first_truthy_value() {
        $this->context->set_data( array(
            'company'   => 'Acme Inc',
            'full_name' => 'Alice',
        ) );

        $result = $this->interpolator->interpolate( '{{ data.company || data.full_name }}' );

        $this->assertSame( 'Acme Inc', $result );
    }

    public function test_fallback_skips_empty_first_value() {
        $this->context->set_data( array(
            'company'   => '',          // empty
            'full_name' => 'Alice',
        ) );

        $result = $this->interpolator->interpolate( '{{ data.company || data.full_name }}' );

        $this->assertSame( 'Alice', $result, 'Empty first operand must fall through to the second.' );
    }

    public function test_fallback_chain_of_three_operands() {
        $this->context->set_data( array(
            'first'  => '',
            'second' => '',
            'third'  => 'Bob',
        ) );

        $result = $this->interpolator->interpolate( '{{ data.first || data.second || data.third }}' );

        $this->assertSame( 'Bob', $result, 'Multi-operand fallback must walk the chain.' );
    }

    public function test_fallback_all_falsy_returns_empty_string() {
        $this->context->set_data( array( 'a' => '', 'b' => '' ) );

        $result = $this->interpolator->interpolate( '{{ data.a || data.b }}' );

        $this->assertSame( '', $result );
    }

    // -----------------------------------------------------------------
    // Type preservation
    //
    // When the ENTIRE string is one {{ expr }}, the resolved value's
    // native type is preserved (bool, int, array). When the expression
    // is embedded in a larger string, it's stringified.
    // -----------------------------------------------------------------

    public function test_whole_string_match_preserves_boolean_type() {
        $this->context->set_data( array( 'is_admin' => true ) );

        $result = $this->interpolator->interpolate( '{{ data.is_admin }}' );

        $this->assertSame( true, $result, 'Whole-string match must preserve boolean — used by skip_if conditions.' );
    }

    public function test_whole_string_match_preserves_integer_type() {
        $this->context->set_data( array( 'count' => 42 ) );

        $result = $this->interpolator->interpolate( '{{ data.count }}' );

        $this->assertSame( 42, $result );
    }

    public function test_inline_match_stringifies_value() {
        $this->context->set_data( array( 'count' => 42 ) );

        $result = $this->interpolator->interpolate( 'Count: {{ data.count }}' );

        $this->assertSame( 'Count: 42', $result );
    }

    // -----------------------------------------------------------------
    // Literal expressions
    // -----------------------------------------------------------------

    public function test_string_literal_in_double_quotes_resolves_to_string() {
        $result = $this->interpolator->interpolate( '{{ "hello" }}' );

        $this->assertSame( 'hello', $result );
    }

    public function test_string_literal_in_single_quotes_resolves_to_string() {
        $result = $this->interpolator->interpolate( "{{ 'hello' }}" );

        $this->assertSame( 'hello', $result );
    }

    public function test_integer_literal_resolves_to_int() {
        $result = $this->interpolator->interpolate( '{{ 42 }}' );

        $this->assertSame( 42, $result );
    }

    public function test_float_literal_resolves_to_float() {
        $result = $this->interpolator->interpolate( '{{ 3.14 }}' );

        $this->assertSame( 3.14, $result );
    }

    public function test_boolean_literal_true_resolves_to_bool_true() {
        $result = $this->interpolator->interpolate( '{{ true }}' );

        $this->assertSame( true, $result );
    }

    public function test_boolean_literal_false_resolves_to_bool_false() {
        $result = $this->interpolator->interpolate( '{{ false }}' );

        $this->assertSame( false, $result );
    }

    public function test_null_literal_resolves_to_null() {
        $result = $this->interpolator->interpolate( '{{ null }}' );

        $this->assertNull( $result );
    }

    // -----------------------------------------------------------------
    // Function calls
    // -----------------------------------------------------------------

    public function test_now_function_with_format_returns_formatted_date() {
        $result = $this->interpolator->interpolate( "{{ now('Y-m') }}" );

        $this->assertSame( '2024-05', $result );
    }

    public function test_is_empty_returns_true_for_empty_string() {
        $this->context->set_data( array( 'note' => '' ) );

        $result = $this->interpolator->interpolate( '{{ is_empty(data.note) }}' );

        $this->assertTrue( $result );
    }

    public function test_is_empty_returns_false_for_non_empty_string() {
        $this->context->set_data( array( 'note' => 'hi' ) );

        $result = $this->interpolator->interpolate( '{{ is_empty(data.note) }}' );

        $this->assertFalse( $result );
    }

    public function test_length_function_on_string_returns_character_count() {
        $this->context->set_data( array( 'msg' => 'hello' ) );

        $result = $this->interpolator->interpolate( '{{ length(data.msg) }}' );

        $this->assertSame( 5, $result );
    }

    public function test_contains_returns_true_when_needle_present_in_string() {
        $this->context->set_data( array( 'tags' => 'urgent,review' ) );

        $result = $this->interpolator->interpolate( "{{ contains(data.tags, 'urgent') }}" );

        $this->assertTrue( $result );
    }

    public function test_contains_returns_false_when_needle_absent() {
        $this->context->set_data( array( 'tags' => 'normal' ) );

        $result = $this->interpolator->interpolate( "{{ contains(data.tags, 'urgent') }}" );

        $this->assertFalse( $result );
    }

    public function test_equals_ci_is_case_insensitive() {
        $this->context->set_data( array( 'level' => 'PREMIUM' ) );

        $result = $this->interpolator->interpolate( "{{ equals_ci(data.level, 'premium') }}" );

        $this->assertTrue( $result );
    }

    // -----------------------------------------------------------------
    // Recursive array interpolation
    // -----------------------------------------------------------------

    public function test_array_values_are_recursively_interpolated() {
        $this->context->set_data( array(
            'email' => 'alice@example.com',
            'name'  => 'Alice',
        ) );

        $config = array(
            'to'        => '{{ data.email }}',
            'subject'   => 'Hi {{ data.name }}',
            'metadata'  => array(
                'recipient_name' => '{{ data.name }}',
            ),
        );

        $result = $this->interpolator->interpolate( $config );

        $this->assertSame( 'alice@example.com', $result['to'] );
        $this->assertSame( 'Hi Alice', $result['subject'] );
        $this->assertSame( 'Alice', $result['metadata']['recipient_name'], 'Nested arrays must also be interpolated.' );
    }

    public function test_non_string_scalar_values_pass_through_unchanged() {
        $config = array(
            'count'  => 42,           // int — no interpolation needed
            'active' => true,          // bool — no interpolation needed
            'rate'   => 3.14,          // float
            'opt'    => null,          // null
        );

        $result = $this->interpolator->interpolate( $config );

        $this->assertSame( 42, $result['count'] );
        $this->assertSame( true, $result['active'] );
        $this->assertSame( 3.14, $result['rate'] );
        $this->assertNull( $result['opt'] );
    }
}
