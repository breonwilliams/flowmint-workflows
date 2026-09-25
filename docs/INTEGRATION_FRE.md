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
- Form submissions still produce entries, send notifications, fire `pforms_submission_complete`
- The action just has no listener

## What FlowMint Workflows reads from FormEngine

### Hooks (FormEngine fires, FlowMint Workflows listens)

| Hook | Signature | Purpose |
|---|---|---|
| `pforms_submission_complete` | `($entry_id, $form_id, $sanitized_data)` | Primary trigger. FMW enqueues a workflow run if the form has a workflow registered. |
| `pforms_entry_created` | `($entry_id, $form_id, $data)` | NOT used. FMW prefers `pforms_submission_complete` because it fires AFTER files are attached. |

FormEngine 1.8.0 renamed its PHP surface from `fre_*` to `pforms_*` with no aliases: a listener on the old `fre_submission_complete` name never fires. The `fre_*` **step type** names in FlowMint (`fre_get_entry`, `fre_delete_entry`…) are FlowMint's own vocabulary, stored in client workflow JSON, and are deliberately unchanged.

FMW listens to `pforms_submission_complete` because that hook fires AFTER:
1. Entry is stored
2. Files are uploaded and attached to the entry
3. Conditional fields are stripped (FMW gets the clean payload)
4. ...but BEFORE the FE notification email sends (FE's webhook dispatcher listens on the same hook)

It does NOT fire for a submission FormEngine 1.11.0+ keeps as spam (a filled honeypot): that entry is stored with `is_spam=1` and nothing downstream runs. Marking it **Not Spam** later does not start a workflow either.

This ordering means workflows have access to fully-attached file data via PForms_Entry but can complete asynchronously without delaying the user's form submission response.

### Classes (FormEngine exposes, FlowMint Workflows calls)

| Class / function | Used for |
|---|---|
| `PForms_Entry` | Loading entry data and file attachments by entry ID; `update_status()` and `delete()` for the entry steps |
| `PForms_Entry_Query` | Listing entries for `fre_list_entries` |
| `pforms()->registry->get($form_id)` | Verifying a form_id exists when a workflow is created/updated |
| `pforms()->registry->exists($form_id)` | Quick existence check |

These are the ONLY FormEngine APIs FlowMint Workflows is allowed to call directly. Anything else is implementation detail that may change without notice in FormEngine releases.

### REST endpoints (FormEngine exposes, FlowMint Workflows does NOT call)

FlowMint Workflows does NOT call FormEngine's REST API (`/wp-json/fre/v1/connector/...`). All cross-plugin interaction happens via WordPress hooks and class-level PHP calls.

This is intentional: REST calls between plugins on the same WP install would be wasteful HTTP overhead.

### Filters (FormEngine offers, FlowMint Workflows answers)

| Filter | Since | What we do with it |
|---|---|---|
| `pforms_entry_notification_status` | FRE 1.12.0 | Tell the Entries screen's **Email** column what our run did with the team email. |

FormEngine's Email column reports FormEngine's own notification and nothing
else. The sensible setup with FlowMint is to switch that notification off so
the team gets one email instead of two — and then the column has nothing of
its own to say. Until FRE 1.12.0 it showed a bare dash, which reads as a
delivery failure; that cost a live site an afternoon (2026-09-22), with the
owner concluding no submission was reaching anyone while the workflow had in
fact emailed all three recipients.

`FMW_Entry_Notification_Status` answers the filter **only when FormEngine has
nothing of its own to report** — its status is `off` or `not_sent`. If
FormEngine sent the notification itself, or tried and failed, that record
stands and we stay quiet: it is the more direct fact, and whatever our run did
is in Run History. 0.12.0 claimed the column whenever a run had emailed
anyone, which on a form whose own notification is ON replaced a true "sent"
tick with a link to a run whose only email was the customer's auto-reply
(seen live, fixed in 0.12.1).

When FormEngine is silent, we answer with the newest run for that entry: **Sent by workflow** when a `send_email` / `send_email_template`
step succeeded, **Workflow failed** when the run failed and it was going to
email someone, **Workflow running** while it is still going. A run that sends
no email at all leaves the column alone, and so does a completed run whose
email step failed. Every answer links to the run.

FormEngine renders it attributed to FlowMint and never as its own "Sent"
tick — it is our claim, not its record. The direction of the dependency is
unchanged: FormEngine knows nothing about this plugin, and adding the filter
on an older FormEngine is harmless because nothing calls it.

## What FlowMint Workflows writes to FormEngine

### Via the `fre_delete_entry` step type

FlowMint Workflows includes a step type that calls FormEngine's existing entry deletion logic. This is the only state-modifying touch point.

Implementation: the step calls `PForms_Entry::delete()` directly (same install, no HTTP), which runs FormEngine's own cascade cleanup of the entry's meta and files. NOT a raw database delete.

### Via the `fre_update_entry_status` step type

Updates entry status (unread/read/spam) via `PForms_Entry::update_status()`.

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
| Uploaded files (Media Library attachments in `wp-content/uploads/YYYY/MM/`, linked from `wp_fre_entry_files`) | FormEngine |
| Workflow definitions (`wp_fmw_workflows`) | FlowMint Workflows |
| Workflow runs (`wp_fmw_workflow_runs`) | FlowMint Workflows |
| Workflow run steps (`wp_fmw_workflow_run_steps`) | FlowMint Workflows |
| API credentials (`wp_options` `fmw_credential_*`) | FlowMint Workflows |

When FMW is uninstalled (`uninstall.php`), FRE data is never touched. FMW's own tables are kept unless `FMW_REMOVE_ALL_DATA` is defined, and then only FMW-owned tables are dropped.

When FRE is uninstalled, FMW's run history that references deleted entries becomes "orphaned" — the entry_id field still exists but the entry it references is gone. The run history table is preserved (don't lose audit trail). The admin UI gracefully handles missing entries by showing "Entry deleted" in the run detail view.

## Local development with both plugins

In Breon's Local Flywheel WordPress instance:

```
wp-content/plugins/
├── form-runtime-engine/      ← FRE source
├── flowmint-workflows/       ← FMW source (this plugin)
├── ai-section-builder-modern/  ← Promptless WP (optional, for full stack)
```

Both plugins active simultaneously. FMW's `pforms_submission_complete` listener fires automatically. The local environment provides the full integration test surface without needing production deploys.

For the development cycle:
1. Make changes in FMW source
2. Test directly in Local (no rebuild needed for PHP changes)
3. When ready to ship, run `bin/build-release.sh` to produce a clean zip
4. Upload the zip to the production WP install via Plugins → Add New → Upload Plugin
5. Same workflow as FormEngine

## Coupling boundaries

What's tightly coupled (intentional):
- FMW listens to `pforms_submission_complete` → must match FRE's signature exactly
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
