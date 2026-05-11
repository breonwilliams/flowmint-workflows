<?php
/**
 * Step: fre_update_entry_status
 *
 * Updates a FormEngine entry's status (unread / read / spam).
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Step_Fre_Update_Entry_Status extends FMW_Step_Base {

    public static function type(): string {
        return 'fre_update_entry_status';
    }

    public static function display_name(): string {
        return 'FormEngine: Update Entry Status';
    }

    public static function category(): string {
        return 'FormEngine';
    }

    public static function description(): string {
        return "Updates the FE entry's status. Useful for marking entries as 'read' or 'spam' as part of a workflow.";
    }

    public static function config_schema(): array {
        return [
            'type'     => 'object',
            'required' => [ 'status' ],
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => [ 'unread', 'read', 'spam' ],
                ],
            ],
        ];
    }

    public static function output_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'previous_status' => [ 'type' => 'string' ],
                'new_status'      => [ 'type' => 'string' ],
            ],
        ];
    }

    public static function has_side_effects(): bool {
        return true;
    }

    public function execute( FMW_Workflow_Context $context ): array {
        $allowed = [ 'unread', 'read', 'spam' ];
        $new_status = (string) ( $this->config['status'] ?? '' );

        if ( ! in_array( $new_status, $allowed, true ) ) {
            throw new FMW_Step_Exception(
                'config_error',
                sprintf(
                    "fre_update_entry_status: invalid status '%s'. Must be one of: %s",
                    esc_html( $new_status ),
                    esc_html( implode( ', ', $allowed ) )
                )
            );
        }

        if ( ! class_exists( 'FRE_Entry' ) ) {
            throw new FMW_Step_Exception(
                'dependency_missing',
                'fre_update_entry_status: FormEngine is not loaded.'
            );
        }

        $entry_id = $context->get_entry_id();
        $repo     = new FRE_Entry();
        $existing = $repo->get( $entry_id );

        if ( ! $existing ) {
            throw new FMW_Step_Exception(
                'entry_not_found',
                sprintf( 'fre_update_entry_status: entry %d not found.', (int) $entry_id )
            );
        }

        $previous_status = $existing['status'] ?? '';

        if ( method_exists( $repo, 'update_status' ) ) {
            $repo->update_status( $entry_id, $new_status );
        } else {
            // Fall back to direct DB update.
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'fre_entries',
                [ 'status' => $new_status, 'updated_at' => current_time( 'mysql' ) ],
                [ 'id' => $entry_id ]
            );
        }

        return [
            'previous_status' => $previous_status,
            'new_status'      => $new_status,
        ];
    }
}
