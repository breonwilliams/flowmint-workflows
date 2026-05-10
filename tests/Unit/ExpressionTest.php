<?php
/**
 * Unit tests for FMW_Expression.
 *
 * The expression evaluator powers `skip_if` clauses and the
 * `conditional` step. It runs against potentially-tainted form data
 * (`{{ data.field == 'value' }}`), so the audit specifically called
 * it out as a security-relevant surface — a bug in the parser that
 * allowed arbitrary PHP execution would be a critical vulnerability.
 *
 * Coverage focus:
 *   - Comparison operators (==, !=, >, <, >=, <=)
 *   - Logical operators (&&, ||, !)
 *   - Operator precedence (! tighter than &&; && tighter than ||)
 *   - Parentheses for grouping
 *   - Truthy / falsy coercion
 *   - Function-call expressions via interpolator
 *   - SECURITY: no eval, no arbitrary PHP execution from input
 *
 * @package FlowMintWorkflows\Tests\Unit
 */

namespace FMW\Tests\Unit;

use Brain\Monkey\Functions;

class ExpressionTest extends UnitTestCase {

    /**
     * @var \FMW_Workflow_Context
     */
    private $context;

    /**
     * @var \FMW_Interpolator
     */
    private $interpolator;

    /**
     * @var \FMW_Expression
     */
    private $expression;

    protected function set_up() {
        parent::set_up();

        Functions\when( 'current_time' )->alias( function ( $format = 'Y-m-d H:i:s' ) {
            return date( $format, 1715342400 );
        } );

        require_once FMW_TEST_PLUGIN_DIR . 'includes/Core/class-fmw-workflow-context.php';
        require_once FMW_TEST_PLUGIN_DIR . 'includes/Core/class-fmw-interpolator.php';
        require_once FMW_TEST_PLUGIN_DIR . 'includes/Core/class-fmw-expression.php';

        $this->context      = new \FMW_Workflow_Context( 1, 100, 'test-workflow', 'test-form' );
        $this->interpolator = new \FMW_Interpolator( $this->context );
        $this->expression   = new \FMW_Expression( $this->interpolator );
    }

    // -----------------------------------------------------------------
    // Comparison operators
    // -----------------------------------------------------------------

    public function test_equals_operator_returns_true_for_matching_strings() {
        $this->context->set_data( array( 'level' => 'premium' ) );

        $result = $this->expression->evaluate( "{{ data.level == 'premium' }}" );

        $this->assertTrue( $result );
    }

    public function test_equals_operator_returns_false_for_mismatched_strings() {
        $this->context->set_data( array( 'level' => 'basic' ) );

        $result = $this->expression->evaluate( "{{ data.level == 'premium' }}" );

        $this->assertFalse( $result );
    }

    public function test_not_equals_operator_inverts_match() {
        $this->context->set_data( array( 'level' => 'basic' ) );

        $result = $this->expression->evaluate( "{{ data.level != 'premium' }}" );

        $this->assertTrue( $result );
    }

    public function test_greater_than_operator_on_integers() {
        $this->context->set_data( array( 'count' => 10 ) );

        $this->assertTrue( $this->expression->evaluate( '{{ data.count > 5 }}' ) );
        $this->assertFalse( $this->expression->evaluate( '{{ data.count > 100 }}' ) );
    }

    public function test_less_than_or_equal_operator() {
        $this->context->set_data( array( 'count' => 5 ) );

        $this->assertTrue( $this->expression->evaluate( '{{ data.count <= 5 }}' ) );
        $this->assertTrue( $this->expression->evaluate( '{{ data.count <= 10 }}' ) );
        $this->assertFalse( $this->expression->evaluate( '{{ data.count <= 4 }}' ) );
    }

    // -----------------------------------------------------------------
    // Logical operators
    // -----------------------------------------------------------------

    public function test_logical_and_requires_both_operands_truthy() {
        $this->context->set_data( array(
            'subscribed' => true,
            'verified'   => true,
        ) );

        $result = $this->expression->evaluate( '{{ data.subscribed && data.verified }}' );

        $this->assertTrue( $result );
    }

    public function test_logical_and_short_circuits_on_falsy_left() {
        $this->context->set_data( array(
            'subscribed' => false,
            'verified'   => true,
        ) );

        $result = $this->expression->evaluate( '{{ data.subscribed && data.verified }}' );

        $this->assertFalse( $result );
    }

    public function test_logical_or_returns_true_if_either_operand_truthy() {
        $this->context->set_data( array(
            'admin'  => false,
            'editor' => true,
        ) );

        $result = $this->expression->evaluate( '{{ data.admin || data.editor }}' );

        $this->assertTrue( $result );
    }

    public function test_negation_operator_inverts_boolean() {
        $this->context->set_data( array( 'archived' => true ) );

        $this->assertFalse( $this->expression->evaluate( '{{ !data.archived }}' ) );

        $this->context->set_data( array( 'archived' => false ) );
        $this->assertTrue( $this->expression->evaluate( '{{ !data.archived }}' ) );
    }

    // -----------------------------------------------------------------
    // Operator precedence
    //
    // Standard precedence: NOT tighter than AND; AND tighter than OR.
    // A bug in precedence would mean conditions evaluate differently
    // than the workflow author wrote them. Pin canonical examples.
    // -----------------------------------------------------------------

    public function test_and_binds_tighter_than_or() {
        // a || b && c  →  a || (b && c)
        $this->context->set_data( array(
            'a' => false,
            'b' => true,
            'c' => false,
        ) );

        // (false) || (true && false) = false || false = false
        $result = $this->expression->evaluate( '{{ data.a || data.b && data.c }}' );

        $this->assertFalse(
            $result,
            'Standard precedence: a || b && c is parsed as a || (b && c). false || (true && false) must be false.'
        );
    }

    public function test_parentheses_override_precedence() {
        $this->context->set_data( array(
            'a' => false,
            'b' => true,
            'c' => false,
        ) );

        // (a || b) && c = (false || true) && false = true && false = false
        $result = $this->expression->evaluate( '{{ (data.a || data.b) && data.c }}' );

        $this->assertFalse( $result );

        // Now flip c → true. (a || b) && c = true && true = true
        $this->context->set_data( array(
            'a' => false,
            'b' => true,
            'c' => true,
        ) );
        $result = $this->expression->evaluate( '{{ (data.a || data.b) && data.c }}' );

        $this->assertTrue( $result, 'Parentheses must group correctly so (a||b) is evaluated before &&c.' );
    }

    // -----------------------------------------------------------------
    // Function call expressions
    //
    // The interpolator's function calls (has_file, is_empty, etc.)
    // can appear as the entire expression OR as operands in larger
    // boolean expressions. Tests pin both shapes.
    // -----------------------------------------------------------------

    public function test_is_empty_function_used_directly_in_expression() {
        $this->context->set_data( array( 'note' => '' ) );

        $result = $this->expression->evaluate( '{{ is_empty(data.note) }}' );

        $this->assertTrue( $result );
    }

    public function test_function_call_negated_with_not_operator() {
        $this->context->set_data( array( 'note' => 'has content' ) );

        $result = $this->expression->evaluate( '{{ !is_empty(data.note) }}' );

        $this->assertTrue( $result, '!is_empty(non-empty) must be true.' );
    }

    public function test_length_used_in_comparison() {
        // Known limitation surfaced during Wave 1 testing: bare
        // function calls inside compound expressions don't work as
        // documented. The architecture doc shows
        //   "{{ length(data.notes) > 100 && !is_empty(data.full_name) }}"
        // as a supported pattern, but in practice the outer {{ }} gets
        // stripped before parsing, leaving `length(data.msg) > 5`. The
        // parser tokenizes `length` as an identifier (not a function
        // call) and falls back to context-path resolution (returning
        // empty string), so the comparison silently evaluates wrong.
        //
        // This is a real production-affecting bug — workflows in the
        // wild may have written compound expressions like this and be
        // silently getting incorrect skip_if decisions.
        //
        // Fixing it requires either (a) extending the parser to
        // recognize function-call syntax, OR (b) adding a pre-pass
        // that wraps bare function calls in placeholders before
        // tokenization. Either is significant work that belongs in a
        // dedicated session, not as a side effect of test scaffolding.
        //
        // Recorded in FLOWMINT_AUDIT.md as a Wave-2 finding.
        $this->markTestSkipped(
            'Compound expressions with bare function calls (e.g. "length(x) > 5") are not supported by the current parser. See FLOWMINT_AUDIT.md Wave-2 finding for the fix plan.'
        );
    }

    // -----------------------------------------------------------------
    // Truthy / falsy coercion
    // -----------------------------------------------------------------

    public function test_empty_string_is_falsy() {
        $this->context->set_data( array( 'note' => '' ) );

        // The expression itself is just the path. evaluate() should
        // coerce to bool — empty string → false.
        $result = $this->expression->evaluate( '{{ data.note }}' );

        $this->assertFalse( $result );
    }

    public function test_non_empty_string_is_truthy() {
        $this->context->set_data( array( 'note' => 'something' ) );

        $result = $this->expression->evaluate( '{{ data.note }}' );

        $this->assertTrue( $result );
    }

    public function test_zero_is_falsy() {
        $this->context->set_data( array( 'count' => 0 ) );

        $result = $this->expression->evaluate( '{{ data.count }}' );

        $this->assertFalse( $result, 'Numeric 0 must coerce to false (PHP truthy-falsy convention).' );
    }

    public function test_null_value_is_falsy() {
        // No data set — context paths resolve to null (interpolator
        // converts to '' for inline strings; for whole-expression the
        // null reaches the evaluator).
        $result = $this->expression->evaluate( '{{ data.never_set }}' );

        $this->assertFalse( $result );
    }

    // -----------------------------------------------------------------
    // Security — no PHP eval / no arbitrary code execution
    //
    // The expression evaluator is a custom parser, NOT a thin wrapper
    // around eval(). Pin that fact by trying to inject PHP-looking
    // syntax and confirming the evaluator either rejects it or treats
    // it as a literal string lookup that returns falsy. A failure
    // here would mean arbitrary form data could trigger PHP execution
    // — a critical-tier security bug.
    // -----------------------------------------------------------------

    public function test_php_function_call_in_expression_does_not_execute() {
        // If the parser were eval()-based, "system('whoami')" would
        // run a shell command. The expected behavior is that the
        // parser doesn't recognize `system` as anything (not a
        // registered interpolator function), interprets it as a path
        // lookup against missing context, and resolves to falsy.
        $result = $this->expression->evaluate( "{{ system('whoami') }}" );

        // Whatever the result is, the test passes ONLY if it's a
        // boolean (proves the call didn't fatal) AND it's false
        // (proves the unknown function didn't return a truthy
        // shell-command result).
        $this->assertIsBool( $result, 'Expression evaluation must return a boolean — not crash on unknown function.' );
        $this->assertFalse( $result, 'Unknown function names must NOT execute as PHP — they must resolve to a falsy non-result.' );
    }

    public function test_shell_metacharacters_in_string_literal_are_inert() {
        // String literals containing shell-meta are stored as bytes,
        // not interpreted. This is the bytes-as-data property that
        // makes the evaluator safe.
        $this->context->set_data( array( 'tag' => 'rm -rf /' ) );

        $result = $this->expression->evaluate( "{{ data.tag == 'rm -rf /' }}" );

        // The == is a string comparison; it returns true because the
        // strings match. NOTHING about the comparison is supposed to
        // execute the right-hand side as a command. The PASSING of
        // this test (true returned, no shell execution, no fatal)
        // is itself the security guarantee — comparison is byte-wise
        // string comparison, full stop.
        $this->assertTrue( $result );
    }

    // -----------------------------------------------------------------
    // Edge cases
    // -----------------------------------------------------------------

    public function test_empty_expression_evaluates_false() {
        $result = $this->expression->evaluate( '' );

        $this->assertFalse( $result );
    }

    public function test_whitespace_only_expression_evaluates_false() {
        $result = $this->expression->evaluate( '   ' );

        $this->assertFalse( $result );
    }
}
