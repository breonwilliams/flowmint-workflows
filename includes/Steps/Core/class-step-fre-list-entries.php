<?php
/**
 * Step: fre_list_entries
 *
 * Queries FormEngine entries by form_id, status, and/or age. Returns the
 * matching entries as a list — typically consumed by `fre_delete_entries`
 * in a retention workflow, but also useful for any "do something with
 * a batch of entries" pattern.
 *
 * Backed by FRE_Entry_Query (FRE 1.6.0+), so all filters compose
 * naturally with FE's own admin UI behavior.
 *
 * Returning empty result is NOT an error — it's the normal state when
 * no entries match. Downstream steps should be idempotent on empty input.
 *
 * @package FlowMintWorkflows
 * @since   0.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Step_Fre_List_Entries extends FMW_Step_Base {

    /**
     * Hard cap on entries returned in a single execution. Even if the
     * caller specifies a larger limit, this floors it to 1000.
     *
     * Rationale: scheduled workflows that hit this cap should chunk
     * across multiple ticks rather than try to process tens of
     * thousands in one run. Prevents runaway memory + DB load.
     */
    const MAX_LIMIT = 1000;

    /**
     * Default limit if the config doesn't specify one.
     */
    const DEFAULT_LIMIT = 100;

    public static function type(): string {
        return 'fre_list_entries';
    }

    public static function display_name(): string {
        return 'FormEngine: List Entries';
    }

    public static function category(): string {
        return 'FormEngine';
    }

    public static function description(): string {
        return 'Queries FormEngine entries by form_id, status, and/or age. Returns the matching list as `entries` plus a `count`. Empty result is normal — not an error. Typically chained with fre_delete_entries for retention workflows.';
    }

    public static function config_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'form_id'         => [
                    'type'        => 'string',
                    'description' => 'Optional. Restrict to entries from a single FE form. Omit (or pass "*") to query across all forms.',
                ],
                'status'          => [
                    'oneOf'       => [
                        [ 'type' => 'string' ],
                        [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    ],
                    'description' => 'Optional. Restrict to entries with these statuses. Pass a single string or an array of statuses.',
                ],
                'older_than_days' => [
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'description' => 'Optional. Restrict to entries whose created_at is on or before (today − N days), site-local. Mutually exclusive with older_than_date — if both are set, older_than_date wins.',
                ],
                'older_than_date' => [
                    'type'        => 'string',
                    'pattern'     => '^\\d{4}-\\d{2}-\\d{2}$',
                    'description' => 'Optional. Restrict to entries whose created_at is on or before this date (YYYY-MM-DD, site-local). Mutually exclusive with older_than_days.',
                ],
                'limit'           => [
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'maximum'     => self::MAX_LIMIT,
                    'default'     => self::DEFAULT_LIMIT,
                    'description' => 'Optional. Maximum number of entries to return. Default 100, hard cap ' . self::MAX_LIMIT . '.',
                ],
            ],
        ];
    }

    public static function output_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'entries' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'id'         => [ 'type' => 'integer' ],
                            'form_id'    => [ 'type' => 'string' ],
                            'status'     => [ 'type' => 'string' ],
                            'created_at' => [ 'type' => 'string' ],
                        ],
                    ],
                ],
                'count'   => [
                    'type'        => 'integer',
                    'description' => 'Number of entries returned (always equal to length of entries array).',
                ],
                'limit'   => [
                    'type'        => 'integer',
                    'description' => 'Limit applied to this query (capped at ' . self::MAX_LIMIT . ').',
                ],
                'hit_limit' => [
                    'type'        => 'boolean',
                    'description' => 'True if the result count equals the limit — there may be more matching entries that this run did not return.',
                ],
            ],
        ];
    }

    public static function has_side_effects(): bool {
        return false;
    }

    /**
     * Execute the step.
     *
     * @param FMW_Workflow_Context $context
     * @return array
     * @throws FMW_Step_Exception When FormEngine isn't loaded.
     */
    public function execute( FMW_Workflow_Context $context ): array {
        if ( ! class_exists( 'FRE_Entry_Query' ) ) {
            throw new FMW_Step_Exception(
                'dependency_missing',
                'fre_list_entries: FRE_Entry_Query class not available. FormEngine 1.6.0+ is required.'
            );
        }

        $config = $this->config;

        $query = new FRE_Entry_Query();

        // form_id filter (optional; '*' or empty means all forms)
        $form_id = isset( $config['form_id'] ) ? trim( (string) $config['form_id'] ) : '';
        if ( $form_id !== '' && $form_id !== '*' ) {
            $query->form( $form_id );
        }

        // status filter (optional; accepts string or array)
        if ( isset( $config['status'] ) && $config['status'] !== '' ) {
            $statuses = is_array( $config['status'] )
                ? array_values( array_filter( array_map( 'strval', $config['status'] ) ) )
                : [ (string) $config['status'] ];

            if ( count( $statuses ) === 1 ) {
                $query->status( $statuses[0] );
            } elseif ( count( $statuses ) > 1 ) {
                $query->where_in( 'status', $statuses );
            }
        }

        // Age filter — older_than_date wins over older_than_days when both
        // are present. We compute the cutoff in site-local time because
        // FRE_Entry::insert() stores created_at via current_time('mysql'),
        // which is site-local. Matching timezones avoids off-by-N-hour
        // edge cases at day boundaries.
        $cutoff_date = $this->resolve_cutoff_date( $config );
        if ( $cutoff_date !== null ) {
            $query->date_range( '', $cutoff_date );
        }

        // Limit (capped).
        $limit = isset( $config['limit'] ) ? (int) $config['limit'] : self::DEFAULT_LIMIT;
        $limit = max( 1, min( self::MAX_LIMIT, $limit ) );
        $query->limit( $limit );

        // Stable ordering — oldest first so retention workflows
        // consistently process the oldest data first.
        $query->order_by( 'created_at', 'ASC' );

        $rows = $query->get( false );

        // Project to a stable, compact output shape — id, form_id, status,
        // created_at. We deliberately do NOT include field values or files
        // here. Steps that need entry detail can call fre_get_entry per id.
        $entries = [];
        foreach ( (array) $rows as $row ) {
            $entries[] = [
                'id'         => isset( $row['id'] ) ? (int) $row['id'] : 0,
                'form_id'    => (string) ( $row['form_id'] ?? '' ),
                'status'     => (string) ( $row['status'] ?? '' ),
                'created_at' => (string) ( $row['created_at'] ?? '' ),
            ];
        }

        $count = count( $entries );

        return [
            'entries'   => $entries,
            'count'     => $count,
            'limit'     => $limit,
            'hit_limit' => $count >= $limit,
        ];
    }

    /**
     * Resolve the date_range upper bound from the step config.
     *
     * Precedence:
     *   1. config.older_than_date (explicit YYYY-MM-DD) — wins.
     *   2. config.older_than_days (integer) — cutoff = today − N days, site-local.
     *   3. neither set — returns null (no age filter applied).
     *
     * @param array $config
     * @return string|null Y-m-d date string, or null if no age filter.
     */
    private function resolve_cutoff_date( array $config ) {
        if ( ! empty( $config['older_than_date'] ) ) {
            // Take the caller's value as-is; FRE_Entry_Query::date_range()
            // validates and rejects malformed dates internally.
            return (string) $config['older_than_date'];
        }

        $days = isset( $config['older_than_days'] ) ? (int) $config['older_than_days'] : 0;
        if ( $days < 1 ) {
            return null;
        }

        $tz     = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
        $cutoff = ( new DateTimeImmutable( 'now', $tz ) )->modify( '-' . $days . ' days' );

        return $cutoff->format( 'Y-m-d' );
    }
}
