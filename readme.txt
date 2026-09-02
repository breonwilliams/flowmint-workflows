=== FlowMint Workflows ===
Contributors: flowmint
Tags: workflow, automation, form submissions, async, action scheduler
Requires at least: 5.6
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.6.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Async workflow runtime that turns Form Runtime Engine submissions into multi-step pipelines (Drive uploads, Printavo Quote creation, customer ack emails, conditional branches) without an external orchestrator.

== Description ==

FlowMint Workflows is a WordPress plugin that listens for `fre_submission_complete` (Form Runtime Engine's post-submission action) and runs configurable multi-step workflows asynchronously via Action Scheduler.

Use cases include:

* Uploading form attachments to a Google Drive folder.
* Creating Printavo customers and Quotes from a quote-request form.
* Sending templated customer acknowledgment emails.
* Conditionally branching on form values.
* Running HTTP requests against arbitrary REST APIs.

The plugin includes a built-in MCP connector so Claude Desktop / Claude Cowork can create and inspect workflows over the WordPress REST API.

**Companion to Form Runtime Engine** — FlowMint Workflows requires FRE 1.6.0+ to be active.

== Installation ==

1. Install and activate **Form Runtime Engine** (required dependency).
2. Upload this plugin folder to `/wp-content/plugins/` or install via WP Admin → Plugins → Add New → Upload Plugin.
3. Activate the plugin through the **Plugins** screen.
4. Run `composer install --no-dev` inside the plugin directory if vendor/ is missing (the build script handles this for distributed ZIPs).
5. Visit **FlowMint Workflows → Run History** to confirm setup.
6. To enable the Claude Cowork MCP connector, go to **FlowMint Workflows → Claude Connection** and follow the setup steps.

For credential storage (Drive service account, Printavo API token, Slack webhook), see the docs/ directory inside the plugin.

== Frequently Asked Questions ==

= Does FlowMint Workflows require Form Runtime Engine? =

Yes. FlowMint listens to `fre_submission_complete`, an action that FRE 1.6.0+ fires after a form submission is fully processed. Without FRE active, FlowMint shows an admin notice and does not initialize.

= Can workflows run synchronously? =

No. All workflows run async via Action Scheduler. The form submission returns immediately; the workflow is enqueued in a background job.

= Where are workflows stored? =

In custom database tables (`{$prefix}fmw_workflows`, `{$prefix}fmw_workflow_runs`, `{$prefix}fmw_workflow_run_steps`). Workflow definitions are JSON; runs and per-step records are structured rows.

= How are credentials secured? =

Sensitive credentials (Drive service account JSON, Printavo API token) are encrypted at rest via the WordPress salts. The connector REST API never exposes plaintext values — it only reports whether each credential key is configured.

== Changelog ==

= 0.6.8 =
* Improved: the "Copy Command" button on the Connector setup screen now sits below the command block instead of overlaying it, fixing a tap-target overlap and a color-contrast issue.

= 0.6.7 =
* Internal: developer/AI reference documentation (AGENTS.md) is now maintained in the repository. No functional changes.

= 0.6.0 — 2026-05-15 =
* **New: Scheduled workflow triggers.** Workflows can now fire on a recurring schedule (`hourly` / `twicedaily` / `daily` / `weekly`) in addition to form submissions. Two trigger types in v0.6: `{ type: "form", form_id: "…" }` (existing pattern, explicit) and `{ type: "schedule", interval: "…", hour: …, minute: …, day_of_week: … }` (new). Existing form-triggered workflows are normalized into the new shape transparently; no JSON changes required.
* **New step types:** `fre_list_entries` (query FE entries by form, status, and age) and `fre_delete_entries` (bulk-delete, idempotent, per-id failure tolerant). Designed for retention workflows — typical chain is `fre_list_entries → fre_delete_entries → log_info`.
* **New class:** `FMW_Schedule_Listener` — registers Action Scheduler recurring events on workflow save/enable, unschedules on disable/delete, handles tick dispatch through to the existing executor.
* **New database column:** `wp_fmw_workflows.trigger_type VARCHAR(32) DEFAULT 'form'` plus index `idx_trigger_type (trigger_type, enabled)`. Existing `form_id` column made nullable. Migration is additive and idempotent.
* **New REST surface:** `/preflight` reports `supported_trigger_types: ["form", "schedule"]`; `/workflows` accepts a `trigger_type` filter; create/update accept the new `trigger` block at the wrapper level or inside the config JSON.
* **Daily reconciliation:** an `fmw_reconcile_scheduled_events` AS recurring action reconciles AS events with the workflows table, providing drift correction for the rare case where an event was lost.
* Full design contract: `docs/DESIGN_SCHEDULED_TRIGGERS.md`. User guide: `docs/SCHEDULED_WORKFLOWS.md`.

= 0.5.0 — 2026-05-10 =
* New: Claude Cowork MCP connector. Workflows can now be created, inspected, and replayed from Claude Desktop via the `FlowMint Workflows → Claude Connection` admin page. Includes 16 MCP tools mapping 1:1 to the existing REST endpoints.
* New: Two-gate security model — connector defaults to disabled site-wide; admin opts in via the kill-switch toggle.
* Plugin-checker compliance pass: wrapped exception messages in `esc_html()`, added `phpcs:disable/enable` blocks around repository SQL with dynamic table names, normalized file-system operations to WP_Filesystem where practical, and added context-specific `phpcs:ignore` reasons where direct PHP filesystem APIs are required (streaming uploads).

= 0.4.0-rc7 — 2026-05-03 =
* Added `drive_create_text_file` step type for creating small text/markdown/HTML files in Drive from in-memory strings.

= 0.4.0-rc6 — 2026-05-03 =
* Refactored Printavo client and step types to match Printavo's current GraphQL schema (Customer ↔ Contact relationship, quoteCreate mutation, payload shape changes). See CHANGELOG.md for full migration details.

= 0.3.x =
* Phase 3 connector + MCP work, expanded step library, hosted pressure testing.

= 0.2.x =
* Phase 2: Drive, Email, Printavo, HTTP step categories.

= 0.1.x =
* Phase 1: Core engine, DB schema, base step types, submission listener.

== Upgrade Notice ==

= 0.6.5 =
Adds failure notifications: when a workflow run permanently fails, FlowMint now alerts you via Slack (slack_webhook credential) or email (notification_email credential / admin email) with a link to inspect and replay the run. No schema or workflow changes; safe update from any 0.6.x.


= 0.6.1 =
Connector admin page UI polish — renamed to vendor-neutral "Connector" / "The FlowMint Connector" naming, new card-based layout with clearer 3-step setup flow. No behavior changes.

= 0.6.0 =
Adds scheduled workflow triggers (hourly / daily / weekly / twicedaily) and two step types for bulk-querying and deleting form entries. An additive, idempotent migration runs on first load. Existing workflows are unaffected; rolling back to 0.5.0 needs no database change.

= 0.5.0 =
Adds the Claude Cowork MCP connector and full Plugin Check compliance pass. No behavior changes for existing workflows.
