<?php
/**
 * The preflight example in docs/CONNECTOR_API.md matches what the endpoint
 * returns, and preflight reports the schema version under an honest name.
 *
 * Until 2026-11-12 the example showed keys the endpoint never returned
 * (`fmw_manage_workflows`, `drive` / `printavo` / `slack`, `recent_calls`),
 * and the only version in `diagnostics` was `stored_plugin_version`, which
 * reports fmw_db_version: a fully migrated 0.12.1 site read as stuck on 0.4.0.
 *
 * @package FlowMintWorkflows\Tests\Unit
 */

namespace FMW\Tests\Unit;

require_once __DIR__ . '/UnitTestCase.php';

class PreflightDocTest extends UnitTestCase {

    private function read( $rel ) {
        return (string) file_get_contents( FMW_TEST_PLUGIN_DIR . $rel );
    }

    private function code() {
        return $this->read( 'includes/Connectors/REST/class-fmw-rest-preflight.php' );
    }

    /** The JSON example under "### Preflight", decoded. */
    private function doc_example() {
        $doc   = $this->read( 'docs/CONNECTOR_API.md' );
        $start = strpos( $doc, '### Preflight' );
        $this->assertNotFalse( $start, 'CONNECTOR_API.md has a Preflight section' );
        $this->assertSame( 1, preg_match( '/```json\s*(\{.*?\})\s*```/s', substr( $doc, $start ), $m ) );
        $json = json_decode( $m[1], true );
        $this->assertIsArray( $json, 'the preflight example is valid JSON' );
        return $json['data'];
    }

    public function test_schema_version_is_reported_under_its_own_name() {
        $code = $this->code();
        $this->assertMatchesRegularExpression( "/'db_schema_version'\s*=>\s*get_option\(\s*'fmw_db_version'/", $code );
        $this->assertMatchesRegularExpression( "/'db_schema_version_expected'\s*=>\s*FMW_DB_VERSION/", $code );
        // The old name stays for existing readers.
        $this->assertMatchesRegularExpression( "/'stored_plugin_version'\s*=>\s*get_option\(\s*'fmw_db_version'/", $code );
    }

    public function test_every_key_in_the_example_is_returned_by_the_endpoint() {
        $code    = $this->code();
        $example = $this->doc_example();
        $keys    = array_merge(
            array_keys( $example ),
            array_keys( $example['user_capabilities'] ),
            array_keys( $example['diagnostics'] ),
            array_keys( $example['diagnostics']['credentials_configured'] )
        );
        foreach ( $keys as $key ) {
            $this->assertStringContainsString( "'{$key}'", $code, "the doc example shows `{$key}`, which preflight does not return" );
        }
    }

    public function test_every_credential_the_endpoint_reports_is_in_the_example() {
        preg_match( '/\$credentials\s*=\s*\[(.*?)\];/s', $this->code(), $m );
        preg_match_all( "/'([a-z_]+)'\s*=>/", $m[1], $names );
        $this->assertNotEmpty( $names[1] );
        $documented = array_keys( $this->doc_example()['diagnostics']['credentials_configured'] );
        foreach ( $names[1] as $name ) {
            $this->assertContains( $name, $documented, "preflight reports the credential {$name}, the doc example does not show it" );
        }
    }
}
