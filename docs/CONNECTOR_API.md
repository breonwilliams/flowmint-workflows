# FlowMint Workflows — Connector API

REST endpoints and MCP tool surface. This is the contract between the plugin and external callers (Claude via MCP, future admin UI, future integrations).

## REST namespace

All endpoints live under `/wp-json/flowmint/v1/connector/...`.

This namespace is independent of FormEngine's `/wp-json/fre/v1/connector/...`. The two plugins have separate connector REST APIs that interoperate via the WordPress hook system, not via cross-plugin REST calls.

## Authentication

- **REST endpoints:** WordPress App Password via Basic Auth, plus `manage_options` capability check
- **MCP tools:** authenticated via the same App Password Basic Auth (the MCP layer translates tool calls to REST calls under the hood)

## Versioning

`v1` namespace is stable for the v1.x lifetime. Breaking changes require a `v2` namespace; both can coexist.

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

Validation:
- `id` matches `^[a-z0-9\-_]+$`
- `id` does not already exist (use PATCH to update)
- `form_id` exists in FormEngine
- `config` is valid JSON
- `config` matches the workflow JSON schema (validated against `STEP_LIBRARY.md` step types)
- All step types referenced exist in the registry

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
- `400 invalid_workflow_id` — id format invalid
- `400 form_not_found` — form_id doesn't exist in FRE
- `400 invalid_config` — config JSON malformed
- `400 invalid_step_type` — references unknown step type
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

Bumps `connector_version` on every successful update.

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

**Request body:**
```json
{
  "from_step": null,
  "with_modified_context": null
}
```

| Field | Type | Description |
|---|---|---|
| `from_step` | string \| null | Step name to resume from. Default: start from step 0. |
| `with_modified_context` | object \| null | Override context fields for this replay (e.g., manually fix a value that caused failure). |

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

#### `POST /runs/{id}/cancel`

Cancel a queued or running run. v2 feature; v1 returns `not_implemented`.

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
  "config": "{\"version\":\"1.0\",\"steps\":[...]}",
  "test_data": {
    "data": { "email": "test@example.com", "full_name": "Test User", ... },
    "entry": { "id": 999, "created_at": "2026-05-03" }
  }
}
```

If `config` is provided, validates it. If omitted, validates the existing workflow's saved config.

If `test_data` is provided, runs each step's interpolation against this synthetic context (without executing external API calls) and returns what the interpolated config would look like.

**Response:**
```json
{
  "success": true,
  "data": {
    "valid": true,
    "warnings": ["Step 'create_quote' uses {{ data.tax_doc_url }} which is not in test_data"],
    "interpolated_steps": [
      { "name": "customer", "interpolated_config": { ... } },
      ...
    ]
  }
}
```

---

### Credentials

#### `GET /credentials`

List configured credential keys (NEVER returns values).

**Response:**
```json
{
  "success": true,
  "data": [
    { "key": "drive_service_account", "configured": true, "last_updated": "..." },
    { "key": "printavo_api_token", "configured": true, "last_updated": "..." },
    { "key": "slack_webhook", "configured": false, "last_updated": null }
  ]
}
```

#### `PUT /credentials/{key}`

Set a credential. Encrypted at rest.

**Request body:**
```json
{
  "value": "<credential value>"
}
```

For Drive service account: paste the entire JSON service account key.
For Printavo: paste the API token string.
For Slack: paste the webhook URL.

**Response:** `{ "success": true, "data": { "key": "...", "configured": true } }`

Stored value is NEVER returned in responses. To rotate, PUT a new value.

#### `DELETE /credentials/{key}`

Removes a credential. Workflows using it will fail until reconfigured.

#### `POST /credentials/{key}/test`

Tests a credential by making a benign API call to the corresponding service.

**Response:**
```json
{
  "success": true,
  "data": {
    "key": "drive_service_account",
    "test_result": "ok",
    "details": { "service_account_email": "fmw-prod@project.iam.gserviceaccount.com" }
  }
}
```

If test fails: `success: false, data.test_result: "failed", data.error: "..."`.

---

## MCP tool surface

The plugin exposes MCP tools that mirror the REST API. Tool names use the `workflow_*` prefix (singular, matches resource name; parallels FormEngine's `formengine_*` pattern).

| Tool | REST equivalent | Description |
|---|---|---|
| `workflow_preflight` | GET /preflight | Health check |
| `workflow_list` | GET /workflows | List workflows |
| `workflow_get` | GET /workflows/{id} | Get one workflow |
| `workflow_create` | POST /workflows | Create workflow |
| `workflow_update` | PATCH /workflows/{id} | Update workflow |
| `workflow_delete` | DELETE /workflows/{id} | Delete workflow |
| `workflow_test` | POST /workflows/{id}/test | Validate / dry-run |
| `workflow_get_runs` | GET /runs | List runs |
| `workflow_get_run` | GET /runs/{id} | Get run detail |
| `workflow_replay_run` | POST /runs/{id}/replay | Replay run |
| `workflow_step_types_list` | GET /step-types | List step types |
| `workflow_step_types_get` | GET /step-types/{type} | Get one step type |
| `workflow_credentials_list` | GET /credentials | List credentials (no values) |
| `workflow_credentials_set` | PUT /credentials/{key} | Set credential |
| `workflow_credentials_test` | POST /credentials/{key}/test | Test credential |

Each tool's input/output schema is generated from the corresponding REST endpoint's request/response schema.

### Common patterns for AI usage

**Creating a workflow from natural language:**
1. Claude calls `workflow_step_types_list` to know what's available
2. Claude composes the workflow JSON
3. Claude calls `workflow_test` (with `config` parameter) to validate before saving
4. If valid, Claude calls `workflow_create` to persist

**Debugging a failed run:**
1. Claude calls `workflow_get_runs` filtered by status=failed
2. Picks the most recent
3. Calls `workflow_get_run` to see step-level detail
4. Diagnoses (e.g., a step's config_snapshot reveals the issue)
5. Either: edits the workflow definition (`workflow_update`) and replays, OR: calls `workflow_replay_run` with `with_modified_context` to fix the data

**Onboarding a new client:**
1. Breon describes the client's workflow in natural language to Claude
2. Claude reads `STEP_LIBRARY.md` to know the vocabulary
3. Claude generates the workflow JSON
4. Claude validates via `workflow_test`
5. Claude calls `workflow_create` (with `managed_by: connector:cowork` automatically set)
6. Done. New client workflow is live.

## Rate limiting

REST endpoints are rate-limited per authenticated user:
- `GET` operations: 60 requests/minute
- `POST/PATCH/PUT/DELETE`: 30 requests/minute
- `POST /workflows/{id}/test`: 10 requests/minute (more expensive)

Exceeded: returns 429 with `Retry-After` header. Same pattern as FormEngine's connector rate limiting.

## Error response format

All errors:
```json
{
  "success": false,
  "code": "invalid_workflow_id",
  "message": "Workflow ID must match ^[a-z0-9\\-_]+$",
  "data": {
    "field": "id",
    "received": "Bulk Order!"
  }
}
```

Common error codes:
- `invalid_json` — request body or config not valid JSON
- `invalid_workflow_id` — id format invalid
- `workflow_not_found` — id doesn't exist
- `form_not_found` — form_id doesn't exist in FormEngine
- `invalid_config` — workflow JSON doesn't match schema
- `invalid_step_type` — references unknown step type
- `invalid_step_config` — step config doesn't match step's schema
- `step_not_found` — step name not in workflow
- `run_not_found` — run id doesn't exist
- `cannot_replay` — run cannot be replayed (e.g., still queued)
- `credential_not_configured` — required credential missing
- `credential_invalid` — credential failed test
- `rate_limit_exceeded` — too many requests
- `permission_denied` — caller lacks required capability
- `dependency_missing` — FormEngine not active or wrong version

## OpenAPI spec

Full OpenAPI 3.0 spec lives at `/wp-json/flowmint/v1/connector/openapi.json` (Phase 5 deliverable). Until then, this doc is the spec.
