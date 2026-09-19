<?php
/**
 * The workflow language section of docs/CONNECTOR_API.md — the document the
 * preflight's schema_document_url points assistants at — matches the code.
 *
 * Until 2026-09-19 the preflight promised that document covered expression
 * syntax, interpolation and on_error, and it covered none of them; the relay
 * advertised a `when` step key that does not exist (a step with `when` runs
 * every time) and the interpolator's header promised filters it never had.
 *
 * @package FlowMintWorkflows\Tests\Unit
 */

namespace FMW\Tests\Unit;

require_once __DIR__ . '/UnitTestCase.php';

class WorkflowLanguageDocTest extends UnitTestCase {

    private function read( $rel ) {
        return (string) file_get_contents( FMW_TEST_PLUGIN_DIR . $rel );
    }

    private function doc_section() {
        $doc   = $this->read( 'docs/CONNECTOR_API.md' );
        $start = strpos( $doc, '## The workflow language' );
        $this->assertNotFalse( $start, 'CONNECTOR_API.md has a "The workflow language" section' );
        $end = strpos( $doc, "\n## ", $start + 5 );
        return substr( $doc, $start, $end === false ? null : $end - $start );
    }

    public function test_every_interpolation_function_is_documented_and_no_other() {
        preg_match_all( "/case '([a-z_]+)':/", $this->read( 'includes/Core/class-fmw-interpolator.php' ), $m );
        $code = $m[1];
        $this->assertNotEmpty( $code );

        $section = $this->doc_section();
        foreach ( $code as $fn ) {
            $this->assertStringContainsString( "`{$fn}(", $section, "the function {$fn}() exists but is not documented" );
        }
        preg_match_all( '/`([a-z_]+)\(/', $section, $doc_fns );
        foreach ( array_unique( $doc_fns[1] ) as $fn ) {
            $this->assertContains( $fn, $code, "the section documents {$fn}(), which the interpolator does not have" );
        }
    }

    public function test_every_on_error_value_is_documented() {
        preg_match( '/allowed_on_error\s*=\s*\[([^\]]+)\]/', $this->read( 'includes/Core/class-fmw-workflow-validator.php' ), $m );
        preg_match_all( "/'([a-z]+)'/", $m[1], $values );
        foreach ( $values[1] as $value ) {
            $this->assertStringContainsString( "`{$value}`", $this->doc_section() );
        }
    }

    public function test_no_when_step_key_is_advertised() {
        $relay = $this->read( 'includes/Connectors/MCP/assets/flowmint-connector.js' );
        $this->assertSame( 0, preg_match( '/on_error\?,\s*when\?/', $relay ), 'the relay advertises a `when` key; the executor reads skip_if' );
        $this->assertStringContainsString( 'skip_if', $this->read( 'includes/Core/class-fmw-workflow-executor.php' ) );
    }
}
