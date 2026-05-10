# Next-Session Handoff: Build the FlowMint MCP Connector

**Authored:** 2026-05-10 by the previous Claude session as a handoff document.
**Estimated effort:** 4–6 hours focused work.
**Goal:** Make FlowMint workflows manageable through Claude Desktop, exactly the way FRE forms and PRE post types are today.

---

## What this session is

Breon (the user) needs FlowMint workflows to be reachable from Claude Desktop via the connector pattern. The WordPress-side REST connector is already built (`flowmint/v1/connector/*` — auth, preflight, workflows, runs, step types, credentials, templates). What's missing is the small client-side bridge: a stdio MCP server file shipped inside this plugin, plus the admin page that hands the user a one-line terminal command to install it.

Once shipped, Breon will install the new plugin build on a client site, open WP admin → FlowMint → Claude Connection, click "generate command," paste the command into Terminal, and restart Claude Desktop. After that, he can describe a workflow to Claude in plain English and Claude will build the JSON via MCP tool calls — same way Promptless / FRE / PRE work today.

## Folders to mount in the Cowork session

You need write access to FlowMint (where you'll be adding files) and read access to the three reference plugins. Mount all four:

- `flowmint-workflows` — the plugin being modified (write access)
- `form-runtime-engine` — closest reference implementation; FRE's connector pattern is the one to copy line-for-line
- `post-runtime-engine` — secondary reference; cross-check details
- `ai-section-builder-modern` — Promptless plugin; the original reference for the in-plugin connector pattern

You do NOT need the `promptless-theme` folder. The connector pattern is entirely inside the plugin folders.

## Critical mental model — read this before writing any code

The MCP connector is **shipped as a single Node.js file inside the WordPress plugin**, not as a separate npm package or external repo. The pattern across all three working plugins is identical:

| Plugin | MCP server file | Admin class |
|---|---|---|
| Promptless | `includes/Connector/assets/wordpress-connector.js` | `includes/Admin/ConnectorSettings.php` |
| FRE | `includes/Connector/assets/form-engine-connector.js` | `includes/Connector/class-fre-connector-admin.php` |
| PRE | `includes/Connector/assets/post-runtime-connector.js` | `includes/Connector/class-pre-connector-admin.php` |
| **FlowMint** | **add this** | **add this** |

How the install flow works end-to-end:

1. The plugin ships `<plugin>-connector.js` as a static asset. It's a stdio MCP server — a Node.js script that speaks JSON-RPC on stdin/stdout, makes HTTPS calls to the WP REST API, and exposes a fixed list of tools to Claude Desktop.
2. The admin class registers a WP admin submenu page where the user generates an Application Password and sees a one-line terminal command to copy.
3. The terminal command does two things: (a) downloads the JS file from the WordPress site via an `admin-ajax.php` action handler in the admin class, saving it to a folder on the user's Mac; (b) writes one entry into Claude Desktop's config telling it to spawn that local JS file with the right env vars (site URL, username, app password).
4. After a Claude Desktop restart, the MCP server is live. Tools like `flowmint_list_workflows` become callable from Claude.

**Important: do not over-engineer this.** No separate repo. No npm package. No build pipeline. Just two files added inside the FlowMint plugin folder. Copy FRE's pattern and adapt it.

## Step 1 — Read the reference implementations first

Before writing any code, read these in full. They are your spec.

- `form-runtime-engine/includes/Connector/assets/form-engine-connector.js` — 650 lines. This is the MCP server. Read the whole thing. Pay attention to:
  - The TOOLS array at the top (each tool's name, description, inputSchema)
  - How HTTPS requests are constructed and authenticated (Basic auth with Application Password)
  - How tool calls are routed to the right REST endpoint
  - Error handling and JSON-RPC envelope
- `form-runtime-engine/includes/Connector/class-fre-connector-admin.php` — 596 lines. This is the admin page. Read for:
  - Submenu registration and rendering
  - Application Password generation/revocation AJAX handlers
  - The `download_connector` AJAX action that serves the JS file
  - The terminal command generation logic (the magic that produces the copy-paste line)
- `post-runtime-engine/includes/Connector/class-pre-connector-admin.php` — read briefly. Same pattern as FRE but PRE-flavored. Cross-reference for anything that looks unclear in FRE.

Don't skim. The connector pattern has subtle pieces (env-var passing, password regeneration on revoke, license gating, SSL check, AJAX nonce wiring) that are easy to miss if you don't read line by line.

## Step 2 — FlowMint REST routes you need to wrap

The MCP server will expose tools that map 1:1 to FlowMint's existing REST routes. Here's the route inventory (read from `includes/Connectors/REST/class-fmw-rest-*.php`):

- `GET /preflight` → `flowmint_preflight` — auth check, plugin version, capability flags
- `GET /workflows` → `flowmint_list_workflows` — list all stored workflows, supports `managed_by` filter
- `GET /workflows/{id}` → `flowmint_get_workflow` — fetch one workflow with its config JSON
- `POST /workflows` → `flowmint_create_workflow` — create new workflow, tag `managed_by: connector:cowork`
- `PATCH /workflows/{id}` → `flowmint_update_workflow` — partial update; respect `connector_version` for concurrency
- `DELETE /workflows/{id}` → `flowmint_delete_workflow` — soft delete (FlowMint preserves run history by design)
- `POST /workflows/{id}/test` → `flowmint_test_workflow` — dry-run the workflow without enqueuing
- `GET /runs` → `flowmint_list_runs` — paginated run history with status filter
- `GET /runs/{id}` → `flowmint_get_run` — single run with step-by-step status
- `POST /runs/{id}/replay` → `flowmint_replay_run` — re-enqueue a failed run
- `GET /step-types` → `flowmint_list_step_types` — discoverable step types and their config schemas
- `GET /step-types/{type}` → `flowmint_get_step_type` — single step type details
- `GET /credentials` → `flowmint_list_credentials` — list credential keys (NEVER expose values — see FMW_Credential_Store contract)
- `POST /credentials/{key}/test` → `flowmint_test_credential` — test a stored credential against its target service
- `GET /templates` → `flowmint_list_templates` — list available email templates
- `GET /templates/{name}` → `flowmint_get_template` — single template content

Confirm the exact route shapes by reading each `class-fmw-rest-*.php` file directly. The list above is from the previous session's grep — verify before coding.

## Step 3 — Files to create

### 3a. `includes/Connector/assets/flowmint-connector.js`

Adapt FRE's `form-engine-connector.js`. Key adaptations:

- Rename the MCP server identifier from `form-engine` → `flowmint`
- Rename env vars: `FRE_SITE_URL` / `FRE_USERNAME` / `FRE_APP_PASSWORD` → `FMW_SITE_URL` / `FMW_USERNAME` / `FMW_APP_PASSWORD`
- Replace the TOOLS array contents with the 16 FlowMint tools listed above
- Replace REST route paths: `/fre/v1/connector/*` → `/flowmint/v1/connector/*`
- For tool descriptions, model after FRE's style — but reference the FlowMint architecture doc (`docs/ARCHITECTURE.md`) and the step-type registry rather than form schema docs
- Preserve FRE's preflight pattern: return a `schema_reference_url` plus inline `critical_rules` so the consuming Claude agent has the contract on first call
- Tag every workflow created via this MCP with `managed_by: connector:cowork`

### 3b. `includes/Connector/class-fmw-connector-admin.php`

Adapt FRE's `class-fre-connector-admin.php`. Key adaptations:

- Rename class `FRE_Connector_Admin` → `FMW_Connector_Admin`
- Rename hooks/actions: `fre_*` → `fmw_*`
- Change submenu parent from FRE's slug to FlowMint's admin slug (check FlowMint's main plugin file for the slug — likely `flowmint-workflows`)
- Update the App Password naming pattern: the existing-password lookup should match names containing "FlowMint" or "Claude FlowMint"
- Update the `download_connector` AJAX handler to serve `flowmint-connector.js` instead of `form-engine-connector.js`
- Update the install-command template to write a Claude Desktop config entry named `flowmint-workflows` (or `flowmint`, pick one and document it)
- **No license gate.** FlowMint is a FREE plugin — only Promptless uses Freemius. The connector admin page should be visible to any user with `manage_options` capability, no premium check, no `can_use_premium()` call. If FRE's reference implementation has a license gate, strip it for the FlowMint port.

### 3c. Wire it up in `flowmint-workflows.php` (main plugin file)

Add the FMW_Connector_Admin instantiation alongside FlowMint's existing admin classes. Visible to any user with `manage_options`. No premium gate — FlowMint is free.

## Step 4 — Update the FlowMint preflight

The existing `FMW_REST_Preflight` may already return enough data for the MCP, but cross-check it against FRE's preflight response shape. FRE's preflight returns:

- `plugin_version`, `connector_api_version`, `connector_enabled`, `entry_read_enabled`
- `authenticated_as`, `user_capabilities`
- `schema_document_url`, `schema_reference_url`
- `read_first`, `critical_rules`, `field_hints`, `universal_field_properties`, `settings_hints`
- `diagnostics` (database health, recent calls)

FlowMint's preflight should return the equivalent shape adapted for FlowMint's domain: workflow schema, step types catalogue, credential keys list, run statistics. Surface a `critical_rules` block covering the FlowMint patterns (idempotency keys for stateful steps, on_error policy semantics, the FRE submission listener wiring, Action Scheduler enqueue handling).

## Step 5 — Verification (this is the most important step)

Don't ship without running through this:

1. **Local syntax check.** Run `node -c includes/Connector/assets/flowmint-connector.js` to verify the JS parses.
2. **Build a fresh ZIP** via `bin/build-release.sh` (or the same path-around approach the previous session used if the build script's clean phase fails). Verify both new files are inside the ZIP.
3. **Have Breon install on staging** (`https://hmz.wao.mybluehost.me/website_313db98b`). Walk him through opening the FlowMint Claude Connection page, clicking the generate-command button, pasting the command into Terminal.
4. **Verify Claude Desktop restart picks up the new MCP server.** After restart, list the available MCP tools — `flowmint_*` tools should appear.
5. **Run `flowmint_preflight`** — should authenticate and return the plugin info shape.
6. **Run `flowmint_list_workflows`** — should return an empty array (clean staging) or any existing workflows.
7. **Run `flowmint_create_workflow`** with a minimal one-step workflow (a single `log_info` step) — should create the workflow and return its ID + connector_version.
8. **Cross-plugin integration test:** create the workflow targeting `pressuretest-contact` (FRE form created in the previous session — recreate if cleanup wiped it). Submit the form via `formengine_test_submit` (with `dry_run: false, skip_notifications: true`). Then call `flowmint_list_runs` and confirm a run was queued and ran.
9. **Run cleanup:** delete the test workflow via `flowmint_delete_workflow`. Verify it's gone via `flowmint_list_workflows`.

If any of those steps fail, debug before declaring done. The previous session learned the hard way that "MCP showed success but tool calls return errors" can mean stale config — full Cmd+Q quit and reopen Claude Desktop after every config change.

## Step 6 — Update version, changelog, and audit doc

Once verification passes:

- Bump version in `flowmint-workflows.php` header and the `FMW_VERSION` constant (currently `0.4.0-rc7` → propose `0.5.0` for the meaningful new feature)
- Add a `## [0.5.0]` section to `CHANGELOG.md` describing the connector + MCP work
- Mark Critical finding `C2` in `FLOWMINT_AUDIT.md` as "Fixed in v0.5.0" with a brief note
- Update the priority callout at the top of `CLAUDE.md` to reflect that the gap is closed
- Update `docs/ROADMAP.md` to note the MCP delivery
- Add `docs/MCP_CONNECTOR_SETUP.md` mirroring FRE's setup doc

## Step 7 — Hand back to Breon

Provide the new ZIP via a `computer://` link to outputs. Give a concise summary: what was built, the install steps in plain language, what to verify on staging. Don't write a wall of text — Breon prefers plain-language summaries and brevity.

---

## What was already done before this session

Don't redo any of this — it's already in place:

- FlowMint REST connector API: complete, in `includes/Connectors/REST/`
- FlowMint Wave 1 unit tests: complete, in `tests/Unit/` (credential store, workflow validator, interpolator, expression)
- FlowMint test infrastructure: complete (`tests/bootstrap.php`, `tests/Unit/UnitTestCase.php`, mocks)
- FlowMint architecture docs: complete, in `docs/`
- FLOWMINT_AUDIT.md C2 finding: documented in full detail — read this first for the why
- Reference implementations (Promptless, FRE, PRE): all working in production on the staging site

## Open questions to confirm with Breon before coding

Briefly verify with him:

1. Name for the Claude Desktop MCP entry: `flowmint`, `flowmint-workflows`, or `fmw`? PRE used `post-runtime-engine`, FRE used `form-engine-wordpress` — pick something consistent with that pattern.
2. Where should the MCP file install to on the user's Mac? PRE/FRE picked specific paths — match those.

(Note: licensing is NOT a question. FlowMint is free, no premium gate, no Freemius. Only Promptless uses Freemius.)

These are 30-second confirmations, not architectural debates. Ask, get the answers, proceed.

## Why this matters (for context)

Without this connector, every new FlowMint client requires hand-authoring workflow JSON in the WP database or making direct REST calls with curl. With it, Breon can describe a workflow to Claude in plain English and have it built in seconds. This is the single biggest velocity unblock for FlowMint adoption. Don't deprioritize.

---

**One last thing:** the previous session created a small bug in its own documentation. Earlier passes of `FLOWMINT_AUDIT.md` C2 referred to a "separate Node.js project" — that was wrong, and the section has been corrected. If you see any lingering language suggesting an external repo or npm package, ignore it and follow this document instead.
