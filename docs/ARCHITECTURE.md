# FlowMint Workflows — Architecture

This is the technical design contract for the plugin. Phase 1+ code MUST follow this design. Disagreements are resolved by editing this doc, not by writing different code.

## High-level shape

```
┌──────────────────────────────────────────────────────────────────────────┐
│                       Form Runtime Engine (separate plugin)               │
│                                                                           │
│  Form submitted → validate → store entry → attach files →                 │
│                                                                           │
│  do_action('fre_submission_complete', $entry_id, $form_id, $data)         │
└────────────────────────────────────────┬──────────────────────────────────┘
                                         │
                                         ▼
┌──────────────────────────────────────────────────────────────────────────┐
│                       FlowMint Workflows (this plugin)                    │
│                                                                           │
│  ┌────────────────────────────────────────────────────────────────────┐  │
│  │ Listener: FMW_Submission_Listener::on_submission_complete()        │  │
│  │   Looks up workflow by form_id                                      │  │
│  │   Enqueues Action Scheduler async job                               │  │
│  │   Returns immediately                                                │  │
│  └────────────────────────────────────────────────────────────────────┘  │
│                                  │                                        │
│                                  ▼ (later, async)                         │
│  ┌────────────────────────────────────────────────────────────────────┐  │
│  │ Worker: FMW_Workflow_Job::run()                                    │  │
│  │   Loads workflow definition + entry data                            │  │
│  │   Creates FMW_Workflow_Context                                      │  │
│  │   Hands to FMW_Workflow_Executor                                    │  │
│  └────────────────────────────────────────────────────────────────────┘  │
│                                  │                                        │
│                                  ▼                                        │
│  ┌────────────────────────────────────────────────────────────────────┐  │
│  │ Executor: runs steps in sequence                                    │  │
│  │   For each step:                                                    │  │
│  │     interpolate config variables                                    │  │
│  │     instantiate step class                                          │  │
│  │     execute(context)                                                │  │
│  │     write output to context                                         │  │
│  │     write run_step record to DB                                     │  │
│  │   On error: retry per Action Scheduler policy, or fail run          │  │
│  └────────────────────────────────────────────────────────────────────┘  │
│                                  │                                        │
│                                  ▼                                        │
│  ┌────────────────────────────────────────────────────────────────────┐  │
│  │ Steps execute against external services                            │  │
│  │   FMW_Drive_Client → Google Drive API                               │  │
│  │   FMW_Printavo_Client → Printavo GraphQL                            │  │
│  │   FMW_Email_Client → wp_mail / SMTP                                 │  │
│  │   etc.                                                              │  │
│  └────────────────────────────────────────────────────────────────────┘  │
└──────────────────────────────────────────────────────────────────────────┘
                                         │
                                         ▼
                              Run completes (success or fail)
                                         │
                            ┌────────────┴─────────────┐
                            ▼                          ▼
              do_action('fmw_workflow_run_         If failed:
                        completed', $run_id)        Slack/email
                                                    notification
                                                    to FlowMint
```

## Plugin file structure (final)

```
flowmint-workflows/
  flowmint-workflows.php              # Main plugin file (bootstrap, autoloader, deps check)
  CLAUDE.md                           # AI reference
  README.md
  CHANGELOG.md
  uninstall.php                       # On plugin delete: drop tables, clean transients
  composer.json                       # Defines vendor deps (Action Scheduler, Drive SDK, etc.)
  composer.lock
  phpunit.xml                         # Test config
  docs/                               # All docs
  includes/
    Core/                             # Workflow engine
      class-fmw-workflow.php          # Workflow value object (id, form_id, steps, settings)
      class-fmw-workflow-validator.php # JSON schema validation for workflow definitions
      class-fmw-workflow-registry.php # DB-backed workflow lookup
      class-fmw-workflow-context.php  # Runtime state during execution
      class-fmw-workflow-executor.php # Runs the steps
      class-fmw-workflow-job.php      # Action Scheduler job handler
      class-fmw-submission-listener.php # Listens to fre_submission_complete
      class-fmw-step-base.php         # Abstract base class for all steps
      class-fmw-step-registry.php     # Step type registration
      class-fmw-interpolator.php      # {{ variable }} substitution
      class-fmw-expression.php        # Conditional expression evaluator
      class-fmw-logger.php            # Structured logger (wraps FRE_Logger or Monolog)
    Steps/                            # Step library
      Core/                           # Control flow + FE integration
        class-step-set-variable.php
        class-step-conditional.php
        class-step-try-catch.php
        class-step-delay.php
        class-step-log-info.php
        class-step-log-warning.php
        class-step-log-error.php
        class-step-fre-get-entry.php
        class-step-fre-get-file.php
        class-step-fre-update-entry-status.php
        class-step-fre-delete-entry.php
      Drive/                          # Phase 2
        class-step-drive-find-folder.php
        class-step-drive-find-or-create-folder.php
        class-step-drive-create-folder.php
        class-step-drive-upload-file.php
        class-step-drive-share-link.php
      Email/                          # Phase 2
        class-step-send-email.php
        class-step-send-email-template.php
      Printavo/                       # Phase 3
        class-step-printavo-find-customer.php
        class-step-printavo-create-customer.php
        class-step-printavo-find-or-create-customer.php
        class-step-printavo-create-quote.php
      Http/                           # Phase 3
        class-step-http-get.php
        class-step-http-post.php
        class-step-http-request.php
      Notify/                         # Phase 5 (used by FlowMint, not in workflows)
        class-step-slack-notify.php
        class-step-admin-notify.php
    Connectors/                       # External service clients
      class-fmw-drive-client.php
      class-fmw-printavo-client.php
      class-fmw-email-client.php
      class-fmw-slack-client.php
      class-fmw-http-client.php
      REST/
        class-fmw-rest-api.php        # Registers REST routes
        class-fmw-rest-workflows.php  # /workflows endpoints
        class-fmw-rest-runs.php       # /runs endpoints
        class-fmw-rest-step-types.php # /step-types endpoints
        class-fmw-rest-preflight.php  # /preflight
        class-fmw-rest-auth.php       # Auth helpers (cap checks, rate limit)
    Database/
      class-fmw-schema.php            # DDL + migrations
      class-fmw-workflow-repository.php
      class-fmw-run-repository.php
      class-fmw-run-step-repository.php
      class-fmw-credential-store.php  # Encrypted API credential storage
    Mcp/
      class-fmw-mcp-tools.php         # PHP-side MCP tool definitions
    Admin/
      class-fmw-admin.php             # Top-level admin menu registration
      class-fmw-admin-runs.php        # Run history list table
      class-fmw-admin-run-detail.php  # Single run detail view
      class-fmw-admin-replay.php      # Manual replay handler
      class-fmw-admin-settings.php    # Settings page (credentials, etc.)
      class-fmw-admin-workflows.php   # Workflow list (read-only in v1, helpful for debugging)
  assets/
    css/
      admin.css                       # Admin UI styles
    js/
      admin.js                        # Admin UI behavior (mostly server-rendered)
  languages/
    flowmint-workflows.pot
  tests/
    Unit/                             # No DB, no external services, fast
      InterpolatorTest.php
      ExpressionTest.php
      ExecutorTest.php
      ConditionalStepTest.php
      ...
    Integration/                      # Real DB via WP test harness, mocked externals
      WorkflowExecutionTest.php
      RestApiTest.php
      ...
  bin/
    build-release.sh                  # Mirrors FRE — produces clean zip
    install-wp-tests.sh               # Sets up WP test harness for integration tests
```

## Database schema

Three custom tables. All InnoDB. Foreign keys (informational only — WP doesn't enforce, but they document intent).

### `wp_fmw_workflows`

The workflow definitions registry.

```sql
CREATE TABLE wp_fmw_workflows (
  id              VARCHAR(64)  NOT NULL,         -- e.g., "725-bulk-order-quote"
  title           VARCHAR(255) NOT NULL,
  form_id         VARCHAR(64)  NOT NULL,         -- references FRE form_id
  enabled         TINYINT(1)   NOT NULL DEFAULT 1,
  config          LONGTEXT     NOT NULL,         -- JSON
  managed_by      VARCHAR(64)  NOT NULL DEFAULT 'admin',  -- 'admin' or 'connector:cowork'
  connector_version BIGINT     NOT NULL DEFAULT 0,        -- bumps on each update
  created_at      DATETIME     NOT NULL,
  updated_at      DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_form_id (form_id),
  KEY idx_enabled (enabled, form_id)
) ENGINE=InnoDB;
```

Field notes:
- `id` is a string (workflow slug), chosen by the caller. Same convention as FormEngine forms. Format: `^[a-z0-9\-_]+$`.
- `form_id` is the FormEngine form this workflow binds to. Must exist (validated on workflow create/update by calling FormEngine's registry).
- `config` is the workflow JSON definition (steps array + settings). Schema documented in `STEP_LIBRARY.md`.
- `managed_by` mirrors FormEngine's pattern: `admin` for hand-authored workflows, `connector:cowork` for AI-created ones. Immutable after creation.
- `connector_version` increments on each update. Useful for change tracking and concurrency control.

### `wp_fmw_workflow_runs`

One row per workflow execution.

```sql
CREATE TABLE wp_fmw_workflow_runs (
  id              BIGINT UNSIGNED AUTO_INCREMENT,
  workflow_id     VARCHAR(64)  NOT NULL,
  form_id         VARCHAR(64)  NOT NULL,
  entry_id        BIGINT UNSIGNED NOT NULL,    -- FRE entry id
  status          VARCHAR(32)  NOT NULL DEFAULT 'queued',  -- queued | running | completed | failed | cancelled
  started_at      DATETIME     NULL,
  completed_at    DATETIME     NULL,
  duration_ms     INT          NULL,
  error_code      VARCHAR(64)  NULL,
  error_message   TEXT         NULL,
  failed_step     VARCHAR(64)  NULL,           -- step name that caused failure, if any
  retry_count     SMALLINT     NOT NULL DEFAULT 0,
  parent_run_id   BIGINT UNSIGNED NULL,        -- if this is a manual replay, points to original
  context_snapshot LONGTEXT    NULL,           -- final context as JSON, for debugging
  created_at      DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_workflow (workflow_id, created_at),
  KEY idx_status (status, created_at),
  KEY idx_entry (entry_id),
  KEY idx_form (form_id, created_at)
) ENGINE=InnoDB;
```

Status state machine:
- `queued` → enqueued, not yet picked up by Action Scheduler
- `running` → worker is executing it
- `completed` → all steps succeeded
- `failed` → a step failed and retries exhausted
- `cancelled` → admin cancelled it (manual action; v2 feature)

`context_snapshot` is the full FMW_Workflow_Context as JSON at the moment of completion or failure. Heavy data (file contents, large payloads) is stored as references, not inlined. Used for debugging in the admin UI.

### `wp_fmw_workflow_run_steps`

One row per step execution within a run.

```sql
CREATE TABLE wp_fmw_workflow_run_steps (
  id              BIGINT UNSIGNED AUTO_INCREMENT,
  run_id          BIGINT UNSIGNED NOT NULL,
  step_index      SMALLINT     NOT NULL,        -- 0-based position in workflow
  step_name       VARCHAR(64)  NOT NULL,        -- e.g., "find_customer", "create_quote"
  step_type       VARCHAR(64)  NOT NULL,        -- e.g., "printavo_find_customer"
  status          VARCHAR(32)  NOT NULL,        -- pending | running | success | failure | skipped
  started_at      DATETIME     NULL,
  completed_at    DATETIME     NULL,
  duration_ms     INT          NULL,
  config_snapshot LONGTEXT     NULL,           -- step's interpolated config (after variable substitution)
  output_snapshot LONGTEXT     NULL,           -- step's output as JSON (truncated if huge)
  error_code      VARCHAR(64)  NULL,
  error_message   TEXT         NULL,
  PRIMARY KEY (id),
  KEY idx_run (run_id, step_index)
) ENGINE=InnoDB;
```

Output snapshots are truncated at ~64KB to keep the table from bloating with large API responses. Full responses live only in PHP memory during execution.

### `wp_options` for credentials

API credentials (Drive service account JSON, Printavo API token, Slack webhook URL) are stored in `wp_options` with the prefix `fmw_credential_*`, encrypted at rest using OpenSSL with a key derived from `wp_salt('auth')` and a per-credential nonce. Never logged. Never returned in REST responses.

## Workflow JSON schema

A workflow definition's `config` field is JSON of this shape:

```json
{
  "version": "1.0",
  "title": "725 Bulk Order → Printavo + Drive",
  "description": "Internal note: optional",
  "form_id": "bulk-order-quote",
  "enabled": true,
  "settings": {
    "max_retries": 3,
    "retry_delay_seconds": 60,
    "timeout_seconds": 300,
    "on_failure_notify": ["slack", "email"]
  },
  "steps": [
    {
      "name": "customer",
      "type": "printavo_find_or_create_customer",
      "config": {
        "email": "{{ data.email }}",
        "name": "{{ data.full_name }}",
        "phone": "{{ data.phone }}"
      },
      "on_error": "fail",
      "skip_if": null
    },
    {
      "name": "month_folder",
      "type": "drive_find_or_create_folder",
      "config": {
        "parent_id": "1aVp_Zhd0OyL5K_h9dNYQ_f6lf_VOC8K8",
        "name": "{{ now('Y-m') }}"
      }
    },
    {
      "name": "submission_folder",
      "type": "drive_create_folder",
      "config": {
        "parent_id": "{{ steps.month_folder.id }}",
        "name": "{{ data.company || data.full_name }}"
      }
    },
    {
      "name": "design_upload",
      "type": "drive_upload_file",
      "config": {
        "parent_id": "{{ steps.submission_folder.id }}",
        "file_field": "design_file"
      },
      "skip_if": "{{ !has_file(entry, 'design_file') }}"
    },
    {
      "name": "create_quote",
      "type": "printavo_create_quote",
      "config": {
        "customer_id": "{{ steps.customer.id }}",
        "user_id": 60522,
        "invoice_status_id": 416419,
        "nickname": "{{ steps.submission_folder.name }}",
        "description": "{{ template('725-bulk-quote-description') }}"
      }
    },
    {
      "name": "customer_ack",
      "type": "send_email_template",
      "config": {
        "to": "{{ data.email }}",
        "from_name": "Orders Team",
        "from_email": "orders@725printlab.com",
        "subject": "Thank You for Your Quote Request!",
        "template": "725-customer-ack"
      }
    },
    {
      "name": "cleanup",
      "type": "fre_delete_entry",
      "config": {}
    }
  ]
}
```

Schema rules:
- `version` is the workflow JSON schema version. Currently `1.0`. Used for forward compatibility.
- `form_id` must match an existing FormEngine form ID at create-time (validated by calling FRE's registry).
- `settings.max_retries`, `settings.retry_delay_seconds`, `settings.timeout_seconds` are workflow-level defaults. Individual steps can override.
- `settings.on_failure_notify` is a list of notification channels. Possible values: `slack`, `email`, `none`.
- `steps[].name` is unique within the workflow and is how downstream steps reference outputs (`{{ steps.<name>.<output_field> }}`).
- `steps[].type` must be a registered step type (validated at create-time and at run-time).
- `steps[].config` is the step's configuration. Schema is per-step-type; documented in `STEP_LIBRARY.md`.
- `steps[].on_error` is `fail` (default — fail the run), `continue` (log error, skip this step's outputs, continue), or `retry` (use Action Scheduler retry).
- `steps[].skip_if` is a conditional expression. If it evaluates truthy, the step is skipped (status `skipped`).

## Variable interpolation syntax

Workflow configs use `{{ ... }}` for variable substitution. Resolved at step execution time, AFTER previous step outputs are available in context.

### Available variables

- `{{ data.<field> }}` — submitted form field value (e.g., `data.email`, `data.full_name`)
- `{{ entry.<field> }}` — FE entry metadata (e.g., `entry.id`, `entry.created_at`, `entry.ip_address`)
- `{{ entry_files.<field_key> }}` — file object for a file field (e.g., `entry_files.design_file.file_url`)
- `{{ steps.<step_name>.<output_field> }}` — output from a previously executed step
- `{{ form.<field> }}` — form metadata (e.g., `form.id`, `form.title`)
- `{{ workflow.<field> }}` — workflow metadata (e.g., `workflow.id`, `workflow.title`)
- `{{ run.<field> }}` — current run metadata (e.g., `run.id`, `run.started_at`)
- `{{ env.<key> }}` — explicit safe environment values (whitelist; not raw `$_ENV`)
- `{{ now(<format>) }}` — current timestamp, optional PHP date format
- `{{ template(<name>) }}` — render a named template (resolves to a string)

### Available expressions (in `skip_if` and `conditional` step `if`)

- Comparisons: `==`, `!=`, `>`, `<`, `>=`, `<=`
- Logical: `&&`, `||`, `!`
- Functions: `has_file(entry, '<field_key>')`, `is_empty(<value>)`, `length(<value>)`, `contains(<haystack>, <needle>)`

Expressions are parsed by a custom small expression evaluator. NO eval, NO arbitrary PHP execution. The evaluator is documented in `STEP_LIBRARY.md` under the `conditional` step.

### Defaults and safety

- Missing variables resolve to empty string (not undefined). Logs a warning so workflow authors notice typos.
- `||` is fallback (returns first truthy operand). Useful for defaults: `{{ data.company || data.full_name }}`.
- Variables are HTML-escaped only when used in HTML contexts. Default is raw string. Step authors are responsible for context-appropriate escaping.

## Async execution model

All workflow execution is async via Action Scheduler.

### Why Action Scheduler

- WordPress's built-in `wp_cron` is unreliable (depends on traffic to fire) and not designed for high-volume jobs
- Action Scheduler is the de-facto standard in WP land in 2026 (used by WooCommerce, Mailpoet, AutomateWoo)
- Built-in retry policy with exponential backoff
- Concurrent processing (multiple workers can execute jobs in parallel)
- Admin UI bundled (we hide it from clients but use it for FlowMint debugging)
- Battle-tested at massive scale

### Job lifecycle

1. Form submitted → FE fires `fre_submission_complete`
2. `FMW_Submission_Listener::on_submission_complete()`:
   - Looks up workflow by form_id
   - If no workflow exists for this form, returns silently
   - If workflow exists and is enabled, calls `as_enqueue_async_action('fmw_run_workflow', [$workflow_id, $entry_id])`
   - Inserts a `queued` row into `wp_fmw_workflow_runs`
   - Returns
3. Action Scheduler worker picks up the job (within seconds typically)
4. `FMW_Workflow_Job::run($workflow_id, $entry_id)`:
   - Updates run row to `running`, sets `started_at`
   - Loads workflow definition from DB
   - Loads entry data via FRE_Entry
   - Constructs FMW_Workflow_Context
   - Hands to FMW_Workflow_Executor
5. Executor runs each step:
   - Inserts a `running` row into `wp_fmw_workflow_run_steps`
   - Interpolates the step's config
   - Instantiates the step class
   - Calls `execute($context)`
   - On success: updates run_step row to `success` with output snapshot
   - On failure: updates run_step row to `failure`, then either retries (Action Scheduler will requeue) or fails the run
6. On run completion (success):
   - Update run row to `completed`, set `completed_at`, `duration_ms`
   - Fire `fmw_workflow_run_completed` action
7. On run failure (after max retries):
   - Update run row to `failed`
   - Fire `fmw_workflow_run_failed` action (which Phase 5 notification system listens to)

### Retry policy

Default: 3 retries with exponential backoff (60s, 240s, 900s — 1 min, 4 min, 15 min). Configurable per workflow via `settings.max_retries` and `settings.retry_delay_seconds`. Configurable per step via `step.max_retries`.

Retries happen at the WORKFLOW level, not the step level. If step 5 of 10 fails, the WHOLE WORKFLOW retries from step 1. This is intentional — many workflows have order dependencies that make resuming from the failed step incorrect.

For workflows where this is wrong (e.g., creating a Printavo Quote should not happen twice), individual steps must be IDEMPOTENT (see "Idempotency" below).

### Idempotency

Steps that create external resources (Printavo Quote, Drive folder, etc.) MUST be idempotent. The contract:

- Each run has a unique `run_id` (the row ID in `wp_fmw_workflow_runs`)
- Steps that create resources include the `run_id` in an idempotency key sent to the external service (e.g., as a custom field or a deduplication header)
- On retry, the step checks if a resource was already created with this `run_id` and returns the existing resource instead of creating a new one
- If the external service doesn't support idempotency keys natively, the step queries first ("does a Quote with this nickname + run_id already exist?") before creating

Specific patterns:
- `printavo_create_quote`: query for an existing Quote with `nickname == "{run_id}: {original nickname}"` before creating; on creation, prefix nickname with `{run_id}:`. Strip prefix in the visible output. (TBD — verify Printavo supports this query pattern.)
- `drive_create_folder`: pre-check by listing children of parent with matching name. If exists, return existing.
- `send_email`: deduplicate via a SHA256(run_id + recipient + subject) check against an `fmw_email_sent` transient with 1-hour TTL.

Steps that don't create external state (`set_variable`, `log`, `conditional`, etc.) are inherently idempotent.

## Error handling

### Errors caught at the workflow level

- Step throws an unhandled exception → caught by executor, run marked as failed at this step, retry triggered (or fail run if max retries exhausted)
- Step times out (PHP execution time limit approaching) → step is given a chance to clean up; the worker process is killed if needed; run marked as failed; retry triggered
- External service returns 5xx → handled as an exception; step's connector class converts the HTTP error into an FMW_Step_Exception with `code = 'external_5xx'`; retried
- External service returns 4xx → handled as an exception with `code = 'external_4xx'`; NOT retried by default (it's a client error, retrying won't help); run marked as failed
- External service returns auth error (401/403) → handled as `code = 'auth_failed'`; NOT retried; FlowMint is notified (likely a credential expired)
- Rate limit (429) → handled as `code = 'rate_limited'`; retried with EXTRA delay (the retry delay × 5)

### Errors NOT caught (panic mode)

- PHP fatal error mid-execution (out of memory, segfault) → Action Scheduler marks the job as `failed` and won't retry. Manual intervention required. Notification fires.
- WordPress database connection lost → similar handling, manual intervention.

### Per-step error policy

Each step can override the default error handling via `on_error`:
- `fail` (default) — error fails the workflow run
- `continue` — error is logged, the step's output is empty, the workflow continues to the next step
- `retry` — same as `fail` but the failure triggers an Action Scheduler retry

`continue` is useful for non-critical steps (e.g., "send Slack notification" in a workflow that's primarily about creating a Quote — failure to send Slack shouldn't fail the whole workflow).

## Security

### API credential storage

- Stored in `wp_options` with prefix `fmw_credential_*`
- Encrypted at rest using OpenSSL AES-256-GCM
- Encryption key derived from `wp_salt('auth')` + a per-install random nonce stored in a separate option
- Never logged; never returned in REST responses (REST returns `<encrypted>` placeholder for masking)
- Read by the connector classes only at runtime, never cached in static variables

### Capability checks

- All admin pages: `manage_options` capability required
- All REST endpoints: `manage_options` capability required (FlowMint operates the plugin; clients don't have this capability)
- MCP tool calls: authenticated via WP App Password + capability check; rate-limited per user via FRE's existing `FRE_Connector_Auth::enforce_rate_limit` pattern

### Input validation

- Workflow JSON validated against schema on create/update (rejects malformed definitions)
- Step types validated against the registered step library (rejects unknown types)
- Form ID validated against FormEngine registry (rejects workflows for non-existent forms)
- All user-provided strings sanitized via `sanitize_text_field` or context-appropriate WP sanitizers

### Sensitive data in logs

- Never log full credentials, API tokens, or PII
- Email addresses logged as masked: `b***@gmail.com`
- File contents never logged (only metadata: filename, size, MIME)
- Debug-level logs that contain more detail are gated behind a `FMW_DEBUG` constant in `wp-config.php`

## Observability

### Structured logging

`FMW_Logger` wraps WordPress logging with structured fields:

```php
FMW_Logger::info('Workflow run started', [
    'run_id' => 123,
    'workflow_id' => '725-bulk-order-quote',
    'entry_id' => 5,
]);
```

Output format depends on configuration:
- Default: writes to WP debug.log if `WP_DEBUG_LOG` is enabled, with structured prefix
- Optional: integrate with Monolog (Phase 5) for multi-handler support (file + Slack + Sentry)
- Optional: send to FRE_Logger for unified logging across both plugins

### Run history is the primary observability surface

Admin UI shows:
- List of recent runs (filterable by workflow, status, date range)
- Per-run detail: every step with timing, status, config snapshot, output snapshot, error if any
- Full context_snapshot for debugging
- Replay button for failed runs

### Notifications

- Slack webhook (if configured) fires on workflow run failure
- Email to FlowMint admin on workflow run failure
- Configurable rules (e.g., "only after 3 consecutive failures")
- All notifications include a deep link to the run detail page

## Plugin lifecycle

### Activation

`register_activation_hook()` runs:
- Verifies FormEngine is active and version >= 1.6.0 (deactivate self with admin notice if not)
- Creates DB tables (`wp_fmw_workflows`, `wp_fmw_workflow_runs`, `wp_fmw_workflow_run_steps`)
- Schedules a daily housekeeping Action Scheduler job (cleanup old run records per retention policy)
- Sets default options

### Deactivation

`register_deactivation_hook()` runs:
- Unschedules housekeeping jobs
- Does NOT drop tables (data preserved for reactivation)
- Does NOT delete options (preserved)

### Uninstallation

`uninstall.php` runs (when plugin is DELETED):
- Drops all `wp_fmw_*` tables
- Deletes all `fmw_*` options
- Deletes all `fmw_*` transients
- Logs the uninstall action

### Upgrades (future)

`flowmint-workflows.php` runs an upgrade check on init:
- Compares `FMW_VERSION` with stored `fmw_db_version` option
- If newer, runs migrations (DDL changes, data migrations) in `class-fmw-schema.php`
- Updates `fmw_db_version` to current

## FormEngine integration contract

FlowMint Workflows uses these FormEngine APIs and ONLY these:

### Hooks consumed (FRE → FMW)

- `fre_submission_complete($entry_id, $form_id, $sanitized_data)` — primary trigger; FMW listens here

### Classes used (FMW reads from FRE)

- `FRE_Entry` — load entry data, file attachments
- `fre_get_entry($entry_id)` — convenience function
- `FRE_Logger` (optional) — for unified logging

### Hooks emitted (FMW → other plugins / FlowMint code)

- `fmw_workflow_run_started($run_id, $workflow_id, $entry_id)`
- `fmw_workflow_run_completed($run_id, $workflow_id, $entry_id)`
- `fmw_workflow_run_failed($run_id, $workflow_id, $entry_id, $error_code, $error_message)`
- `fmw_step_completed($run_id, $step_name, $step_type, $output)`
- `fmw_step_failed($run_id, $step_name, $step_type, $error_code, $error_message)`

### Filters offered

- `fmw_workflow_definition($definition, $workflow_id)` — modify a workflow's JSON before execution
- `fmw_step_config($config, $step, $context)` — modify a step's interpolated config before execution
- `fmw_step_output($output, $step, $context)` — modify a step's output before it's written to context
- `fmw_credential($credential, $key)` — intercept credential lookup (useful for testing)

The integration is intentionally one-way: FormEngine never knows about FlowMint Workflows. The plugin can be deactivated and FE works fine. See `INTEGRATION_FRE.md` for the full contract.

## Performance considerations

### Expected workload

- Per client: 10-100 form submissions per day (estimate for typical service business)
- Per workflow: 5-15 steps
- Step execution time: 100ms-3000ms each (most are network calls)
- End-to-end workflow time: typically 5-30 seconds

### Bottlenecks to plan for

- **Drive API**: 1000 requests/100 seconds default quota. With chunked uploads of large files, request rate is bounded by upload duration, not the per-second quota. Plan for buffering if a client expects 100+ submissions/min.
- **Printavo API**: rate limits TBD (check Printavo docs). The connector class includes per-second rate limiting and 429 handling.
- **DB writes**: each step inserts a `wp_fmw_workflow_run_steps` row. With 100 runs/day × 10 steps = 1000 inserts/day. Trivial. The table will grow, hence the housekeeping job.

### Housekeeping

A daily Action Scheduler job:
- Deletes `wp_fmw_workflow_runs` rows older than `fmw_run_retention_days` (default: 90 days), CASCADE deletes their `wp_fmw_workflow_run_steps`
- Deletes Action Scheduler's own completed action records older than 30 days (configurable)

Configurable via `fmw_run_retention_days` option.

## Versioning

Plugin follows Semantic Versioning:
- v0.x — pre-release; breaking changes at any time
- v1.0+ — public API stable; breaking changes require major version bump
- Workflow JSON schema versioned independently (currently `1.0`)

The plugin's REST API namespace `flowmint/v1/connector/...` is stable for the v1.x lifetime. v2 would use a new namespace to allow side-by-side compatibility.

## Open questions / decisions to revisit

- **Action Scheduler vs ActionScheduler-as-package vs WP-Cron native**. Defaulting to bundling Action Scheduler via Composer.
- **Encryption library**. OpenSSL AES-256-GCM is fine but Sodium is more modern. Defaulting to OpenSSL for compatibility.
- **MCP tool naming**: `workflow_*` vs `flowmint_workflow_*` for namespace clarity. Defaulting to `workflow_*` to keep it short; the tool surface is unique to this plugin so prefix collision is unlikely.
- **GraphQL client for Printavo**: write our own or use a library? Defaulting to our own (~200 lines, no deps) since Printavo's GraphQL is small and we want full control.
- **Should `fre_delete_entry` be a step or implicit?** Currently a step. Could be implicit (always run at end of successful workflow). Defaulting to explicit step so workflows that intentionally preserve entries can do so.

These will be revisited as Phase 1 implementation surfaces concrete requirements.
