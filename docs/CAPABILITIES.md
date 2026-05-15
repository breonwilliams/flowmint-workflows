# FlowMint Capabilities

FlowMint registers one custom WordPress capability and uses it consistently across all admin UIs, AJAX handlers, REST endpoints, and the Cowork connector.

## The capability

**`flowmint_manage_workflows`** — Controls access to the FlowMint admin UI (Run History, Workflows, Claude Connection submenus), all AJAX handlers, the connector REST endpoints, and the Cowork MCP tools.

Constant in code: `FMW_Capabilities::MANAGE_WORKFLOWS`.

## Default role grants

On plugin activation and on every plugin-version upgrade, the capability is granted to:

- `administrator`

The grant is idempotent — WordPress's `add_cap()` is a no-op when the role already has the capability, so multiple calls are safe.

## Granting to other roles

### Option A — Hook the filter (preferred)

Add this snippet to a site-specific plugin or your theme's `functions.php`. Fires once at activation / version-upgrade time:

```php
add_filter( 'flowmint_default_manage_workflows_roles', function ( $roles ) {
    $roles[] = 'editor';      // also grant to editors
    $roles[] = 'shop_manager'; // also grant to WooCommerce shop managers
    return $roles;
} );
```

After adding the filter, deactivate and reactivate FlowMint (or trigger a version bump) for the grant to apply to the new roles.

### Option B — Grant manually via WP-CLI

For one-off grants without changing code:

```bash
wp cap add editor flowmint_manage_workflows
wp cap add shop_manager flowmint_manage_workflows
```

### Option C — Grant programmatically

For dynamic role-management plugins (Members, User Role Editor, etc.), use the standard `WP_Role::add_cap()` API:

```php
$role = get_role( 'editor' );
if ( $role ) {
    $role->add_cap( 'flowmint_manage_workflows' );
}
```

## Overriding the required capability (enterprise)

For enterprise installs that want connector access gated on a different (more restrictive or more permissive) capability — e.g. an SSO-driven role that maps cleanly to one specific WordPress capability — use the `flowmint_manage_workflows_capability` filter:

```php
add_filter( 'flowmint_manage_workflows_capability', function () {
    return 'my_custom_workflow_admin_cap';
} );
```

This swaps the capability that REST endpoints check, but does NOT change which capability is granted on activation. Use sparingly — the default capability is the right answer for nearly all deployments.

## Migrating from pre-v0.7.0

Before v0.7.0, FlowMint checked `manage_options` everywhere — meaning only WordPress super-admins could use the connector or admin UI. The v0.7.0 upgrade introduces `flowmint_manage_workflows` and:

1. **Existing administrators:** unaffected. The new capability is granted to administrator on the first page load after the upgrade runs (via the version-bump hook), so admin access continues uninterrupted.
2. **Non-admin roles previously locked out:** can now be granted access cleanly via any of the three methods above.
3. **Filter overrides:** if a site previously used `user_has_cap` filters to grant `manage_options` solely for FlowMint access, those can now be removed in favor of the scoped capability.

## Cleanup on uninstall

When the plugin is deleted (not just deactivated) via the WP Plugins admin page, FlowMint's `uninstall.php` calls `FMW_Capabilities::revoke_all_capabilities()`, which iterates every WordPress role and removes the capability. This catches custom roles that admins may have granted the capability to via `add_cap` directly.

## Pattern parity across the Promptless plugin family

This pattern aligns with:

- **FRE (Form Runtime Engine):** `fre_manage_forms` via `FRE_Capabilities::MANAGE_FORMS`
- **PRE (Post Runtime Engine):** `pre_manage_cpts` via `PRE_Capabilities::MANAGE_CAP` (v0.4+; see [PRE CAPABILITIES.md](../../post-runtime-engine/docs/CAPABILITIES.md))
- **Promptless WP:** `promptless_manage_settings` via `\AISB\Modern\Core\Capabilities::MANAGE_SETTINGS` (v1.4+; see [Promptless CAPABILITIES.md](../../ai-section-builder-modern/docs/development/CAPABILITIES.md))

Each plugin owns its own scoped capability. Multi-user sites (agencies with client editors, e-commerce teams with marketing roles, nonprofit volunteer setups) can grant per-plugin access without giving up site-wide super-admin.

### Capability summary across the family

| Plugin | Capability | Constant | Granted to (default) |
|---|---|---|---|
| Form Runtime Engine | `fre_manage_forms` | `FRE_Capabilities::MANAGE_FORMS` | `administrator` |
| FlowMint Workflows | `flowmint_manage_workflows` | `FMW_Capabilities::MANAGE_WORKFLOWS` | `administrator` |
| Post Runtime Engine | `pre_manage_cpts` | `PRE_Capabilities::MANAGE_CAP` | `administrator` |
| Promptless WP | `promptless_manage_settings` | `\AISB\Modern\Core\Capabilities::MANAGE_SETTINGS` | `administrator` |

Each plugin's `default_*_roles()` (or equivalent) is filterable so the same site-wide grant pattern works on any role model. Each plugin's `revoke_all_capabilities()` runs on uninstall so role tables stay clean.
