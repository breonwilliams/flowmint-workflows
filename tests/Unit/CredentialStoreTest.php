<?php
/**
 * Unit tests for FMW_Credential_Store.
 *
 * Credential storage is the most security-critical surface in the
 * plugin — Drive service-account JSON, Printavo API tokens, Slack
 * webhook URLs all flow through this class. The audit (item I2)
 * flagged that the get() method conflates "not configured" with
 * "decryption failed"; these tests pin both the existing-correct
 * behavior (round-trip, format, sanitization) AND will be extended
 * once I2 lands to pin the new WP_Error-on-failure contract.
 *
 * What's covered here (stable across the I2 fix):
 *   - Round-trip identity (set → get recovers original)
 *   - Versioned bundle format (chr(1) prefix + base64)
 *   - IV uniqueness (same input encrypts differently each time)
 *   - Option-name sanitization (no key-injection)
 *   - is_configured presence check
 *   - delete behavior
 *   - fmw_credential filter override (test/CI shortcut)
 *
 * What WILL be added once I2 lands:
 *   - decryption-failure paths return WP_Error (currently null)
 *
 * @package FlowMintWorkflows\Tests\Unit
 */

namespace FMW\Tests\Unit;

use Brain\Monkey\Functions;

class CredentialStoreTest extends UnitTestCase {

    protected function set_up() {
        parent::set_up();

        // The credential store touches openssl, get_option, update_option,
        // delete_option, and apply_filters('fmw_credential', ...). The
        // base UnitTestCase already wires the option API + apply_filters;
        // openssl is a PHP extension and works natively.
        require_once FMW_TEST_PLUGIN_DIR . 'includes/Database/class-fmw-credential-store.php';

        // Default the fmw_credential filter to NOT override (returning
        // the second arg as-is). Tests that exercise the override path
        // re-mock this per-test.
        Functions\when( 'apply_filters' )->alias( function ( $tag, $value ) {
            return $value;
        } );
    }

    // -----------------------------------------------------------------
    // Round-trip — the basic correctness invariant
    // -----------------------------------------------------------------

    public function test_set_then_get_recovers_original_value() {
        $original = 'pk_live_aBcD1234567890ZyXwVuTsRqPo';

        \FMW_Credential_Store::set( 'printavo_api_token', $original );
        $retrieved = \FMW_Credential_Store::get( 'printavo_api_token' );

        $this->assertSame(
            $original,
            $retrieved,
            'Round-trip set → get must recover the original credential exactly. Anything else means the encryption layer is corrupting data.'
        );
    }

    public function test_round_trip_handles_unicode_credentials() {
        // Credentials are typically ASCII, but a service-account JSON
        // file or a Slack webhook URL can contain non-ASCII characters.
        // Encryption operates on bytes; pin that unicode survives.
        $original = '{"name":"Drïve Sërvice 🔑","key":"abc123"}';

        \FMW_Credential_Store::set( 'drive_service_account', $original );
        $retrieved = \FMW_Credential_Store::get( 'drive_service_account' );

        $this->assertSame( $original, $retrieved );
    }

    public function test_round_trip_handles_long_credentials() {
        // Drive service-account JSON files are ~2-3KB. AES-GCM should
        // handle this fine but pin it explicitly so a future cipher
        // change doesn't introduce a length limit silently.
        $original = str_repeat( 'X', 4096 );

        \FMW_Credential_Store::set( 'long_credential', $original );
        $retrieved = \FMW_Credential_Store::get( 'long_credential' );

        $this->assertSame( $original, $retrieved );
    }

    // -----------------------------------------------------------------
    // Encrypted bundle format
    // -----------------------------------------------------------------

    public function test_stored_value_is_versioned_base64_bundle() {
        \FMW_Credential_Store::set( 'token', 'plaintext-value' );

        $stored = $this->options['fmw_credential_token'] ?? null;

        $this->assertNotNull( $stored, 'Setting a credential must persist a value to wp_options.' );
        $this->assertNotSame(
            'plaintext-value',
            $stored,
            'Stored value must NEVER be plaintext — that is the entire point of the encryption layer.'
        );

        // Bundle is base64-encoded.
        $decoded = base64_decode( $stored, true );
        $this->assertNotFalse(
            $decoded,
            'Stored value must be valid base64 (the bundle wrapper).'
        );

        // First byte is the version tag (currently 1).
        $this->assertSame(
            1,
            ord( $decoded[0] ),
            'Bundle version byte must be 1 (current format) so the decryption path can branch on it for future migrations.'
        );

        // Bundle = 1 byte version + 12 bytes IV + 16 bytes tag + ciphertext.
        $this->assertGreaterThanOrEqual(
            29,
            strlen( $decoded ),
            'Bundle must contain at minimum: version (1) + IV (12) + tag (16) + at least 1 byte of ciphertext.'
        );
    }

    public function test_two_encryptions_of_same_value_produce_different_ciphertext() {
        // Random IV per encryption is what makes AES-GCM safe to reuse
        // a key with. If two encryptions of the same plaintext produced
        // the same ciphertext, an attacker reading the database could
        // tell that two credentials are identical without decrypting
        // either one. This is exactly the "deterministic IV" bug
        // flagged in FRE's Twilio audit (item CR1) — pin that
        // FlowMint's design avoids it.
        \FMW_Credential_Store::set( 'token_a', 'identical-plaintext' );
        $first = $this->options['fmw_credential_token_a'];

        \FMW_Credential_Store::set( 'token_b', 'identical-plaintext' );
        $second = $this->options['fmw_credential_token_b'];

        $this->assertNotSame(
            $first,
            $second,
            'Encrypting the same value twice must produce different ciphertext (random IV per encryption is the AES-GCM safe-reuse guarantee).'
        );
    }

    // -----------------------------------------------------------------
    // Option-name sanitization
    //
    // The option_name() helper strips invalid characters from credential
    // keys before composing the wp_options name. Without this, a caller
    // could pass a malicious key like `printavo'; DROP TABLE...` and
    // although wpdb prepares correctly, the option-name shape would be
    // wrong. Pin the sanitization so a future refactor can't silently
    // drop it.
    // -----------------------------------------------------------------

    public function test_credential_keys_are_sanitized_to_safe_charset() {
        // Inject characters that aren't in [a-z0-9_].
        \FMW_Credential_Store::set( 'Bad-Key.With/Slashes!', 'value' );

        // The option must be stored under a sanitized name. The set()
        // call above should have stripped the bad characters down to
        // 'badkeywithslashes' (lowercase, no separators).
        $this->assertArrayHasKey(
            'fmw_credential_badkeywithslashes',
            $this->options,
            'Option name must be sanitized to safe character set [a-z0-9_]; the original mixed-case-and-symbols key must be reduced.'
        );
    }

    public function test_get_with_sanitization_finds_value_set_with_unsanitized_key() {
        // The same sanitization applies on read, so a caller who set
        // with 'Bad-Key' and later asked for 'bad-key' or 'badkey'
        // should still find their value. Tests both ends of the
        // sanitize/lookup symmetry.
        \FMW_Credential_Store::set( 'My-Token', 'secret' );

        $a = \FMW_Credential_Store::get( 'My-Token' );
        $b = \FMW_Credential_Store::get( 'mytoken' );

        $this->assertSame( 'secret', $a, 'Original key form must retrieve the value.' );
        $this->assertSame( 'secret', $b, 'Sanitized key form must retrieve the same value (sanitize is deterministic).' );
    }

    // -----------------------------------------------------------------
    // is_configured — presence check
    // -----------------------------------------------------------------

    public function test_is_configured_returns_false_for_unset_key() {
        $this->assertFalse(
            \FMW_Credential_Store::is_configured( 'never_set' )
        );
    }

    public function test_is_configured_returns_true_after_set() {
        \FMW_Credential_Store::set( 'token', 'value' );

        $this->assertTrue(
            \FMW_Credential_Store::is_configured( 'token' )
        );
    }

    public function test_is_configured_returns_false_after_delete() {
        \FMW_Credential_Store::set( 'token', 'value' );
        \FMW_Credential_Store::delete( 'token' );

        $this->assertFalse(
            \FMW_Credential_Store::is_configured( 'token' )
        );
    }

    // -----------------------------------------------------------------
    // set / delete behavior
    // -----------------------------------------------------------------

    public function test_set_with_empty_string_deletes_existing_credential() {
        \FMW_Credential_Store::set( 'token', 'real-value' );
        $this->assertTrue( \FMW_Credential_Store::is_configured( 'token' ) );

        // Per docblock: "if ( ! is_string( $value ) || $value === '' ) { return self::delete( $key ); }"
        \FMW_Credential_Store::set( 'token', '' );

        $this->assertFalse(
            \FMW_Credential_Store::is_configured( 'token' ),
            'set() with empty string must delete the existing credential — that is the documented behavior.'
        );
    }

    public function test_set_with_non_string_deletes_existing_credential() {
        \FMW_Credential_Store::set( 'token', 'real-value' );

        // Pass an array (caller error) — per the docblock should also delete.
        \FMW_Credential_Store::set( 'token', array( 'not', 'a', 'string' ) );

        $this->assertFalse(
            \FMW_Credential_Store::is_configured( 'token' ),
            'set() with non-string value must delete (defensive — never store an unencrypted serialized array).'
        );
    }

    // -----------------------------------------------------------------
    // fmw_credential filter — wp-config-driven override
    // -----------------------------------------------------------------

    public function test_fmw_credential_filter_overrides_stored_value() {
        // Filter usage: a site can hardcode a credential in wp-config.php
        // and override the stored value via the fmw_credential filter.
        // Important for CI/test environments where credentials live in
        // env vars, not in the database.
        \FMW_Credential_Store::set( 'stored_token', 'value-in-database' );

        // Override the apply_filters mock to return a specific value
        // when the credential is being looked up.
        Functions\when( 'apply_filters' )->alias( function ( $tag, $value, $key = null ) {
            if ( $tag === 'fmw_credential' && $key === 'stored_token' ) {
                return 'value-from-filter';
            }
            return $value;
        } );

        $result = \FMW_Credential_Store::get( 'stored_token' );

        $this->assertSame(
            'value-from-filter',
            $result,
            'fmw_credential filter must take precedence over stored values. This is how CI tests inject test credentials without writing them to the DB.'
        );
    }

    public function test_get_returns_null_for_completely_missing_credential() {
        // Baseline: no value stored, no filter override → null.
        // null is the "not configured" signal — distinct from
        // WP_Error which is the "stored but unreadable" signal
        // (audit item I2).
        $result = \FMW_Credential_Store::get( 'never_set' );

        $this->assertNull( $result );
    }

    // -----------------------------------------------------------------
    // Stage B — audit item I2 contract:
    //   "configured but unreadable" must surface as WP_Error so admin
    //   UIs and connector clients can distinguish it from "never
    //   configured" (which stays null) and from "ready to use" (which
    //   stays plaintext string).
    //
    // The five failure modes each get their own error code so admin
    // UI can show targeted messages.
    // -----------------------------------------------------------------

    public function test_get_returns_wp_error_when_stored_value_is_corrupted_base64() {
        // Simulate a stored value that's NOT valid base64 (corrupted
        // wp_options row, manual edit, byte truncation in storage).
        // The wrapper expected base64 → bundle_decode_failed.
        $this->options['fmw_credential_corrupt'] = '!!!not-valid-base64!!!';

        $result = \FMW_Credential_Store::get( 'corrupt' );

        $this->assertInstanceOf(
            '\\WP_Error',
            $result,
            'Corrupted stored value must surface as WP_Error so admins know to re-enter — NOT silently appear as "not configured".'
        );
        $this->assertSame( 'bundle_decode_failed', $result->get_error_code() );
    }

    public function test_get_returns_wp_error_when_stored_bundle_is_too_short() {
        // Valid base64 but the decoded bytes are shorter than the
        // minimum bundle size (1 version + 12 IV + 16 tag = 29).
        // Indicates a partially-stored bundle.
        $this->options['fmw_credential_short'] = base64_encode( 'too-short' );

        $result = \FMW_Credential_Store::get( 'short' );

        $this->assertInstanceOf( '\\WP_Error', $result );
        $this->assertSame( 'bundle_truncated', $result->get_error_code() );
    }

    public function test_get_returns_wp_error_when_bundle_version_is_unknown() {
        // Bundle version 1 is the only currently-supported version.
        // A bundle with version byte 99 (e.g., from a future plugin
        // version, or just garbage data that happens to start with
        // chr(99)) must be rejected explicitly.
        $bogus_bundle = chr( 99 ) . str_repeat( 'X', 28 );  // 1+12+16 = 29 bytes total
        $this->options['fmw_credential_future'] = base64_encode( $bogus_bundle );

        $result = \FMW_Credential_Store::get( 'future' );

        $this->assertInstanceOf( '\\WP_Error', $result );
        $this->assertSame(
            'bundle_unknown_version',
            $result->get_error_code(),
            'Unknown version byte must surface explicitly so admins can either upgrade the plugin or re-save the credential — not just see "not configured".'
        );
    }

    public function test_get_returns_wp_error_when_salt_rotates_after_storage() {
        // The classic host-migration scenario from the audit: a
        // credential is encrypted with one set of WP salts; the host
        // migrates and wp-config.php is regenerated; salts change;
        // the old ciphertext is unreadable. Without this fix, the
        // admin would see "not configured" — they'd re-enter
        // credentials with no idea the old encrypted blob was sitting
        // orphaned in wp_options.
        \FMW_Credential_Store::set( 'rotating_token', 'real-value' );

        // Simulate salt rotation by changing the wp_salt mock.
        Functions\when( 'wp_salt' )->alias( function ( $scheme = 'auth' ) {
            return 'rotated-' . $scheme;
        } );

        $result = \FMW_Credential_Store::get( 'rotating_token' );

        $this->assertInstanceOf(
            '\\WP_Error',
            $result,
            'Salt rotation must surface as WP_Error — this is the canonical real-world host-migration scenario the audit specifically called out.'
        );
        $this->assertSame( 'decryption_failed', $result->get_error_code() );
    }

    public function test_each_failure_mode_uses_distinct_error_code() {
        // The audit recommended distinct error codes per failure mode
        // so admin UIs can render targeted messages. Pin that the
        // codes are stable strings that consumers can pattern-match.
        $expected_codes = array(
            'bundle_decode_failed',
            'bundle_truncated',
            'bundle_unknown_version',
            'decryption_failed',
        );

        // Confirm that the four codes we just tested are all distinct
        // (catches accidental code collapse during refactor).
        $this->assertSame(
            count( $expected_codes ),
            count( array_unique( $expected_codes ) ),
            'Failure-mode codes must be distinct so admin UIs can render specific remediation messages.'
        );
    }
}
