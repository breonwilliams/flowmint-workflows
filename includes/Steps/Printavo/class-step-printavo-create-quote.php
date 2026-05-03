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
            'required' => [ 'customer_id', 'user_id', 'invoice_status_id' ],
            'properties' => [
                'customer_id'       => [ 'type' => 'string', 'description' => 'Printavo customer (contact) ID' ],
                'user_id'           => [ 'description' => 'Printavo owner/user ID (sales rep)' ],
                'invoice_status_id' => [ 'description' => 'Printavo invoice status ID' ],
                'nickname'          => [ 'type' => 'string', 'description' => 'Quote nickname visible in Printavo UI' ],
                'description'       => [ 'type' => 'string', 'description' => 'Long-form description (customerNote)' ],
                'production_note'   => [ 'type' => 'string' ],
                'customer_due_date' => [ 'type' => 'string', 'description' => 'YYYY-MM-DD' ],
            ],
        ];
    }

    public static function output_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'id'         => [ 'type' => 'string' ],
                'visual_id'  => [ 'type' => 'string' ],
                'nickname'   => [ 'type' => 'string' ],
                'created_at' => [ 'type' => 'string' ],
                'url'        => [ 'type' => 'string' ],
            ],
        ];
    }

    public function execute( FMW_Workflow_Context $context ): array {
        if ( empty( $this->config['customer_id'] ) ) {
            throw new FMW_Step_Exception( 'config_error', 'printavo_create_quote: customer_id is required.' );
        }
        if ( empty( $this->config['user_id'] ) ) {
            throw new FMW_Step_Exception( 'config_error', 'printavo_create_quote: user_id is required.' );
        }
        if ( empty( $this->config['invoice_status_id'] ) ) {
            throw new FMW_Step_Exception( 'config_error', 'printavo_create_quote: invoice_status_id is required.' );
        }

        $client = FMW_Printavo_Client::from_credentials();
        return $client->create_quote( $this->config );
    }
}
