# MCP Connector Setup

**Audience:** Site owners setting up the FlowMint Workflows Claude Cowork connector.
**Prerequisite reading:** `docs/CONNECTOR_API.md` (REST endpoint reference) and `docs/ARCHITECTURE.md` (workflow runtime concepts).

---

## 1. What this is

The connector is a **Node.js MCP server** that runs on your local machine and bridges Claude Desktop to your WordPress site's FlowMint REST API. Claude Cowork (the sandboxed agent) cannot make outbound HTTP requests to arbitrary WordPress sites on its own; the MCP server closes that gap by running locally and translating Cowork's tool calls into authenticated HTTPS requests to your site.

The architecture is identical to the Form Runtime Engine and Promptless WP connectors — this code is forked from FRE's connector, which was forked from Promptless's. The hard-won fixes baked in there (message framing auto-detection, protocol-version echo, ModSecurity workarounds) are preserved here so you inherit the same reliability.

```
 ┌────────────────┐    stdio     ┌──────────────────────┐   HTTPS +      ┌────────────────────┐
 │ Claude Desktop │ ◀─────────▶ │ flowmint-            │   Basic Auth   │ WordPress          │
 │  (Cowork)      │   JSON-RPC   │ connector.js         │ ◀────────────▶ │ REST API           │
 └────────────────┘              │ (on your Mac)        │                │ /wp-json/flowmint/ │
                                 └──────────────────────┘                └────────────────────┘
```

If you've already set up the FRE or Promptless connectors, this one looks and feels identical — the only differences are the Terminal install path (`~/flowmint-mcp/`), Claude Desktop config key (`flowmint-workflows`), and env-var prefix (`FLOWMINT_*`). All three connectors coexist cleanly in `claude_desktop_config.json`.

---

## 2. Requirements

- **macOS** (the shipped setup command is macOS-specific; Linux and Windows support is a future phase).
- **Node.js 14+** installed. Works with Homebrew-installed Node, the official installer, or nvm-managed versions.
- **Claude Desktop** installed and launched at least once (so its config directory exists).
- **WordPress site reachable over HTTPS** with Application Passwords enabled. WordPress enforces HTTPS for Application Passwords by default; the `WP_ENVIRONMENT_TYPE=local` constant waives this for Local by Flywheel and similar local-dev environments.
- **Form Runtime Engine 1.6.0+ active** on the same site. FlowMint depends on FRE — workflows trigger off `fre_submission_complete`, the action FRE fires after a form submission. If FRE is missing or out-of-date, FlowMint surfaces an admin notice and refuses to initialize.
- **Action Scheduler** loaded (bundled in FRE; FlowMint also ships its own copy). Verifiable on the Claude Connection page — preflight reports `action_scheduler_active: true` when it's working.

---

## 3. Setup (happy path)

Three steps, each done once per site:

1. On your WordPress site, open **FlowMint Workflows → Claude Connection** in the admin.
2. Enable the **Claude Cowork Connection** toggle (Step 1 on the page). The kill switch is **off by default**; nothing works until you flip it on. Every connector REST endpoint returns 403 `connector_disabled` while it's off — the only exception is `/preflight`, which Claude can call to discover that the kill switch is off.
3. Click **Generate Connection** (Step 2). WordPress creates an Application Password named "FlowMint Workflows — Claude Cowork" for your user, revoking any prior FlowMint connector credential for you. The password displays once — do not close the page until you have copied the bash command in Step 3.

   The bash command appears immediately after the password and looks roughly like:

   ```bash
   mkdir -p ~/flowmint-mcp && \
   curl -fsSL -A 'WordPress/FlowMintWorkflows' '{your-site}/wp-admin/admin-ajax.php?action=fmw_download_connector' -o ~/flowmint-mcp/flowmint-connector.js && \
   NODE_PATH=$(ls -d ~/.nvm/versions/node/v*/bin/node 2>/dev/null | sort -V | tail -1) ; [ -z "$NODE_PATH" ] && NODE_PATH=$(which node) ; \
   …writes claude_desktop_config.json via Node…
   ```

   Copy it and paste into Terminal.

4. Quit Claude Desktop (⌘Q) and reopen it. A new Cowork session now has access to the FlowMint tools. Try: *"Run a flowmint preflight check on my site"* — Claude should return the plugin version, FRE version, Action Scheduler status, and configured-credentials list.

---

## 4. Claude Desktop configuration

The setup command writes a `mcpServers` entry to `~/Library/Application Support/Claude/claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "flowmint-workflows": {
      "command": "/Users/you/.nvm/versions/node/v22.18.0/bin/node",
      "args": ["/Users/you/flowmint-mcp/flowmint-connector.js"],
      "env": {
        "FLOWMINT_SITE_URL": "https://example.com",
        "FLOWMINT_USERNAME": "admin",
        "FLOWMINT_APP_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx"
      }
    }
  }
}
```

The key `flowmint-workflows` is distinct from `form-engine-wordpress` (FRE) and `promptless-wordpress` (Promptless), so all three connectors run alongside each other. If you already have FRE or Promptless wired up, your existing entries stay untouched — the FlowMint setup command only adds a new entry under `mcpServers["flowmint-workflows"]`.

---

## 5. Environment variables

| Variable | Purpose | Notes |
|---|---|---|
| `FLOWMINT_SITE_URL` | Root URL of the WordPress site | Trailing slash optional. HTTPS strongly recommended; see §7 on local-dev. |
| `FLOWMINT_USERNAME` | WordPress user login (not display name) | Whatever `wp_get_current_user()->user_login` returns. |
| `FLOWMINT_APP_PASSWORD` | Application Password | Spaces in the displayed form are stripped on use; either form works. |

Never put these into a shell profile or `.env` file that might end up in version control. The setup command puts them into the Claude Desktop config file, which stays local.

---

## 6. The 16 tools

After setup, Claude has access to:

| Category | Tools |
|---|---|
| **Discovery** | `flowmint_preflight` |
| **Workflows** | `flowmint_list_workflows`, `flowmint_get_workflow`, `flowmint_create_workflow`, `flowmint_update_workflow`, `flowmint_delete_workflow`, `flowmint_test_workflow` |
| **Runs** | `flowmint_list_runs`, `flowmint_get_run`, `flowmint_replay_run` |
| **Step types** | `flowmint_list_step_types`, `flowmint_get_step_type` |
| **Credentials** | `flowmint_list_credentials`, `flowmint_test_credential` |
| **Templates** | `flowmint_list_templates`, `flowmint_get_template` |

`flowmint_preflight` is the tool to call first in any session — it returns the schema doc URL, configured credentials, FRE/Action Scheduler status, and the kill-switch state. The MCP server's tool descriptions instruct Claude to call it before creating workflows.

`flowmint_list_step_types` is the catalog of what each FlowMint install supports (Drive, Email, Printavo, HTTP, conditional, log_*, set_variable, fre_*, etc.). Each step type's config schema is part of the response, so Claude can build a workflow JSON without guessing field names.

Credential **values** are intentionally not settable through the MCP. Storing service account JSON or API tokens has to happen in WP admin so the credentials encryption stays under the site owner's control. The MCP can introspect (`flowmint_list_credentials`) and test (`flowmint_test_credential`) but never read or write the plaintext.

---

## 7. Troubleshooting

### Preflight shows `connector_enabled: false` even though I toggled it on

The toggle is per-site. Check that you're hitting the right WordPress site — `FLOWMINT_SITE_URL` in your Claude Desktop config must point at the same install where you flipped the toggle. Cross-site mistakes happen often when a site has staging and production with similar URLs.

If the URL is right and preflight still shows `false`, the option may not have saved. Refresh the Claude Connection page; if the toggle appears off, flip it on again. The toggle uses an AJAX save, so a flaky network can leave the UI looking on while the server-side option is unchanged.

### `connector_disabled` 403 on every endpoint except preflight

That's the kill switch doing its job. Open WP admin → FlowMint Workflows → Claude Connection → flip "Enable Claude Cowork Connection" on. The response code is intentional — preflight stays open so Claude can detect the disabled state and tell you, rather than every other endpoint silently failing.

### `Authentication required` on every REST call

Same diagnostic flow as the FRE connector — see `form-runtime-engine/docs/MCP_CONNECTOR_SETUP.md` §6 for the full troubleshooting tree. The most common cause is the `Authorization` header being stripped by nginx/Apache before it reaches PHP. Local by Flywheel's default config strips it for `/wp-json/` routes; managed hosts (Kinsta, WP Engine, SiteGround) handle it correctly.

### Claude Desktop picks the wrong Node version

The setup command picks the highest nvm-installed Node by default, falling back to `which node`. If Claude Desktop spawns the connector and it crashes immediately, the chosen Node may be too old (< 14). Open `~/Library/Application Support/Claude/claude_desktop_config.json`, find the `flowmint-workflows.command` field, and edit it to point at a known-good Node binary (e.g. `/opt/homebrew/bin/node`). Restart Claude Desktop.

### `flowmint_create_workflow` returns `dependency_missing` when testing a credential

Step types that depend on Composer-loaded vendor libraries (Google API client for Drive, Guzzle for Printavo) require `composer install` to have run in the plugin directory. If a test like `flowmint_test_credential` for `drive_service_account` returns `{ "test_result": "failed", "error_code": "dependency_missing" }`, run `composer install` over SSH on the host (or via your hosting provider's PHP shell) and try again.

### Workflows don't fire when forms are submitted

Run `flowmint_preflight` and check `action_scheduler_active`. If `false`, Action Scheduler isn't loaded — usually means `composer install` hasn't run in the FlowMint plugin directory. Run it.

If `action_scheduler_active: true` but workflows still don't fire, check that the workflow's `enabled` flag is true (`flowmint_get_workflow` shows it) and that its `form_id` matches the actual form being submitted. Workflows trigger off `fre_submission_complete` keyed on the form_id field.

### I want to revoke Cowork access immediately

Two equivalent options:

1. **Soft revoke (most common):** Flip the "Enable Claude Cowork Connection" toggle off. Every endpoint except preflight returns 403 `connector_disabled`. Re-enabling later does NOT require regenerating the App Password — the connector picks back up where it left off.
2. **Hard revoke:** Click "Revoke Connection" on the admin page. This deletes the App Password from WordPress core's Application Passwords store. The connector will start returning 401 Unauthorized until you generate a new one.

Use soft revoke for a quick "pause" — switching from staging to production, debugging an unauthorized-access concern, etc. Use hard revoke if you suspect the credential leaked.

---

## 8. Architectural notes (for engineers)

The MCP server file lives at `includes/Connectors/MCP/assets/flowmint-connector.js` inside the WordPress plugin. It is **not** a separately-distributed npm package — it ships with the plugin and is served to user machines via the unauthenticated `admin-ajax.php?action=fmw_download_connector` action handler. The file contains no secrets; credentials are passed at runtime via env vars.

The admin page (`includes/Connectors/MCP/class-fmw-connector-admin.php`) registers under the `fmw-runs` parent menu and uses WordPress core's `WP_Application_Passwords` for credential issuance. The plugin never sees or stores the plaintext password — it's returned once in the AJAX response, displayed once in the UI, and inserted directly into the user's local Claude Desktop config via the install command.

The kill switch is a single boolean option `fmw_connector_enabled` in `wp_options`. `FMW_REST_Auth::require_manage()` checks it on every endpoint except `/preflight`. The exemption for `/preflight` is deliberate: Claude must be able to detect "the connector is off" via a distinguishable response (preflight succeeds, returns `connector_enabled: false`) rather than getting a generic 403 it can't interpret.

The two-gate model (kill switch + manage_options) is borrowed from FRE's design (`docs/COWORK_CONNECTOR_ASSESSMENT.md` §5 in the FRE plugin). The reasoning carries over: a single capability check is the floor for any WordPress endpoint; the kill switch lets the site owner disable remote access without revoking the underlying user's manage_options capability.
