# FlowMint Workflows — AI Reference

**Status (as of 2026-07-04, version 0.6.4):** FlowMint is a **fully implemented, shipping plugin** — the "planning phase" language further down this doc is historical (see the corrected phase table and footer). v0.6.4's notable fix: control-flow steps (`conditional`) read pre-interpolation `raw_config` so comparison expressions aren't mangled by the interpolator. **FRE integration note:** FRE 1.8.0 renamed its PHP surface to `pforms_*` — FlowMint listens to `pforms_submission_complete` and uses `PForms_Entry`; any `fre_*` hook names appearing in older docs/comments refer to the pre-rename era. v0.6.0 added **scheduled workflow triggers** as a first-class capability — workflows can now fire on a recurring schedule (`hourly` / `twicedaily` / `daily` / `weekly`) in addition to the existing form-submission trigger. The motivating use case was a daily FRE entry retention sweep for 725 Print Lab, but the underlying trigger abstraction is general-purpose. Phases 1–3 complete with 99/99 smoke checks green; Phase 4 packaging complete, 725 production verified. **Read `docs/DESIGN_SCHEDULED_TRIGGERS.md` for the engineering contract and `docs/SCHEDULED_WORKFLOWS.md` for the user-facing guide.** All v0.5.x guarantees still hold — existing form-triggered workflows run unchanged. Phases 0–3 of the original v1.0 plan remain fully complete; v0.5.0's Claude Cowork MCP connector ships unchanged. **READ `docs/TROUBLESHOOTING.md` BEFORE adding a new connector or onboarding a new client** — it captures every gotcha the first 725 deployment surfaced (Printavo schema migration, GraphQL variables encoding, GoDaddy SMTP block, Drive service-account quota, etc.).

> **🗓️ Scheduled triggers (v0.6.0):** Workflows declare a `trigger` block in their config JSON. `{ type: "form", form_id: "…" }` is the existing form-triggered shape (pre-v0.6 workflows are normalized into this transparently). `{ type: "schedule", interval: "daily", hour: 2, minute: 0 }` is the new recurring shape. Scheduled runs use `entry_id = 0` and `form_id = ''` sentinels because they have no FE entry context. The schedule listener (`FMW_Schedule_Listener`) wires save/disable/delete hooks to register/unregister AS recurring events; a daily reconciliation action handles drift. Two new step types (`fre_list_entries`, `fre_delete_entries`) make the entry retention use case viable. Database schema bumped to v0.2.0 — additive ALTER (nullable `form_id` + new `trigger_type` column with composite index). Migration is idempotent and reversible.

> **💼 Licensing model (clarified 2026-05-10):** This plugin is **FREE**. No Freemius, no premium tier, no license gates. Only Promptless WP is sold; FlowMint, FRE, and PRE are all free and exist to add value to the Promptless ecosystem. The connector and every endpoint are gated only by WP user capability (`manage_options`) plus the connector kill switch — never by license state.

> **🤖 MCP connector (v0.5.0):** Claude Desktop bridge lives at `includes/Connectors/MCP/`:
> - `assets/flowmint-connector.js` — stdio MCP server, 16 tools mapping 1:1 to the REST endpoints under `flowmint/v1/connector/*`
> - `class-fmw-connector-admin.php` — admin page (App Password generate/revoke + Terminal install command)
> - `class-fmw-connector-settings.php` — kill-switch state class
>
> Setup flow for end users: WP admin → FlowMint Workflows → Claude Connection → toggle "Enable Claude Cowork Connection" → Generate Connection → copy the Terminal command, paste into macOS Terminal, restart Claude Desktop. Default state is **disabled**; `FMW_REST_Auth::require_manage()` checks both `manage_options` and the kill switch (with `/preflight` exempt so Claude can introspect "is this on?" without it being on). See `docs/MCP_CONNECTOR_SETUP.md` for full setup notes.

A WordPress plugin that turns FormEngine submissions into multi-step workflows — Drive uploads, Printavo Quote creation, customer ack emails, conditional branches, etc. — without requiring an external orchestrator like Zapier.

## What this plugin IS

- An async workflow runtime that listens to `pforms_submission_complete` (FormEngine's post-submission action; named `fre_submission_complete` before FRE 1.8.0)
- A library of reusable "steps" — pluggable units that do one thing each (find a Drive folder, upload a file, create a Printavo Quote, send an email)
- A workflow registry where each form_id can be wired to a workflow definition (JSON, stored in DB)
- A connector REST API + MCP tool layer so workflows can be created and managed by AI tools (Claude) the same way FormEngine forms are
- A FlowMint-internal admin UI for run history, replay, and debugging

## What this plugin IS NOT

- A visual workflow builder (no drag-and-drop UI for end users)
- A general-purpose iPaaS (this is a focused tool for FlowMint's client work, not a Zapier replacement for the world)
- A multi-tenant SaaS (each client has their own WordPress install)
- Synchronous execution (all workflows run async via Action Scheduler)
- Coupled to FormEngine's core (FormEngine ships separately and shouldn't know this plugin exists)

## System requirements

| Requirement | Version | Notes |
|-------------|---------|-------|
| WordPress | 5.6+ | Application Passwords API (connector) requires 5.6; `wp_timezone()` (scheduled triggers) requires 5.3 — raised from 5.0 per Plugin Check, 2026-07-11 |
| PHP | 7.4+ | Type hints, arrow functions, null coalescing |
| MySQL | 5.6+ / MariaDB 10.0+ | InnoDB required (transactional integrity for run history) |
| Form Runtime Engine (Promptless Forms) | 1.8.0+ (`FMW_REQUIRED_FRE_VERSION`) | Hard dependency — admin notice if missing. 1.8.0 minimum because that release renamed FRE's PHP surface to `pforms_*`/`PForms_*` |
| Action Scheduler | bundled | Used for async job processing; bundled in vendor/ |

## Documentation map

The full docs live in `docs/`. Read in roughly this order:

| Topic | File |
|-------|------|
| **Build plan, phases, scope** | `docs/ROADMAP.md` |
| **Technical architecture** | `docs/ARCHITECTURE.md` |
| **Step library reference** | `docs/STEP_LIBRARY.md` |
| **Scheduled workflows — user guide (v0.6.0+)** | `docs/SCHEDULED_WORKFLOWS.md` |
| **Scheduled workflows — engineering design contract** | `docs/DESIGN_SCHEDULED_TRIGGERS.md` |
| **Connector REST + MCP API** | `docs/CONNECTOR_API.md` |
| **FormEngine integration contract** | `docs/INTEGRATION_FRE.md` |
| **Example workflow patterns** | `docs/REFERENCE_PATTERNS.md` |
| **Google Drive setup (service account)** | `docs/SETUP_GOOGLE_DRIVE.md` |
| **Printavo API setup** | `docs/SETUP_PRINTAVO.md` |
| **Slack notification setup** | `docs/SETUP_SLACK.md` |
| **Migrating existing Zapier workflows** | `docs/MIGRATION_FROM_ZAPIER.md` |
| **Detailed patterns, examples, gotchas** | `docs/CLAUDE.md` |
| **🔧 First-deployment lessons + gotchas (READ FIRST)** | `docs/TROUBLESHOOTING.md` |

## Quick architectural summary

```
[ Form Submission ]
        ↓
[ FormEngine: validate, sanitize, store entry, attach files ]
        ↓
[ pforms_submission_complete action fires ]
        ↓
[ FlowMint Workflows listener: find workflow for form_id ]
        ↓
[ Action Scheduler: enqueue async job ]
        ↓ (returns immediately — user sees thank-you page)
        ↓
[ Async worker picks up job ]
        ↓
[ Workflow executor runs each step in sequence ]
        ↓
[ Step 1: find_or_create_customer (Printavo) ]
[ Step 2: find_or_create_folder (Drive) ]
[ Step 3: upload_file (Drive) ]
[ Step 4: create_quote (Printavo) ]
[ Step 5: send_email (customer ack) ]
[ Step 6: delete_entry (FormEngine cleanup) ]
        ↓
[ Run history: success ]
```

If any step fails, Action Scheduler retries with exponential backoff (workflow-level retry, default max 3, via `FMW_Workflow_Job::handle_failure()`). After max retries the run is marked failed and `fmw_workflow_run_failed` fires. **`FMW_Failure_Notifier`** (`includes/Core/class-fmw-failure-notifier.php`) listens at priority 100 and pushes a notification: Slack incoming webhook when the `slack_webhook` credential is configured (non-blocking POST), else email to the `notification_email` credential falling back to `admin_email`. Filters: `fmw_failure_notification_enabled`, `fmw_failure_notification_message`. The notifier is defensively wrapped — it can never break the failure path it observes. (There is still no general-purpose `FMW_Slack_Client` step for workflows to POST arbitrary messages — `docs/SETUP_SLACK.md`'s webhook setup section now serves the notifier.)

## Plugin file structure

```
flowmint-workflows/
  flowmint-workflows.php       # Main plugin file (currently a stub)
  CLAUDE.md                    # This file — AI-facing reference
  README.md                    # Public-facing readme
  CHANGELOG.md                 # Version history
  docs/                        # All design/reference docs
  includes/
    Core/                      # Workflow engine, executor, context, registry
    Steps/                     # Step library — one class per step type
    Connectors/                # External service clients (Drive, Printavo, Email, Http)
    Connectors/REST/           # Connector REST controllers (flowmint/v1/connector/*)
    Connectors/MCP/            # Connector admin page + assets/flowmint-connector.js (16-tool stdio MCP server — there is NO includes/Mcp/ PHP dir)
    Database/                  # DB schema, migrations, repositories, encrypted credential store
    Admin/                     # Admin UI (run history, replay)
  assets/                      # CSS/JS for admin UI
  languages/                   # i18n
  tests/
    Unit/                      # Step-level unit tests
    Integration/               # End-to-end workflow tests
  bin/
    build-release.sh           # Production zip builder (mirrors FRE pattern)
  composer.json
  phpunit.xml
  uninstall.php                # Cleanup on plugin deletion
```

All of the above is built and shipping (65 PHP class files under includes/ as of v0.6.4).

## Companion to FormEngine, not a fork

FlowMint Workflows DEPENDS ON Form Runtime Engine but lives as a separate plugin in a separate repo. Reasoning:

- **Single Responsibility:** FormEngine is a generic form runtime. It shouldn't know about Printavo or Drive. Coupling business-logic orchestration into FRE would pollute its core.
- **Distribution flexibility:** FormEngine may eventually be sold/distributed separately. The orchestration layer is FlowMint IP.
- **Update independence:** Workflow plugin iterates frequently; FormEngine should stay stable.
- **Clean integration contract:** The two plugins interoperate via documented WordPress hooks and class-level APIs (see `docs/INTEGRATION_FRE.md`).

## Naming conventions

- **Class prefix:** `FMW_*` (FlowMint Workflows)
- **Plugin slug / text domain:** `flowmint-workflows`
- **REST namespace:** `flowmint/v1/connector/`
- **Action prefix:** `fmw_*` (e.g., `fmw_workflow_run_started`, `fmw_workflow_run_completed`)
- **Filter prefix:** `fmw_*`
- **DB table prefix:** `{wp_prefix}fmw_*` (e.g., `wp_fmw_workflows`, `wp_fmw_workflow_runs`)
- **Option prefix:** `fmw_*`
- **MCP tool prefix:** `workflow_*` (matches the resource name, parallels `formengine_*` in FRE)

## Phased build status

| Phase | Description | Hours est | Status |
|---|---|---|---|
| 0 | Planning + scaffolding + design docs | 3 | ✅ Complete |
| 1 | Foundation (engine, DB, base steps, connector, MCP) | 14 | ✅ Complete |
| 2 | Drive + Email integrations | 10 | ✅ Complete |
| 3 | Printavo + HTTP integrations | 8 | ✅ Complete |
| 4 | 725 Print Lab migration | 6 | ✅ Complete (725 production verified) |
| 5 | Production polish | 12 | ✅ Complete through v0.6.4 (28 step types, scheduled triggers, encrypted credential store) |

Known gaps as of v0.6.4: no `FMW_Slack_Client` (Slack failure notifications documented but unbuilt), no `includes/Mcp/` PHP layer (superseded by the JS stdio connector under `includes/Connectors/MCP/`).

See `docs/ROADMAP.md` for detail on each phase.

## Key architectural decisions (locked)

These are documented in detail in `docs/ARCHITECTURE.md`. Quick summary:

1. **Async-only execution.** All workflows run via Action Scheduler. No synchronous mode in v1.
2. **Workflow definitions stored as JSON in DB.** Same model as FormEngine forms — created via MCP, edited via REST API, no GUI editor in v1.
3. **Per-client WordPress install.** No multi-tenancy. Each client gets their own plugin instance.
4. **MCP-first interface.** No client-facing admin UI. Only FlowMint-internal admin UI for debugging.
5. **Step library is code, workflow definitions are data.** New step types require code (a new Step class). New workflows just need a JSON definition.
6. **Tight integration with FormEngine via hooks + classes.** FlowMint Workflows is useless without FRE active.
7. **Companion plugin, not a fork.** Separate plugin folder, separate repo, separate version.
8. **Service-business focus, not industry-specific.** Universal primitives (Drive, Email, HTTP, conditionals) plus opinionated connectors (Printavo) added as clients require.
9. **Action Scheduler over wp_cron.** Industry-standard for async job processing in WordPress in 2026.
10. **No client-facing visual workflow builder.** FlowMint operates the plugin; clients only see the form.

## Critical guardrails for AI sessions working on this plugin

- **Plugin is IN PRODUCTION (v0.6.4, 725 Print Lab live).** Changes must not break running workflows: existing form-triggered workflows and their JSON configs are a compatibility contract. Schema changes require an `FMW_DB_VERSION` bump with an idempotent migration; step-config shape changes require normalization for stored definitions.
- **Do not modify FormEngine to integrate with this plugin.** FormEngine should not know FlowMint Workflows exists. Use only documented FRE hooks (`pforms_submission_complete`, `pforms_entry_created`, etc. — `pforms_*` since FRE 1.8.0) and public classes (`PForms_Entry`).
- **Workflow JSON is the source of truth, not PHP files.** Workflow definitions live in the DB, created via the connector REST API or MCP tools. PHP "workflow definitions" only exist in tests and reference examples.
- **Steps must be deterministic for a given context.** A step's execution should depend only on its config and the current run context. No global state, no static caches that survive across runs.
- **Async execution is mandatory.** Never block the form submission to wait for workflow completion. Action Scheduler is the only execution path in v1.
- **Failed steps must not corrupt state.** A step that fails mid-execution must leave external services in a consistent state OR mark the run as needing manual intervention. Idempotency keys + transactional patterns are required for state-changing steps.
- **No client-specific code in the plugin.** All client-specific configuration (Drive folder IDs, Printavo user IDs, email templates) lives in the workflow JSON, never in the plugin codebase.
- **Test coverage is required for v1 ship.** Each step type ships with unit tests. Each integration ships with at least one end-to-end test. Coverage target: >80%.

## Releasing New Versions

**Canonical release procedure: [`RELEASE.md`](RELEASE.md)** at the plugin root. That document is the single source of truth — version-stamp locations, pre-release checklist, build commands (including the `composer install --no-dev` step the build script depends on), tag pattern, `gh release create` invocation, post-release verification.

Special considerations FlowMint releases require:

- **FRE dependency check.** FlowMint requires `FMW_REQUIRED_FRE_VERSION` (currently 1.8.0 — the FRE release that renamed its surface to `pforms_*`). Bumping that constant is a breaking change; coordinate with FRE's release cadence.
- **Database schema version.** Schema-affecting changes must bump `FMW_DB_VERSION` to trigger the migration on next load — separate from the plugin version. The version is ALSO legitimately bumped without any DDL change to fire one-time idempotent upgrade tasks on existing sites (e.g. 0.3.0 exists solely to re-grant the `flowmint_manage_workflows` capability — there is intentionally no `migrate_to_0_3_0()`; see the constant's comment block in `flowmint-workflows.php`).
- **Vendor dir.** The build script runs `composer install --no-dev` inside the staged copy; Composer must be on PATH or the ZIP will ship with dev dependencies (or no vendor/ at all).

**One-line summary:** update version stamps in `flowmint-workflows.php` (header + `FMW_VERSION` constant), `readme.txt` (Stable tag + Upgrade Notice), and `CHANGELOG.md` → commit → `git tag v0.6.0` → `git push --tags` → `./bin/build-release.sh` → `gh release create v0.6.0 build/flowmint-workflows.zip --notes-file CHANGELOG.md`.

---

**Plugin status:** v0.6.4 in production (725 Print Lab live). All build phases complete: workflow engine, 28 step types, form + scheduled triggers, Drive/Printavo/Email/HTTP connectors, encrypted credential store, connector REST API + 16-tool MCP server, run-history admin UI with replay. Known gaps: Slack failure notifications (unbuilt), test coverage expansion. This line previously said "no runtime code yet" — that was stale planning-phase text; corrected 2026-07-04.
