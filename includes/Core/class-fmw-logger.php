<?php
/**
 * FlowMint Workflows logger.
 *
 * Wraps WordPress error_log with structured context and PII masking.
 * Mirrors FRE_Logger's interface where possible for consistency.
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Logger {

    const LEVEL_DEBUG   = 'debug';
    const LEVEL_INFO    = 'info';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR   = 'error';

    /**
     * Log levels in order of severity.
     */
    private static $level_priority = [
        self::LEVEL_DEBUG   => 10,
        self::LEVEL_INFO    => 20,
        self::LEVEL_WARNING => 30,
        self::LEVEL_ERROR   => 40,
    ];

    /**
     * Default minimum level (anything below is suppressed).
     *
     * Override via the `fmw_log_min_level` filter.
     */
    private static $min_level = self::LEVEL_INFO;

    /**
     * Log a debug message. Only emitted when FMW_DEBUG is true OR min_level lowered.
     *
     * @param string $message
     * @param array  $context Structured context fields.
     */
    public static function debug( $message, array $context = [] ) {
        if ( ! ( defined( 'FMW_DEBUG' ) && FMW_DEBUG ) ) {
            return;
        }
        self::log( self::LEVEL_DEBUG, $message, $context );
    }

    public static function info( $message, array $context = [] ) {
        self::log( self::LEVEL_INFO, $message, $context );
    }

    public static function warning( $message, array $context = [] ) {
        self::log( self::LEVEL_WARNING, $message, $context );
    }

    public static function error( $message, array $context = [] ) {
        self::log( self::LEVEL_ERROR, $message, $context );
    }

    /**
     * Core logging method.
     *
     * @param string $level
     * @param string $message
     * @param array  $context
     */
    private static function log( $level, $message, array $context ) {
        $min_level = apply_filters( 'fmw_log_min_level', self::$min_level );

        if ( self::$level_priority[ $level ] < self::$level_priority[ $min_level ] ) {
            return;
        }

        // Mask PII in context values.
        $masked_context = self::mask_pii( $context );

        // Format: [FMW][level] message {context_json}
        $formatted = sprintf(
            '[FMW][%s] %s %s',
            strtoupper( $level ),
            $message,
            $masked_context ? wp_json_encode( $masked_context ) : ''
        );

        // Write to PHP error log if WP_DEBUG_LOG is enabled.
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( $formatted );
        }

        // Fire an action for downstream listeners (e.g., Sentry, Slack).
        do_action( 'fmw_log', $level, $message, $masked_context );
    }

    /**
     * Mask PII in context values.
     *
     * - Email addresses: foo@bar.com → f**@b**.com
     * - Recursively processes arrays.
     * - Strips known credential keys entirely.
     *
     * @param array $context
     * @return array
     */
    public static function mask_pii( array $context ) {
        $sensitive_keys = [ 'password', 'token', 'api_key', 'api_token', 'secret', 'authorization' ];

        $masked = [];
        foreach ( $context as $key => $value ) {
            $lower_key = strtolower( (string) $key );
            $is_sensitive = false;
            foreach ( $sensitive_keys as $needle ) {
                if ( strpos( $lower_key, $needle ) !== false ) {
                    $is_sensitive = true;
                    break;
                }
            }

            if ( $is_sensitive ) {
                $masked[ $key ] = '<redacted>';
                continue;
            }

            if ( is_array( $value ) ) {
                $masked[ $key ] = self::mask_pii( $value );
                continue;
            }

            if ( is_string( $value ) ) {
                $masked[ $key ] = self::mask_email_in_string( $value );
                continue;
            }

            $masked[ $key ] = $value;
        }

        return $masked;
    }

    /**
     * Mask email addresses found in a string.
     *
     * @param string $value
     * @return string
     */
    private static function mask_email_in_string( $value ) {
        return preg_replace_callback(
            '/([a-zA-Z0-9._-])([a-zA-Z0-9._+-]*?)@([a-zA-Z0-9])([a-zA-Z0-9.-]*?)\.([a-zA-Z]{2,})/',
            function( $matches ) {
                return $matches[1] . '***@' . $matches[3] . '***.' . $matches[5];
            },
            $value
        );
    }
}
