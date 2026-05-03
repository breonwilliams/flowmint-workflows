<?php
/**
 * Encrypted credential store.
 *
 * Stores API tokens, service account JSON, etc. in wp_options with
 * AES-256-GCM encryption. The encryption key is derived from wp_salt('auth')
 * combined with a per-install nonce stored separately.
 *
 * Never returns plaintext via REST. Never logs values.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Credential_Store {

    const OPTION_PREFIX  = 'fmw_credential_';
    const NONCE_OPTION   = 'fmw_credential_install_nonce';
    const CIPHER         = 'aes-256-gcm';

    /**
     * Get a credential by key. Returns plaintext value or null.
     *
     * @param string $key e.g., "drive_service_account", "printavo_api_token"
     * @return string|null
     */
    public static function get( $key ) {
        // Allow filter overrides (e.g., wp-config.php constants for CI/test).
        $filtered = apply_filters( 'fmw_credential', null, $key );
        if ( $filtered !== null ) {
            return $filtered;
        }

        $stored = get_option( self::option_name( $key ), null );
        if ( $stored === null || $stored === false || $stored === '' ) {
            return null;
        }

        return self::decrypt( $stored );
    }

    /**
     * Set a credential. Encrypts before storage.
     *
     * @param string $key
     * @param string $value
     * @return bool
     */
    public static function set( $key, $value ) {
        if ( ! is_string( $value ) || $value === '' ) {
            return self::delete( $key );
        }

        $encrypted = self::encrypt( $value );
        if ( $encrypted === false ) {
            FMW_Logger::error( 'Credential encryption failed', [ 'key' => $key ] );
            return false;
        }

        return update_option( self::option_name( $key ), $encrypted, false );
    }

    /**
     * Delete a credential.
     *
     * @param string $key
     * @return bool
     */
    public static function delete( $key ) {
        return delete_option( self::option_name( $key ) );
    }

    /**
     * Check whether a credential is configured (without retrieving the value).
     *
     * @param string $key
     * @return bool
     */
    public static function is_configured( $key ) {
        $stored = get_option( self::option_name( $key ), null );
        return ! empty( $stored );
    }

    /**
     * Get the option name for a credential key.
     *
     * @param string $key
     * @return string
     */
    private static function option_name( $key ) {
        // Sanitize the key — only allow alphanumeric + underscore.
        $key = preg_replace( '/[^a-z0-9_]/', '', strtolower( $key ) );
        return self::OPTION_PREFIX . $key;
    }

    /**
     * Encrypt a value.
     *
     * @param string $plaintext
     * @return string|false Base64-encoded ciphertext bundle, or false on error.
     */
    private static function encrypt( $plaintext ) {
        if ( ! function_exists( 'openssl_encrypt' ) ) {
            return false;
        }

        $key = self::derive_key();
        $iv  = openssl_random_pseudo_bytes( 12 ); // 96 bits for GCM
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '', // additional auth data
            16  // tag length
        );

        if ( $ciphertext === false ) {
            return false;
        }

        // Bundle: version || iv || tag || ciphertext, then base64.
        $bundle = chr( 1 ) . $iv . $tag . $ciphertext;
        return base64_encode( $bundle );
    }

    /**
     * Decrypt a value.
     *
     * @param string $encoded Base64-encoded ciphertext bundle
     * @return string|null
     */
    private static function decrypt( $encoded ) {
        if ( ! function_exists( 'openssl_decrypt' ) ) {
            return null;
        }

        $bundle = base64_decode( $encoded, true );
        if ( $bundle === false || strlen( $bundle ) < 1 + 12 + 16 ) {
            return null;
        }

        $version = ord( $bundle[0] );
        if ( $version !== 1 ) {
            return null; // unknown version
        }

        $iv         = substr( $bundle, 1, 12 );
        $tag        = substr( $bundle, 13, 16 );
        $ciphertext = substr( $bundle, 29 );

        $key = self::derive_key();

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            ''
        );

        return $plaintext === false ? null : $plaintext;
    }

    /**
     * Derive the encryption key from wp_salt + a per-install nonce.
     *
     * @return string 32 raw bytes
     */
    private static function derive_key() {
        $nonce = get_option( self::NONCE_OPTION, null );
        if ( $nonce === null || $nonce === false ) {
            $nonce = bin2hex( openssl_random_pseudo_bytes( 16 ) );
            update_option( self::NONCE_OPTION, $nonce, false );
        }

        $material = wp_salt( 'auth' ) . '|' . $nonce . '|fmw-credentials';
        return hash( 'sha256', $material, true ); // 32 raw bytes
    }
}
