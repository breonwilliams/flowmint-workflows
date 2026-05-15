<?php
/**
 * Capability management for FlowMint Workflows.
 *
 * Centralizes the plugin's custom capabilities so callers never hard-code
 * capability strings. All workflow management and connector REST checks
 * route through the MANAGE_WORKFLOWS constant.
 *
 * Capability model:
 *   - `flowmint_manage_workflows`: Controls access to the FlowMint admin UI,
 *     the workflow CRUD endpoints, AJAX handlers, and the Cowork connector.
 *
 * Granted to the `administrator` role by default on install and on every
 * plugin-version upgrade. Site operators can extend the default-grant list
 * via the `flowmint_default_manage_workflows_roles` filter (fires during
 * activation + version-upgrade only — does NOT fire on every page load).
 *
 * Pattern mirrors FRE_Capabilities and the broader convention across the
 * Promptless / PRE / FRE / FlowMint plugin family. Each plugin owns its
 * own scoped capability so multi-user sites (agencies, e-commerce teams,
 * nonprofit volunteer setups) don't have to grant `manage_options` —
 * which is WordPress's super-admin grant — just to use the connector.
 *
 * Pre-v0.6.x sites used `current_user_can('manage_options')` for every
 * gate. Existing administrators are unaffected: the new capability gets
 * granted to administrator on the next page load after the upgrade runs,
 * so admin access continues uninterrupted. Non-admin roles that were
 * previously locked out can now be granted access cleanly.
 *
 * @package FlowMintWorkflows
 * @since 0.7.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Capability helper.
 */
class FMW_Capabilities {

    /**
     * Primary capability for managing workflows, the admin UI, and the
     * Cowork connector.
     *
     * Use `current_user_can( FMW_Capabilities::MANAGE_WORKFLOWS )` anywhere
     * a check is needed. Do not hard-code the string elsewhere.
     *
     * @var string
     */
    const MANAGE_WORKFLOWS = 'flowmint_manage_workflows';

    /**
     * Roles that receive MANAGE_WORKFLOWS by default on install and on
     * every plugin-version upgrade.
     *
     * Extendable via the `flowmint_default_manage_workflows_roles` filter
     * so site owners can opt additional roles in at activation time
     * rather than granting the capability manually after the fact.
     *
     * @return array Array of role slugs.
     */
    public static function default_roles() {
        /**
         * Filters the roles that receive the MANAGE_WORKFLOWS capability
         * by default.
         *
         * Fires during activation and plugin-version upgrades. Does not
         * fire on every page load.
         *
         * Example — also grant to editors at activation time:
         *
         *     add_filter( 'flowmint_default_manage_workflows_roles', function ( $roles ) {
         *         $roles[] = 'editor';
         *         return $roles;
         *     } );
         *
         * @param array $roles Default roles (administrator only by default).
         */
        return apply_filters(
            'flowmint_default_manage_workflows_roles',
            array( 'administrator' )
        );
    }

    /**
     * Grant the MANAGE_WORKFLOWS capability to the default roles.
     *
     * Idempotent: WordPress's `add_cap()` is safe to call repeatedly. Only
     * persists to the database when the capability is not already present
     * on the role.
     *
     * Called from:
     *   - Plugin activation hook (fresh installs)
     *   - `maybe_run_db_migration()` on every plugin-version upgrade (so
     *     existing sites that update via wp-cli or auto-update still pick
     *     up the capability without manual re-activation)
     */
    public static function grant_default_capabilities() {
        foreach ( self::default_roles() as $role_slug ) {
            $role = get_role( $role_slug );

            if ( null === $role ) {
                continue;
            }

            if ( ! $role->has_cap( self::MANAGE_WORKFLOWS ) ) {
                $role->add_cap( self::MANAGE_WORKFLOWS );
            }
        }
    }

    /**
     * Remove the MANAGE_WORKFLOWS capability from every role.
     *
     * Called only during plugin uninstall. Iterates ALL roles (not just
     * the default-granted ones) because admins may have delegated the
     * capability to custom roles via `flowmint_default_manage_workflows_roles`
     * or directly via `WP_Role::add_cap()`. Uninstall must clean up all traces.
     */
    public static function revoke_all_capabilities() {
        $roles = wp_roles();

        if ( ! $roles instanceof WP_Roles ) {
            return;
        }

        foreach ( array_keys( $roles->role_objects ) as $role_slug ) {
            $role = get_role( $role_slug );

            if ( null === $role ) {
                continue;
            }

            if ( $role->has_cap( self::MANAGE_WORKFLOWS ) ) {
                $role->remove_cap( self::MANAGE_WORKFLOWS );
            }
        }
    }

    /**
     * Convenience check for the current user.
     *
     * Use sparingly — most call sites should call `current_user_can()`
     * directly so the capability string appears in a WordPress-standard
     * pattern. This helper exists for code paths that test the capability
     * multiple times in close proximity.
     *
     * @return bool True if the current user has MANAGE_WORKFLOWS.
     */
    public static function current_user_can_manage_workflows() {
        return current_user_can( self::MANAGE_WORKFLOWS );
    }

    /**
     * Resolve the capability name to use for permission checks.
     *
     * Filterable so site operators can swap the required capability
     * without forking the plugin. Use case: an enterprise install that
     * wants connector access gated on a different (more restrictive or
     * more permissive) capability — e.g. an SSO-driven role that maps
     * cleanly to one specific WordPress capability.
     *
     * Call sites that need the canonical capability name use this
     * accessor instead of the constant directly:
     *
     *     if ( ! current_user_can( FMW_Capabilities::required_capability() ) ) { ... }
     *
     * Default returns the MANAGE_WORKFLOWS constant unchanged.
     *
     * @return string Capability name to require.
     */
    public static function required_capability() {
        /**
         * Filter the capability required to manage FlowMint workflows.
         *
         * Defaults to `flowmint_manage_workflows`. Override sparingly —
         * the standard capability is the right answer for nearly all
         * deployments. Use this filter only when integrating with an
         * external authorization system that maps to a different cap.
         *
         * @param string $capability Default `flowmint_manage_workflows`.
         */
        return (string) apply_filters(
            'flowmint_manage_workflows_capability',
            self::MANAGE_WORKFLOWS
        );
    }
}
