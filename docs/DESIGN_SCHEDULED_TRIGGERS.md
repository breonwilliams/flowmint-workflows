# FlowMint Workflows — Scheduled Triggers Design

**Status:** **Implemented in v0.6.0-rc1** (2026-05-13). All Phase 1–3 deliverables verified end-to-end on local Flywheel (99/99 smoke checks green). Phase 4 packaging + 725 production deploy in progress at time of writing.
**Author:** Architecture review session, May 13, 2026
**Target version:** 0.6.0 (minor bump from 0.5.0)
**Supersedes:** ROADMAP.md "Scheduled workflows (cron-style triggers)" out-of-scope item for v1.0

## Implementation divergences from this proposal

Six small refinements surfaced during Phase 1 implementation. None changed the core design; all moved in the direction of more defensive code:

1. **`entry_id = 0` sentinel for scheduled runs is documented.** The `fmw_workflow_run_started` / `_completed` / `_failed` action hooks carry `entry_id` as a param; for scheduled runs this is `0`. External listeners should test `entry_id > 0` before assuming a real entry exists. Noted in `SCHEDULED_WORKFLOWS.md` and the hook docblocks.
2. **Reconciliation strategy = daily AS action (not every plugins_loaded).** Per-request reconciliation would add a DB query to every page load on every site. Instead, a daily `fmw_reconcile_scheduled_events` AS recurring action handles drift correction; save / disable / delete hooks handle real-time changes. Bootstrap is self-healing: on first `init` priority 20 after v0.6 lands, schedules the daily action + runs one immediate reconciliation, gated by `fmw_reconciliation_bootstrapped` option.
3. **`get_for_form()` belt-and-suspenders filter.** Added explicit `AND trigger_type = 'form'` to `FMW_Workflow_Repository::get_for_form` so a misconfigured scheduled workflow with a stray `form_id` can never be picked up by the FRE submission listener as if it were form-triggered.
4. **No silent JSON rewrites on existing rows.** The validator's `normalize()` operates on a local copy — caller's arrays are not mutated. The migration's `ALTER TABLE` adds the `trigger_type` column with default `'form'` but does NOT rewrite any existing `config` JSON. Existing rows keep their legacy shape (top-level `form_id`, no `trigger` block) until something explicitly updates them via `FMW_Workflow_Repository::update`, at which point the new code persists the normalized shape.
5. **Validator warning for `data.*`/`entry.*` references in scheduled workflows.** Promoted from "future consideration" (§11.3) to a Phase 1 deliverable since we were already touching the validator. Surfaces typos at save time instead of silently resolving to empty string at runtime.
6. **Raw `ALTER TABLE` over `dbDelta()` for the nullability change.** dbDelta has historically been unreliable detecting `NOT NULL → NULL` column changes. The migration uses `SHOW COLUMNS` introspection followed by an explicit `ALTER TABLE … MODIFY COLUMN form_id VARCHAR(64) NULL`. Idempotent — re-running probes current state first.

## Reconciliation timing decision (originally TBD in §11)

The reconciliation bootstrap fires on `init` priority 20 (not `plugins_loaded` priority 20). Reason: Action Scheduler's data store initializes at `init` priority 1; calling `as_schedule_recurring_action` earlier emits a "called incorrectly" PHP notice AND silently no-ops. Phase 2's first smoke test caught this; the fix made the bootstrap self-healing (re-schedules if the option is set but the AS action is missing). Both behaviors are documented in `flowmint-workflows.php::maybe_schedule_reconciliation` docblock.

---

---

## 1. Summary

This document proposes adding scheduled (cron-style) workflow triggers to FlowMint Workflows as a first-class feature. Currently every workflow is bound to a FormEngine form and triggered only by `fre_submission_complete`. This design adds a parallel trigger path — `WP-Cron / Action Scheduler recurring event → Schedule Listener → Executor` — so workflows can run on time intervals in addition to or independent of form submissions. The motivating use case is FormEngine entry retention (delete entries older than N days), but the capability is generally reusable: any client may eventually need periodic syncs, scheduled reports, or maintenance jobs.

The design is deliberately small in surface area — one new trigger type, one new listener class, two new step types — and preserves full backwards compatibility for existing form-triggered workflows.

---

## 2. Context & motivation

### 2.1 Why now

The 725 Print Lab production deployment is the first FMW client. End-to-end runs through the Bulk + Small workflows produce two outputs in WordPress that we want to retire over time:

1. **FRE entries** — every form submission creates a row in the FormEngine entries table with the form data and (for forms with file fields) attached files in `wp-content/uploads/fre/...`. These accumulate forever unless explicitly deleted.
2. **`wp_fmw_workflow_runs` records** — already addressed by the existing daily housekeeping job (`fmw_run_retention_days`, default 90 days). Not in scope here.

The clean architectural answer for FRE entries is **scheduled cleanup**: a daily workflow that deletes entries older than 30 days. This requires a trigger mechanism the plugin doesn't currently expose.

### 2.2 Why not a one-off hardcoded retention feature

The simpler alternative is to extend the existing housekeeping job to also purge FRE entries. That fixes 725's immediate problem but doesn't unlock any reusable capability. The cost-of-the-right-design tax is modest (~200 LOC) and the long-term payoff is large: every future FMW client may eventually need periodic operations.

### 2.3 Why this design respects the existing architecture

The plugin's architecture doc (`ARCHITECTURE.md`) explicitly anticipated this work:

- Section "Async execution model" describes a clean separation between *trigger* (`fre_submission_complete`) and *execution* (`FMW_Workflow_Job → Executor`). A new trigger source plugs into the same executor.
- Section "Plugin lifecycle / Activation" already registers a daily Action Scheduler housekeeping job, demonstrating the cron pattern the plugin uses.
- ROADMAP.md notes scheduled workflows as "deferred to v2+" and explicitly says "The architecture leaves room for them but doesn't pay the complexity cost upfront." This document is the cash-in on that left-room.

---

## 3. Goals & non-goals

### 3.1 Goals

1. Workflows can be triggered by a recurring schedule instead of (or in addition to) form submission.
2. The schedule is specified in the workflow JSON config (no out-of-band scheduling state).
3. Existing form-triggered workflows continue to work unchanged.
4. The 725 Print Lab production install can deploy a daily "FRE entry retention" workflow that purges entries older than 30 days across all three forms.
5. The capability is reusable: any new step type added later (Phase 2+) becomes invocable from a scheduled workflow without further plugin changes.

### 3.2 Non-goals

1. **Visual schedule editor.** Schedules are specified in JSON via MCP or REST, same as everything else in FMW.
2. **Custom cron expressions.** v0.6 supports a fixed enum of intervals (`hourly`, `twicedaily`, `daily`, `weekly`). Full cron expressions (e.g. `0 2 * * 1`) deferred to a follow-up release once we know we actually need them.
3. **Per-workflow timezone configuration.** v0.6 uses the WordPress site timezone for schedule semantics (`Settings → General → Timezone`). Multi-timezone workflows deferred.
4. **Catch-up behavior for missed scheduled runs.** Action Scheduler's default behavior is "run the missed action when WP-Cron next fires" — we inherit this. No explicit catch-up policy in v0.6.
5. **Webhook triggers.** Out of scope for this document. The trigger abstraction added here makes them straightforward later.
6. **Manual trigger / "Run now" button for scheduled workflows.** Out of scope for v0.6. The existing replay mechanism (`/runs/{id}/replay`) is sufficient for ad-hoc execution during debugging.

---

## 4. Design overview

### 4.1 High-level shape

```
Form submission path (existing, unchanged):
  fre_submission_complete
    → FMW_Submission_Listener::on_submission_complete()
      → FMW_Run_Repository::create_pending()
      → as_enqueue_async_action('fmw_run_workflow', [$run_id])
        → FMW_Workflow_Job::run($run_id)
          → FMW_Workflow_Executor
            → steps[]

Scheduled path (new):
  Action Scheduler recurring event fires
    → FMW_Schedule_Listener::on_scheduled_tick($workflow_id)
      → FMW_Run_Repository::create_pending_scheduled()
      → as_enqueue_async_action('fmw_run_workflow', [$run_id])
        → FMW_Workflow_Job::run($run_id)  ← same job handler
          → FMW_Workflow_Executor          ← same executor
            → steps[]
```

The Executor and downstream code are unchanged. The only new code path is the listener that creates the run record and dispatches to Action Scheduler. Scheduled runs share the same `wp_fmw_workflow_runs` table, the same retry policy, the same logging — they just have a different origin.

### 4.2 Key abstraction: workflow trigger

Currently every workflow has a `form_id` column. This becomes one of several possible trigger types. The workflow JSON config gains a `trigger` block:

**Form-triggered (existing pattern, made explicit):**
```json
{
  "trigger": {
    "type": "form",
    "form_id": "bulk-order-quote"
  },
  "steps": [...]
}
```

**Schedule-triggered (new):**
```json
{
  "trigger": {
    "type": "schedule",
    "interval": "daily"
  },
  "steps": [...]
}
```

The `form_id` column on `wp_fmw_workflows` becomes nullable (was `NOT NULL`). For form-triggered workflows it remains populated and indexed (the existing form-id lookup path is unchanged). For scheduled workflows it is NULL.

Backwards compatibility is preserved by the validator: a workflow JSON without a top-level `trigger` block but with a top-level `form_id` field is treated as `trigger.type == "form"`. Existing workflows on production sites continue working with zero migration.

---

## 5. Detailed design

### 5.1 Workflow JSON schema changes

**Add the `trigger` block** as a sibling of `settings` and `steps`. Two trigger types in v0.6:

```json
// trigger.type = "form"
{
  "trigger": {
    "type": "form",
    "form_id": "bulk-order-quote"
  }
}

// trigger.type = "schedule"
{
  "trigger": {
    "type": "schedule",
    "interval": "daily",
    "hour": 2,        // optional; for interval = "daily" only; default 2 (2am site-local)
    "minute": 0,      // optional; default 0
    "day_of_week": 1  // optional; for interval = "weekly" only; 1-7, Monday = 1, ISO-style
  }
}
```

**Schedule interval enum** (v0.6):

| Value | Frequency | Notes |
|---|---|---|
| `hourly` | every hour | first run within the next hour |
| `twicedaily` | every 12 hours | matches WP's built-in `twicedaily` |
| `daily` | every 24 hours | first run at the next `hour:minute` site-local |
| `weekly` | every 7 days | runs on `day_of_week` at `hour:minute` |

These intervals map directly to Action Scheduler / WP-Cron-compatible recurrences. No custom parser needed in v0.6.

**Backwards compatibility:** If `trigger` is absent and `form_id` is present at the top level (the pre-0.6 pattern), the validator treats this as `trigger: { type: "form", form_id: <value> }`. Existing workflows continue to work without their JSON being rewritten. The REST API also accepts both shapes on PATCH.

### 5.2 Database schema changes

Single DDL change to `wp_fmw_workflows`:

```sql
ALTER TABLE wp_fmw_workflows
  MODIFY COLUMN form_id VARCHAR(64) NULL,
  ADD COLUMN trigger_type VARCHAR(32) NOT NULL DEFAULT 'form' AFTER form_id,
  ADD INDEX idx_trigger_type (trigger_type, enabled);
```

Rationale:
- `form_id` becomes nullable so scheduled workflows can exist.
- `trigger_type` is denormalized from the JSON config into its own column for indexing. Lets the schedule listener efficiently query "all enabled scheduled workflows" without parsing every JSON config. Also lets the admin UI filter by trigger type.
- No change to `wp_fmw_workflow_runs` or `wp_fmw_workflow_run_steps`. Scheduled runs reuse the same tables. The `entry_id` column on runs becomes `0` (or NULL — TBD in implementation) for scheduled runs; the schema already has it as `NOT NULL` so we'll use `0` as the sentinel to avoid another DDL change.

**Migration handled in `class-fmw-schema.php`:**
- Detects existing schema version, applies the ALTER, bumps `fmw_db_version` option.
- Migration is idempotent (safe to re-run).
- Existing rows: `trigger_type = 'form'` by default — all current workflows are correctly classified after migration.

### 5.3 New class: `FMW_Schedule_Listener`

**File:** `includes/Core/class-fmw-schedule-listener.php`
**Pattern:** Mirrors `FMW_Submission_Listener`. Three responsibilities:

**(a) Cron event registration.** On plugin init, scan workflows with `trigger_type = 'schedule'` and `enabled = 1`, ensure each has an Action Scheduler recurring event registered:

```php
public function ensure_recurring_events_registered() {
    $workflows = fmw()->registry->get_all_by_trigger_type( 'schedule', [ 'enabled' => 1 ] );
    foreach ( $workflows as $workflow ) {
        $hook = 'fmw_scheduled_workflow_tick';
        $args = [ $workflow->id() ];
        $group = 'fmw';
        
        if ( ! as_has_scheduled_action( $hook, $args, $group ) ) {
            $next_timestamp = $this->compute_next_run_timestamp( $workflow );
            $interval_seconds = $this->compute_interval_seconds( $workflow );
            
            as_schedule_recurring_action(
                $next_timestamp,
                $interval_seconds,
                $hook,
                $args,
                $group
            );
        }
    }
}
```

**(b) Workflow save / disable hooks.** Listens to `fmw_workflow_saved` and `fmw_workflow_disabled` actions (which `FMW_Workflow_Repository` already fires on save). Re-registers or unregisters the cron event as needed:

```php
public function on_workflow_saved( $workflow ) {
    $this->unschedule_for_workflow( $workflow->id() );
    if ( $workflow->trigger_type() === 'schedule' && $workflow->is_enabled() ) {
        $this->schedule_for_workflow( $workflow );
    }
}
```

**(c) Tick handler.** Responds to the `fmw_scheduled_workflow_tick` action, looks up the workflow, creates a run record, dispatches via Action Scheduler (same pattern as `FMW_Submission_Listener::on_submission_complete`):

```php
public function on_scheduled_tick( $workflow_id ) {
    $workflow = fmw()->registry->get( $workflow_id );
    if ( ! $workflow || ! $workflow->is_enabled() || $workflow->trigger_type() !== 'schedule' ) {
        return; // workflow was deleted, disabled, or retyped between scheduling and firing
    }

    $run_id = FMW_Run_Repository::create_pending_scheduled( $workflow->id() );
    if ( is_wp_error( $run_id ) ) {
        FMW_Logger::error( 'Failed to create scheduled run record', [...] );
        return;
    }

    $action_id = as_enqueue_async_action( 'fmw_run_workflow', [ $run_id ], 'fmw' );
    // ...same audit-fix-I1 handling as the submission listener
}
```

### 5.4 Changes to existing classes

**`FMW_Workflow` (value object):**
- New accessor: `trigger_type(): string` — returns the trigger type from the JSON config (or column, whichever is canonical).
- New accessor: `trigger_config(): array` — returns the trigger block from the JSON config.
- Existing `form_id(): ?string` — returns null for scheduled workflows.

**`FMW_Workflow_Validator`:**
- Accept the new `trigger` block in the JSON config.
- If `trigger` is absent and `form_id` is at top level (legacy pattern), normalize to `trigger: { type: "form", form_id }`.
- If `trigger.type == "form"`, require `trigger.form_id` to be a valid FRE form (existing check).
- If `trigger.type == "schedule"`, require `trigger.interval` to be one of the enum values; validate optional `hour`, `minute`, `day_of_week` ranges.
- Reject unknown trigger types.

**`FMW_Workflow_Repository`:**
- `create()` / `update()` — persist `trigger_type` to its own column (denormalized from JSON for indexing) and `form_id` (nullable now).
- New method: `get_all_by_trigger_type( string $type, array $where = [] ): FMW_Workflow[]`.
- Existing `get_for_form( $form_id )` — unchanged (only matches form-triggered workflows).
- On save/disable, fire `fmw_workflow_saved` / `fmw_workflow_disabled` action hooks so the schedule listener can re-register cron events.

**`FMW_Run_Repository`:**
- New method: `create_pending_scheduled( string $workflow_id ): int|WP_Error` — creates a run row with `form_id = NULL` (or workflow's form_id if applicable), `entry_id = 0`, status `queued`.
- Existing `create_pending( $workflow_id, $form_id, $entry_id )` — unchanged.

**`FMW_Workflow_Job::run( $run_id )`:**
- Already loads workflow + entry data from the run row. For scheduled runs, entry_id is `0` — the job handler needs to handle this:
  - If `entry_id === 0`, skip the FRE entry fetch step. The context's `entry`, `data`, `entry_files`, `form` fields stay empty/null.
  - The rest of the executor flow is unchanged.

**`FMW_Workflow_Context`:**
- New construction path for scheduled runs: empty `entry`, `data`, `entry_files`, `form`. All other fields (workflow metadata, run metadata, vars, steps, env) populated normally.
- The interpolator already handles missing variables by returning empty string (per ARCHITECTURE.md section "Defaults and safety"). No interpolator change needed.

**`FMW_REST_Workflows` (REST API):**
- Accept `trigger` in workflow CRUD payloads.
- Backwards compat: accept `form_id` at top level (legacy pattern), normalize to `trigger.form_id`.
- Return `trigger` in workflow responses.
- New optional query parameter on `/workflows` GET: `trigger_type` filter.

**`FMW_REST_Preflight`:**
- Add `supported_trigger_types: ["form", "schedule"]` to the preflight payload so MCP / connector clients can introspect capabilities.

### 5.5 New step types

Two new step types in `includes/Steps/Core/`. Both follow the existing patterns established by the 26 existing step types.

#### 5.5.1 `fre_list_entries`

**Purpose:** Query FormEngine entries with filters. Output is an array of entry records the next step can iterate over.

**File:** `class-step-fre-list-entries.php`
**Category:** FormEngine

**Config schema:**

```json
{
  "name": "find_old_entries",
  "type": "fre_list_entries",
  "config": {
    "form_id": "bulk-order-quote",     // optional; "*" or omitted for all forms
    "older_than_days": 30,             // optional; entries created more than N days ago
    "older_than_date": "2026-04-13",   // optional; explicit cutoff (mutually exclusive with above)
    "status": ["unread", "read"],      // optional; default all statuses
    "limit": 100                       // optional; default 100, max 1000 (safety cap)
  }
}
```

**Output schema:**

```json
{
  "entries": [
    {"id": 38, "form_id": "small-order-request", "status": "unread", "created_at": "2026-04-01 12:34:56"},
    {"id": 32, "form_id": "bulk-order-quote", "status": "read", "created_at": "2026-04-02 08:12:00"}
    // ...
  ],
  "count": 42
}
```

**Implementation notes:**
- Uses FormEngine's existing entry query API (`FRE_Entry::query()` or similar).
- The 1000-row safety cap exists to prevent runaway queries; pagination across multiple workflow runs is out of scope for v0.6.
- Returns an empty `entries` array (count 0) if no matches — never errors on "nothing found". The next step (`fre_delete_entries`) is idempotent on empty input.

#### 5.5.2 `fre_delete_entries`

**Purpose:** Delete FormEngine entries (and their attached files) in bulk, given an array of entry records or IDs. Idempotent — re-running on the same IDs returns `already_gone: true` per ID.

**File:** `class-step-fre-delete-entries.php`
**Category:** FormEngine

**Config schema:**

```json
{
  "name": "purge_old_entries",
  "type": "fre_delete_entries",
  "config": {
    "entries": "{{ steps.find_old_entries.entries }}"  // array of entry objects, OR array of IDs
  }
}
```

**Output schema:**

```json
{
  "deleted_count": 38,
  "already_gone_count": 4,
  "failed_count": 0,
  "deleted_ids": [1, 2, 3, ...],
  "already_gone_ids": [...],
  "failed": [{"id": 99, "error": "..."}]
}
```

**Implementation notes:**
- Accepts either an array of entry objects (from `fre_list_entries`) or a plain array of IDs.
- Calls FRE's existing single-entry delete primitive in a loop (same primitive `fre_delete_entry` step uses for the single-entry case). Future optimization: bulk SQL delete if FRE adds that primitive.
- Each individual delete failure is logged and added to the `failed` array. The step itself does NOT throw — failures are normal (entry might have been manually deleted between list and delete) and shouldn't fail the whole workflow.
- `on_error: continue` is the recommended default for this step in retention workflows.

### 5.6 Example end-to-end: FRE entry retention workflow

Putting everything together — the workflow that solves 725's immediate problem:

```json
{
  "id": "fre-entry-retention-30d",
  "title": "FRE Entry Retention — Delete entries older than 30 days",
  "trigger": {
    "type": "schedule",
    "interval": "daily",
    "hour": 2,
    "minute": 0
  },
  "settings": {
    "max_retries": 1,
    "on_failure_notify": ["email"]
  },
  "steps": [
    {
      "name": "log_start",
      "type": "log_info",
      "config": {
        "message": "FRE entry retention workflow started at {{ now('Y-m-d H:i:s') }}"
      }
    },
    {
      "name": "find_old",
      "type": "fre_list_entries",
      "config": {
        "older_than_days": 30,
        "limit": 500
      }
    },
    {
      "name": "purge",
      "type": "fre_delete_entries",
      "on_error": "continue",
      "config": {
        "entries": "{{ steps.find_old.entries }}"
      }
    },
    {
      "name": "log_done",
      "type": "log_info",
      "config": {
        "message": "Retention sweep complete. Deleted {{ steps.purge.deleted_count }} entries. Already-gone: {{ steps.purge.already_gone_count }}. Failed: {{ steps.purge.failed_count }}."
      }
    }
  ]
}
```

One workflow handles all forms. Runs every day at 2am site-local time. The first run may delete a backlog; subsequent runs delete the day's worth of newly-aged-out entries.

---

## 6. Backwards compatibility

### 6.1 Workflow JSON

- Existing workflows (no `trigger` block, top-level `form_id`) continue to work without their JSON being rewritten.
- The validator normalizes to the new shape internally; the canonical stored form may still have just `form_id` until the workflow is next saved through the REST API.
- New workflows authored through the connector can use either the new `trigger` block or the legacy top-level `form_id` field — both work.

### 6.2 Database

- The `ALTER TABLE` to make `form_id` nullable and add `trigger_type` is the only schema change.
- All existing rows are correctly classified as `trigger_type = 'form'` by the default value.
- The migration is idempotent and runs once via `fmw_db_version` bumping. Safe across plugin upgrades.

### 6.3 REST API

- Existing endpoints' request/response shapes are extended (new optional `trigger` field), never broken.
- The connector_version on each workflow still bumps on every update — clients that track version don't need new logic.
- Preflight gains a `supported_trigger_types` field — clients that don't know to look for it continue working as before.

### 6.4 MCP tools

- No new MCP tools required for v0.6. The existing `flowmint_create_workflow` / `flowmint_update_workflow` accept the new `trigger` field via their existing `config` parameter (which is already a JSON string).
- A future MCP enhancement (post-v0.6) could expose a `flowmint_create_scheduled_workflow` convenience tool, but it's not necessary for the feature to work.

---

## 7. Implementation phases

Broken into reviewable, independently shippable chunks. Each phase ends in a working state with green tests.

### Phase 1 — Foundation (schema, trigger abstraction, listener stub)

**Files touched:** `class-fmw-schema.php`, `class-fmw-workflow.php`, `class-fmw-workflow-validator.php`, `class-fmw-workflow-repository.php`, new `class-fmw-schedule-listener.php` (stub only), `flowmint-workflows.php` (init wiring).

**Deliverables:**
- DDL migration: `form_id` nullable + new `trigger_type` column + index.
- Workflow value object exposes `trigger_type()`, `trigger_config()`.
- Validator accepts and validates the new `trigger` block.
- Repository persists `trigger_type` denormalized from JSON.
- Schedule Listener class exists with method stubs but no real cron registration yet.
- All existing tests still pass (backwards compat verified).

**Exit criteria:** A scheduled workflow can be created via REST (with `trigger.type: "schedule"`), persisted, retrieved, and validated. It does NOT yet actually run on a schedule.

### Phase 2 — Cron registration + execution

**Files touched:** `class-fmw-schedule-listener.php` (fill in real logic), `class-fmw-run-repository.php` (new `create_pending_scheduled`), `class-fmw-workflow-job.php` (handle entry_id === 0 case), `class-fmw-workflow-context.php` (synthetic context for scheduled runs).

**Deliverables:**
- Schedule Listener registers Action Scheduler recurring events on init and on workflow save.
- Listener unregisters events on workflow disable/delete.
- Cron tick handler creates a queued run, enqueues for async execution.
- Workflow Job correctly handles runs with no entry (skip FRE fetch, populate context with empty entry/data).
- A workflow with no real work (e.g., just `log_info`) can be scheduled and verified to fire on its interval.

**Exit criteria:** A test workflow scheduled to run hourly fires at the expected time, appears in run history with status `completed`, and logs the expected message.

### Phase 3 — New step types

**Files touched:** new `class-step-fre-list-entries.php`, new `class-step-fre-delete-entries.php`, `class-fmw-step-registry.php` (registration), `STEP_LIBRARY.md` (docs).

**Deliverables:**
- `fre_list_entries` step with full config schema, returns entries array.
- `fre_delete_entries` step with bulk delete, idempotent, per-id failure handling.
- Both step types registered in the step registry.
- Unit tests for both step types (against a test FRE entries fixture).

**Exit criteria:** A scheduled workflow that uses these two steps can find and delete entries older than N days on a test fixture.

### Phase 4 — Smoke tests, packaging, deployment

**Deliverables:**
- Local end-to-end smoke test: create a retention workflow on the dev site, submit test entries, fast-forward time (or set retention to 1 minute for testing), trigger the cron manually via `as_enqueue_async_action` invocation, verify entries deleted.
- Build script (`bin/build-release.sh`) produces a clean v0.6.0 zip with composer deps bundled.
- Test the in-place upgrade from v0.5.0 → v0.6.0 on a separate test site (verify DDL migration runs cleanly, existing workflows unaffected).
- Update CHANGELOG.md, README.md, ARCHITECTURE.md, ROADMAP.md, CLAUDE.md.
- Add `docs/SCHEDULED_WORKFLOWS.md` dedicated user guide.

**Exit criteria:** v0.6.0 zip is built, tested on a fresh-site upgrade, ready to deploy to 725 production. Design doc is updated to reflect any divergences from this proposal that surfaced during implementation.

---

## 8. Testing strategy

### 8.1 Unit tests (no DB, no external services)

- `WorkflowValidatorTest::test_trigger_block_normalization()` — legacy form_id at top level normalizes to trigger.type=form.
- `WorkflowValidatorTest::test_schedule_trigger_validation()` — interval enum, hour/minute ranges, day_of_week ranges.
- `WorkflowValidatorTest::test_rejects_unknown_trigger_type()` — unknown trigger.type returns validation error.
- `ScheduleListenerTest::test_compute_next_run_timestamp()` — for each interval, given a current time, returns correct next-tick timestamp respecting hour/minute/day_of_week.
- `FreListEntriesStepTest::test_filters()` — given a fixture of 10 entries with varying ages and statuses, returns the right subset for each filter combination.
- `FreDeleteEntriesStepTest::test_idempotency()` — running delete twice returns `already_gone: true` on second invocation, no errors.

### 8.2 Integration tests (real DB via WP test harness)

- `ScheduledWorkflowIntegrationTest::test_full_lifecycle()` — create scheduled workflow → verify Action Scheduler event registered → trigger manually → verify run record created and executed → verify expected step outputs in run_steps table.
- `RetentionWorkflowIntegrationTest::test_30_day_purge()` — fixture: insert 50 FRE entries across forms with varying created_at dates → run retention workflow → assert N entries older than 30 days were deleted, M younger entries remain.

### 8.3 Smoke tests on local Flywheel site

Manual but scripted:
1. Install v0.6.0 over v0.5.0.
2. Verify preflight reports `plugin_version: 0.6.0` and `supported_trigger_types: ["form", "schedule"]`.
3. Create the retention workflow via the FlowMint connector with retention period set to **5 minutes** (compressed time for testing).
4. Submit 3 test form entries spaced 1 minute apart.
5. Wait 6 minutes (or use a Bash shell with `date` mocking if available).
6. Trigger the cron manually: `wp action-scheduler run --hooks=fmw_scheduled_workflow_tick`.
7. Verify run history shows one new scheduled run with status `completed`.
8. Verify FRE entries table contains only entries < 5 minutes old.
9. Verify Action Scheduler shows the recurring action still scheduled.

### 8.4 Production verification on 725

After deploy:
1. Run preflight, verify v0.6.0 active and credentials/workflows healthy.
2. Create the retention workflow with `interval: "daily"`, `hour: 2`, `minute: 0`.
3. Verify Action Scheduler shows `fmw_scheduled_workflow_tick` recurring action scheduled.
4. Wait one daily cycle, verify run appears in history.
5. After several daily cycles, sample a few deleted entries to confirm they're really gone (no orphan files in `wp-content/uploads/fre/...`).

---

## 9. Deployment strategy

### 9.1 Rollout sequence

1. **Local dev site (Flywheel)** — full implementation + smoke tests pass.
2. **Internal staging** — fresh test site, in-place upgrade from v0.5.0 zip, all existing workflows verified still running.
3. **725 Print Lab production** — in-place upgrade once staging passes. Deploy alongside the new retention workflow JSON.
4. **Monitor for 7 days** — daily verification that retention workflow runs and existing Bulk/Small/Contact workflows continue to run on form submissions.
5. **Document lessons** — update this design doc with any divergences from the planned implementation.

### 9.2 Rollback plan

If v0.6.0 causes issues on 725 production:
1. Disable the retention workflow via REST (`enabled: false`) — this is the new functionality; disabling it isolates the issue.
2. If existing workflows are also broken: re-upload the v0.5.0 zip via WP Admin → Plugins → Upload Plugin → Replace Existing. The DDL changes (nullable form_id, new trigger_type column) do NOT need to be rolled back — they're additive and backwards-compatible.
3. Existing form-triggered workflows resume working immediately.
4. Diagnose offline.

The DDL is intentionally additive precisely so rollback never requires database changes.

---

## 10. Observability

### 10.1 Scheduled run visibility

Scheduled runs appear in `wp_fmw_workflow_runs` alongside form-triggered runs. The admin Run History page (FMW Admin → Run History) shows all runs together.

To distinguish scheduled runs visually:
- The run detail view shows the workflow's `trigger_type` in the header.
- A filter on the runs list lets the admin filter by trigger type.
- Scheduled runs have `entry_id = 0` (sentinel value) — the UI shows "—" instead of an entry link.

### 10.2 Logs

`FMW_Schedule_Listener` writes structured logs at info level for: cron event registration, cron event un-registration, tick fire. At error level for: scheduling failures, tick handler failures. Same log channel and format as `FMW_Submission_Listener`.

### 10.3 Notifications

The existing `on_failure_notify` setting works unchanged. A failed scheduled run sends the same Slack/email notification as a failed form-triggered run. The notification body includes "Triggered by: schedule" so the recipient knows the context.

---

## 11. Risks & mitigations

### 11.1 Risk: missed cron fires (WP-Cron unreliable on low-traffic sites)

Action Scheduler depends on WP-Cron firing. On sites with low organic traffic, WP-Cron may not fire for long stretches. A daily retention workflow could go a week between actual runs.

**Mitigation:** Documented in `docs/SETUP_GOOGLE_DRIVE.md` (already) — production sites should configure a real OS-level cron via the host (GoDaddy cPanel, etc.). The same instruction applies for FMW scheduled workflows. For 725 specifically, this is one of the open items from the original migration (the v0.5 docs noted this).

### 11.2 Risk: Action Scheduler queue backlog

If the daily retention sweep takes longer than its interval (unlikely for entry deletion, but conceivable for other future scheduled workflows), runs could queue up.

**Mitigation:** Action Scheduler has built-in concurrency limits. Long-running scheduled workflows should chunk their work (use the `limit` config on `fre_list_entries`) and let subsequent runs pick up where the previous left off. The design supports this naturally.

### 11.3 Risk: scheduled run with no entry context surfaces unexpected `{{ data.* }}` references

A scheduled workflow author might accidentally use `{{ data.email }}` in a step config. The interpolator returns empty string for missing variables, so the step still runs but with empty values.

**Mitigation:** The interpolator already logs a warning for missing variables (per ARCHITECTURE.md). Add a validator check: a scheduled workflow's step configs should not reference `data.*` or `entry.*` — flag this at validation time with a warning (not an error, since `||` fallbacks may legitimately use them).

### 11.4 Risk: schedule listener race condition on workflow save

If a workflow is saved twice in quick succession via concurrent REST requests, the schedule listener might double-register the cron event.

**Mitigation:** `as_has_scheduled_action()` check before `as_schedule_recurring_action()` prevents duplicates. Action Scheduler also dedupes by hook + args + group.

### 11.5 Risk: enabled-but-stale cron events

A workflow is created with `enabled: true`, schedules a cron event, then is later updated to `enabled: false`. The cron event needs to be unregistered to avoid useless ticks.

**Mitigation:** The `on_workflow_saved` listener explicitly unschedules then re-schedules. Deletion fires `fmw_workflow_deleted` which the listener also handles.

---

## 12. Alternatives considered

### 12.1 Hardcode retention in the existing housekeeping job

**Why not:** Solves 725's immediate problem but creates zero reusable capability. Every future use case requiring scheduling becomes another hardcoded job. Violates the "Steps-as-code" architecture pattern from ROADMAP.md.

### 12.2 Use a separate "scheduled_workflows" table

**Why not:** Doubles the schema surface. Existing run history table would need a parallel structure. The cleaner answer is one workflow type with multiple trigger sources — same execution path, same run history, same admin UI.

### 12.3 Full cron expression support in v0.6 (e.g., `0 2 * * 1`)

**Why not:** Adds a custom parser (or a library dependency) for capability we may not need. The enum of `hourly | twicedaily | daily | weekly` covers ~95% of realistic use cases. If a client needs `every 15 minutes on weekdays only`, we add custom expressions in a follow-up release based on real demand. YAGNI.

### 12.4 Use WP-Cron's `wp_schedule_event` instead of Action Scheduler's `as_schedule_recurring_action`

**Why not:** Action Scheduler is already a dependency, already used for the housekeeping job, has reliable retry semantics, and is the architecture's chosen async layer. Mixing WP-Cron primitives in would split the observability surface and complicate testing.

### 12.5 Add a "first-class" trigger field to the schema (rather than denormalizing into a column)

**Why not:** Considered making the trigger live entirely in the JSON config with no DB column. Rejected because the schedule listener needs to query `WHERE trigger_type = 'schedule' AND enabled = 1` efficiently on plugin init. JSON column queries are slower and less portable. Denormalizing `trigger_type` to its own indexed column is the standard pattern.

---

## 13. Open questions

1. **Should the retention workflow JSON ship as a built-in workflow template?** I.e., should FMW have a `templates/` folder with canned workflows clients can install with one command? Currently leaning yes for a follow-up release; v0.6 ships without it. Each client creates their own retention workflow via REST.

2. **Should scheduled workflows have per-step timezone overrides?** No, in v0.6. All schedules run in site-local timezone (`Settings → General → Timezone`). Per-step timezones would matter only if a step's output needs to format dates differently — addressable via the existing `now('Y-m-d', timezone)` interpolator function in the future.

3. **Should the retention workflow auto-skip if a previous run is still running?** Action Scheduler doesn't natively prevent this for recurring events. If a daily retention sweep takes >24h (unlikely), it would overlap. For v0.6, accept this edge case (no overlap protection); revisit if it occurs.

4. **Should `fre_list_entries` and `fre_delete_entries` also work for FormEngine entries that don't belong to FMW-managed forms?** Yes — FormEngine entries are entries; the workflow doesn't care whether a workflow exists for the parent form. Documented as a feature, not a bug.

---

## 14. Acceptance criteria

A v0.6.0 release is shippable when ALL of the following are true:

1. The DDL migration runs cleanly on a fresh v0.5.0 → v0.6.0 in-place upgrade. Existing workflows are correctly classified as `trigger_type = 'form'` and continue to run on form submissions.
2. A scheduled workflow created via the FlowMint MCP connector with `trigger.type = "schedule"` and `interval = "daily"` correctly registers an Action Scheduler recurring event.
3. When the cron event fires, a new run row appears in `wp_fmw_workflow_runs` with the correct `workflow_id`, `entry_id = 0`, and status transitions correctly through `queued → running → completed`.
4. The `fre_list_entries` step correctly returns entries matching the filter criteria. The `fre_delete_entries` step correctly deletes the provided entries and reports counts.
5. The end-to-end retention workflow successfully deletes FRE entries older than the configured threshold on a test fixture, leaving newer entries untouched.
6. All existing unit and integration tests still pass (no regressions in form-triggered behavior).
7. New unit tests added for: validator (trigger normalization, enum validation), schedule listener (next-tick computation), and both new step types. Test coverage for new code > 85%.
8. CHANGELOG.md, README.md, ARCHITECTURE.md, ROADMAP.md, CLAUDE.md, and a new `docs/SCHEDULED_WORKFLOWS.md` are updated to reflect v0.6.0.
9. The 725 production install is upgraded and runs the 30-day retention workflow daily for at least one full week with no errors.

---

## 15. Future work (out of scope for v0.6.0)

- **Full cron expression support** (`0 2 * * 1` style).
- **Manual "Run now" button** for scheduled workflows in the admin UI.
- **Workflow execution catch-up policy** — explicit control over what happens if a scheduled tick was missed.
- **`for_each` step type** — would let workflows iterate over entry lists more flexibly than the current bulk-delete pattern. Already on the roadmap for v2.
- **Webhook triggers** — `trigger.type: "webhook"` would receive HTTP POST callbacks and dispatch to the executor. Same trigger abstraction; one new listener class.
- **Workflow chaining** — `trigger.type: "workflow_completed"` triggers Workflow B when Workflow A finishes. Useful for follow-up actions; deferred until requested.
- **Per-step timeout configuration** for long-running scheduled operations.

---

**Document version:** 1.0
**Awaiting approval:** Breon
**Implementation kicks off:** After approval; the design is the contract.
