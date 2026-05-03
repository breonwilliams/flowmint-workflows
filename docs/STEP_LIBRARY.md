# FlowMint Workflows — Step Library

The vocabulary of the workflow language. Every step type that ships in v1.0, with config schema, output schema, error modes, and examples.

## Step type contract

Every step is a PHP class extending `FMW_Step_Base`:

```php
abstract class FMW_Step_Base {
    /**
     * Unique step type identifier (snake_case). e.g., "drive_create_folder".
     */
    abstract public static function type(): string;

    /**
     * Human-readable display name for admin UI.
     */
    abstract public static function display_name(): string;

    /**
     * One-paragraph description of what this step does.
     */
    abstract public static function description(): string;

    /**
     * JSON Schema describing the step's `config` object.
     * Used for validation on workflow create/update.
     */
    abstract public static function config_schema(): array;

    /**
     * JSON Schema describing the step's output.
     * Used for documentation and downstream chip suggestions.
     */
    abstract public static function output_schema(): array;

    /**
     * Whether this step makes external state changes (creates/modifies external resources).
     * Steps with side_effects=true MUST be idempotent (see ARCHITECTURE.md).
     */
    abstract public static function has_side_effects(): bool;

    /**
     * Execute the step. Receives the run context, returns the step's output.
     * Throws FMW_Step_Exception on failure.
     */
    abstract public function execute(FMW_Workflow_Context $context): array;
}
```

All steps register themselves via `FMW_Step_Registry::register(self::class)` during plugin init.

## Step categories

| Category | Step Types | Phase |
|---|---|---|
| Control flow | set_variable, conditional, try_catch, delay | 1 |
| Logging | log_info, log_warning, log_error | 1 |
| FormEngine integration | fre_get_entry, fre_get_file, fre_update_entry_status, fre_delete_entry | 1 |
| Google Drive | drive_find_folder, drive_find_or_create_folder, drive_create_folder, drive_upload_file, drive_share_link | 2 |
| Email | send_email, send_email_template | 2 |
| Printavo | printavo_find_customer, printavo_create_customer, printavo_find_or_create_customer, printavo_create_quote | 3 |
| HTTP | http_get, http_post, http_request | 3 |

24 step types in v1.0.

---

## Control flow

### `set_variable`

Sets a value into the run context for downstream steps to reference. Useful for computed values, defaults, or aliasing.

**Side effects:** No.

**Config:**
```json
{
  "name": "computed_folder_name",
  "value": "{{ data.company || data.full_name || 'Unknown' }}"
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `name` | string | yes | Variable name. Accessible as `{{ vars.<name> }}` in subsequent steps. |
| `value` | any | yes | Value to store. Variables in the value are interpolated before storage. |

**Output:** `{ "value": <stored value> }`

**Example:**
```json
{
  "name": "folder_name",
  "type": "set_variable",
  "config": {
    "name": "folder_name",
    "value": "{{ now('Y-m-d') }}_{{ data.company || data.full_name }}"
  }
}
```

---

### `conditional`

Runs one of two branches based on an expression. Either branch is a list of nested steps.

**Side effects:** Depends on inner steps.

**Config:**
```json
{
  "if": "{{ has_file(entry, 'design_file') }}",
  "then": [
    { "name": "upload", "type": "drive_upload_file", "config": {...} }
  ],
  "else": [
    { "name": "log_no_file", "type": "log_info", "config": {"message": "No design file"} }
  ]
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `if` | string | yes | Expression that evaluates to truthy/falsy. See "Expression syntax" below. |
| `then` | array | yes | Steps to execute if `if` is truthy. Can be empty. |
| `else` | array | no | Steps to execute if `if` is falsy. Defaults to empty (no-op). |

**Output:** `{ "branch": "then" | "else", "steps_executed": <count> }`

**Expression syntax:**

Expressions are parsed by `FMW_Expression`. NOT eval. NOT arbitrary PHP. The grammar:

```
expr := comparison ( ('&&' | '||') comparison )*
comparison := value ( ('==' | '!=' | '>' | '<' | '>=' | '<=') value )?
value := variable | literal | '!' value | '(' expr ')' | function_call
literal := string | number | true | false | null
variable := '{{ ... }}' (interpolated to a value)
function_call := identifier '(' arg ( ',' arg )* ')'
arg := expr | literal
```

Available functions:
- `has_file(entry, '<field_key>')` — entry has a file attached for the given field
- `is_empty(<value>)` — value is null, empty string, empty array, or '0'
- `length(<value>)` — string length or array count
- `contains(<haystack>, <needle>)` — string or array contains
- `equals_ci(<a>, <b>)` — case-insensitive string equality

---

### `try_catch`

Runs a list of steps; on failure runs an alternative list. Useful for graceful degradation.

**Side effects:** Depends on inner steps.

**Config:**
```json
{
  "try": [
    { "name": "primary", "type": "http_post", "config": {...} }
  ],
  "catch": [
    { "name": "fallback", "type": "log_warning", "config": {"message": "Primary failed"} }
  ],
  "catch_codes": ["external_4xx", "external_5xx"]
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `try` | array | yes | Steps to attempt. |
| `catch` | array | yes | Steps to run on failure. |
| `catch_codes` | array | no | Specific error codes to catch. Defaults to all. |

**Output:** `{ "branch": "try" | "catch", "error_code": <code if catch> }`

---

### `delay`

Pauses execution for a duration. Use sparingly — Action Scheduler reschedules the workflow rather than blocking the worker, so this consumes a queue slot but not CPU time.

**Side effects:** No (just time).

**Config:**
```json
{
  "seconds": 30
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `seconds` | integer | yes | 1-3600. Anything longer is rejected on validation. |

**Output:** `{ "delayed_seconds": <seconds> }`

---

## Logging

### `log_info` / `log_warning` / `log_error`

Three step types with identical config, different log levels.

**Side effects:** No (logs only).

**Config:**
```json
{
  "message": "Customer ID {{ steps.customer.id }} created",
  "context": {
    "customer_id": "{{ steps.customer.id }}",
    "email": "{{ data.email }}"
  }
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `message` | string | yes | Log message. Variables interpolated. |
| `context` | object | no | Structured context fields. Each value is interpolated. |

**Output:** `{ "logged": true }`

`log_error` ALSO sends a notification to FlowMint via the configured notification channel(s).

---

## FormEngine integration

### `fre_get_entry`

Loads the full FormEngine entry into the context. Usually unnecessary because the entry is auto-loaded into `entry` at workflow start, but useful for explicit refresh after modifications.

**Side effects:** No.

**Config:** `{ "entry_id": "<int or {{var}}>" }` (defaults to current run's entry)

**Output:** Full entry record (id, status, fields, files, metadata).

---

### `fre_get_file`

Resolves a file field to its full file metadata. Used when downstream steps need both the URL and the local path.

**Side effects:** No.

**Config:**
```json
{
  "field_key": "design_file"
}
```

**Output:**
```json
{
  "field_key": "design_file",
  "file_name": "logo.jpg",
  "file_path": "/var/www/wp-content/uploads/2026/05/...jpg",
  "file_url": "https://example.com/wp-content/uploads/2026/05/...jpg",
  "file_size": 8436,
  "mime_type": "image/jpeg",
  "exists": true
}
```

If the field has no file: `{ "field_key": "design_file", "exists": false }`. Downstream steps can check `{{ steps.<name>.exists }}` before using.

---

### `fre_update_entry_status`

Updates the FE entry's status (e.g., mark as read, mark as processed).

**Side effects:** Yes (modifies FE entry record).

**Config:**
```json
{
  "status": "read"
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `status` | string | yes | One of `unread`, `read`, `spam` |

**Output:** `{ "previous_status": "<old>", "new_status": "<new>" }`

---

### `fre_delete_entry`

Cascade-deletes the FE entry and its files (mirrors what the v1.0 Zapier workflow's "DELETE" step does).

**Side effects:** Yes (destructive).

**Config:** `{}` (no parameters; deletes the current run's entry)

**Output:** `{ "deleted": true, "entry_id": <id>, "files_deleted": <count> }`

Idempotency: if the entry was already deleted (e.g., by a previous attempt of this same run), returns `{ "deleted": false, "already_gone": true }` instead of erroring.

---

## Google Drive (Phase 2)

All Drive steps use the global FMW Drive credential. See `SETUP_GOOGLE_DRIVE.md` for service account setup.

### `drive_find_folder`

Looks up a folder by name within a parent. Returns metadata or empty if not found.

**Side effects:** No (read-only).

**Config:**
```json
{
  "parent_id": "1aVp_Zhd0OyL5K_h9dNYQ_f6lf_VOC8K8",
  "name": "2026-05",
  "exact_match": true
}
```

**Output:**
```json
{
  "found": true,
  "id": "1Q-7G6aXbeqHFSlxwhEN8pnDdmxsSxt3F",
  "name": "2026-05",
  "web_view_link": "https://drive.google.com/drive/folders/1Q-7..."
}
```

If not found: `{ "found": false }`.

---

### `drive_find_or_create_folder`

Returns an existing folder if it exists, otherwise creates one.

**Side effects:** Yes (may create).

**Config:**
```json
{
  "parent_id": "1aVp_Zhd0OyL5K_h9dNYQ_f6lf_VOC8K8",
  "name": "{{ now('Y-m') }}"
}
```

**Output:** Same as `drive_find_folder`, plus `"was_created": true | false`.

Idempotency: if called twice with same parent + name, second call returns the existing folder.

---

### `drive_create_folder`

Always creates a new folder. Errors if a folder with the same name already exists in the parent (unless `allow_duplicate` is true).

**Side effects:** Yes (creates).

**Config:**
```json
{
  "parent_id": "{{ steps.month_folder.id }}",
  "name": "{{ data.company || data.full_name }}",
  "allow_duplicate": false
}
```

**Output:** `{ "id": "...", "name": "...", "web_view_link": "..." }`

Idempotency: includes the run_id as a hidden property on the created folder so retries return the existing folder.

---

### `drive_upload_file`

Uploads a FE entry file to a Drive folder.

**Side effects:** Yes.

**Config:**
```json
{
  "parent_id": "{{ steps.submission_folder.id }}",
  "file_field": "design_file",
  "rename_to": null
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `parent_id` | string | yes | Drive folder ID to upload into. |
| `file_field` | string | yes | FE field key (must be type=file). |
| `rename_to` | string | no | Override filename. Defaults to original. Variables interpolated. |

**Output:**
```json
{
  "id": "1xyz...",
  "name": "logo.jpg",
  "web_view_link": "https://drive.google.com/file/d/1xyz/view",
  "size": 8436,
  "mime_type": "image/jpeg"
}
```

Behavior:
- Uses chunked upload for files >5MB
- Verifies upload integrity via MD5 hash comparison
- After successful upload, the local file in `wp-content/uploads/fre-uploads/` IS NOT deleted by this step — that's the responsibility of `fre_delete_entry` later in the workflow OR an explicit cleanup step

If the file field has no file (`{{ steps.fre_get_file.exists == false }}`), the step skips with output `{ "skipped": true, "reason": "no_file" }`. Use `skip_if` if you want stricter handling.

---

### `drive_share_link`

Sets sharing permissions on a Drive resource.

**Side effects:** Yes (modifies permissions).

**Config:**
```json
{
  "resource_id": "{{ steps.submission_folder.id }}",
  "permission_type": "anyone_with_link",
  "role": "reader"
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `resource_id` | string | yes | Drive file/folder ID. |
| `permission_type` | string | yes | `anyone_with_link`, `domain`, `user` |
| `role` | string | yes | `reader`, `commenter`, `writer` |
| `email` | string | conditional | Required if permission_type=`user` |
| `domain` | string | conditional | Required if permission_type=`domain` |

**Output:** `{ "permission_id": "...", "shareable_url": "https://drive.google.com/..." }`

---

## Email (Phase 2)

### `send_email`

Sends a plain-text or HTML email via wp_mail.

**Side effects:** Yes (sends email).

**Config:**
```json
{
  "to": "{{ data.email }}",
  "from_name": "Orders Team",
  "from_email": "orders@example.com",
  "subject": "Thank you, {{ data.full_name }}",
  "body": "Hi {{ data.full_name }},\n\nThank you...",
  "is_html": false,
  "reply_to": "support@example.com"
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `to` | string\|array | yes | Recipient(s) |
| `from_name` | string | no | Defaults to site name |
| `from_email` | string | no | Defaults to admin email |
| `subject` | string | yes | |
| `body` | string | yes | |
| `is_html` | bool | no | Defaults to false |
| `reply_to` | string | no | |
| `cc`, `bcc` | string\|array | no | |

**Output:** `{ "sent": true, "recipients": ["..."] }`

Idempotency: deduplicates via SHA256(run_id + recipient + subject) for 1 hour.

---

### `send_email_template`

Like `send_email` but loads body from a template file. Templates live in `wp-content/uploads/fmw-templates/<name>.html` (or `.txt`) and support variable interpolation.

**Config:**
```json
{
  "to": "{{ data.email }}",
  "from_name": "Orders Team",
  "from_email": "orders@example.com",
  "subject": "Thank you for your quote request",
  "template": "725-customer-ack",
  "is_html": false
}
```

Template file `725-customer-ack.txt`:
```
Hi {{ data.full_name }},

Thank you for your custom quote request. A member from our Sales Team will get back to you soon.

If you need anything in the meantime, please reach out at customerservice@example.com.

Thank you,
The {{ env.site_name }} Team
```

**Output:** Same as `send_email`.

---

## Printavo (Phase 3)

All Printavo steps use the global FMW Printavo credential. See `SETUP_PRINTAVO.md` for API token setup.

### `printavo_find_customer`

Looks up a customer by email. Returns metadata or empty.

**Side effects:** No (read-only).

**Config:**
```json
{
  "email": "{{ data.email }}"
}
```

**Output:**
```json
{
  "found": true,
  "id": "10706641",
  "first_name": "Test",
  "last_name": "User",
  "email": "test@example.com",
  "company": "Acme Corp"
}
```

If not found: `{ "found": false }`.

---

### `printavo_create_customer`

Creates a new customer. Errors if a customer with the same email already exists.

**Side effects:** Yes.

**Config:**
```json
{
  "email": "{{ data.email }}",
  "first_name": "{{ data.first_name }}",
  "last_name": "{{ data.last_name }}",
  "phone": "{{ data.phone }}",
  "company_name": "{{ data.company }}"
}
```

**Output:** `{ "id": "...", ... }`

---

### `printavo_find_or_create_customer`

Returns existing customer if found by email, otherwise creates one.

**Side effects:** Yes (may create).

**Config:**
```json
{
  "email": "{{ data.email }}",
  "name": "{{ data.full_name }}",
  "phone": "{{ data.phone }}",
  "company_name": "{{ data.company }}"
}
```

**Output:** `{ "id": "...", "was_created": true|false, ... }`

Note: `name` is split into `first_name`/`last_name` automatically (first whitespace-separated token = first_name, rest = last_name). For finer control, use the explicit `printavo_create_customer` step instead.

---

### `printavo_create_quote`

Creates a Printavo Quote. The heaviest step — most config fields.

**Side effects:** Yes.

**Config:**
```json
{
  "customer_id": "{{ steps.customer.id }}",
  "user_id": 60522,
  "invoice_status_id": 416419,
  "nickname": "{{ steps.submission_folder.name }}",
  "description": "{{ template('725-bulk-quote-description') }}",
  "customer_due_date": "{{ data.target_delivery_date }}",
  "lineitems": [],
  "tags": ["website-submission"]
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `customer_id` | string | yes | Printavo customer ID |
| `user_id` | int | yes | Printavo user (sales rep) ID |
| `invoice_status_id` | int | yes | Printavo invoice status ID (e.g., "Quote - Inquiry") |
| `nickname` | string | yes | Quote nickname (visible in Printavo UI) |
| `description` | string | no | Long-form description |
| `customer_due_date` | string | no | YYYY-MM-DD |
| `lineitems` | array | no | Array of lineitem objects (size, quantity, color, etc.). Empty for inquiry quotes. |
| `tags` | array | no | Tags to apply |

**Output:**
```json
{
  "id": "22877961",
  "visual_id": "INQ-12345",
  "url": "https://printavo.com/invoices/...",
  "created_at": "2026-05-03T12:00:00Z"
}
```

Idempotency: includes `run_id` as a custom field. On retry, queries for an existing Quote with this `run_id` before creating.

---

## HTTP (Phase 3)

For ad-hoc API integrations not covered by dedicated connectors.

### `http_get` / `http_post`

Convenience wrappers for the most common cases.

**Side effects:** `http_get`: no. `http_post`: yes (assumed; many POST endpoints are state-changing).

**Config (http_get):**
```json
{
  "url": "https://api.example.com/v1/widgets/{{ data.widget_id }}",
  "headers": {
    "Authorization": "Bearer {{ env.example_api_token }}"
  },
  "timeout_seconds": 30
}
```

**Config (http_post):**
```json
{
  "url": "https://api.example.com/v1/widgets",
  "headers": {
    "Authorization": "Bearer {{ env.example_api_token }}",
    "Content-Type": "application/json"
  },
  "body": {
    "name": "{{ data.widget_name }}",
    "color": "{{ data.color }}"
  },
  "timeout_seconds": 30
}
```

**Output:**
```json
{
  "status": 200,
  "headers": { ... },
  "body": { ... },
  "duration_ms": 234
}
```

For non-2xx responses, throws `FMW_Step_Exception` with `code = 'external_4xx'` or `'external_5xx'` unless `accept_non_2xx` is true in config.

---

### `http_request`

Full control over HTTP method, headers, body. Use for PUT/PATCH/DELETE or unusual auth schemes.

**Config:**
```json
{
  "url": "https://api.example.com/v1/widgets/123",
  "method": "PATCH",
  "headers": { ... },
  "body": { ... },
  "body_format": "json",
  "timeout_seconds": 30,
  "accept_non_2xx": false,
  "follow_redirects": true,
  "verify_ssl": true
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `url` | string | yes | |
| `method` | string | yes | GET, POST, PUT, PATCH, DELETE, HEAD |
| `body_format` | string | no | `json` (default), `form`, `raw` |
| `accept_non_2xx` | bool | no | If true, returns the response instead of throwing |

**Output:** Same as `http_get`/`http_post`.

---

## Adding new step types

The contract for adding a new step type to the plugin codebase:

1. Create a new PHP class in `includes/Steps/<Category>/class-step-<name>.php`
2. Extend `FMW_Step_Base`, implement all abstract methods
3. Register via `FMW_Step_Registry::register(My_New_Step::class)` in plugin init
4. Document in this file (STEP_LIBRARY.md) following the same template (config schema, output schema, examples, idempotency notes)
5. Write at least one unit test in `tests/Unit/Steps/<Category>/<Name>Test.php`
6. If the step has external service dependencies, add a setup guide in `docs/SETUP_<SERVICE>.md`
7. Update `CHANGELOG.md`

The plugin's value scales linearly with the step library's coverage. Adding a step is the most common reason to touch the plugin's code.

## Step naming conventions

- Step type names are `snake_case`
- Format: `<service_or_category>_<verb_noun>`
- Examples: `drive_create_folder`, `printavo_find_customer`, `send_email_template`, `http_post`, `set_variable`, `fre_delete_entry`
- "Find or create" steps use the `_find_or_create_` middle segment
- Generic verbs: `find`, `create`, `update`, `delete`, `send`, `get`, `set`, `log`
- Avoid abbreviations except for well-known acronyms (HTTP, URL, ID)
