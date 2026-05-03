# FlowMint Workflows ↔ Form Runtime Engine — Integration Contract

This is the documented boundary between FlowMint Workflows and Form Runtime Engine. Both plugins ship and version independently; this contract defines what they're allowed to assume about each other.

## Direction

**One-way dependency:** FlowMint Workflows depends on Form Runtime Engine. FormEngine has zero knowledge of FlowMint Workflows.

If FormEngine is deactivated:
- FlowMint Workflows shows an admin notice and disables its workflow listener
- Existing run history is preserved
- Once FormEngine is reactivated, FlowMint Workflows resumes normally

If FlowMint Workflows is deactivated:
- FormEngine continues operating as if FMW never existed
- Form submissions still produce entries, send notifications, fire `fre_submission_complete`
- The action just has no listener

## What FlowMint Workflows reads from FormEngine

### Hooks (FormEngine fires, FlowMint Workflows listens)

| Hook | Signature | Purpose |
|---|---|---|
| `fre_submission_complete` | `($entry_id, $form_id, $sanitized_data)` | Primary trigger. FMW enqueues a workflow run if the form has a workflow registered. |
| `fre_entry_created` | `($entry_id, $form_id, $data)` | NOT used in v1. FMW prefers `fre_submission_complete` because it fires AFTER files are attached. |

FMW listens to `fre_submission_complete` because that hook fires AFTER:
1. Entry is stored
2. Files are uploaded and attached to the entry
3. Conditional fields are stripped (FMW gets the clean payload)
4. ...but BEFORE the FE notification email sends and BEFORE the FE webhook dispatches

This ordering means workflows have access to fully-attached file data via FRE_Entry but can complete asynchronously without delaying the user's form submission response.

### Classes (FormEngine exposes, FlowMint Workflows calls)

| Class / function | Used for |
|---|---|
| `FRE_Entry` | Loading entry data, file attachments by entry ID |
| `fre()->registry->get($form_id)` | Verifying a form_id exists when a workflow is created/updated |
| `fre()->registry->exists($form_id)` | Quick existence check |
| `FRE_Logger::info()` / `::warning()` / `::error()` | (Optional) unified logging across both plugins |

These are the ONLY FormEngine APIs FlowMint Workflows is allowed to call directly. Anything else is implementation detail that may change without notice in FormEngine releases.

### REST endpoints (FormEngine exposes, FlowMint Workflows does NOT call)

FlowMint Workflows does NOT call FormEngine's REST API (`/wp-json/fre/v1/connector/...`). All cross-plugin interaction happens via WordPress hooks and class-level PHP calls.

This is intentional: REST calls between plugins on the same WP install would be wasteful HTTP overhead.

## What FlowMint Workflows writes to FormEngine

### Via the `fre_delete_entry` step type

FlowMint Workflows includes a step type that calls FormEngine's existing entry deletion logic. This is the only state-modifying touch point.

Implementation: the step calls FormEngine's REST connector endpoint `DELETE /wp-json/fre/v1/connector/entries/{id}` from PHP via `wp_remote_request()` with the WP App Password configured in FMW credentials. NOT a direct database delete (which would skip FE's cascade cleanup of files).

Future v2: this could be optimized to a direct PHP call to FRE_Entry::delete() when both plugins are on the same install. For v1, the REST round-trip is fine.

### Via the `fre_update_entry_status` step type

Updates entry status (unread/read/spam) via FormEngine's REST endpoint.

## Hooks FlowMint Workflows offers (other plugins / themes can listen)

These are emitted by FMW for downstream consumers (theme code, other plugins, FlowMint's own integrations). FormEngine does NOT listen to any of these.

| Hook | Signature | When |
|---|---|---|
| `fmw_workflow_run_started` | `($run_id, $workflow_id, $entry_id)` | Run dequeued by Action Scheduler, about to execute |
| `fmw_workflow_run_completed` | `($run_id, $workflow_id, $entry_id, $context)` | Run finished successfully |
| `fmw_workflow_run_failed` | `($run_id, $workflow_id, $entry_id, $error_code, $error_message)` | Run failed, retries exhausted |
| `fmw_step_completed` | `($run_id, $step_name, $step_type, $output)` | Individual step succeeded |
| `fmw_step_failed` | `($run_id, $step_name, $step_type, $error_code, $error_message)` | Individual step failed |

Use cases:
- A custom integration listens to `fmw_workflow_run_completed` to update a CRM
- A monitoring tool listens to `fmw_workflow_run_failed` to alert ops
- Theme code listens to step events for analytics

## Filters FlowMint Workflows offers

| Filter | Signature | Purpose |
|---|---|---|
| `fmw_workflow_definition` | `($definition, $workflow_id)` | Modify a workflow's JSON definition before execution. Useful for environment-specific tweaks. |
| `fmw_step_config` | `($interpolated_config, $step, $context)` | Modify a step's interpolated config just before execution. |
| `fmw_step_output` | `($output, $step, $context)` | Modify a step's output just before it's written to context. |
| `fmw_credential` | `($value, $key)` | Intercept credential lookups. Useful for testing or environment-specific overrides via wp-config.php constants. |
| `fmw_should_run_workflow` | `($should_run, $workflow_id, $entry_id)` | Veto a workflow run before enqueuing. Return false to skip. |

## Version compatibility

FlowMint Workflows declares `Requires FormEngine >= 1.6.0` in its plugin header.

If FormEngine is below the minimum version:
- FMW shows an admin notice on every page load with the version mismatch
- The submission listener is not registered (no workflows fire)
- The admin UI shows a banner explaining the issue
- The plugin doesn't activate beyond the bare minimum (no DB tables created)

When FormEngine releases a new version, FlowMint Workflows is tested against it and the minimum version may be bumped. Bumping the minimum required FRE version is a MINOR version change for FMW (not major), since the contract direction is one-way.

## Data ownership

| Resource | Owner |
|---|---|
| Form definitions (DB row in `wp_fre_forms`) | FormEngine |
| Form entries (`wp_fre_entries`) | FormEngine |
| Uploaded files (`wp-content/uploads/fre-uploads/`) | FormEngine |
| Workflow definitions (`wp_fmw_workflows`) | FlowMint Workflows |
| Workflow runs (`wp_fmw_workflow_runs`) | FlowMint Workflows |
| Workflow run steps (`wp_fmw_workflow_run_steps`) | FlowMint Workflows |
| API credentials (`wp_options` `fmw_credential_*`) | FlowMint Workflows |

When FMW is uninstalled (`uninstall.php`), only FMW-owned tables are dropped. FRE data is left untouched.

When FRE is uninstalled, FMW's run history that references deleted entries becomes "orphaned" — the entry_id field still exists but the entry it references is gone. The run history table is preserved (don't lose audit trail). The admin UI gracefully handles missing entries by showing "Entry deleted" in the run detail view.

## Local development with both plugins

In Breon's Local Flywheel WordPress instance:

```
wp-content/plugins/
├── form-runtime-engine/      ← FRE source
├── flowmint-workflows/       ← FMW source (this plugin)
├── ai-section-builder-modern/  ← Promptless WP (optional, for full stack)
```

Both plugins active simultaneously. FMW's `fre_submission_complete` listener fires automatically. The local environment provides the full integration test surface without needing production deploys.

For the development cycle:
1. Make changes in FMW source
2. Test directly in Local (no rebuild needed for PHP changes)
3. When ready to ship, run `bin/build-release.sh` to produce a clean zip
4. Upload the zip to the production WP install via Plugins → Add New → Upload Plugin
5. Same workflow as FormEngine

## Coupling boundaries

What's tightly coupled (intentional):
- FMW listens to `fre_submission_complete` → must match FRE's signature exactly
- FMW reads via `FRE_Entry` → must match FRE's class API
- FMW calls FRE's REST endpoint for entry deletion → must match FRE's REST contract

What's loosely coupled (preserved):
- Workflow definitions don't reference FRE field types directly (workflows reference field KEYS as strings, not field type metadata)
- Step library doesn't import FRE classes except for `FRE_Entry` and `FRE_Logger`
- Admin UI is rendered separately (no shared admin styles or assets)
- DB tables are namespace-isolated (`fre_*` vs `fmw_*`)

What's NOT coupled (independent evolution):
- FRE's UI, field types, validation, sanitization
- FRE's REST API for forms (FMW only calls the entries DELETE endpoint)
- FRE's email notification system (FMW has its own email steps)
- FRE's webhook dispatch (FMW receives form data via the action, not the webhook)

## What happens when FormEngine has a bug that breaks FMW

The previous session's experience demonstrated this in practice. When FRE had:
- The idempotency-token leak bug (FMW didn't exist yet, but a future workflow listener would have been blocked by the same bug)
- The duplicate-detection-token leak bug (similarly)
- The single-file-payload edge case (a webhook quirk that affected the Zap; FMW reads via FRE_Entry directly, not the webhook, so this specific issue doesn't propagate)

The fix path is:
1. Diagnose the FRE bug
2. Fix in FRE source
3. Bump FRE version (or not, if hotfix)
4. Update FMW's `Requires FormEngine` if needed
5. Both plugins ship independently

FMW does NOT patch FRE. FMW does not fork FRE. FMW reads FRE's documented APIs and works around bugs only if FRE's maintainer (currently Breon) declines to fix them upstream.

## Future considerations

- **Bidirectional metadata:** future v2 might want FE to know which workflows are wired to which forms (so FE's admin UI can show "this form has a FMW workflow registered"). This would require a new optional API in FRE that FMW could populate. Out of scope for v1.
- **FE webhook deprecation:** if all FE-driven automations move to FMW, the existing `webhook_*` settings on FE forms become unnecessary. FE keeps them for backwards compat but FMW doesn't use them.
- **Shared logger:** v2 might unify FRE_Logger and FMW_Logger into a shared library. For v1 they're separate.
- **Shared connector auth:** v2 might unify the connector REST auth (currently both plugins implement their own App Password auth with capability checks). For v1 they're separate.

These would all be additive — no breaking changes — when implemented.
