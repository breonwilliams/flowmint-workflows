<?php
/**
 * Abstract base class for all step types.
 *
 * Concrete step classes extend this and implement the abstract methods.
 * The executor instantiates them with their interpolated config and calls
 * execute() with the run context.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

abstract class FMW_Step_Base {

    /**
     * Step's name within the workflow (unique per workflow).
     *
     * @var string
     */
    protected $step_name;

    /**
     * Step's interpolated config (variables already substituted).
     *
     * @var array
     */
    protected $config;

    /**
     * Step's raw config (BEFORE interpolation). Same shape as $config, but
     * string values still contain unresolved `{{ … }}` markers and any
     * expression-typed fields (such as the conditional step's `if`) are
     * still parseable as expressions.
     *
     * Most step types should keep using $config — it has variables already
     * substituted, which is what nearly every step wants. The exceptions
     * are control-flow steps whose config contains expressions or nested
     * step lists that must be deferred to their own per-iteration
     * interpolation pass:
     *
     *   - conditional: read $raw_config['if'] for the expression, and
     *     $raw_config['then'] / $raw_config['else'] for nested step arrays
     *     so nested conditionals get their `if` raw too.
     *   - try_catch (if/when added): same pattern for any future
     *     expression-typed fields.
     *
     * Falls back to $config when the executor doesn't pass raw_config —
     * preserves backward compat for any direct instantiation path.
     *
     * @var array
     */
    protected $raw_config;

    /**
     * Per-step error policy: 'fail', 'continue', 'retry'.
     *
     * @var string
     */
    protected $on_error;

    /**
     * Constructor.
     *
     * @param array $step_definition The full step definition from the workflow JSON.
     *                                Must include 'name', 'config'. Optional: 'on_error',
     *                                'raw_config' (control-flow steps that need
     *                                pre-interpolation access).
     */
    public function __construct( array $step_definition ) {
        $this->step_name  = isset( $step_definition['name'] ) ? (string) $step_definition['name'] : '';
        $this->config     = isset( $step_definition['config'] ) ? (array) $step_definition['config'] : [];
        $this->raw_config = isset( $step_definition['raw_config'] ) ? (array) $step_definition['raw_config'] : $this->config;
        $this->on_error   = isset( $step_definition['on_error'] ) ? (string) $step_definition['on_error'] : 'fail';
    }

    /**
     * Step type identifier (snake_case). MUST be unique across all step types.
     *
     * @return string
     */
    abstract public static function type(): string;

    /**
     * Human-readable display name.
     *
     * @return string
     */
    abstract public static function display_name(): string;

    /**
     * One-paragraph description of what the step does.
     *
     * @return string
     */
    abstract public static function description(): string;

    /**
     * JSON Schema for the step's config object.
     *
     * @return array
     */
    abstract public static function config_schema(): array;

    /**
     * JSON Schema for the step's output.
     *
     * @return array
     */
    abstract public static function output_schema(): array;

    /**
     * Whether this step makes external state changes.
     *
     * @return bool
     */
    abstract public static function has_side_effects(): bool;

    /**
     * Step category (used for grouping in admin UI / step-types listing).
     * Override in concrete classes; default is "Other".
     *
     * @return string
     */
    public static function category(): string {
        return 'Other';
    }

    /**
     * Execute the step. Receives the workflow context, returns the step's output.
     *
     * @param FMW_Workflow_Context $context
     * @return array Step output (will be made available to downstream steps as {{ steps.<name>.<field> }})
     * @throws FMW_Step_Exception On any failure.
     */
    abstract public function execute( FMW_Workflow_Context $context ): array;

    /**
     * Get the step's name within the workflow.
     *
     * @return string
     */
    public function get_step_name() {
        return $this->step_name;
    }

    /**
     * Get the step's interpolated config.
     *
     * @return array
     */
    public function get_config() {
        return $this->config;
    }

    /**
     * Get the on_error policy.
     *
     * @return string
     */
    public function get_on_error() {
        return $this->on_error;
    }
}
