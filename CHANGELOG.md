# Changelog

All notable changes to FlowMint Workflows will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
