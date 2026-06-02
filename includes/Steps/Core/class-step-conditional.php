<?php
/**
 * Step: conditional
 *
 * Runs one of two branches based on an expression.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Step_Conditional extends FMW_Step_Base {

    public static function type(): string {
        return 'conditional';
    }

    public static function display_name(): string {
        return 'Conditional Branch';
    }

    public static function category(): string {
        return 'Control flow';
    }

    public static function description(): string {
        return 'Evaluates an expression and runs one of two nested step lists based on the result.';
    }

    public static function config_schema(): array {
        return [
            'type'     => 'object',
            'required' => [ 'if', 'then' ],
            'properties' => [
                'if'   => [ 'type' => 'string', 'description' => 'Expression to evaluate (truthy/falsy).' ],
                'then' => [ 'type' => 'array',  'description' => 'Steps to execute if true.' ],
                'else' => [ 'type' => 'array',  'description' => 'Steps to execute if false.' ],
            ],
        ];
    }

    public static function output_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'branch'         => [ 'type' => 'string', 'enum' => [ 'then', 'else' ] ],
                'steps_executed' => [ 'type' => 'integer' ],
            ],
        ];
    }

    public static function has_side_effects(): bool {
        return true; // Depends on inner steps; safest to assume yes.
    }

    public function execute( FMW_Workflow_Context $context ): array {
        // Use raw_config['if'] — the pre-interpolation expression text — so
        // comparison operators (==, !=, <=, etc.) survive into the
        // expression evaluator. The interpolated $config['if'] is unusable
        // here: the string interpolator doesn't understand expressions, so
        // `{{ data.urgency == 'high' }}` came out empty/garbage and every
        // conditional took the else branch. Fixed in v0.6.4 after pressure
        // testing exposed the bug. The previous code had a "Workaround"
        // comment admitting it didn't actually work; this is the real fix.
        if ( ! isset( $this->raw_config['if'] ) ) {
            throw new FMW_Step_Exception( 'config_error', "conditional: 'if' is required." );
        }

        $interp = new FMW_Interpolator( $context );
        $expr   = new FMW_Expression( $interp );

        // Evaluate the raw expression. The expression evaluator builds its
        // own interpolator pass internally to resolve {{ … }} blocks that
        // contain context paths, then runs the comparison/logical
        // operators on top of those resolved values.
        $raw_if      = $this->raw_config['if'];
        $cond_result = $expr->evaluate( $raw_if );

        // Pull branch step lists from raw_config too so nested conditionals
        // inside `then` or `else` also receive their `if` field
        // un-interpolated. Without this, a workflow nesting conditional
        // inside conditional would re-introduce the original bug at the
        // inner level. Fall back to $this->config for backward compat with
        // any direct-instantiation callers that didn't pass raw_config.
        $branch_steps = $cond_result
            ? ( $this->raw_config['then'] ?? $this->config['then'] ?? [] )
            : ( $this->raw_config['else'] ?? $this->config['else'] ?? [] );

        if ( ! is_array( $branch_steps ) ) {
            $branch_steps = [];
        }

        // Inline-execute the branch steps. We construct a temporary "sub-workflow".
        // The subexecutor uses the same context.
        $sub_workflow_config = [
            'version' => '1.0',
            'steps'   => $branch_steps,
        ];

        $sub_workflow = new FMW_Workflow( [
            'id'                => $context->get_workflow_id() . '::' . $this->step_name,
            'title'             => 'Conditional sub-workflow',
            'form_id'           => $context->get_form_id(),
            'enabled'           => 1,
            'config'            => wp_json_encode( $sub_workflow_config ),
            'managed_by'        => 'inline',
            'connector_version' => 0,
        ] );

        $executor = new FMW_Workflow_Executor();
        $executor->execute( $sub_workflow, $context );

        return [
            'branch'         => $cond_result ? 'then' : 'else',
            'steps_executed' => count( $branch_steps ),
        ];
    }
}
