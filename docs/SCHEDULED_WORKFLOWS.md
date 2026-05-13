# Scheduled Workflows — User Guide

*New in v0.6.0.* Workflows can now run on a recurring schedule (hourly, twice-daily, daily, or weekly) in addition to (or instead of) being triggered by form submissions.

This guide is for operators who want to **create** scheduled workflows. For the engineering contract behind this feature, see `DESIGN_SCHEDULED_TRIGGERS.md`. For the underlying architecture, see `ARCHITECTURE.md`.

## When to use scheduled workflows

Use scheduled workflows for any periodic operation that doesn't depend on a fresh form submission:

- **Retention sweeps.** Daily purge of old FRE entries (the motivating use case for this feature — see "Full example: daily entry retention" below).
- **Periodic syncs.** Pull a remote API once a day, write the result to Drive or post to Slack.
- **Maintenance.** Daily housekeeping, weekly digest emails, monthly reports.

Form submissions are *also* still supported, of course — workflows can be one or the other. v0.6 doesn't yet support a single workflow that listens to both.

## The `trigger` block

In v0.6.0+, every workflow declares its trigger explicitly via a `trigger` block in the workflow JSON config:

```json
// Form-triggered (the existing pattern)
{
  "trigger": { "type": "form", "form_id": "bulk-order-quote" },
  "steps": [ … ]
}

// Schedule-triggered (new)
{
  "trigger": {
    "type": "schedule",
    "interval": "daily",
    "hour": 2,
    "minute": 0
  },
  "steps": [ … ]
}
```

**Backwards compatibility:** pre-v0.6 workflows that have a top-level `form_id` field (no `trigger` block) continue to work. The validator silently normalizes them to the new shape. You do **not** need to edit existing workflow JSON to deploy v0.6.

## Schedule intervals

v0.6 supports a fixed enum of four intervals. Full cron expressions (e.g. `0 2 * * 1`) are deliberately deferred until concrete demand surfaces — these four cover ~95% of realistic use cases.

| Interval | Description | `hour` / `minute` / `day_of_week` honored? |
|---|---|---|
| `hourly` | Every hour, first run roughly an hour after the workflow is saved | No — fires on the hour boundary AS chose |
| `twicedaily` | Every 12 hours from the moment the workflow is saved | No |
| `daily` | Once per day at `hour:minute` site-local | `hour` (0–23) and `minute` (0–59) |
| `weekly` | Once per week at `hour:minute` site-local on `day_of_week` | All three fields |

**Site timezone matters.** When you specify `hour: 2`, that's **2am in your WordPress site's timezone** (`Settings → General → Timezone`), not UTC. The plugin handles the conversion. So if your site's timezone is `America/New_York` and you set `hour: 2`, the workflow fires at 2am Eastern, regardless of where the server is physically located.

**Day-of-week numbering** for weekly schedules follows ISO 8601: `1 = Monday`, `2 = Tuesday`, …, `7 = Sunday`. If you want "every Monday at 9am," that's `day_of_week: 1, hour: 9, minute: 0`.

## What's available inside a scheduled run

Scheduled runs have **no FormEngine entry**. There's no submission, no form, no uploaded files. That means a scheduled workflow can NOT reference:

- `{{ data.* }}` — submitted form field values (there are no submitted fields)
- `{{ entry.* }}` — FE entry metadata (there is no entry)
- `{{ entry_files.* }}` — uploaded files (none)
- `{{ form.* }}` — form metadata (no bound form)

Workflow validation **warns** if your config references any of these in a scheduled workflow — they'll resolve to empty string at runtime, which is almost certainly not what you want.

Scheduled workflows **can** reference:

- `{{ now('Y-m-d H:i:s') }}` — current timestamp (in any PHP date format)
- `{{ env.site_name }}`, `{{ env.site_url }}`, `{{ env.admin_email }}` — safe environment values
- `{{ workflow.id }}`, `{{ workflow.title }}` — workflow metadata
- `{{ run.id }}`, `{{ run.started_at }}` — current run metadata
- `{{ vars.<your_var> }}` — variables you set via `set_variable` earlier in the run
- `{{ steps.<earlier_step>.<output_field> }}` — outputs from previous steps in the same run

## Creating a scheduled workflow via the connector

Through Claude Desktop, with the FlowMint connector connected:

```
Please create a scheduled workflow on my-site.com:
  - id: daily-entry-retention
  - title: Daily FRE entry retention
  - Run every day at 2am site-local
  - Steps:
    1. List FORM_A entries older than 30 days
    2. Delete those entries
    3. Log how many were deleted
```

Claude will assemble the workflow JSON and POST it to `/wp-json/flowmint/v1/connector/workflows`. Verify via the connector's `flowmint_list_workflows` tool that it shows up with `trigger_type: schedule`.

## Lifecycle

When you save an enabled scheduled workflow, the schedule listener immediately registers an Action Scheduler recurring event for it. You can verify this via the Action Scheduler admin UI:

- WP Admin → Tools → Scheduled Actions
- Look for actions with the hook `fmw_scheduled_workflow_tick` and your workflow ID in the args column.

When you **disable** the workflow (`enabled: false`), the cron event is unregistered immediately. When you **delete** it, the cron event is unregistered. When you **change the interval** (e.g., from daily to hourly), the existing event is unscheduled and a new one is scheduled with the new interval — no duplicates.

There's also a **daily reconciliation pass** that runs in the background and brings AS state into sync with the workflows table. This is drift insurance — even if an AS recurring event got lost somehow, the next reconciliation rebuilds it.

## How a scheduled run flows

```
2am site-local: AS fires the recurring event
  → FMW_Schedule_Listener creates a queued run row
    (form_id = '', entry_id = 0 — sentinels indicating "no form")
  → workflow_job picks it up asynchronously
    → Executor runs each step in sequence
      → Run completes with status="completed"
        → Run history shows it like any other run
```

A scheduled run looks identical to a form-triggered run in run history, except:
- The "Entry" column shows `—` (there's no entry to link to).
- The "Form" column shows `—`.

## Full example: daily entry retention

The original use case that motivated this feature — purge FE entries older than 30 days, once a day, across all forms.

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
    "max_retries": 1
  },
  "steps": [
    {
      "name": "log_start",
      "type": "log_info",
      "config": {
        "message": "FRE entry retention sweep started at {{ now('Y-m-d H:i:s') }}"
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
        "message": "Retention sweep complete. Deleted {{ steps.purge.deleted_count }} entries. Already gone: {{ steps.purge.already_gone_count }}. Failed: {{ steps.purge.failed_count }}."
      }
    }
  ]
}
```

Walk-through:

1. **`log_start`** — Records that the sweep ran. Useful even on days when there's nothing to delete, so you can see in run history that the workflow is firing.
2. **`find_old`** — Queries entries across all forms (no `form_id` filter) older than 30 days, capped at 500 per run. If there are >500 to delete on the first day, subsequent days catch up.
3. **`purge`** — Bulk-deletes the found entries. `on_error: continue` means a single failed delete doesn't fail the whole run; failures end up in `failed[]` and the log step still runs.
4. **`log_done`** — Reports the counts. The numbers flow in via `{{ steps.purge.deleted_count }}` etc.

Scoping options if you don't want a blanket policy:

- **One form only:** add `"form_id": "bulk-order-quote"` to the `find_old` config.
- **Different retention per form:** create multiple workflows, each scoped to one form with its own retention period.
- **Stricter age:** change `older_than_days: 30` to `older_than_days: 7` for a 1-week retention.
- **Status-based:** `"status": ["read", "archived"]` keeps unread entries around longer.

## Verifying a scheduled workflow

Two checks after creating a scheduled workflow:

1. **AS event registered.** Via WP-CLI in your Local Site Shell:
   ```
   wp action-scheduler list --hook=fmw_scheduled_workflow_tick
   ```
   You should see one pending action per enabled scheduled workflow, with the workflow ID in the args column and a future `scheduled_date_gmt`.

2. **First run after the schedule fires.** Wait for the schedule to fire (or fast-forward in dev), then check:
   ```
   wp flowmint runs list
   ```
   (Or via the connector tool `flowmint_list_runs`.) The completed scheduled run should appear with `form_id=''`, `entry_id=0`, and status `completed`.

## Limitations of v0.6.0

These are deliberate scope cuts; they may be lifted in a later release based on demand:

- **No full cron expressions.** Only the four enum intervals.
- **No per-workflow timezone overrides.** All schedules use site-local time.
- **No "run now" button** for scheduled workflows in the admin UI. Use the existing `/runs/{id}/replay` endpoint to manually re-run any completed/failed run for debugging.
- **No catch-up policy.** If WP-Cron didn't fire for a stretch (low-traffic site, server downtime), missed ticks are lost — the next tick fires at its normal scheduled time. For high-reliability schedules on low-traffic sites, configure an OS-level cron via your host that hits `wp-cron.php` periodically.
- **No overlap protection.** If a scheduled workflow's previous run hasn't finished by the time the next tick fires, both run in parallel. For long-running scheduled work, chunk the work via `fre_list_entries`'s `limit` so each run completes quickly.

## When something goes wrong

Run history is the primary surface for diagnosing scheduled workflow problems. If a scheduled run fails:

- Open WP Admin → FlowMint Workflows → Run History.
- Find the failed run (filter by status = failed).
- The run detail view shows which step failed, the error code and message, and the context snapshot at the moment of failure.
- Use the **Replay** button to re-run after fixing the underlying issue.

If the workflow simply isn't firing on schedule:

- Check WP Admin → Tools → Scheduled Actions for a `fmw_scheduled_workflow_tick` action with your workflow ID. If it's missing, the daily reconciliation pass (which fires once every 24 hours) will re-register it; you can also force reconciliation immediately via `do_action('fmw_reconcile_scheduled_events')`.
- Confirm the workflow is `enabled` — disabled workflows have no cron event.
- Confirm `trigger.type === 'schedule'` and `trigger.interval` is one of the supported enum values.
- On a low-traffic site, ensure WP-Cron is firing. The `wp action-scheduler run` CLI command will manually process the queue.
