<?php
/**
 * Step: fre_delete_entries
 *
 * Bulk-deletes FormEngine entries (and their attached files / meta) by
 * iterating PForms_Entry::delete() per id.
 *
 * Designed for retention workflows: the typical chain is
 * fre_list_entries → fre_delete_entries.
 *
 * **Idempotent.** Re-running on an already-deleted id returns
 * `already_gone: true` for that id instead of erroring. This is the
 * right semantic for retention sweeps that may race with manual admin
 * deletes.
 *
 * **Per-id failure tolerance.** A single id's delete failure is
 * recorded in the `failed` array; the step does NOT throw. This is
 * because the typical caller wants "purge as many as we can" rather
 * than "all or nothing." The recommended `on_error` is `continue`.
 *
 * @package FlowMintWorkflows
 * @since   0.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Step_Fre_Delete_Entries extends FMW_Step_Base {

    public static function type(): string {
        return 'fre_delete_entries';
    }

    public static function display_name(): string {
        return 'FormEngine: Delete Entries (bulk)';
    }

    public static function category(): string {
        return 'FormEngine';
    }

    public static function description(): string {
        return 'Bulk-deletes FormEngine entries by ID. Idempotent (already-deleted ids report already_gone). Per-id failures are recorded in the output\'s failed array — the step itself does not throw. Typical input: the entries array from fre_list_entries.';
    }

    public static function config_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'entries' => [
                    'description' => 'Array of entry objects (each with an "id" key) OR array of bare integer IDs. Typical source: {{ steps.<list_step_name>.entries }}.',
                    'oneOf'       => [
                        [
                            'type'  => 'array',
                            'items' => [ 'type' => 'integer' ],
                        ],
                        [
                            'type'  => 'array',
                            'items' => [
                                'type'       => 'object',
                                'properties' => [
                                    'id' => [ 'type' => 'integer' ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'required'   => [ 'entries' ],
        ];
    }

    public static function output_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'requested_count'    => [
                    'type'        => 'integer',
                    'description' => 'Total ids in the input (after dedup/normalization).',
                ],
                'deleted_count'      => [ 'type' => 'integer' ],
                'already_gone_count' => [ 'type' => 'integer' ],
                'failed_count'       => [ 'type' => 'integer' ],
                'deleted_ids'        => [
                    'type'  => 'array',
                    'items' => [ 'type' => 'integer' ],
                ],
                'already_gone_ids'   => [
                    'type'  => 'array',
                    'items' => [ 'type' => 'integer' ],
                ],
                'failed'             => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'id'    => [ 'type' => 'integer' ],
                            'error' => [ 'type' => 'string' ],
                        ],
                    ],
                ],
            ],
        ];
    }

    public static function has_side_effects(): bool {
        return true;
    }

    /**
     * Execute the step.
     *
     * @param FMW_Workflow_Context $context
     * @return array
     * @throws FMW_Step_Exception When FormEngine isn't loaded. Per-id
     *                            failures are recorded in `failed` and
     *                            do NOT throw.
     */
    public function execute( FMW_Workflow_Context $context ): array {
        if ( ! class_exists( 'PForms_Entry' ) ) {
            throw new FMW_Step_Exception(
                'dependency_missing',
                'fre_delete_entries: PForms_Entry class not available. FormEngine 1.6.0+ is required.'
            );
        }

        $ids = $this->normalize_ids( $this->config['entries'] ?? [] );

        $deleted_ids      = [];
        $already_gone_ids = [];
        $failed           = [];

        if ( empty( $ids ) ) {
            // Empty input is the normal "nothing to do" case — not an error.
            return [
                'requested_count'    => 0,
                'deleted_count'      => 0,
                'already_gone_count' => 0,
                'failed_count'       => 0,
                'deleted_ids'        => [],
                'already_gone_ids'   => [],
                'failed'             => [],
            ];
        }

        $repo = new PForms_Entry();

        foreach ( $ids as $id ) {
            // Per-id try/catch so one bad entry doesn't sink the batch.
            try {
                $existing = $repo->get( (int) $id );

                if ( ! $existing ) {
                    // Idempotent: already deleted (or never existed).
                    $already_gone_ids[] = (int) $id;
                    continue;
                }

                $result = $repo->delete( (int) $id );

                if ( $result === false || is_wp_error( $result ) ) {
                    $message = is_wp_error( $result )
                        ? $result->get_error_message()
                        : 'PForms_Entry::delete returned false';
                    $failed[] = [
                        'id'    => (int) $id,
                        'error' => $message,
                    ];
                    continue;
                }

                $deleted_ids[] = (int) $id;
            } catch ( \Throwable $e ) {
                $failed[] = [
                    'id'    => (int) $id,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'requested_count'    => count( $ids ),
            'deleted_count'      => count( $deleted_ids ),
            'already_gone_count' => count( $already_gone_ids ),
            'failed_count'       => count( $failed ),
            'deleted_ids'        => $deleted_ids,
            'already_gone_ids'   => $already_gone_ids,
            'failed'             => $failed,
        ];
    }

    /**
     * Normalize the config's entries field into a deduplicated list of
     * positive integer ids.
     *
     * Accepts:
     *   - array of integers: [1, 2, 3]
     *   - array of entry objects: [{ id: 1, ... }, { id: 2, ... }]
     *   - mixed: [1, { id: 2 }, ...]
     *
     * Anything that doesn't resolve to a positive integer is silently
     * dropped. We deliberately don't throw on bad shape — the typical
     * caller is a steps.<list>.entries reference, and the interpolator
     * has already done its work.
     *
     * @param mixed $raw
     * @return int[] Sorted, deduplicated list of positive integer ids.
     */
    private function normalize_ids( $raw ) {
        if ( ! is_array( $raw ) ) {
            return [];
        }

        $ids = [];
        foreach ( $raw as $item ) {
            $candidate = null;
            if ( is_int( $item ) || ( is_string( $item ) && ctype_digit( $item ) ) ) {
                $candidate = (int) $item;
            } elseif ( is_array( $item ) && isset( $item['id'] ) ) {
                $candidate = (int) $item['id'];
            } elseif ( is_object( $item ) && isset( $item->id ) ) {
                $candidate = (int) $item->id;
            }

            if ( $candidate !== null && $candidate > 0 ) {
                $ids[] = $candidate;
            }
        }

        $ids = array_values( array_unique( $ids ) );
        sort( $ids );
        return $ids;
    }
}
