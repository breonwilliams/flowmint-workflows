<?php
/**
 * REST: /preflight
 *
 * Health check endpoint.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_REST_Preflight {

    public function register() {
        register_rest_route( FMW_REST_Api::ns(), '/' . FMW_REST_Api::base() . '/preflight', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'handle' ],
            'permission_callback' => [ 'FMW_REST_Auth', 'require_manage' ],
        ] );
    }

    public function handle( $request ) {
        $health = FMW_Schema::check_health();

        $credentials = [
            'drive_service_account' => FMW_Credential_Store::is_configured( 'drive_service_account' ),
            'printavo_api_token'    => FMW_Credential_Store::is_configured( 'printavo_api_token' ),
            'slack_webhook'         => FMW_Credential_Store::is_configured( 'slack_webhook' ),
        ];

        $connector_enabled = class_exists( 'FMW_Connector_Settings' )
            ? FMW_Connector_Settings::is_enabled()
            : (bool) get_option( 'fmw_connector_enabled', false );

        return rest_ensure_response( FMW_REST_Auth::success( [
            'plugin_version'         => FMW_VERSION,
            'connector_api_version'  => 'v1',
            // Reports the actual kill-switch state. When false, every other
            // endpoint returns 403 connector_disabled — the MCP shows this
            // value to the user so they know to enable the connector in
            // WP admin before trying to do anything else.
            'connector_enabled'      => $connector_enabled,
            'fre_active'             => defined( 'PForms_VERSION' ),
            'fre_version'            => defined( 'PForms_VERSION' ) ? PForms_VERSION : null,
            'action_scheduler_active' => function_exists( 'as_enqueue_async_action' ),
            'authenticated_as'       => wp_get_current_user()->user_login,
            'user_capabilities'      => [
                // Reports against the scoped MANAGE_WORKFLOWS capability so
                // the connector preflight reflects the actual permission
                // gate REST endpoints check. Pre-v0.7.0 this checked
                // manage_options directly; mirrors the swap there.
                'fmw_manage'       => class_exists( 'FMW_Capabilities' )
                    ? FMW_Capabilities::current_user_can_manage_workflows()
                    : current_user_can( 'manage_options' ),
            ],
            // Trigger types this install can execute. MCP clients use
            // this to decide whether to surface the "scheduled workflow"
            // affordance to the user. Pre-v0.6.0 installs report just
            // ['form']; v0.6.0+ reports both.
            'supported_trigger_types' => [ 'form', 'schedule' ],
            'schema_document_url' => FMW_PLUGIN_URL . 'docs/CONNECTOR_API.md',
            // Top-level namespaces accessible via `{{ … }}` interpolation
            // in any string field of any step config. Added in v0.6.3
            // after pressure testing surfaced that agents were guessing
            // variable paths (e.g. `entry.fields.email`) and getting
            // silent empty-string substitutions, because the interpolator
            // resolves missing paths to '' rather than leaving the
            // literal markers. The actual root namespace for form data
            // is `data.*`. This block documents every namespace the
            // workflow context exposes so the next MCP session doesn't
            // hit the same trap. See FMW_Workflow_Context::get_root() for
            // the authoritative source.
            'context_shape' => [
                'description'  => 'Top-level namespaces accessible via {{ … }} interpolation in any string field of any step config. Use these paths everywhere a step takes a string (subject, body, message, to, url, headers, etc.).',
                'namespaces'   => [
                    'data'        => 'Form submission field values, keyed by field key. Example: {{ data.email }}, {{ data.name }}, {{ data.preferred_track }}. This is the most common namespace — use it for form data. Missing keys resolve to empty string, not the literal marker.',
                    'entry'       => 'Promptless Forms entry RECORD metadata (id, form_id, status, ip_address, created_at, updated_at). Example: {{ entry.id }}, {{ entry.created_at }}. Does NOT contain field values — those are at data.*. Common mistake: writing {{ entry.fields.email }} expecting the submission email; the correct path is {{ data.email }}.',
                    'entry_files' => 'Array of uploaded files. Each item is an object with { field_key, file_name, file_url, file_path, file_size, mime_type }. Prefer the typed file step types (fre_get_file, drive_upload_file) over direct iteration where possible.',
                    'run'         => 'Current workflow run: { id, started_at }. Example: {{ run.id }} in a log message for traceability.',
                    'workflow'    => 'Workflow metadata: { id }. Example: {{ workflow.id }} when one log line covers many workflows.',
                    'form'        => 'Bound form metadata: { id }. Example: {{ form.id }} when the same listener handles multiple forms.',
                    'steps'       => 'Outputs from prior steps, keyed by step NAME (not type, not index). Example: {{ steps.find_customer.contact_id }} after a step `{ name: "find_customer", type: "printavo_find_or_create_customer" }`. See each step type\'s output_schema in flowmint_list_step_types for available output fields.',
                    'vars'        => 'Variables set via the `set_variable` step. Example: {{ vars.discount_pct }} after `{ name: "set_discount", type: "set_variable", config: { name: "discount_pct", value: 0.15 } }`.',
                ],
                'example'      => 'Send a confirmation email: { "to": "{{ data.email }}", "subject": "Welcome {{ data.name }} — order {{ entry.id }}", "body": "We received your registration for the {{ data.preferred_track }} track on {{ entry.created_at }}." }',
                'common_traps' => [
                    'entry.fields.* does NOT exist. Form fields are at data.*. The interpolator silently resolves missing paths to empty string, so the step succeeds with empty content rather than failing loudly.',
                    'steps.<type>.* does NOT work. Use the step NAME, not the type: `{{ steps.find_customer.contact_id }}` not `{{ steps.printavo_find_or_create_customer.contact_id }}`.',
					'data.* holds RAW STORED VALUES, not the labels a visitor saw. For select / radio / checkbox fields the stored value is the option KEY, so a form whose option is {value:"joinery", label:"Hand-Cut Joinery Intensive"} interpolates {{ data.workshop }} as "joinery". In a machine step (http_post, printavo_*) that is what you want — stable identifiers. In a customer-facing email it produces "your place is held for the joinery workshop", and nothing fails, so the mistake ships. FlowMint has NO label resolution: unlike Promptless Forms notifications, where {field:key} resolves to the label, and unlike its webhook payloads, which resolve labels under the google_sheets preset or an explicit webhook_resolve_option_labels. Until a labels namespace exists, write the human-readable text yourself (a set_variable step, or a conditional on the raw value) whenever the value reaches a person.',
                ],
            ],
            'diagnostics' => [
                'stored_plugin_version' => get_option( 'fmw_db_version', '0.0.0' ),
                'database_health' => [
                    'ok'             => empty( $health ),
                    'tables_present' => array_values( FMW_Schema::get_table_names() ),
                    'tables_missing' => $health,
                ],
                'credentials_configured' => $credentials,
            ],
        ] ) );
    }
}
