# FlowMint Workflows — Connector API

REST endpoints and MCP tool surface. This is the contract between the plugin and external callers (Claude via MCP, future admin UI, future integrations).

## REST namespace

All endpoints live under `/wp-json/flowmint/v1/connector/...`.

This namespace is independent of FormEngine's `/wp-json/fre/v1/connector/...`. The two plugins have separate connector REST APIs that interoperate via the WordPress hook system, not via cross-plugin REST calls.

## Authentication

- **REST endpoints:** WordPress App Password via Basic Auth, plus the `flowmint_manage_workflows` capability (granted to administrators by default; the name is filterable via `flowmint_manage_workflows_capability`) (`FMW_REST_Auth::require_manage`)
- **Connector switch:** every endpoint except `/preflight` also requires the connector to be enabled in **FlowMint Workflows → Connector**; otherwise it returns `403 connector_disabled`. It is off by default.
- **MCP tools:** authenticated via the same App Password Basic Auth (the MCP layer translates tool calls to REST calls under the hood)

## Versioning

`v1` namespace is stable for the v1.x lifetime. Breaking changes require a `v2` namespace; both can coexist.

## The workflow language

What a workflow's `config` JSON may contain and how FlowMint evaluates it.
Every statement here is taken from the code it names; when the two
disagree, the code wins and this section is wrong. (The preflight has
always pointed assistants here for this material; until 2026-09-19 the
section did not exist, and the expression syntax was documented only in a
code comment.)

### Shape

```json
{
  "trigger": { "type": "form", "form_id": "contact" },
  "settings": { "max_retries": 3 },
  "steps": [
    { "name": "log_it", "type": "log_info", "config": { "message": "Entry {{ entry.id }}" } },
    { "name": "notify", "type": "send_email", "on_error": "continue",
      "skip_if": "{{ is_empty(data.email) }}",
      "config": { "to": "{{ data.email }}", "subject": "Thanks", "body": "…" } }
  ]
}
```

- **`trigger`** (required). `{ "type": "form", "form_id": "…" }` runs the
  workflow when that Promptless Forms form is submitted (the
  `pforms_submission_complete` action); a top-level `form_id` without a
  trigger block is normalised to this. `{ "type": "schedule", "interval":
  "hourly" | "twicedaily" | "daily" | "weekly" }` runs on a schedule, with
  optional `hour` (0–23), `minute` (0–59) and `day_of_week`, site-local
  (`FMW_Workflow_Validator`, `FMW_Schedule_Listener`). There is no "run now"
  for a scheduled workflow; replay a finished run instead.
- **One workflow per form.** A submission runs only ONE workflow: the
  most recently updated *enabled* form-triggered workflow for that form
  (`FMW_Workflow_Repository::get_for_form`, `ORDER BY updated_at DESC
  LIMIT 1`). Enabling or editing a second workflow for the same form
  silently takes over from the first, which stops running without any
  error. Put everything a form needs into one workflow, and disable or
  delete the old one when you replace it.
- **`settings.max_retries`** — how many times a step with
  `on_error: "retry"` is retried; default 3, 0 turns retries off
  (`FMW_Workflow_Job::get_max_retries`). It does nothing for steps with
  `fail` or `continue`.
- **`steps`** — an ordered list. Each step is `{ name, type, config }` plus
  the optional keys below. `name` is unique within the workflow; later
  steps read this step's output as `{{ steps.<name>.<field> }}`. `type` is
  one of the registered step types (`flowmint_list_step_types`).

### Per-step keys

| Key | Meaning |
|---|---|
| `skip_if` | An expression. When it is truthy the step is skipped and recorded as skipped with the expression as the reason (`FMW_Workflow_Executor`). There is no `when` key — a step with `when` runs every time. |
| `on_error` | `fail` (default), `continue` or `retry`. `fail` ends the run as **Failed** at once: the alert goes out and the run can be replayed. `continue` records the failure, gives the step the output `{ failed: true, error: <code> }` and carries on. `retry` retries the run when the error is retryable and `settings.max_retries` allows — after 1, 5 and then 15 minutes — resuming at this step (see below); when retries run out it fails like `fail`. Use `retry` on a step that talks to a service that can be briefly down. |

**A retry resumes at the step that failed.** After each top-level step the
run saves a checkpoint (the context: step outputs, variables, the entry),
and the retry restores it and starts at the failed step, so the steps before
it — their emails, records and quotes — do not run again
(`FMW_Workflow_Job::resume_point`). The failed step itself does run again,
and so does a whole `conditional` or `try_catch` that failed part-way
through its nested steps. If the workflow's steps are edited while a retry
waits, the retry fails with `workflow_changed` rather than resume at a step
that may have moved; replay it. While it waits, the run shows **Queued** with
the error and failed step recorded.

**A replay runs the whole workflow again from the first step**, with a fresh
context. Steps with side effects are written to be safe to repeat:
`send_email` skips a send it already made in the same run to the same
recipient with the same subject within the hour; `*_find_or_create_*` steps
find what they created the first time. Errors that cannot succeed on a
second attempt are not retried: `external_4xx`, `auth_failed`,
`validation_failed`, `config_error`, `permission_denied`,
`credential_not_configured`, `dependency_missing`, `file_not_found`,
`file_not_readable`, `template_not_found`, `invalid_input`, `php_error`
(a bug in a step), `workflow_changed` (`FMW_Step_Exception::is_retryable`).

### Interpolation: `{{ … }}`

Any string in a step's `config` may contain `{{ … }}`
(`FMW_Interpolator`). Inside the braces:

- **A context path** — `data.email`, `labels.service`, `entry.id`,
  `steps.find.contact_id`, `vars.team`, `run.id`, `workflow.id`, `form.id`,
  `entry_files.<field_key>`, and inside `pre_upsert_records`'s `map`,
  `item.<field>`. `env` holds exactly three values — `env.site_name`,
  `env.site_url`, `env.admin_email` (`FMW_Workflow_Context`) — and nothing
  else: stored credentials cannot be read through `{{ env.* }}` — an HTTP
  step uses a stored secret through its `auth` option instead (see
  Credentials). The preflight's `context_shape` describes each namespace.
  **A path that does not exist resolves to an empty string**, and the step
  still succeeds — check paths against the run history.
- **A fallback** — `{{ data.company || data.full_name }}` gives the first
  truthy operand.
- **A literal** — `'text'` or `"text"`, a number, `true`, `false`, `null`.
- **A function call** — `now('Y-m')` (site-local time in a PHP date
  format), `template('name')` (renders `wp-content/uploads/fmw-templates/`),
  `has_file(entry, 'field_key')`, `is_empty(x)`, `length(x)` (items or
  characters), `contains(haystack, needle)`, `equals_ci(a, b)`. An unknown
  function logs a warning and resolves to an empty string.

There are **no filters** (`{{ data.email | upper }}` resolves to an empty
string).

When a string is exactly one `{{ … }}` the value keeps its type (an array
stays an array — that is how `records: "{{ steps.fetch.body.items }}"`
passes a list); when the braces sit inside other text, the value is turned
into text.

**Human-facing text should use `labels.*`, machine destinations `data.*`.**
For a select, radio or checkbox, `data.*` is the stored option value
(`joinery`) and `labels.*` the label the visitor saw
(`Hand-Cut Joinery Intensive`).

### Expressions: `skip_if` and `conditional`

`skip_if` and the `conditional` step's `if` are boolean expressions
(`FMW_Expression` — a small parser, not `eval`):

- operands: `{{ … }}` paths and function calls as above, and literals;
- comparison: `==`, `!=`, `>`, `<`, `>=`, `<=`;
- logic: `&&`, `||`, `!`, and parentheses.

```json
{ "if": "{{ data.service == 'pothole' || data.service == 'streetlight' }}" }
{ "skip_if": "{{ !has_file(entry, 'photo') }}" }
{ "if": "{{ length(data.notes) > 100 && !is_empty(data.full_name) }}" }
{ "if": "{{ has_file(entry, 'photo') }} && {{ data.rush == 'yes' }}" }
```

Function calls, paths and literals can be combined freely inside one `{{ }}`
or across several; each block that compares or negates is evaluated as a
condition of its own. A block of only `||` is a VALUE — the first non-empty
operand — so `{{ data.nickname || data.name }} == 'Pat'` compares a name.

**Before 0.10.0** three shapes gave an answer that did not depend on the data:
a call sharing its `{{ }}` with an operator was never made
(`{{ !has_file(entry, 'photo') }}` was always true); a call inside an
expression wrapped from its first `{{` to its last `}}` was never made; and
a comparing block inside a larger expression came back empty. The
workarounds that were documented then — the operator outside the braces,
each block in parentheses — still work. After updating, the **Workflows**
screen lists any saved condition in one of those shapes
(`FMW_Expression::legacy_result_differs`), because it now evaluates as
written and may take a different path.

### Nested steps

`conditional` (`then`, `else`) and `try_catch` (`try`, `catch`) hold nested
step lists. Each nested step is interpolated when it runs, so it can use
the output of the steps before it in the same list. `try_catch` catches
the error codes in `catch_codes`, or every error when that list is empty.
In the run history, a nested step is recorded as its own step, and the
parent records its expression and lists as written.

## Endpoints

### Preflight

`GET /wp-json/flowmint/v1/connector/preflight`

Health check. Returns plugin version, capability info, schema doc URL, recent connector calls (for debugging).

**Response:**
```json
{
  "success": true,
  "data": {
    "plugin_version": "1.0.0",
    "connector_api_version": "v1",
    "connector_enabled": true,
    "fre_active": true,
    "fre_version": "1.6.0",
    "action_scheduler_active": true,
    "authenticated_as": "962486pwpadmin",
    "user_capabilities": {
      "fmw_manage_workflows": true
    },
    "schema_document_url": "https://example.com/wp-content/plugins/flowmint-workflows/docs/CONNECTOR_API.md",
    "diagnostics": {
      "stored_plugin_version": "1.0.0",
      "database_health": { "ok": true, "tables_present": ["wp_fmw_workflows", "wp_fmw_workflow_runs", "wp_fmw_workflow_run_steps"] },
      "credentials_configured": {
        "drive": true,
        "printavo": true,
        "slack": false
      },
      "recent_calls": [...]
    }
  }
}
```

---

### Workflows

#### `GET /workflows`

List all workflows. Paginated.

**Query parameters:**
- `form_id` (optional) — filter to one form
- `enabled` (optional, bool) — filter to enabled/disabled
- `managed_by` (optional) — `admin` or `connector:cowork`
- `page` (default 1)
- `per_page` (default 20, max 100)

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": "725-bulk-order-quote",
      "title": "725 Bulk Order → Printavo + Drive",
      "form_id": "bulk-order-quote",
      "enabled": true,
      "managed_by": "connector:cowork",
      "connector_version": 12,
      "created_at": "2026-05-03 12:00:00",
      "updated_at": "2026-05-03 14:30:00"
    }
  ],
  "meta": { "total": 1, "page": 1, "per_page": 20, "has_more": false }
}
```

Note: list view does NOT include the full `config` JSON. Use GET on a single workflow to retrieve it.

#### `GET /workflows/{id}`

Get a single workflow including its full config.

**Response:**
```json
{
  "success": true,
  "data": {
    "id": "725-bulk-order-quote",
    "title": "725 Bulk Order → Printavo + Drive",
    "form_id": "bulk-order-quote",
    "enabled": true,
    "config": "{\"version\":\"1.0\",\"steps\":[...]}",
    "managed_by": "connector:cowork",
    "connector_version": 12,
    "created_at": "...",
    "updated_at": "..."
  }
}
```

`config` is a JSON STRING (parse client-side). Same convention as FormEngine's form `config`.

#### `POST /workflows`

Create a new workflow.

**Request body:**
```json
{
  "id": "725-bulk-order-quote",
  "title": "725 Bulk Order → Printavo + Drive",
  "form_id": "bulk-order-quote",
  "enabled": true,
  "config": "{\"version\":\"1.0\",\"steps\":[...]}"
}
```

`config` MUST be a JSON STRING. Object form is rejected with `code: invalid_json`.

**`enabled` defaults to false.** A workflow created without `"enabled": true` is saved disabled and does not run (`FMW_Workflow_Repository::create`).

**One workflow per form:** enabling or editing a second workflow for the same `form_id` makes it the one that runs, and the first silently stops — see "One workflow per form" above.

Validation (`FMW_Workflow_Validator::validate_full`, also used by PATCH):
- `id` matches `^[a-z0-9\-_]+$`
- `id` does not already exist (use PATCH to update)
- `form_id` exists in Promptless Forms (a warning, not an error, when Promptless Forms is not loaded)
- `config` is valid JSON with a `trigger` block (or a top-level `form_id`) and a `steps` array
- each **top-level** step has a unique `name`, a registered `type`, a valid `on_error` and an object `config`

Not checked: steps nested inside `conditional` (`then`/`else`) or `try_catch` (`try`/`catch`) — an unknown type or bad key there is found only when the run reaches it — and the contents of each step's `config` against the step type's schema.

**Response (201):**
```json
{
  "success": true,
  "data": {
    "id": "725-bulk-order-quote",
    "managed_by": "connector:cowork",
    "connector_version": 1,
    ...
  }
}
```

**Errors:**
- `400 invalid_json` — body is not JSON, or `config` is an object instead of a string
- `400 invalid_workflow` — validation failed; `data.errors` lists each problem (bad id, unknown form, malformed config, unknown step type, …) and `data.warnings` any warnings
- `409 already_exists` — id already used

#### `PATCH /workflows/{id}`

Update an existing workflow. All fields optional except `id` (in URL).

**Request body:**
```json
{
  "title": "New title",
  "enabled": false,
  "config": "{...}"
}
```

Bumps `connector_version` and `updated_at` on every successful update — so if two enabled workflows share a form, the one you updated last becomes the one that runs. When `config` or `form_id` is supplied, the result is re-validated as for POST.

`managed_by` is IMMUTABLE (cannot change from `admin` to `connector:cowork` or vice versa).

#### `DELETE /workflows/{id}`

Delete a workflow. Existing runs (in `wp_fmw_workflow_runs`) are preserved with the workflow_id reference; the workflow definition is gone but historical run data remains.

To CASCADE delete runs along with the workflow, pass `?cascade=true`.

---

### Workflow runs

#### `GET /runs`

List workflow runs. Paginated.

**Query parameters:**
- `workflow_id` (optional)
- `form_id` (optional)
- `entry_id` (optional)
- `status` (optional) — queued | running | completed | failed | cancelled
- `date_from`, `date_to` (optional, YYYY-MM-DD)
- `page`, `per_page`

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 42,
      "workflow_id": "725-bulk-order-quote",
      "form_id": "bulk-order-quote",
      "entry_id": 5,
      "status": "completed",
      "started_at": "2026-05-03 12:00:00",
      "completed_at": "2026-05-03 12:00:15",
      "duration_ms": 15234,
      "retry_count": 0,
      "created_at": "..."
    }
  ],
  "meta": { ... }
}
```

#### `GET /runs/{id}`

Full run detail including all step results.

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 42,
    "workflow_id": "725-bulk-order-quote",
    "form_id": "bulk-order-quote",
    "entry_id": 5,
    "status": "completed",
    "started_at": "...",
    "completed_at": "...",
    "duration_ms": 15234,
    "error_code": null,
    "error_message": null,
    "failed_step": null,
    "retry_count": 0,
    "context_snapshot": "{...}",
    "steps": [
      {
        "step_index": 0,
        "step_name": "customer",
        "step_type": "printavo_find_or_create_customer",
        "status": "success",
        "started_at": "...",
        "completed_at": "...",
        "duration_ms": 1240,
        "config_snapshot": "{...}",
        "output_snapshot": "{\"id\":\"10706641\",...}",
        "error_code": null,
        "error_message": null
      },
      ...
    ]
  }
}
```

#### `POST /runs/{id}/replay`

Manually replay a run. Useful for failed runs after fixing the underlying issue.

**No request body.** The route ignores any body: there is no resume-from-step and no context override. A replay is a new run of the same workflow for the same entry, from the first step, using the workflow's CURRENT saved config (`FMW_REST_Runs::replay`).

- Only runs in `failed`, `cancelled` or `completed` can be replayed; any other status returns `400 cannot_replay` — a run waiting to retry is `queued`. Nothing sets `cancelled` today.
- A replay runs even when the workflow is disabled.

**Response:**
```json
{
  "success": true,
  "data": {
    "new_run_id": 43,
    "parent_run_id": 42,
    "status": "queued"
  }
}
```

The new run is enqueued via Action Scheduler. It runs async — caller polls `GET /runs/{new_run_id}` for status.

There is no cancel route.

---

### Step types

#### `GET /step-types`

List all registered step types. Used by Claude/MCP to know what steps are available when generating a workflow.

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "type": "printavo_find_or_create_customer",
      "category": "Printavo",
      "display_name": "Printavo: Find or Create Customer",
      "description": "Returns existing customer if found by email, otherwise creates one.",
      "has_side_effects": true,
      "config_schema": { "type": "object", "properties": { ... }, "required": [...] },
      "output_schema": { "type": "object", "properties": { ... } }
    },
    ...
  ]
}
```

#### `GET /step-types/{type}`

Get a single step type's full schema and documentation.

**Response:** Same shape as one element of the list endpoint, plus:
- `examples`: array of usage examples (small JSON snippets)
- `error_codes`: array of possible error codes this step can throw

---

### Test / dry-run

#### `POST /workflows/{id}/test`

Validate a workflow without running it. Useful for AI-generated workflows to verify before saving.

**Request body:**
```json
{
  "config": "{\"trigger\":{...},\"steps\":[...]}"
}
```

If `config` is provided, validates it (the `{id}` is then not looked up). If omitted, validates the saved workflow's config (`404 workflow_not_found` if there is none). Nothing is executed and nothing is interpolated; any other body field is ignored.

**This is a shallower check than create/update.** It runs `FMW_Workflow_Validator::validate()`, not `validate_full()`: it does **not** check that the form exists or that the id is well-formed, and — like create/update — it checks **top-level steps only**, not steps nested in `conditional` or `try_catch`. A workflow that passes `/test` can still be rejected by POST/PATCH, and a nested-step mistake passes both.

**Response:**
```json
{
  "success": true,
  "data": {
    "valid": true,
    "errors": [],
    "warnings": []
  }
}
```

---

### Credentials

#### `GET /credentials`

List the four built-in credential keys and every stored HTTP credential (`http_<name>`) — NEVER values. `GET /credentials/{key}` returns one of them.

**Response:**
```json
{
  "success": true,
  "data": [
    { "key": "drive_service_account", "configured": true, "testable": true },
    { "key": "printavo_api_token", "configured": true, "testable": true },
    { "key": "slack_webhook", "configured": false, "testable": false },
    { "key": "notification_email", "configured": false, "testable": false },
    { "key": "http_crm", "configured": true, "testable": false }
  ]
}
```

#### `PUT /credentials/{key}`

Set a credential. Encrypted at rest.

**This route is the only way to set a credential.** There is no admin screen for credentials and no MCP tool that sets one; call `PUT /wp-json/flowmint/v1/connector/credentials/{key}` directly (App Password Basic Auth), with the connector enabled.

**Request body:**
```json
{
  "value": "<credential value>"
}
```

`value` must be a string:
- `drive_service_account`: the entire service-account JSON key, as a string.
- `printavo_api_token`: a JSON string `{"email": "<Printavo login email>", "token": "<API token>"}` — a bare token is rejected when a step runs (`FMW_Printavo_Client::from_credentials`).
- `slack_webhook`: the incoming-webhook URL (must start with `https://`).
- `notification_email`: the address failure alerts are emailed to (the site admin email when unset).
- `http_<name>` (since 0.10.0; `<name>` is lowercase letters, digits and underscores, up to 48): a secret for HTTP steps — an API token, an API key, or `user:password` for Basic auth. A step uses it by name, never by value:

  ```json
  { "type": "http_post", "config": { "url": "https://api.crm.example/v1/leads",
    "auth": { "credential": "crm", "scheme": "bearer" } } }
  ```

  `scheme` is `bearer` (default; `Authorization: Bearer <secret>`), `header` (with `"header": "X-API-Key"`; the header carries the secret) or `basic` (`Authorization: Basic base64(<secret>)`). It replaces a header of the same name in `headers`. The secret is added when the request is sent (`FMW_Http_Client::with_credential`), so it is never in the workflow config, the step's recorded config or its output. A missing one fails the step with `credential_not_configured`; one that no longer decrypts, with `credential_unreadable`. Not testable through `/test`.

Failure alerts go to Slack **or** email, never both: when `slack_webhook` is set, the alert is posted there without waiting for a reply, and if that post fails the alert is lost — no email is sent (`FMW_Failure_Notifier`).

**Response:** `{ "success": true, "data": { "key": "...", "configured": true } }`

Stored value is NEVER returned in responses. To rotate, PUT a new value.

#### `DELETE /credentials/{key}`

Removes a credential. Workflows using it will fail until reconfigured.

#### `POST /credentials/{key}/test`

Tests a stored credential. What that means differs by key:

- `printavo_api_token` — makes a real call: queries Printavo for the account and returns its id, name and email.
- `drive_service_account` — **does not contact Google.** It only checks the stored JSON parses and has a `client_email`, and echoes `client_email` and `project_id` back (`FMW_Drive_Client::test`). A key that Google has revoked, or a folder not shared with the service account, still tests `ok`.
- `slack_webhook`, `notification_email` — cannot be tested; returns `400 not_testable`.

**Response:**
```json
{
  "success": true,
  "data": {
    "key": "drive_service_account",
    "test_result": "ok",
    "details": { "service_account_email": "fmw-prod@project.iam.gserviceaccount.com", "project_id": "..." }
  }
}
```

If the test fails the response is still `success: true`, with `data.test_result: "failed"`, `data.error_code` and `data.error`. An unset credential returns `400 credential_not_configured`.

---

## MCP tool surface

The relay (`includes/Connectors/MCP/assets/flowmint-connector.js`) exposes 16 tools, all prefixed `flowmint_`. Each is a thin wrapper over one REST route.

| Tool | REST equivalent | Description |
|---|---|---|
| `flowmint_preflight` | GET /preflight | Health check |
| `flowmint_list_workflows` | GET /workflows | List workflows |
| `flowmint_get_workflow` | GET /workflows/{id} | Get one workflow |
| `flowmint_create_workflow` | POST /workflows | Create workflow |
| `flowmint_update_workflow` | PATCH /workflows/{id} | Update workflow |
| `flowmint_delete_workflow` | DELETE /workflows/{id} | Delete workflow |
| `flowmint_test_workflow` | POST /workflows/{id}/test | Validate (no execution) |
| `flowmint_list_runs` | GET /runs | List runs |
| `flowmint_get_run` | GET /runs/{id} | Get run detail |
| `flowmint_replay_run` | POST /runs/{id}/replay | Replay run |
| `flowmint_list_step_types` | GET /step-types | List step types |
| `flowmint_get_step_type` | GET /step-types/{type} | Get one step type |
| `flowmint_list_credentials` | GET /credentials | List credentials (no values) |
| `flowmint_test_credential` | POST /credentials/{key}/test | Test credential |
| `flowmint_list_templates` | GET /templates | List templates |
| `flowmint_get_template` | GET /templates/{name} | Get one template |

There is **no tool to set or delete a credential** or to write a template; those are REST-only (`PUT`/`DELETE /credentials/{key}`, `PUT`/`DELETE /templates/{name}`).

### Common patterns for AI usage

**Creating a workflow from natural language:**
1. Claude calls `flowmint_list_step_types` to know what's available
2. Claude composes the workflow JSON
3. Claude checks there is no other enabled workflow for the same form (`flowmint_list_workflows` with `form_id`) — only one runs per form
4. Claude calls `flowmint_create_workflow` with `enabled: false` (the default), then `flowmint_test_workflow`, then `flowmint_update_workflow` with `enabled: true`

**Debugging a failed run:**
1. Claude calls `flowmint_list_runs` filtered by status=failed (a run waiting to retry is status=queued, with `error_code` and `failed_step` already set)
2. Picks the most recent
3. Calls `flowmint_get_run` to see step-level detail
4. Diagnoses (e.g., a step's config_snapshot reveals the issue)
5. Edits the workflow definition (`flowmint_update_workflow`) and calls `flowmint_replay_run`. A replay cannot change the entry's data or start part-way through.

**Onboarding a new client:**
1. Breon describes the client's workflow in natural language to Claude
2. Claude reads `STEP_LIBRARY.md` to know the vocabulary
3. Claude generates the workflow JSON
4. Claude creates it disabled, validates via `flowmint_test_workflow`, then enables it
5. Credentials the workflow needs are set by a person through `PUT /credentials/{key}`

## Error response format

Errors are standard WordPress REST errors (`WP_Error`), with the HTTP status in `data.status`:
```json
{
  "code": "invalid_workflow",
  "message": "Workflow validation failed.",
  "data": {
    "status": 400,
    "errors": ["Invalid workflow id: must match ^[a-z0-9\\-_]+\\$."],
    "warnings": []
  }
}
```

Common error codes:
- `invalid_json` — request body or config not valid JSON, or config sent as an object
- `invalid_workflow` — create/update validation failed; see `data.errors`
- `already_exists` — workflow id already used (409)
- `workflow_not_found` — id doesn't exist
- `run_not_found` — run id doesn't exist
- `cannot_replay` — run is not failed, cancelled or completed
- `unknown_credential_key` — not one of the four supported keys
- `credential_not_configured` — required credential missing
- `not_testable` — credential has no test
- `connector_disabled` — the connector is switched off (403)
- `permission_denied` — caller lacks `flowmint_manage_workflows`
- `dependency_missing` — a required library or plugin is not loaded

There is no rate limiting on these routes.
