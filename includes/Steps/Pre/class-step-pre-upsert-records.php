<?php
/**
 * Step: pre_upsert_records
 *
 * The ingest step: takes the records a previous step fetched from a system
 * of record, maps each one onto a Post Runtime record type, and creates or
 * updates the mirroring record through Post Runtime's own upsert — keyed by
 * (post_type, source, external_id), so re-running never duplicates and an
 * unchanged record is never rewritten.
 *
 * FlowMint has no for_each step (deferred to v2), so this step loops
 * internally the way fre_delete_entries does, with per-record failures
 * collected rather than thrown. Two guards make an upstream change LOUD
 * instead of silent: `expect_min_records` (an API that starts returning an
 * empty list must not quietly draft every record) and `max_failure_ratio`
 * (a renamed field must not quietly skip most of the feed). Either guard
 * fails the step, which fails the run, which fires the failure notifier.
 * Records the upstream no longer returns are kept by default; with
 * `missing_upstream: "draft"` they are unpublished, never deleted, and only
 * when both guards passed.
 *
 * The per-record mapping is a template evaluated once per record with the
 * record available as `{{ item.* }}` — the same raw_config mechanism the
 * conditional step uses for its branches.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Step_Pre_Upsert_Records extends FMW_Step_Base {

    /** Keys of the mapping a record cannot do without. */
    const REQUIRED_MAP_KEYS = [ 'external_id', 'title' ];

    public static function type(): string { return 'pre_upsert_records'; }
    public static function display_name(): string { return 'Post Runtime: Upsert Records'; }
    public static function category(): string { return 'Post Runtime'; }
    public static function has_side_effects(): bool { return true; }

    public static function description(): string {
        return 'Creates or updates Post Runtime records from an array fetched by a previous step (typically http_get), one per record, keyed by (post_type, source, external_id) so re-running never duplicates and unchanged records are left untouched. `map` is a template evaluated per record with the record as {{ item.* }}. Fails the run — loudly — when fewer than expect_min_records arrive or more than max_failure_ratio of them cannot be mapped; records the upstream no longer returns are kept, or unpublished with missing_upstream:"draft", never deleted. Per-record failures are listed in the output; the step does not throw for one bad record.';
    }

    public static function config_schema(): array {
        return [
            'type'     => 'object',
            'required' => [ 'post_type', 'source', 'records', 'map' ],
            'properties' => [
                'post_type' => [ 'type' => 'string', 'description' => 'Post Runtime record type (CPT slug) to write into.' ],
                'source'    => [ 'type' => 'string', 'description' => 'Short key naming the system of record, e.g. "recdesk". Part of every record\'s identity.' ],
                'records'   => [ 'type' => 'array', 'description' => 'The records to upsert, usually {{ steps.fetch.body }} or {{ steps.fetch.body.items }}.' ],
                'map'       => [
                    'type'        => 'object',
                    'description' => 'Per-record template. Keys: external_id (required), title (required), content, excerpt, status, fields {field_key: value}, taxonomies {taxonomy: [terms]}, featured_image_id, featured_image_url (a feed\'s photo URL — Post Runtime sideloads it once per URL and sets it as the featured image; a URL that fails is a per-record warning, not a failure). Values may use {{ item.* }} for the current record and any other context path.',
                    'properties'  => [
                        'external_id'       => [ 'type' => 'string' ],
                        'title'             => [ 'type' => 'string' ],
                        'content'           => [ 'type' => 'string' ],
                        'excerpt'           => [ 'type' => 'string' ],
                        'status'            => [ 'type' => 'string' ],
                        'fields'            => [ 'type' => 'object' ],
                        'taxonomies'        => [ 'type' => 'object' ],
                        'featured_image_id' => [ 'type' => 'integer' ],
                        'featured_image_url' => [ 'type' => 'string' ],
                    ],
                ],
                'expect_min_records' => [ 'type' => 'integer', 'default' => 1, 'minimum' => 0, 'description' => 'Fail the step if fewer records arrive. Set to what an empty feed would NEVER legitimately be. Default 1.' ],
                'max_failure_ratio'  => [ 'type' => 'number', 'default' => 0.1, 'minimum' => 0, 'maximum' => 1, 'description' => 'Fail the step if more than this share of records cannot be mapped or written (0.1 = 10%). Records that succeeded stay.' ],
                'missing_upstream'   => [ 'type' => 'string', 'enum' => [ 'keep', 'draft' ], 'default' => 'keep', 'description' => 'What to do with records of this source the upstream no longer returns: keep (default) or draft (unpublish). Never delete. Only acts when both guards passed.' ],
            ],
        ];
    }

    public static function output_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'received_count'  => [ 'type' => 'integer' ],
                'created_count'   => [ 'type' => 'integer' ],
                'updated_count'   => [ 'type' => 'integer' ],
                'unchanged_count' => [ 'type' => 'integer' ],
                'failed_count'    => [ 'type' => 'integer' ],
                'drafted_count'   => [ 'type' => 'integer', 'description' => 'Records unpublished because the upstream no longer returned them (missing_upstream: draft).' ],
                'created_ids'     => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                'updated_ids'     => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                'unchanged_ids'   => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                'drafted_ids'     => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                'failed'          => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'index'       => [ 'type' => 'integer' ],
                            'external_id' => [ 'type' => 'string' ],
                            'error'       => [ 'type' => 'string' ],
                        ],
                    ],
                ],
                'warnings'        => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Non-fatal: mapping paths missing on some records (with counts), taxonomy or image issues.' ],
            ],
        ];
    }

    public function execute( FMW_Workflow_Context $context ): array {
        if ( ! function_exists( 'pcptpages' ) || ! pcptpages() || empty( pcptpages()->post_data ) || ! method_exists( pcptpages()->post_data, 'upsert_external' ) ) {
            throw new FMW_Step_Exception(
                'dependency_missing',
                'pre_upsert_records: Post Runtime Engine 0.8.2+ is not active (pcptpages()->post_data->upsert_external not available).'
            );
        }

        $post_type = (string) ( $this->config['post_type'] ?? '' );
        $source    = (string) ( $this->config['source'] ?? '' );
        $map       = $this->raw_config['map'] ?? null;
        if ( $post_type === '' || $source === '' ) {
            throw new FMW_Step_Exception( 'config_error', 'pre_upsert_records: post_type and source are required.' );
        }
        if ( ! is_array( $map ) ) {
            throw new FMW_Step_Exception( 'config_error', 'pre_upsert_records: map must be an object.' );
        }
        foreach ( self::REQUIRED_MAP_KEYS as $key ) {
            if ( ! isset( $map[ $key ] ) ) {
                throw new FMW_Step_Exception( 'config_error', esc_html( "pre_upsert_records: map.{$key} is required." ) );
            }
        }

        $records = $this->config['records'] ?? null;
        if ( is_string( $records ) ) {
            $decoded = json_decode( $records, true );
            $records = is_array( $decoded ) ? $decoded : null;
        }
        if ( ! is_array( $records ) ) {
            throw new FMW_Step_Exception(
                'upstream_shape',
                'pre_upsert_records: records did not resolve to an array. Check the path (e.g. {{ steps.fetch.body.items }}) against what the fetch step actually returned.'
            );
        }
        // A keyed object of records is a list too.
        $records = array_values( $records );

        $min = isset( $this->config['expect_min_records'] ) ? (int) $this->config['expect_min_records'] : 1;
        if ( count( $records ) < $min ) {
            throw new FMW_Step_Exception(
                'upstream_shape',
                esc_html( sprintf( 'pre_upsert_records: received %d record(s), expected at least %d. Nothing was drafted. The upstream feed is empty or its shape changed.', count( $records ), $min ) )
            );
        }
        $max_ratio = isset( $this->config['max_failure_ratio'] ) ? (float) $this->config['max_failure_ratio'] : 0.1;
        $missing   = ( $this->config['missing_upstream'] ?? 'keep' ) === 'draft' ? 'draft' : 'keep';

        $post_data     = pcptpages()->post_data;
        $created       = [];
        $updated       = [];
        $unchanged     = [];
        $failed        = [];
        $seen          = [];
        $missing_paths = [];
        $warnings      = [];
        $previous_item = $context->get_var( 'item' );

        foreach ( $records as $index => $record ) {
            $context->set_var( 'item', $record );
            $interp = new FMW_Interpolator( $context );
            $mapped = $interp->interpolate( $map );
            foreach ( $interp->get_missing_paths() as $path ) {
                $missing_paths[ $path ] = ( $missing_paths[ $path ] ?? 0 ) + 1;
            }

            $external_id = isset( $mapped['external_id'] ) ? trim( (string) ( is_scalar( $mapped['external_id'] ) ? $mapped['external_id'] : '' ) ) : '';
            $title       = isset( $mapped['title'] ) ? trim( (string) ( is_scalar( $mapped['title'] ) ? $mapped['title'] : '' ) ) : '';
            if ( $external_id === '' || $title === '' ) {
                $failed[] = [
                    'index'       => (int) $index,
                    'external_id' => $external_id,
                    'error'       => $external_id === '' ? 'map.external_id resolved to empty' : 'map.title resolved to empty',
                ];
                continue;
            }

            $payload = [ 'title' => $title ];
            foreach ( [ 'content', 'excerpt', 'status', 'fields', 'taxonomies', 'featured_image_id', 'featured_image_url' ] as $key ) {
                if ( array_key_exists( $key, $mapped ) ) {
                    $payload[ $key ] = $mapped[ $key ];
                }
            }

            try {
                $result = $post_data->upsert_external( $post_type, $source, $external_id, $payload, 'flowmint' );
            } catch ( \Throwable $e ) {
                $result = new WP_Error( 'exception', $e->getMessage() );
            }
            if ( is_wp_error( $result ) ) {
                $failed[] = [ 'index' => (int) $index, 'external_id' => $external_id, 'error' => $result->get_error_message() ];
                continue;
            }
            $seen[ (int) $result['post_id'] ] = true;
            foreach ( (array) ( $result['warnings'] ?? [] ) as $w ) {
                $warnings[] = "#{$external_id}: {$w}";
            }
            switch ( $result['action'] ) {
                case 'created':
                    $created[] = (int) $result['post_id'];
                    break;
                case 'updated':
                    $updated[] = (int) $result['post_id'];
                    break;
                default:
                    $unchanged[] = (int) $result['post_id'];
            }
        }
        $context->set_var( 'item', $previous_item );

        foreach ( $missing_paths as $path => $count ) {
            $warnings[] = sprintf( '{{ %s }} was missing on %d of %d record(s) and resolved to empty.', $path, $count, count( $records ) );
        }

        $total = count( $records );
        if ( $total > 0 && ( count( $failed ) / $total ) > $max_ratio ) {
            $sample = array_slice( array_map( static function ( $f ) { return "#{$f['index']} ({$f['external_id']}): {$f['error']}"; }, $failed ), 0, 3 );
            throw new FMW_Step_Exception(
                'upstream_shape',
                esc_html( sprintf(
                    'pre_upsert_records: %d of %d record(s) failed (limit %d%%). %d created / %d updated / %d unchanged were kept; nothing was drafted. First failures: %s',
                    count( $failed ),
                    $total,
                    (int) round( $max_ratio * 100 ),
                    count( $created ),
                    count( $updated ),
                    count( $unchanged ),
                    implode( ' | ', $sample )
                ) )
            );
        }

        $drafted = [];
        if ( $missing === 'draft' ) {
            foreach ( $post_data->list_external( $post_type, $source ) as $post_id => $synced_at ) {
                if ( isset( $seen[ $post_id ] ) || get_post_status( $post_id ) !== 'publish' ) {
                    continue;
                }
                $r = wp_update_post( [ 'ID' => $post_id, 'post_status' => 'draft' ], true );
                if ( ! is_wp_error( $r ) ) {
                    $drafted[] = (int) $post_id;
                }
            }
        }

        return [
            'received_count'  => $total,
            'created_count'   => count( $created ),
            'updated_count'   => count( $updated ),
            'unchanged_count' => count( $unchanged ),
            'failed_count'    => count( $failed ),
            'drafted_count'   => count( $drafted ),
            'created_ids'     => $created,
            'updated_ids'     => $updated,
            'unchanged_ids'   => $unchanged,
            'drafted_ids'     => $drafted,
            'failed'          => $failed,
            'warnings'        => $warnings,
        ];
    }
}
