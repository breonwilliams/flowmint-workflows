<?php
/**
 * Step: fre_delete_entry
 *
 * Cascade-deletes the FormEngine entry and its files. Used as the final
 * cleanup step in workflows that have moved data to durable storage
 * (Drive, Printavo, etc.) and no longer need the WP-side entry.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Step_Fre_Delete_Entry extends FMW_Step_Base {

    public static function type(): string {
        return 'fre_delete_entry';
    }

    public static function display_name(): string {
        return 'FormEngine: Delete Entry';
    }

    public static function category(): string {
        return 'FormEngine';
    }

    public static function description(): string {
        return 'Cascade-deletes the FormEngine entry and its uploaded files. Idempotent — returns already_gone=true if entry was already deleted.';
    }

    public static function config_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [],
        ];
    }

    public static function output_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'deleted'       => [ 'type' => 'boolean' ],
                'entry_id'      => [ 'type' => 'integer' ],
                'files_deleted' => [ 'type' => 'integer' ],
                'already_gone'  => [ 'type' => 'boolean' ],
            ],
        ];
    }

    public static function has_side_effects(): bool {
        return true;
    }

    public function execute( FMW_Workflow_Context $context ): array {
        if ( ! class_exists( 'FRE_Entry' ) ) {
            throw new FMW_Step_Exception(
                'dependency_missing',
                'fre_delete_entry: FormEngine is not loaded.'
            );
        }

        $entry_id = $context->get_entry_id();
        $repo     = new FRE_Entry();
        $existing = $repo->get( $entry_id );

        if ( ! $existing ) {
            // Idempotent: previous attempt may have deleted it already.
            return [
                'deleted'      => false,
                'entry_id'     => $entry_id,
                'already_gone' => true,
            ];
        }

        $file_count = isset( $existing['files'] ) && is_array( $existing['files'] ) ? count( $existing['files'] ) : 0;

        // FE_Entry::delete cascades file cleanup automatically.
        if ( method_exists( $repo, 'delete' ) ) {
            $result = $repo->delete( $entry_id );
            if ( $result === false || is_wp_error( $result ) ) {
                $message = is_wp_error( $result ) ? $result->get_error_message() : 'Unknown delete failure';
                throw new FMW_Step_Exception( 'fre_delete_failed', $message );
            }
        } else {
            throw new FMW_Step_Exception(
                'dependency_missing',
                'fre_delete_entry: FRE_Entry::delete() method not found. Check FormEngine version.'
            );
        }

        return [
            'deleted'       => true,
            'entry_id'      => $entry_id,
            'files_deleted' => $file_count,
        ];
    }
}
