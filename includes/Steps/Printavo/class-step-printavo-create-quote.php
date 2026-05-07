<?php
/**
 * Step: printavo_create_quote
 *
 * Creates a Printavo Quote (Invoice in their schema).
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Step_Printavo_Create_Quote extends FMW_Step_Base {

    public static function type(): string { return 'printavo_create_quote'; }
    public static function display_name(): string { return 'Printavo: Create Quote'; }
    public static function category(): string { return 'Printavo'; }
    public static function description(): string { return 'Creates a Printavo Quote/Invoice with the specified customer, owner, status, and details. Use printavo_find_or_create_customer first to get customer_id.'; }
    public static function has_side_effects(): bool { return true; }

    public static function config_schema(): array {
        return [
            'type'     => 'object',
            // Only contact_id is required at the step level. customerDueAt
            // and dueAt are required by Printavo, but the client provides
            // sensible defaults if they're not supplied here, so we don't
            // force workflow authors to think about them up front.
            'required' => [ 'contact_id' ],
            'properties' => [
                'contact_id'        => [ 'type' => 'string', 'description' => 'Printavo Contact ID (the person, not the company). Get from printavo_find_or_create_customer.contact_id.' ],
                'customer_id'       => [ 'type' => 'string', 'description' => 'DEPRECATED — kept for backwards compatibility with pre-rc6 workflows. If contact_id is omitted but customer_id is present, customer_id is used as the Contact ID (matching the old client semantics). Prefer contact_id in new workflows.' ],
                'user_id'           => [ 'description' => 'Printavo User ID (sales rep / Quote owner). Optional — Quote falls back to no owner if omitted.' ],
                'nickname'          => [ 'type' => 'string', 'description' => 'Quote nickname visible in Printavo UI' ],
                'description'       => [ 'type' => 'string', 'description' => 'Long-form description, stored as customerNote on the Quote' ],
                'production_note'   => [ 'type' => 'string', 'description' => 'Internal-only note, stored as productionNote' ],
                'customer_due_date' => [ 'type' => 'string', 'description' => 'YYYY-MM-DD. Defaults to +14 days if omitted.' ],
                'due_at'            => [ 'type' => 'string', 'description' => 'ISO8601 datetime. Defaults to +30 days at 17:00 UTC if omitted.' ],
            ],
        ];
    }

    public static function output_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'id'              => [ 'type' => 'string' ],
                'visual_id'       => [ 'type' => 'string' ],
                'nickname'        => [ 'type' => 'string' ],
                'description'     => [ 'type' => 'string' ],
                'url'             => [ 'type' => 'string' ],
                'public_url'      => [ 'type' => 'string' ],
                'customer_due_at' => [ 'type' => 'string' ],
                'due_at'          => [ 'type' => 'string' ],
            ],
        ];
    }

    public function execute( FMW_Workflow_Context $context ): array {
        $args = $this->config;

        // Backwards compatibility: if a workflow still passes customer_id
        // (the pre-rc6 contract), treat it as the Contact ID. The old client
        // erroneously used Contact IDs in that field anyway, so the
        // semantics are preserved.
        if ( empty( $args['contact_id'] ) && ! empty( $args['customer_id'] ) ) {
            $args['contact_id'] = $args['customer_id'];
        }

        if ( empty( $args['contact_id'] ) ) {
            throw new FMW_Step_Exception( 'config_error', 'printavo_create_quote: contact_id is required (use printavo_find_or_create_customer.contact_id).' );
        }

        $client = FMW_Printavo_Client::from_credentials();
        return $client->create_quote( $args );
    }
}
