# Migration from Zapier

A playbook for moving an existing FormEngine + Zapier workflow to FlowMint Workflows. Designed for zero-downtime cutover with side-by-side verification.

## When to migrate

You have an existing Zapier-based workflow (FE form → webhook → Zap → Drive/Printavo/Email/etc.) and you want to:

- Eliminate the recurring Zapier subscription
- Make future workflow changes faster (no Chrome UI clicking)
- Bring orchestration in-house under FlowMint ownership
- Add capabilities that Zapier doesn't provide easily (custom logic, internal admin UI, etc.)

You should NOT migrate if:

- The Zap is genuinely simple and Zapier's free tier covers it
- The client owns and self-services the Zap (FlowMint isn't the operator)
- The Zap relies on a Zapier app that we don't have (or can't easily build) a step type for

## Migration approach: parallel run, then cutover

The safest path. Both systems process every form submission for a verification period. After confirmation, the Zap is decommissioned.

```
Phase 1 — Pre-migration:
  FE form submits → Zapier webhook → Zap runs → Drive + Printavo + Email + DELETE

Phase 2 — Parallel run (both systems active):
  FE form submits → Zapier webhook → Zap runs → Drive + Printavo + Email + DELETE
                  → FE action fires → FlowMint Workflows runs → [PARALLEL-mode] outputs

Phase 3 — Cutover (Zap disabled):
  FE form submits → FE action fires → FlowMint Workflows runs → Drive + Printavo + Email + DELETE

Phase 4 — Decommission (after observation period):
  Zap turned off in Zapier admin
  Zapier subscription cancelled
```

## Step-by-step migration playbook

### Pre-migration: prerequisites

- FlowMint Workflows plugin installed and active on the client's WP install
- All required credentials configured (Drive service account, Printavo API token, Slack webhook for failure notifications)
- Test connections all pass via `workflow_credentials_test`
- Existing Zap is documented: list of every step, every field mapping, every config value (this becomes the workflow JSON)

### Step 1: Document the current Zap exhaustively

For each Zap step, capture:
- Step name and app
- All config fields and their current values
- Any chip/variable references and what they point to
- Test sample data showing the current behavior

This documentation goes into the workflow JSON. Without it, the migrated workflow has no chance of being equivalent.

For 725 Print Lab specifically: this is captured in the `725 Print Lab/_Zapier-Resume-Context/` folder across multiple primer documents (v1-v12).

### Step 2: Translate Zap config to workflow JSON

Use the step library reference (`STEP_LIBRARY.md`) to map each Zap step to a FlowMint step:

| Zap step | FlowMint step type |
|---|---|
| Webhooks by Zapier — Catch Hook | (no equivalent — FlowMint listens to fre_submission_complete directly, no webhook) |
| Printavo — Find Customer | `printavo_find_customer` or `printavo_find_or_create_customer` |
| Code by Zapier — Run Javascript | (no direct equivalent — split logic into `set_variable` + `conditional` steps) |
| Google Drive — Find a Folder | `drive_find_folder` |
| Google Drive — Create Folder | `drive_create_folder` or `drive_find_or_create_folder` |
| Filter by Zapier | `conditional` step OR `skip_if` on the next step |
| Google Drive — Upload File | `drive_upload_file` |
| Printavo — Create Quote/Invoice | `printavo_create_quote` |
| Gmail — Send Email | `send_email` or `send_email_template` |
| Webhooks DELETE (FE entry cleanup) | `fre_delete_entry` (much simpler — no manual URL/auth needed) |

Most Zap step types have direct equivalents. Code by Zapier (the JS step) is the trickiest — its logic must be ported to FlowMint's `set_variable` and `conditional` steps. For very complex JS, consider splitting into multiple steps.

### Step 3: Configure the workflow in PARALLEL mode

Parallel mode = the workflow runs at the same time as the Zap, but writes its outputs to SEPARATE locations to avoid conflicts.

Specifically:
- Drive folder names get a `[FMW-PARALLEL]` prefix
- Printavo Quote nicknames get a `[FMW]` prefix
- Email recipient is a test address (FlowMint inbox), not the real customer
- Customer ack subject gets a `[PARALLEL TEST]` prefix

Edit the workflow JSON's templates and config values to add these prefixes. After cutover (Step 6), revert.

Example for 725 Bulk:

```json
{
  "name": "submission_folder",
  "type": "drive_create_folder",
  "config": {
    "parent_id": "{{ steps.month_folder.id }}",
    "name": "[FMW-PARALLEL] {{ data.company || data.full_name }}"
  }
}
```

After cutover, change to:
```json
{
  "name": "submission_folder",
  "type": "drive_create_folder",
  "config": {
    "parent_id": "{{ steps.month_folder.id }}",
    "name": "{{ data.company || data.full_name }}"
  }
}
```

### Step 4: Save the workflow and activate

```
POST /wp-json/flowmint/v1/connector/workflows
{
  "id": "<client>-<form-name>",
  "title": "...",
  "form_id": "<fe-form-id>",
  "enabled": true,
  "config": "<JSON string>"
}
```

The workflow is now LIVE. The next form submission will trigger BOTH the existing Zap (still listening to FE webhook) AND the new FlowMint workflow (listening to fre_submission_complete action).

### Step 5: Submit test forms in parallel

Submit at least 5 real test forms. For each:

- Verify Zapier completes (run history shows success)
- Verify FlowMint Workflows completes (admin run history shows completed)
- Compare outputs side-by-side:
  - Drive: original folder + parallel folder both exist with same files
  - Printavo: original Quote + parallel Quote both created with same fields
  - Email: original customer-facing email + parallel test email both received with same content
  - FE entry: deleted by both (in practice, whichever finishes first deletes the entry; the other gets a "deleted: false, already_gone: true" idempotent response)

Differences to investigate:
- Field values not matching (likely a chip/variable interpolation difference)
- Missing fields in description (template needs updating)
- Different folder structure (parent_id mismatch)
- Different Quote status (invoice_status_id mismatch)

Iterate on the workflow JSON until parallel outputs are EQUIVALENT to the Zap outputs.

### Step 6: Cutover

Once you're confident the FlowMint workflow produces equivalent (or better) outputs:

1. **Switch the workflow from parallel to production mode.** Update the workflow JSON to remove parallel-mode prefixes.
2. **Disable the Zapier webhook on the FE form.**
   ```
   formengine_update_form(
     form_id: "<form-id>",
     webhook_enabled: false
   )
   ```
   FE no longer sends to Zapier. The Zap is now idle.
3. **Verify the next real submission**: only the FlowMint workflow runs; Zap shows no new runs.
4. **Watch for 1-3 days.** Real submissions should produce normal outputs. Run history in WP admin shows green checkmarks.

### Step 7: Decommission

After a successful observation period (no failures, equivalent outputs to historical Zap runs):

1. **Turn off the Zap in Zapier.** Don't delete it yet — keep for reference.
2. **Cancel any unused Zapier subscription tier.**
3. **Clean up parallel-mode test artifacts** (the duplicate Drive folders, duplicate Quotes from Step 5).
4. **Update the client's documentation** to reflect that workflows are now in WordPress, not Zapier.

After 30 days of clean operation, you can safely delete the Zap from Zapier entirely.

## Common gotchas during migration

### Gotcha #1: Webhook payload format differs

FormEngine's webhook payload (sent to Zapier) and the data passed via `fre_submission_complete` action (read by FlowMint) are formatted differently:

- Zapier sees a flat JSON with file URLs as strings
- FlowMint reads via `FRE_Entry`, which gives full file objects with paths, URLs, MIME types

Some Zap step configs reference Zapier-flavored chip names (e.g., `1.files.0.file_url`). FlowMint references the FE entry directly (e.g., `{{ steps.fre_get_file.file_url }}` after a `fre_get_file` step). Your workflow JSON has to be rewritten to use FlowMint's variable syntax — direct copy-paste of Zap config doesn't work.

### Gotcha #2: Code by Zapier doesn't translate directly

If the Zap has custom JavaScript (Code by Zapier step), translate the logic to FlowMint primitives:

- Date formatting → `{{ now('Y-m') }}` syntax
- String concatenation → variable interpolation
- Conditional logic → `conditional` step
- Loops → `for_each` (deferred to v2)
- Custom calculations → multiple `set_variable` steps

Complex JS may not map cleanly. If the JS is doing something genuinely sophisticated (e.g., parsing a complex API response), consider:
- Adding a new step type to the plugin to encapsulate the logic
- Using `http_request` to call a small custom endpoint that does the work

### Gotcha #3: Filter by Zapier semantics

Zap's "Filter by Zapier" step halts the WHOLE Zap if the condition is false. FlowMint's `conditional` step only skips its OWN sub-steps.

To replicate Zap's halt-the-whole-workflow behavior:

```json
{
  "name": "halt_if_no_file",
  "type": "conditional",
  "config": {
    "if": "{{ !has_file(entry, 'design_file') }}",
    "then": [
      { "name": "log_no_file", "type": "log_warning", "config": {"message": "Halting: no design file"} }
    ]
  }
}
```

Then use `skip_if` on every subsequent step:
```json
{
  "name": "upload",
  "type": "drive_upload_file",
  "config": { ... },
  "skip_if": "{{ !has_file(entry, 'design_file') }}"
}
```

(Or — better — restructure the workflow so the file-dependent steps are inside a `conditional`'s `then` block.)

### Gotcha #4: Zapier handles file uploads asynchronously, FlowMint synchronously within the workflow

Zapier's "Upload File" step downloads from the file URL and uploads to Drive in two separate HTTP calls. FlowMint's `drive_upload_file` reads the file from local disk (via FRE_Entry) and uploads to Drive in one operation. Faster, simpler, and avoids the file-URL-must-be-public requirement.

This means: FlowMint workflows don't need files to be publicly accessible. FE files can stay private. Better security posture.

### Gotcha #5: Idempotency differences

Zapier doesn't have built-in idempotency for state-changing steps. If a Zap step is retried, it can create duplicate Drive folders / Printavo Quotes / etc.

FlowMint has explicit idempotency for state-changing steps (see ARCHITECTURE.md). On retry, the same `run_id` is sent to external services to detect duplicates.

For migration: existing Zap runs may have created Quote duplicates if they retried. FlowMint won't.

### Gotcha #6: Email sender (the Gmail step)

For 725 Print Lab specifically, the Gmail step in Zapier requires OAuth — Zapier connects to a Gmail account and sends as that account. The setup work was painful and is documented in the v1-v12 primers.

FlowMint's `send_email` step uses `wp_mail()` by default — sends from the WordPress site's configured email. For deliverability, configure SMTP (Mailgun, SendGrid, Postmark, or even a simple app-password Gmail setup) at the WordPress level. The plugin doesn't do OAuth.

If the client wants emails sent from a specific Gmail address (e.g., `orders@725printlab.com`), set up SMTP via a plugin like WP Mail SMTP and configure the From address. FlowMint's `send_email` step picks it up automatically.

This is actually SIMPLER than the Zap setup — no OAuth flows, no per-account auth.

## Migration cost estimate

For a typical service-business workflow (one FE form → one Zap → 5-10 steps → Drive + Printavo/CRM + Email + cleanup):

- Documenting the existing Zap: 1-2 hours
- Writing the FlowMint workflow JSON: 1-2 hours
- Parallel run + verification: 1-2 days of submissions, plus ~1 hour of comparison work per day
- Cutover + monitoring: 1 hour cutover + 1 week passive monitoring

Total: roughly **4-8 hours of active work** plus 1-2 weeks of calendar time for the parallel run + monitoring.

For 725 Print Lab specifically (Bulk + Small workflows): roughly 6 hours of active work for both workflows in parallel, plus the parallel-run verification window.

## Worked example: 725 Print Lab

The 725-specific migration is documented in the client folder at:

```
725 Print Lab/_FlowMint-Workflows-Migration/
├── README.md                           # Migration plan + status
├── workflow-bulk-order-quote.json      # The actual workflow definition for Bulk
├── workflow-small-order-request.json   # The actual workflow definition for Small
├── parallel-run-checklist.md           # Verification checklist for parallel mode
└── cutover-runbook.md                  # Step-by-step cutover with rollback plan
```

These files don't exist yet — they're created during Phase 4 of the build.

The 725 migration leverages 24+ hours of accumulated Zap configuration knowledge (captured in the v1-v12 primers) — translating that into FlowMint Workflows JSON should take a few hours, not 24+, because the docs already describe everything.
