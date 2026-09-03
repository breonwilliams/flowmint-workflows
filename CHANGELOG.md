# Changelog

All notable changes to FlowMint Workflows will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- Declares compatibility with WordPress 7.1. The readme still claimed 7.0 while
  the pressure-test site — which exercises FlowMint's workflow runs, credential
  handling and connector routes — has been running 7.1 throughout. Post Runtime
  and Promptless Forms already declare it from the same testing; this brings
  FlowMint in line. FlowMint is distributed via GitHub rather than
  wordpress.org, so no listing penalised the stale value, which is precisely
  why it went unnoticed.

## [0.7.0] — 2026-09-02

### Added

- **`labels` namespace — human-readable option text for anything a person reads.** `{{ labels.workshop }}` yields "Hand-Cut Joinery Intensive" where `{{ data.workshop }}` yields the stored key `joinery`. Found during end-to-end testing: a confirmation email read "your place is held for the joinery workshop", and nothing failed — the run completed, every step reported success, and the defect was visible only by reading the customer's mail.

  This closed a three-way inconsistency across the stack. Promptless Forms notification templates resolve `{field:key}` to labels, and its webhook payloads resolve them under the `google_sheets` preset — FlowMint was the only surface of the three that could not, at all.

  `labels` mirrors `data` key for key, resolving select / radio / checkbox values through `PForms_Field_Type_Abstract::resolve_display_value()` — the same public helper the Promptless Forms admin entry list already uses, so there is one implementation rather than a copy. Keys with no option list resolve to their raw value, so `labels` is always safe and never emptier than `data`. Degrades to mirroring `data` when Promptless Forms is unavailable or the form cannot be loaded.

  Additive: `data` still holds raw values, which remains correct for machine destinations (`http_post`, `printavo_*`) that want stable identifiers. No existing workflow changes behaviour.

### Fixed

- **`entry_files` was documented as something it is not, in three ways — all of which fail silently.** Verified by uploading a real file through a live multistep form and reading the resulting run context.

  1. Documented as an "Array of uploaded files". It is a **map keyed by field key**. `{{ entry_files[0].file_name }}` resolves to nothing; `{{ entry_files.reference_images.file_name }}` is correct.
  2. Documented as carrying `file_url`. It does not. Promptless Forms webhook payloads do include one, but `resolve_file_url()` is `private static` on the webhook dispatcher and this context never populates the key.
  3. Undocumented entirely: for a multi-file field the value is a **single object** when one file was uploaded and an **array of objects** when two or more were. A workflow written and tested against one upload breaks on the second.

  Each of these is invisible at runtime because the interpolator resolves a missing path to an empty string rather than failing — the step reports success and the payload is quietly wrong. The `context_shape` documentation now states all three accurately and points at `file_path` / `attachment_id` and the typed file steps instead.

  Populating a real `file_url` would need Promptless Forms to expose its resolver (or FlowMint to duplicate it); that is a cross-repo API decision and is not made here.

- **The connector could not reach an HTTPS local dev site, and the error blamed the wrong thing.** Node does not read the macOS keychain — it ships its own Mozilla CA bundle — so trusting a Local by Flywheel certificate fixes browsers and leaves every connector call failing with `DEPTH_ZERO_SELF_SIGNED_CERT`. Ported from the reference fix in Promptless WP (`ai-section-builder-modern`), where it was diagnosed and verified end to end. Touches only the connector admin page and the relay's error reporting — no workflow execution, step config, or DB schema is affected, so no `FMW_DB_VERSION` bump is involved and stored workflow JSON is untouched.
  - **The setup command no longer destroys config you added by hand.** It rebuilt `c.mcpServers["flowmint-workflows"]` from scratch, so env keys (`NODE_EXTRA_CA_CERTS`, `HTTP_PROXY`) and server-level keys (`cwd`, `disabled`) were silently lost on every regenerate. It now merges at BOTH levels, overwriting only `command`, `args` and the three env keys it owns. Correct regardless of the certificate work — a regenerate should never discard user config.
  - **`NODE_EXTRA_CA_CERTS` is wired automatically for non-public https hosts.** For `.local` / `.test` / `localhost` on https the command PROBES Local's conventional certificate path and sets the variable only if the file exists; the path is never assumed, since wp-env, Herd and Valet keep certificates elsewhere. The key is set but NEVER deleted on a miss, because an existing value may have been set by hand for tooling whose path cannot be probed. Local's per-site certificate is a self-signed leaf (`CA:FALSE`), which suffices — OpenSSL accepts a self-signed certificate in the trust store as its own anchor, so no CA-generation step is needed. When the probe cannot help, the connector page names the variable and what to point it at rather than emitting a command that fails opaquely. `NODE_TLS_REJECT_UNAUTHORIZED=0` and `rejectUnauthorized: false` are deliberately NOT used — both disable verification process-wide, including for production sites.
  - **TLS trust-anchor failures now name the real cause.** For `DEPTH_ZERO_SELF_SIGNED_CERT`, `SELF_SIGNED_CERT_IN_CHAIN` and `UNABLE_TO_VERIFY_LEAF_SIGNATURE` the relay explains that the URL is almost certainly fine, that Node ignores the keychain, and what to set. `ERR_TLS_CERT_ALTNAME_INVALID` is deliberately excluded — it is a hostname mismatch, which `NODE_EXTRA_CA_CERTS` cannot fix, and for that code the URL genuinely is suspect — as is every other error code, which keeps the original wording. The Local-path sentence is gated on a non-public hostname.

## [0.6.8] — 2026-08-13

### Changed
- Connector setup: moved the Copy Command button below the code block (it previously overlaid the command) and removed the `!important` flags from the copy-button styles, resolving the overlap and a color-contrast issue.

## [0.6.7] — 2026-07-22

### Added
- **`AGENTS.md` is now tracked in the repo** — the AI-reference doc for the workflow runtime, scheduled triggers, and the MCP connector, freshened against v0.6.6 before first commit. Documentation-only release: no functional changes.

## [0.6.6] — 2026-07-11

### Fixed
- **Plugin Check compliance sweep (2026-07-11).** Added the required `translators:` comments to the failure notifier's placeholder-bearing `__()` calls; raised `Requires at least` from 5.0 to 5.6 (the Cowork connector depends on the Application Passwords API, WP 5.6+, and scheduled triggers use `wp_timezone()`, WP 5.3+ — the header now matches reality instead of under-promising); bumped `Tested up to` to 7.0. (`includes/Core/class-fmw-failure-notifier.php`, `flowmint-workflows.php`, `readme.txt`, `CLAUDE.md`.)

## [0.6.5] — 2026-07-04

### Added
- **Failure notifications.** `FMW_Failure_Notifier` (`includes/Core/class-fmw-failure-notifier.php`) listens to `fmw_workflow_run_failed` at priority 100 and pushes a plain-text alert when a run permanently fails (exhausts all retries): Slack incoming webhook when the `slack_webhook` credential is configured (non-blocking POST, 3s timeout), otherwise email to the `notification_email` credential falling back to the site `admin_email` — every site gets a signal out of the box. The alert carries the workflow name, error code/message, entry context, and a deep link to the run for one-click inspect/replay. Defensively wrapped so it can never break the failure path it observes; fires only after max retries so every alert is a final, human-worthy failure. Filters: `fmw_failure_notification_enabled`, `fmw_failure_notification_message`. Closes the long-documented "failed runs are only visible in the admin UI" operational gap.

### Changed
- `bin/build-release.sh` now verifies the release ZIP's internal structure after packaging (required nested paths incl. `vendor/autoload.php` and Action Scheduler) and aborts on a flattened or vendor-less archive — turning the documented "Composer not on PATH → dead-on-arrival ZIP" gotcha into a hard build failure. Build tooling only; not shipped in the ZIP.
- Documentation truthfulness pass: `CLAUDE.md` corrected to shipped reality (phase table, `pforms_*` hook names since FRE 1.8.0, FRE 1.8.0 minimum, MCP layout, known gaps) and the `FMW_DB_VERSION` 0.3.0 no-DDL convention documented at the constant.

## [0.6.4] — 2026-06-02

### Fixed
- **Conditional step took the else branch every time, regardless of the `if` expression.** `FMW_Step_Conditional::execute()` was reading `$this->config['if']` — i.e. the POST-interpolation config the executor passed in. The executor runs the entire step config through `FMW_Interpolator::interpolate()` before instantiating the step, including the conditional's `if` field. Since the interpolator only understands `{{ context.path }}` substitution and has no concept of comparison/logical operators, an expression like `{{ data.urgency == 'high' }}` came out as an empty string by the time it reached the conditional step. `FMW_Expression::evaluate('')` correctly returns false, so every conditional silently took the else branch. The previous code carried a `// Workaround: ...` comment in the step that explicitly admitted the implementation didn't work — it shipped anyway. Caught during pressure testing of a real urgency-routing workflow that submitted two entries with different `urgency` values, both produced identical branch behavior.

  The fix plumbs the RAW (pre-interpolation) config through alongside the interpolated one:
  - `FMW_Step_Base` gains a `$raw_config` property, populated from `$step_definition['raw_config']` and falling back to `$this->config` for backward compat with any direct-instantiation callers.
  - `FMW_Workflow_Executor` includes `'raw_config' => $raw_config` in the step definition it constructs.
  - `FMW_Step_Conditional` reads `$this->raw_config['if']` for the expression. Comparison operators now survive into the expression evaluator, which builds its own interpolator pass to resolve the `{{ context.path }}` blocks correctly.
  - `FMW_Step_Conditional` also pulls `then` / `else` step arrays from raw_config when available, so a nested conditional inside a `then` branch gets its own `if` field un-interpolated. Without this, the nested-conditional case would reintroduce the bug at the inner level.

### Notes
- No database schema changes. `FMW_DB_VERSION` unchanged at `0.3.0`.
- No new step types, no API changes.
- Existing non-conditional workflows are unaffected — they continue to read `$this->config`, which still receives the same pre-interpolated values it always has.
- `skip_if` clauses (the FMW_Expression docblock mentions them) and the `try_catch` step both also use the expression evaluator. `skip_if` was not exercised by pressure testing and may or may not have the same drift; will be audited separately. `try_catch`'s nested `try`/`catch` arrays contain step definitions that re-enter the executor, so they're not affected by this fix one way or the other.

## [0.6.3] — 2026-06-02

### Fixed
- **Stale rename guard in workflow validator** — `FMW_Workflow_Validator::validate_full()` was checking `function_exists( 'fre' )` before consulting the Promptless Forms registry to verify form_id existence. The function was renamed `fre()` → `pforms()` in PForms v1.8.0; the guard was checking the legacy name. Every form-id existence check silently fell through to the "FormEngine not loaded" warning even when Promptless Forms was present and the registry was queryable. Now checks `function_exists( 'pforms' )`. Caught during pressure testing.
- **Update endpoint dropped trigger inference when caller omitted form_id** — `FMW_REST_Workflows::update()` did not pre-fetch the existing workflow's form_id before re-validating, so a PATCH that supplied a new config without an explicit trigger block — but expected the existing form binding to be preserved per the standard REST "omitted = unchanged" contract — failed with `Missing required field: trigger`. Now pulls the existing form_id from the database and injects it into the validation payload, making update() symmetric with create() when both receive a config that lacks an explicit trigger.

### Changed (documentation / DX)
- **MCP connector descriptions corrected on step shape** — `flowmint-connector.js` step shape descriptions said `{ id, type, config, on_error?, when? }` but the validator requires `name`, not `id`. Two description strings updated to `{ name, type, config, on_error?, when? }` so first-time workflow creates don't fail with a confusing "missing or invalid 'name'" error. **Important for users on existing connector installs:** the connector JS lives at `~/flowmint-mcp/flowmint-connector.js` on each Mac, downloaded by the connector setup command. Updating the plugin does NOT update the local copy. Re-run the connector setup command from WP admin → FlowMint Workflows → Claude Connection to pull the corrected descriptions.
- **Preflight response now documents the workflow context shape** — added a `context_shape` block listing every top-level namespace accessible via `{{ … }}` interpolation (`data`, `entry`, `entry_files`, `run`, `workflow`, `form`, `steps`, `vars`), with descriptions, an example, and a `common_traps` list calling out the two patterns that produce silent empty-string substitutions: `entry.fields.*` (form fields are at `data.*`, not `entry.fields.*`) and `steps.<type>.*` (use the step NAME, not the type). Resolves the friction that surfaced during pressure testing of `session_registration` → `session_registration_confirmation`, where guessed interpolation paths produced an empty `to` field in the email step and a generic `config_error`.
- **MCP tool description now references current names** — `flowmint_create_workflow` description previously referred to "fre_submission_complete" (the legacy hook name) and "an FRE form". Updated to "pforms_submission_complete" and "Promptless Forms" to match the v0.6.2 rename. No behavior change.

### Notes
- No database schema changes. `FMW_DB_VERSION` unchanged at `0.3.0`.
- Minimum required Promptless Forms version unchanged at 1.8.0.
- Internal symbol surface (`FMW_*` classes, `fmw_*` actions/filters, `pforms_submission_complete` hook listener wiring) unchanged.

## [0.6.2] — 2026-06-02

### Fixed
- Dependency check now treats Promptless Forms as present when either the `PForms_VERSION` constant is defined OR the `Promptless_Forms` class exists. Single-signal checks against the constant were observed to false-positive on certain managed-host environments (Bluehost's stack in testing) where the constant was not defined at `admin_notices` time despite Promptless Forms being active and the admin pages rendering correctly. The class fallback closes that gap so FlowMint stops showing a spurious "missing dependency" error when its prerequisite plugin is actually present and working. Version-compare enforcement still requires the constant — when only the class is detectable we accept "present" and let any real version mismatch surface as step-run failures in run history rather than as a blanket block.

### Changed
- User-facing admin notices and the plugin Description header updated from "Form Runtime Engine" to "Promptless Forms" — Promptless Forms shipped on WordPress.org at the renamed slug in v1.8.0 and FlowMint's surface text was still pointing at the legacy name.
- Internal symbol surface (`FMW_REQUIRED_FRE_VERSION` constant, `FormEngine` step categories, internal class names referencing `Fre`) is unchanged — those are internal-only and renaming would invalidate stored workflow JSON without behavioral benefit. Only user-visible strings were touched.

### Notes
- No database schema changes; `FMW_DB_VERSION` unchanged at 0.3.0.
- Minimum required Promptless Forms version unchanged at 1.8.0.

## [0.6.1] — 2026-05-17

### Changed
- Renamed connector admin page from "Claude Connection" to "Connector" (menu) / "The FlowMint Connector" (page title) — vendor-neutral naming future-proofed for additional AI clients
- Redesigned connector page with card-based layout, clearer 3-step setup flow, and improved connection status display
- Added warning notice when Application Passwords are unavailable (requires HTTPS or `WP_ENVIRONMENT_TYPE='local'`)

## [0.6.0] — 2026-05-15

### Added — Scheduled workflow triggers

Workflows can now be triggered on a recurring schedule (hourly / twice-daily / daily / weekly) in addition to form submissions. Closes the "Scheduled workflows" item that was explicitly deferred to v2+ in `docs/ROADMAP.md` — the architecture left room for it, and v0.6.0 cashes that in. Full design contract: `docs/DESIGN_SCHEDULED_TRIGGERS.md`. User-facing guide: `docs/SCHEDULED_WORKFLOWS.md`.

**New trigger abstraction.**
- Workflow JSON gains a `trigger` block. Two trigger types in v0.6: `{ type: "form", form_id: "…" }` (form-triggered, the existing pattern made explicit) and `{ type: "schedule", interval: "…", hour: …, minute: …, day_of_week: … }` (scheduled, new).
- Legacy workflows that have just a top-level `form_id` continue to work — the validator normalizes them into the v0.6 shape transparently. Existing workflows on production sites do NOT need their JSON rewritten.
- The REST API accepts the new `trigger` block at the wrapper level for convenience (alongside `id`, `title`, `config`), or inside the config JSON. Preflight reports `supported_trigger_types: ["form", "schedule"]` so MCP clients can introspect capabilities.

**New step types (FormEngine category).**
- `fre_list_entries` — Query FE entries by form, status, and/or age. Backed by `FRE_Entry_Query` (FRE 1.6.0+). Hard cap of 1000 rows per call. Returns oldest-first. Output includes a `hit_limit` flag so the next tick can pick up the overflow.
- `fre_delete_entries` — Bulk-delete by ID list (accepts entry objects from `fre_list_entries` OR bare integer IDs). **Idempotent** — re-running on already-deleted IDs returns `already_gone` rather than erroring. **Per-id failure tolerance** — one bad entry doesn't sink the batch; failures land in a `failed` array.

**Database schema (v0.2.0 migration).**
- `wp_fmw_workflows.form_id` is now NULLABLE (scheduled workflows have no bound form).
- New `wp_fmw_workflows.trigger_type` column (VARCHAR(32) NOT NULL DEFAULT 'form') indexed via new composite key `idx_trigger_type (trigger_type, enabled)` for efficient "find all enabled scheduled workflows" lookups by `FMW_Schedule_Listener`.
- Migration is additive, idempotent, and probes column / index state via `SHOW COLUMNS` / `SHOW INDEX` before each ALTER. Existing rows are correctly classified as `trigger_type = 'form'` by the column default. Rollback to v0.5.0 does NOT require dropping the new column — the older code simply ignores it.

**New class: `FMW_Schedule_Listener`** (`includes/Core/class-fmw-schedule-listener.php`).
- Mirrors `FMW_Submission_Listener` but for the scheduled trigger path.
- Subscribes to `fmw_workflow_saved` / `fmw_workflow_disabled` / `fmw_workflow_deleted` (newly fired by the repository) — registers an Action Scheduler recurring event when a scheduled workflow is saved + enabled, unregisters when it's disabled or deleted.
- Tick handler creates a queued run via `FMW_Run_Repository::create_pending_scheduled` (`form_id = ''`, `entry_id = 0` sentinels per design §5.2), then enqueues `fmw_run_workflow` async action — same downstream path as form submissions.
- Timezone-aware first-tick computation: `daily` and `weekly` intervals use site-local time (`wp_timezone()`), matching how `Settings → General` displays "site timezone."

**Daily reconciliation pass.**
- New AS recurring action `fmw_reconcile_scheduled_events` fires daily and reconciles AS recurring events with the workflows table. Drift correction: even if an AS action was lost or wiped, the next reconciliation rebuilds it.
- Bootstrap is self-healing: on the first plugins_loaded after v0.6.0 lands, schedules the daily reconciliation AND runs an immediate pass so any pre-existing scheduled workflows get their cron events without a 24h wait. Hooked on `init` priority 20 (after AS's data store initialization at `init` priority 1).

**Validator additions.**
- `FMW_Workflow_Validator::normalize()` — public static method that converts legacy → v0.6 shape on a copy. Idempotent.
- `trigger` block validation: enum-checked `type` ('form' | 'schedule'), enum-checked `interval` ('hourly' | 'twicedaily' | 'daily' | 'weekly'), range-checked `hour` (0–23), `minute` (0–59), `day_of_week` (1–7 ISO-8601).
- New warning (not error) for scheduled workflows that interpolate `{{ data.* }}` / `{{ entry.* }}` / `{{ entry_files.* }}` — scheduled runs have no FE entry context, so those references silently resolve to empty string at runtime; the warning surfaces the typo at save time.

**Job handler.**
- `FMW_Workflow_Job::build_context()` recognizes the scheduled-run sentinel (`entry_id === 0`) and skips the FE entry fetch entirely. The context's `entry`, `data`, `entry_files` stay empty arrays; the interpolator already handles missing variables by returning empty string, so existing step implementations continue to work unchanged.

**Repository.**
- `FMW_Workflow_Repository::create` / `update` now normalize config and persist `trigger_type` column, allow NULL `form_id` for scheduled workflows.
- New `FMW_Workflow_Repository::get_all_by_trigger_type($type, $args)` for the listener's reconciliation pass.
- `get_for_form()` tightened with explicit `trigger_type = 'form'` filter — a misconfigured scheduled workflow that somehow has a non-NULL `form_id` can never be picked up by the FRE submission listener as if it were form-triggered.
- Repository now fires `fmw_workflow_saved` (on create + update), `fmw_workflow_disabled` (on enabled 1→0 transition), and `fmw_workflow_deleted` (on delete) actions.

**REST.**
- `/workflows` list endpoint accepts a `trigger_type` query parameter.
- `/preflight` reports `supported_trigger_types`.
- All other endpoints accept the new `trigger` block in request bodies and return it in responses. Backwards-compat for legacy `form_id` at the wrapper level: still works, still normalized to `trigger.type = "form"` internally.

**Verification (local Flywheel site).**
99 of 99 smoke checks green across three layered test suites:
- Phase 1 (32 checks) — schema migration, value-object accessors, validator normalization + trigger validation, repository scheduled + legacy create/retrieve, lifecycle hooks fire correctly, schedule listener stub is a no-op.
- Phase 2 (32 checks) — listener wiring, AS event registration on save/update/disable/delete, end-to-end tick → run completes synchronously, reconciliation drift correction, form-triggered regression unaffected.
- Phase 3 (35 checks) — both new step types registered with correct metadata, every documented filter combination on `fre_list_entries`, idempotency + mixed-input tolerance on `fre_delete_entries`, end-to-end retention workflow scenario with the actual 725 use case JSON.

**Plugin version stamp:** `0.6.0` — stable release following 725 Print Lab production verification.

## [0.5.0] — 2026-05-10

### Added — Claude Cowork MCP connector

Closes the **Critical C2** finding in `FLOWMINT_AUDIT.md`: the WordPress-side REST connector at `flowmint/v1/connector/*` had been built in v0.3.0, but the client-side MCP bridge that lets Claude Desktop reach those endpoints was missing. This release ships the bridge.

**New files**
- `includes/Connectors/MCP/assets/flowmint-connector.js` — single-file Node.js stdio MCP server. Exposes 16 tools (preflight, full workflow CRUD, run history + replay, step type catalog, credential introspection + test, template read). Maps 1:1 to the existing REST endpoints. Forked from the Form Runtime Engine connector with FlowMint-specific tool definitions and route paths.
- `includes/Connectors/MCP/class-fmw-connector-admin.php` — `FlowMint Workflows → Claude Connection` admin page. Generates and revokes the WordPress Application Password, builds the one-line install command users paste into Terminal, serves the MCP server JS file via `admin-ajax.php?action=fmw_download_connector`.
- `includes/Connectors/MCP/class-fmw-connector-settings.php` — small data-access class for the connector kill-switch state. Pure read/write of `fmw_connector_enabled` option and per-user `_fmw_connector_configured_at` meta.

**Install path / Claude Desktop config**
- MCP server lands at `~/flowmint-mcp/flowmint-connector.js` on the user's Mac (parallel to FRE's `~/form-engine-mcp/` and Promptless's `~/promptless-mcp/` so the three connectors don't collide).
- Claude Desktop entry name: `flowmint-workflows` (matches the plugin slug, distinct from `form-engine-wordpress` and `promptless-wordpress`).
- Env vars: `FLOWMINT_SITE_URL`, `FLOWMINT_USERNAME`, `FLOWMINT_APP_PASSWORD`.

**Two-gate security model**
- Connector defaults to **disabled** site-wide. Admin must explicitly opt in via the Claude Connection page before any REST endpoint responds.
- Existing `manage_options` capability check on every endpoint stays — the kill switch is layered on top of it. `/preflight` is exempt from the kill switch so Claude can introspect "is the connector enabled?" without it being on.
- App Password generation revokes any prior FlowMint App Password for the user, so there's at most one active connector key per user at any time.

### Changed
- `FMW_REST_Auth::require_manage()` now also checks the connector-enabled flag (except on `/preflight`). Backward-compatible: the only behavior change is endpoints returning 403 `connector_disabled` when the kill switch is off, which is the intended security posture.
- `FMW_REST_Preflight` now returns the actual `connector_enabled` flag value (was hardcoded `true`). The MCP surfaces this so users get an actionable "enable the connector in WP admin" message instead of opaque 403s.
- `FMW_Autoloader::$namespace_map` adds `'FMW_Connector' => 'Connectors/MCP'` so `FMW_Connector_Admin` and `FMW_Connector_Settings` autoload from the new subfolder.

### Notes
- This connector is available on every install — FlowMint Workflows is positioned as a value-add plugin alongside Promptless WP (the premium product), not as a paid product itself. No Freemius gating.

## [0.4.0-rc7] — 2026-05-03

### Added
- **`drive_create_text_file` step type** for creating small text/markdown/HTML files in Drive from in-memory strings. Differs from `drive_upload_file` (which uploads from a FRE entry's file field) — this step takes a `content` string directly, typically built via `{{ template(...) }}` or `{{ data.* }}` interpolation. Lets a workflow drop a structured submission record alongside the design file in the same Drive folder, so the folder is self-describing without cross-referencing other systems.
- New private method `FMW_Drive_Client::create_text_file($parent_id, $name, $content, $mime_type)` — uses Drive's multipart upload with a hard 1MB cap to prevent accidental misuse on large blobs (which should go through `upload_file`'s chunked path instead).

### Step library now totals 26 step types across 7 categories
Previously 25 (Phase 1–3 build). The new `drive_create_text_file` slots into the Google Drive category alongside the existing 5 Drive steps.

### Use case for the new step
Fixes the architectural gap surfaced during 725 Print Lab production testing: Roderick browsing a Drive submission folder would only see the customer's design file (or an empty folder if no design was uploaded), with no record of the form data that triggered the workflow. With the new step, the workflow can drop a `submission.txt` file containing the same structured information that lands in the Printavo Quote `customerNote` — providing defense-in-depth so the data is recoverable from Drive even if Printavo is down or the Quote gets accidentally deleted.

## [0.4.0-rc6] — 2026-05-03

### Changed (BREAKING for workflow JSONs that referenced the old Printavo step output keys)

Major refactor of `FMW_Printavo_Client` and the four Printavo step types to match Printavo's current GraphQL schema. The old client was written against a pre-v2 Printavo schema in which:

- `Contact` had a `companyName` field directly (now removed; companyName lives on `Customer`, with `Contact.customer` providing the relationship)
- `ContactCreateInput` existed and was the input type for `contactCreate` (now `ContactInput`, and `contactCreate` requires a parent Customer ID via `contactCreate(id: ID, input: ContactInput)`)
- `invoiceCreate(input: InvoiceCreateInput)` was the way to create a Quote/Invoice (now `quoteCreate(input: QuoteCreateInput)`)
- Mutations returned a payload wrapper like `{ contact { ... } errors { ... } }` (now mutations return the entity directly — `contactCreate` returns `Contact`, `customerCreate` returns `Customer`, `quoteCreate` returns `Quote`)

Schema verified empirically via live introspection against `https://www.printavo.com/api/v2` on 2026-05-03.

### Client refactor

- `find_customer_by_email` now searches Contacts and traverses to `customer { id companyName }` to surface the company entity. Returns a unified shape with both `contact_id` and `customer_id` plus all the contact's fields.
- `create_customer` rewritten to call `customerCreate(input: CustomerCreateInput!)`. The schema requires a `primaryContact: ContactInput` inline, so the single mutation creates the Customer (the company) AND its first Contact (the person). Maps a single set of `{ email, name|first_name+last_name, phone, company_name }` args onto both halves.
- New private helpers: `build_contact_input`, `split_name`, `shape_customer_result`. Centralizes the name-parsing + result-shaping logic instead of duplicating across step files.
- `create_quote` rewritten to call `quoteCreate(input: QuoteCreateInput!)`. Required schema fields (`contact: IDInput!`, `customerDueAt: ISO8601Date!`, `dueAt: ISO8601DateTime!`) are always provided — the client supplies sensible defaults (`+14 days` and `+30 days at 17:00 UTC`) when callers omit the date fields, so callers never get a 400 for a forgotten required field.
- `description` workflow arg now maps onto `customerNote` (which is what it always represented). `production_note` arg maps onto `productionNote`. `user_id` arg maps onto `owner: IDInput`.
- Removed `invoice_status_id` config — no equivalent exists on QuoteCreateInput in the current schema. Quote status is now set via tags or a separate `quoteStatusUpdate` mutation, which is not yet wired in.

### Step output shape changes (BREAKING)

The four Printavo steps (`printavo_find_customer`, `printavo_create_customer`, `printavo_find_or_create_customer`, `printavo_create_quote`) now expose `contact_id` and `customer_id` as separate keys (the old `id` key is preserved as a legacy alias mapping to `contact_id`):

- Old: `{{ steps.customer.id }}` — ambiguous (was a Contact ID despite the name)
- New: `{{ steps.customer.contact_id }}` for Quote-attaching, `{{ steps.customer.customer_id }}` for company-level references
- Legacy alias: `{{ steps.customer.id }}` still works (maps to contact_id)

The `printavo_create_quote` step now requires `contact_id` instead of `customer_id`. `customer_id` is accepted as a backwards-compat alias (treated as a Contact ID since that's what the old client did).

Output of `printavo_create_quote` adds: `description`, `public_url`, `customer_due_at`, `due_at`. Removes: `created_at` (Quote schema doesn't expose it on creation; available later via `timestamps.createdAt` if needed).

## [0.4.0-rc5] — 2026-05-03

### Fixed
- **Printavo `variables` field encoding bug.** PHP encodes empty arrays as JSON `[]`, but GraphQL servers (Printavo included) require `variables` to be a JSON object (`{}`). An empty array crashes Printavo's API with a 500 Internal Server Error. The `query()` method now casts `$variables` to `(object)` before serialization — empty arrays become `{}`, associative arrays serialize identically to before. Verified empirically against Printavo's live API: `{"variables":[]}` → 500, `{"variables":{}}` → 200 with full account data. This bug affected `test()` (which never passes variables) but not `find_customer`/`create_customer`/`create_quote` (which always pass non-empty input variables).

## [0.4.0-rc4] — 2026-05-03

### Fixed
- **Printavo client `Accept: application/json` header.** Without an explicit Accept header, `wp_remote_request` sends nothing, and Printavo's web router falls back to the HTML-rendering Rails app for a request the API namespace was supposed to handle. The result is a 500 HTML page instead of a 200 JSON GraphQL error response. Browsers happen to send Accept by default, which is why the same call works in the browser DevTools but failed when invoked from a WordPress server. Fix verified empirically by capturing the contrast between the two contexts on 725printlab.com production.
- **`FMW_Printavo_Client::test()` query updated for current Printavo schema.** Printavo's `Account` GraphQL type renamed `name` → `companyName` and `contactEmail` → `companyEmail` in a recent API revision. Test query now uses the current names; output keys (`account_name`, `contact_email`) preserved so downstream consumers don't see a behavioral change. Customer + Quote queries already used the post-rename names; only the test query was stale.

## [0.4.0-rc3] — 2026-05-03

### Added
- **Templates REST endpoint** (`FMW_REST_Templates`) for managing the per-site `wp-content/uploads/fmw-templates/` directory. Supports `GET /templates` (list), `GET /templates/{name}` (read), `PUT /templates/{name}` (write), `DELETE /templates/{name}`. Required by the `send_email_template` step and the interpolator's `{{ template('name') }}` function. Without this, every new client install required SFTP access to seed templates — now it's a one-call REST upload from the production WP admin.
- Endpoint enforces: name regex `[a-z0-9_-]{1,64}`, extension whitelist (`txt`, `html`), 256KB content size cap, automatic directory creation with defensive `index.html` drop file, atomic write via WP_Filesystem (with `file_put_contents` + `chmod FS_CHMOD_FILE` fallback), automatic removal of the OPPOSITE-extension twin on PUT (so `name.html` and `name.txt` can never both exist for the same template — eliminates resolution ambiguity in the email step).
- All endpoints require the `fmw_manage` capability.

## [0.4.0-rc2] — 2026-05-03

### Fixed
- **Action Scheduler dependency restored.** The `woocommerce/action-scheduler` package was incorrectly removed from `composer.json` during the local Flywheel recovery on 2026-05-03. That removal worked locally because the local site has WooCommerce active (which bundles AS), but on hosts without WC — e.g. 725printlab.com — FMW would activate but show "Action Scheduler is not loaded" and the submission listener would never enqueue jobs. Re-added `woocommerce/action-scheduler ^3.7` to composer require, plus an explicit `require_once vendor/woocommerce/action-scheduler/action-scheduler.php` in the bootstrap (AS does not load via Composer's PSR-4 autoloader by design — it uses its own `ActionScheduler_Versions` loader so multiple plugins can co-exist with AS's "highest version wins" deduplication).

## [0.4.0-rc1] — 2026-05-03

### Phase 4 — 725 Print Lab production migration (release candidate)
- Workflow JSONs validated against local REST API (`/workflows/{id}/test` endpoint, both pass with zero errors and zero warnings):
  - `725-bulk-order-quote` — 10 steps (Bulk → Printavo + Drive)
  - `725-small-order-request` — 10 steps (Small → Printavo + Drive)
- Migration runbook + parallel-run / cutover / rollback procedures documented in `_FlowMint-Workflows-Migration/README.md`
- Quote description templates committed (`725-bulk-quote-description.txt`, `725-small-quote-description.txt`, `725-customer-ack.txt`)

### Production build hardening
- `composer.json`: added `extra.google/apiclient-services` whitelist for `Drive` only — production builds drop unused Google service stubs (~186MB → ~5MB for the google/ tree)
- `composer.json`: added `post-install-cmd` and `post-update-cmd` running `Google\Task\Composer::cleanup` so the whitelist is applied automatically on every composer install/update
- `composer.json`: pinned `google/apiclient` to `^2.12.0` (PHP 7.4-compatible; the `^2.15` line requires PHP 8.1+ which broke local Flywheel)
- `composer.json`: `"platform-check": false` keeps composer from generating PHP-version assertions that lock the plugin to whichever PHP the build host is running

### Phase 0 — Planning + scaffolding (in progress)
- Plugin folder scaffolding mirroring FormEngine's structure
- Top-level `CLAUDE.md` (AI reference)
- `docs/ROADMAP.md` (phased build plan)
- `docs/ARCHITECTURE.md` (technical design spec)
- `docs/STEP_LIBRARY.md` (step type reference)
- `docs/CONNECTOR_API.md` (REST + MCP API spec)
- `docs/INTEGRATION_FRE.md` (FormEngine integration contract)
- `docs/REFERENCE_PATTERNS.md` (anonymized example workflows)
- `docs/SETUP_GOOGLE_DRIVE.md` (GCP service account walkthrough)
- `docs/SETUP_PRINTAVO.md` (Printavo API token walkthrough)
- `docs/SETUP_SLACK.md` (Slack webhook for FlowMint notifications)
- `docs/MIGRATION_FROM_ZAPIER.md` (generic migration playbook)
- `docs/CLAUDE.md` (detailed patterns + examples)
- Stub plugin file with FormEngine version-check admin notice
- `.gitignore` matching FRE pattern
- Empty folder structure for Phase 1 build (`includes/Core/`, `includes/Steps/`, etc.)

### Phase 1 — Foundation (complete)
- Bootstrap with autoloader, FormEngine version-check, Action Scheduler dependency notice
- Database schema (`wp_fmw_workflows`, `wp_fmw_workflow_runs`, `wp_fmw_workflow_run_steps`) + dbDelta migration on activation
- Workflow execution engine: `FMW_Workflow_Executor` with skip_if support, error policy enforcement, run_step recording
- Variable interpolation: `FMW_Interpolator` (`{{ data.x }}`, `{{ steps.x.y }}`, `{{ vars.x }}`, `{{ env.x }}`, `||` fallback, `now()`, `template()`, `has_file()`, `is_empty()`, etc.)
- Boolean expression evaluator: `FMW_Expression` (custom precedence-climbing parser, no eval; supports `==`, `!=`, `>`, `<`, `>=`, `<=`, `&&`, `||`, `!`, parens)
- Action Scheduler integration: `FMW_Submission_Listener` listens to `fre_submission_complete` and enqueues `fmw_run_workflow` actions; `FMW_Workflow_Job` is the AS handler with retry policy
- Encrypted credential storage: `FMW_Credential_Store` (AES-256-GCM, key derived from `wp_salt('auth')`)
- Repositories: `FMW_Workflow_Repository`, `FMW_Run_Repository`, `FMW_Run_Step_Repository`
- Workflow JSON schema validator: `FMW_Workflow_Validator` (validates structure, step types registered, names unique, on_error values, form_id exists in FRE)
- Step library — all 11 v1 Core step types:
  - Control flow: `set_variable`, `conditional`, `try_catch`, `delay`
  - Logging: `log_info`, `log_warning`, `log_error`
  - FormEngine: `fre_get_entry`, `fre_get_file`, `fre_update_entry_status`, `fre_delete_entry`
- Connector REST API at `/wp-json/flowmint/v1/connector/...`:
  - GET /preflight (health check, credentials configured, FRE/AS status)
  - GET/POST /workflows (list, create with validation)
  - GET/PATCH/DELETE /workflows/{id} (read, update, delete with optional cascade)
  - POST /workflows/{id}/test (validate without executing)
  - GET /runs (list with filters)
  - GET /runs/{id} (detail with all steps)
  - POST /runs/{id}/replay (manual replay, creates child run)
  - GET /step-types (list registered step types with config/output schemas)
  - GET /step-types/{type} (single step type detail)
- Hooks emitted: `fmw_workflow_run_started`, `fmw_workflow_run_completed`, `fmw_workflow_run_failed`, `fmw_step_completed`, `fmw_step_failed`, `fmw_log`
- Filters offered: `fmw_should_run_workflow`, `fmw_step_output`, `fmw_credential`, `fmw_log_min_level`
- FlowMint-internal admin UI:
  - Top-level menu "FlowMint Workflows"
  - Run History list with status/workflow filtering and pagination
  - Run detail view with step-by-step status, timing, output snapshots, error details, context snapshot
  - Manual replay button on completed/failed/cancelled runs
  - Workflows list (read-only debug view)
- Custom autoloader (PSR-like, mirrors FormEngine pattern) with longest-prefix-match for namespace mapping
- 40 PHP files, ~5,400 LOC

### Phase 1 deferred to Phase 5
- Unit tests for Interpolator, Expression, Executor (deferred; full test harness setup deferred)
- Composer install for Action Scheduler bundling (Breon to run `composer install` in plugin dir)

### Phase 2 — Drive + Email (complete)
- Composer autoload integration in bootstrap (loads `vendor/autoload.php` if present after FMW autoloader registers)
- `FMW_Drive_Client` — Google Drive API wrapper:
  - Service account auth from `drive_service_account` credential
  - `find_folder`, `find_or_create_folder`, `create_folder`, `upload_file`, `set_permission` methods
  - Multipart upload for files ≤5MB, chunked resumable upload (1MB chunks) for larger files
  - Structured error translation (Google API exceptions → FMW_Step_Exception with appropriate codes)
  - `test()` method for `/credentials/{key}/test` endpoint
- `FMW_Email_Client` — `wp_mail` wrapper:
  - Captures `wp_mail_failed` action for structured error reporting
  - Builds proper headers (Content-Type, From with name, Reply-To, CC, BCC)
  - HTML and plain text support
- 5 Drive step types in `includes/Steps/Drive/`:
  - `drive_find_folder` (read-only lookup, returns `{found: false}` if missing)
  - `drive_find_or_create_folder` (idempotent — used for YYYY-MM month folders)
  - `drive_create_folder` (with implicit dedup check by name unless `allow_duplicate: true`)
  - `drive_upload_file` (reads from FE entry files, supports rename, skips gracefully if no file)
  - `drive_share_link` (anyone_with_link / user / group / domain)
- 2 Email step types in `includes/Steps/Email/`:
  - `send_email` (plain text or HTML, with idempotency dedup via 1-hour transient)
  - `send_email_template` (loads from `wp-content/uploads/fmw-templates/<name>.html` or `.txt`, infers HTML/text from extension, interpolates variables)
- Step registry split into category-specific registration methods (`register_core_steps`, `register_drive_steps`, `register_email_steps`)
- Autoloader namespace map updated to include `FMW_Drive_Client`, `FMW_Email_Client`, etc. under `Connectors/`
- 7 new step types registered (total now 18)
- Bug fix in `FMW_Step_Exception::is_retryable()`: added `credential_not_configured`, `dependency_missing`, `file_not_found`, `file_not_readable`, `template_not_found`, `invalid_input` to non-retryable list
- Verified end-to-end: workflow with `log_info → send_email → log_info` runs in 151ms, returns `sent: true`
- Verified: Drive step without credentials throws `credential_not_configured` with clear instruction message

### Phase 3 — Printavo + HTTP (complete)
- `FMW_Http_Client` — generic HTTP wrapper:
  - Built on `wp_remote_request` (no SDK dependency)
  - Supports GET/POST/PUT/PATCH/DELETE/HEAD
  - Body formats: json (default), form-urlencoded, raw
  - Auto-parses JSON responses based on Content-Type
  - Configurable timeout, redirects, SSL verification
  - Maps HTTP status codes to FMW error codes (auth_failed, rate_limited, external_4xx, external_5xx, timeout, network_error)
  - `accept_non_2xx` flag for cases where caller wants to handle non-success responses
- 3 HTTP step types in `includes/Steps/Http/`:
  - `http_get` (convenience for GET)
  - `http_post` (convenience for POST with JSON body default)
  - `http_request` (full method/headers/body/options control)
- `FMW_Printavo_Client` — Printavo GraphQL client:
  - Authenticates with email + token headers (credential format: `{"email": "...", "token": "..."}`)
  - GraphQL query execution via `FMW_Http_Client`
  - GraphQL error detection (responses are 200 even on query errors; checks body.errors)
  - Methods: `find_customer_by_email`, `create_customer`, `find_or_create_customer`, `create_quote`, `test`
- 4 Printavo step types in `includes/Steps/Printavo/`:
  - `printavo_find_customer` (read-only lookup, returns `{found: false}` if missing)
  - `printavo_create_customer` (always create, errors if duplicate email)
  - `printavo_find_or_create_customer` (idempotent; auto-splits `name` into firstName/lastName if first/last not explicit)
  - `printavo_create_quote` (creates Invoice with customer_id, user_id, invoice_status_id, nickname, description, customer_due_date)
- `FMW_REST_Credentials` — REST controller for credential management (added during Phase 2 wrap-up):
  - GET /credentials (lists known keys with `configured` flag, never values)
  - GET /credentials/{key} (status of one key)
  - PUT /credentials/{key} (set encrypted credential)
  - DELETE /credentials/{key}
  - POST /credentials/{key}/test (instantiates the appropriate client and calls test())
  - Test client mappings: `drive_service_account` → FMW_Drive_Client, `printavo_api_token` → FMW_Printavo_Client
- Step registry: `register_printavo_steps()` and `register_http_steps()` methods added
- 7 new step types registered (total now 25 across 7 categories: Control flow, Logging, FormEngine, Google Drive, Email, Printavo, HTTP)
- Verified end-to-end: `http_get` workflow against httpbin.org returned HTTP 200 with parsed JSON in 393ms; downstream step correctly interpolated `{{ steps.fetch_data.status }}`
- Printavo client unverifiable without real API credentials — same as Drive, will be tested during Phase 4 migration

### Phase 4 — 725 Print Lab migration (not started)
- 725 workflow definitions (JSON)
- Parallel-run verification with Zapier
- Cutover from Zapier to FlowMint Workflows

### Phase 5 — Production polish (not started)
- Comprehensive admin UI
- Notification system (Slack/email to FlowMint on failure)
- Settings UI for global configs
- Test coverage to >80%
- v1.0 release tag

---

## [0.1.0-alpha] - TBD

Initial scaffold. No runtime code yet.
