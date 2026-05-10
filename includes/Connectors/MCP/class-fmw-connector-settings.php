<?php
/**
 * Cowork connector settings storage.
 *
 * Central accessor for the persisted connector state:
 *   - Whether the connector is enabled site-wide (default off — same two-gate
 *     pattern Form Runtime Engine uses; the user must explicitly opt in
 *     before the REST API will respond to authenticated requests).
 *   - Whether the current user has a configured App Password for the
 *     connector (stored per-user as user meta — informational only, the
 *     actual credential lives in WordPress core's Application Password
 *     storage which this plugin never reads or caches).
 *
 * Deliberately a pure data-access class: no UI, no REST, no auth decisions.
 * FMW_REST_Auth reads the toggle, FMW_Connector_Admin writes it.
 *
 * @package FlowMintWorkflows
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Connector settings accessor.
 */
class FMW_Connector_Settings {

    /**
     * Option key storing the outer toggle ("Enable Claude Cowork Connection").
     *
     * @var string
     */
    const OPTION_ENABLED = 'fmw_connector_enabled';

    /**
     * User meta key marking that the current user has generated a connector
     * App Password at some point. Presence is an informational hint for the
     * admin UI; the actual credential lives in WordPress core Application
     * Password storage.
     *
     * @var string
     */
    const USER_META_CONFIGURED = '_fmw_connector_configured_at';

    /**
     * App Password "name" used when creating the connector credential via
     * WP_Application_Passwords::create_new_application_password(). Used as
     * the matching key when we need to revoke the connector's prior
     * credential before creating a new one.
     *
     * @var string
     */
    const APP_PASSWORD_NAME = 'FlowMint Workflows — Claude Cowork';

    /**
     * Is the connector enabled site-wide?
     *
     * @return bool
     */
    public static function is_enabled() {
        return (bool) get_option( self::OPTION_ENABLED, false );
    }

    /**
     * Enable or disable the connector.
     *
     * @param bool $enabled Desired state.
     * @return bool True on success.
     */
    public static function set_enabled( $enabled ) {
        return update_option( self::OPTION_ENABLED, (bool) $enabled );
    }

    /**
     * Has the given user configured a connector credential?
     *
     * Returns the timestamp of configuration (int) or 0 if never configured.
     *
     * @param int $user_id User ID. Defaults to current user.
     * @return int Unix timestamp or 0.
     */
    public static function configured_at( $user_id = 0 ) {
        if ( 0 === $user_id ) {
            $user_id = get_current_user_id();
        }
        if ( ! $user_id ) {
            return 0;
        }
        return (int) get_user_meta( $user_id, self::USER_META_CONFIGURED, true );
    }

    /**
     * Mark the given user as having configured a connector credential.
     *
     * Called after the "Generate Connection" AJAX handler successfully
     * creates a new Application Password.
     *
     * @param int $user_id User ID. Defaults to current user.
     * @return bool
     */
    public static function mark_configured( $user_id = 0 ) {
        if ( 0 === $user_id ) {
            $user_id = get_current_user_id();
        }
        if ( ! $user_id ) {
            return false;
        }
        return (bool) update_user_meta( $user_id, self::USER_META_CONFIGURED, time() );
    }

    /**
     * Clear the configured-at marker.
     *
     * Called after the "Revoke Connection" AJAX handler removes the
     * Application Password.
     *
     * @param int $user_id User ID. Defaults to current user.
     * @return bool
     */
    public static function clear_configured( $user_id = 0 ) {
        if ( 0 === $user_id ) {
            $user_id = get_current_user_id();
        }
        if ( ! $user_id ) {
            return false;
        }
        return (bool) delete_user_meta( $user_id, self::USER_META_CONFIGURED );
    }

    /**
     * Remove all connector settings.
     *
     * Called from uninstall.php so a plugin uninstall leaves no state behind.
     */
    public static function delete_all() {
        delete_option( self::OPTION_ENABLED );

        // Clean up user meta markers. This is a small DB scan but only runs
        // during uninstall, so it's acceptable.
        global $wpdb;
        if ( isset( $wpdb ) ) {
            $wpdb->delete(
                $wpdb->usermeta,
                array( 'meta_key' => self::USER_META_CONFIGURED )
            );
        }
    }
}
