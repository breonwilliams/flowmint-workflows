<?php
/**
 * Step: fre_get_entry
 *
 * Loads a FormEngine entry into the context. Usually unnecessary because
 * the entry is auto-loaded at workflow start, but useful for explicit refresh
 * after modifications.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Step_Fre_Get_Entry extends FMW_Step_Base {

    public static function type(): string {
        return 'fre_get_entry';
    }

    public static function display_name(): string {
        return 'FormEngine: Get Entry';
    }

    public static function category(): string {
        return 'FormEngine';
    }

    public static function description(): string {
        return 'Loads a FormEngine entry by ID into the run context. Defaults to the current run\'s entry.';
    }

    public static function config_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'entry_id' => [
                    'description' => 'Entry ID to load. Defaults to the current run\'s entry.',
                ],
            ],
        ];
    }

    public static function output_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'id'         => [ 'type' => 'integer' ],
                'form_id'    => [ 'type' => 'string' ],
                'fields'     => [ 'type' => 'object' ],
                'files'      => [ 'type' => 'array' ],
                'created_at' => [ 'type' => 'string' ],
            ],
        ];
    }

    public static function has_side_effects(): bool {
        return false;
    }

    public function execute( FMW_Workflow_Context $context ): array {
        if ( ! class_exists( 'PForms_Entry' ) ) {
            throw new FMW_Step_Exception(
                'dependency_missing',
                'fre_get_entry: FormEngine is not loaded.'
            );
        }

        $entry_id = isset( $this->config['entry_id'] ) && $this->config['entry_id'] !== ''
            ? (int) $this->config['entry_id']
            : $context->get_entry_id();

        if ( $entry_id <= 0 ) {
            throw new FMW_Step_Exception( 'config_error', 'fre_get_entry: invalid entry_id.' );
        }

        $repo  = new PForms_Entry();
        $entry = $repo->get( $entry_id );

        if ( ! $entry ) {
            throw new FMW_Step_Exception(
                'entry_not_found',
                sprintf( 'fre_get_entry: entry %d not found.', (int) $entry_id )
            );
        }

        // Update context with the freshly-loaded entry.
        $context->set_entry( $entry );
        $context->set_data( $entry['fields'] ?? [] );

        return [
            'id'         => (int) $entry['id'],
            'form_id'    => $entry['form_id'] ?? '',
            'fields'     => $entry['fields'] ?? [],
            'files'      => $entry['files'] ?? [],
            'created_at' => $entry['created_at'] ?? '',
        ];
    }
}
