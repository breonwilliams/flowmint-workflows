<?php
/**
 * Unit tests for FMW_Step_Pre_Upsert_Records: per-record mapping, the two
 * guards, and pruning — against a fake Post Runtime data layer.
 *
 * @package FlowMintWorkflows\Tests\Unit\Steps
 */

namespace FMW\Tests\Unit\Steps;

use FMW\Tests\Unit\UnitTestCase;
use Brain\Monkey\Functions;

require_once dirname( __DIR__ ) . '/UnitTestCase.php';

/** Fake of PCPTPages_Post_Data's external surface. */
class FakePostData {
    public $calls = [];
    public $existing = []; // "source:external_id" => [post_id, hash]
    public $records = [];  // list_external result: post_id => synced_at
    public $fail_ids = []; // external ids upsert_external should refuse
    private $next_id = 100;

    public function upsert_external( $post_type, $source, $external_id, array $record, $origin = 'programmatic' ) {
        $this->calls[] = compact( 'post_type', 'source', 'external_id', 'record', 'origin' );
        if ( in_array( $external_id, $this->fail_ids, true ) ) {
            return new \WP_Error( 'pcptpages_unknown_field_key', "Field nope is not registered on {$post_type}." );
        }
        $key  = "{$source}:{$external_id}";
        $hash = md5( json_encode( $record ) );
        if ( isset( $this->existing[ $key ] ) ) {
            [ $id, $old_hash ] = $this->existing[ $key ];
            if ( $old_hash === $hash ) {
                return [ 'post_id' => $id, 'action' => 'unchanged', 'permalink' => '', 'warnings' => [] ];
            }
            $this->existing[ $key ] = [ $id, $hash ];
            return [ 'post_id' => $id, 'action' => 'updated', 'permalink' => '', 'warnings' => [] ];
        }
        $id = $this->next_id++;
        $this->existing[ $key ] = [ $id, $hash ];
        return [ 'post_id' => $id, 'action' => 'created', 'permalink' => '', 'warnings' => [ 'taxonomy "x" is not registered for post type "prog"; skipped.' ] ];
    }

    public function list_external( $post_type, $source ) {
        return $this->records;
    }
}

class PreUpsertRecordsTest extends UnitTestCase {

    /** @var FakePostData */
    private $post_data;
    public static $statuses = [];
    public static $drafted = [];

    protected function set_up() {
        parent::set_up();
        Functions\when( 'current_time' )->alias( function ( $format = 'Y-m-d H:i:s' ) { return $format === 'timestamp' ? time() : gmdate( $format ); } );
        Functions\when( 'is_wp_error' )->alias( function ( $thing ) { return $thing instanceof \WP_Error; } );
        Functions\when( 'get_post_status' )->alias( function ( $id ) { return self::$statuses[ $id ] ?? false; } );
        Functions\when( 'wp_update_post' )->alias( function ( $args ) { self::$drafted[] = $args['ID']; return $args['ID']; } );
        self::$statuses = [];
        self::$drafted  = [];

        require_once FMW_TEST_PLUGIN_DIR . 'includes/Core/class-fmw-workflow-context.php';
        require_once FMW_TEST_PLUGIN_DIR . 'includes/Core/class-fmw-interpolator.php';
        require_once FMW_TEST_PLUGIN_DIR . 'includes/Core/class-fmw-step-exception.php';
        require_once FMW_TEST_PLUGIN_DIR . 'includes/Core/class-fmw-step-base.php';
        require_once FMW_TEST_PLUGIN_DIR . 'includes/Steps/Pre/class-step-pre-upsert-records.php';

        $this->post_data = new FakePostData();
        $GLOBALS['fmw_test_post_data'] = $this->post_data;
        if ( ! function_exists( 'pcptpages' ) ) {
            eval( 'function pcptpages() { return (object) [ "post_data" => $GLOBALS["fmw_test_post_data"] ]; }' );
        }
    }

    private function run_step( array $config, array $records ) {
        $context = new \FMW_Workflow_Context( 1, 0, 'ingest', '' );
        $context->set_step_output( 'fetch', [ 'body' => [ 'items' => $records ] ] );
        $raw = array_merge( [
            'post_type' => 'prog',
            'source'    => 'recdesk',
            'records'   => '{{ steps.fetch.body.items }}',
            'map'       => [
                'external_id' => '{{ item.id }}',
                'title'       => '{{ item.name }}',
                'fields'      => [ 'event_location' => '{{ item.where }}' ],
            ],
        ], $config );
        // Mirror the executor: config is interpolated once (item.* is empty
        // there), raw_config keeps the template.
        $interp = new \FMW_Interpolator( $context );
        $step   = new \FMW_Step_Pre_Upsert_Records( [
            'name'       => 'upsert',
            'config'     => $interp->interpolate( $raw ),
            'raw_config' => $raw,
        ] );
        return $step->execute( $context );
    }

    public function test_maps_each_record_and_reports_actions() {
        $records = [
            [ 'id' => 1, 'name' => 'Soccer', 'where' => 'Park' ],
            [ 'id' => 2, 'name' => 'Tennis', 'where' => 'Courts' ],
        ];
        $out = $this->run_step( [], $records );
        $this->assertSame( 2, $out['received_count'] );
        $this->assertSame( 2, $out['created_count'] );
        $this->assertSame( [ 100, 101 ], $out['created_ids'] );
        $this->assertSame( 'Soccer', $this->post_data->calls[0]['record']['title'] );
        $this->assertSame( 'Park', $this->post_data->calls[0]['record']['fields']['event_location'] );
        $this->assertSame( '1', $this->post_data->calls[0]['external_id'] );
        $this->assertSame( 'flowmint', $this->post_data->calls[0]['origin'] );
        $this->assertStringContainsString( 'taxonomy "x"', $out['warnings'][0], 'per-record warnings surface with the external id' );

        // Same feed again: unchanged, nothing created.
        $out = $this->run_step( [], $records );
        $this->assertSame( 2, $out['unchanged_count'] );
        $this->assertSame( 0, $out['created_count'] );

        // One record changes: one update.
        $records[1]['where'] = 'Indoor courts';
        $out = $this->run_step( [], $records );
        $this->assertSame( [ 101 ], $out['updated_ids'] );
        $this->assertSame( 1, $out['unchanged_count'] );
    }

    public function test_missing_optional_path_is_a_warning_not_a_failure() {
        $out = $this->run_step( [], [ [ 'id' => 1, 'name' => 'Soccer' ] ] );
        $this->assertSame( 1, $out['created_count'] );
        $this->assertSame( 0, $out['failed_count'] );
        $this->assertStringContainsString( 'item.where', implode( ' ', $out['warnings'] ) );
    }

    public function test_missing_required_key_fails_that_record_only() {
        $out = $this->run_step( [ 'max_failure_ratio' => 0.5 ], [ [ 'id' => 1, 'name' => 'Soccer' ], [ 'id' => '', 'name' => 'Nameless id' ] ] );
        $this->assertSame( 1, $out['created_count'] );
        $this->assertSame( 1, $out['failed_count'] );
        $this->assertSame( 1, $out['failed'][0]['index'] );
        $this->assertStringContainsString( 'external_id', $out['failed'][0]['error'] );
    }

    public function test_too_few_records_fails_loudly_before_writing() {
        $this->expectException( \FMW_Step_Exception::class );
        $this->expectExceptionMessage( 'expected at least 5' );
        try {
            $this->run_step( [ 'expect_min_records' => 5 ], [ [ 'id' => 1, 'name' => 'Only one' ] ] );
        } finally {
            $this->assertSame( [], $this->post_data->calls, 'nothing is written when the feed is too small' );
        }
    }

    public function test_failure_ratio_fails_the_step_and_keeps_successes() {
        $this->post_data->fail_ids = [ '2', '3' ];
        $this->post_data->records  = [ 100 => '2026-01-01 00:00:00', 555 => '2026-01-01 00:00:00' ];
        self::$statuses = [ 555 => 'publish' ];
        try {
            $this->run_step( [ 'missing_upstream' => 'draft' ], [ [ 'id' => 1, 'name' => 'A' ], [ 'id' => 2, 'name' => 'B' ], [ 'id' => 3, 'name' => 'C' ] ] );
            $this->fail( 'expected upstream_shape' );
        } catch ( \FMW_Step_Exception $e ) {
            $this->assertSame( 'upstream_shape', $e->get_error_code() );
            $this->assertStringContainsString( '2 of 3', $e->getMessage() );
            $this->assertStringContainsString( '1 created', $e->getMessage() );
        }
        $this->assertSame( [], self::$drafted, 'nothing is drafted when a guard trips' );
    }

    public function test_missing_upstream_draft_unpublishes_only_unseen_published_records() {
        $this->post_data->existing = [ 'recdesk:1' => [ 100, 'stale' ] ];
        $this->post_data->records  = [ 100 => '2026-01-01', 555 => '2026-01-01', 556 => '2026-01-01' ];
        self::$statuses = [ 100 => 'publish', 555 => 'publish', 556 => 'draft' ];
        $out = $this->run_step( [ 'missing_upstream' => 'draft' ], [ [ 'id' => 1, 'name' => 'Still here' ] ] );
        $this->assertSame( [ 555 ], $out['drafted_ids'], 'seen and already-draft records are left alone' );
        $this->assertSame( [ 555 ], self::$drafted );
    }

    public function test_keep_is_the_default_for_missing_upstream() {
        $this->post_data->records = [ 555 => '2026-01-01' ];
        self::$statuses = [ 555 => 'publish' ];
        $out = $this->run_step( [], [ [ 'id' => 1, 'name' => 'A' ] ] );
        $this->assertSame( 0, $out['drafted_count'] );
        $this->assertSame( [], self::$drafted );
    }

    public function test_records_that_are_not_an_array_fail_with_a_pointer_to_the_path() {
        $this->expectException( \FMW_Step_Exception::class );
        $this->expectExceptionMessage( 'did not resolve to an array' );
        $this->run_step( [ 'records' => '{{ steps.fetch.body.nope }}' ], [ [ 'id' => 1, 'name' => 'A' ] ] );
    }

    public function test_item_is_restored_after_the_step() {
        $context = new \FMW_Workflow_Context( 1, 0, 'ingest', '' );
        $context->set_var( 'item', 'before' );
        $context->set_step_output( 'fetch', [ 'body' => [ 'items' => [ [ 'id' => 1, 'name' => 'A' ] ] ] ] );
        $raw  = [ 'post_type' => 'prog', 'source' => 'recdesk', 'records' => '{{ steps.fetch.body.items }}', 'map' => [ 'external_id' => '{{ item.id }}', 'title' => '{{ item.name }}' ] ];
        $step = new \FMW_Step_Pre_Upsert_Records( [ 'name' => 'u', 'config' => ( new \FMW_Interpolator( $context ) )->interpolate( $raw ), 'raw_config' => $raw ] );
        $step->execute( $context );
        $this->assertSame( 'before', $context->get_var( 'item' ) );
        $this->assertSame( 'before', $context->resolve_path( 'item' ) );
    }
}
