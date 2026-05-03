# FlowMint Workflows — Roadmap

This document defines the phased build plan, scope boundaries, and success criteria for v1.0. It is the master strategy doc; every other planning doc in this folder elaborates a slice of what's defined here.

## Vision

FlowMint Workflows turns FormEngine submissions into multi-step automation pipelines without requiring an external orchestrator (Zapier, Make.com, etc.). Workflow definitions are JSON in the WordPress database, created and managed by Claude through MCP, and executed asynchronously via Action Scheduler. The plugin is internal FlowMint tooling — operated by FlowMint, invisible to end clients, used to deliver bespoke client workflows at speed.

## Why this is being built

The 725 Print Lab project consumed ~24+ hours of development time mostly because Zapier's UI is opaque to AI tools. Every workflow change requires Chrome MCP UI clicking. Every new client repeats the same friction. Three architectural patterns drive the alternative:

1. **Workflow-as-data.** JSON definitions instead of UI-built configurations. Claude generates them in seconds via MCP.
2. **Steps-as-code.** A library of reusable PHP classes (one per step type) that compose into any workflow.
3. **Runtime-in-WordPress.** Lives in the same WordPress install as the form, eliminating webhook latency and SaaS cost.

Result: new client workflows take 1-2 hours instead of 24+. No recurring Zapier subscription. Full ownership of the orchestration layer.

## Scope for v1.0

### In scope

- Workflow definitions as JSON in DB
- Async execution via Action Scheduler (retries with exponential backoff)
- ~24 step types covering FormEngine + Drive + Email + Printavo + HTTP + control flow + logging
- REST API + MCP tools for workflow CRUD and run history
- FlowMint-internal admin UI: run history list, run details, manual replay, settings
- Slack/email notifications to FlowMint when workflows fail
- 725 Print Lab migration as the v1.0 acceptance test (Bulk + Small workflows running in production via FlowMint Workflows, Zapier decommissioned)
- Production-grade: structured logging, error handling, idempotency for state-changing steps, test coverage >80%
- Comprehensive documentation in `docs/`

### Explicitly out of scope for v1.0

- **Visual workflow builder UI.** Workflows are created via MCP or REST. No drag-and-drop editor.
- **Client-facing admin UI.** End clients (Roderick, future clients) never see the workflow layer. FlowMint operates it.
- **Multi-tenant SaaS.** Each client has their own WordPress install with their own copy of the plugin.
- **Synchronous workflow execution.** All workflows are async. If a use case requires sub-second response, this plugin is the wrong tool.
- **Visual workflow monitoring dashboards (Grafana-style).** Run history table is enough for v1; richer dashboards deferred.
- **OAuth flows initiated by the plugin.** External services (Drive, Printavo) use service accounts or pre-issued API tokens, configured manually once.
- **Workflow versioning / rollback.** v1 supports a single "current" workflow per ID. Rollback is git-based on the JSON definition.
- **Branching / parallel execution.** v1 supports linear step sequences with conditionals. `for_each` and `parallel` step types are deferred to v2.
- **Custom step types created by clients.** v1's step library is fixed. Adding new step types requires a code change to the plugin (Phase 1+ effort each).
- **Sub-workflows / workflow composition.** A workflow cannot invoke another workflow in v1.
- **Scheduled workflows (cron-style triggers).** Only form submissions trigger workflows in v1.
- **Approval steps (workflow waits for human input).** Out of scope.

These deferred features can be added in v2+ as need arises. The architecture leaves room for them but doesn't pay the complexity cost upfront.

## Success criteria for v1.0

A v1.0 release is shippable when ALL of the following are true:

1. **725 Print Lab is fully migrated.** Both Bulk and Small workflows run in production via FlowMint Workflows. The corresponding Zaps are turned off and the Zapier subscription canceled.
2. **Functional equivalence verified.** The 725 workflows produce the same outputs as the previous Zapier setup: same Drive folder structure, same Printavo Quote fields, same email content, same FormEngine entry cleanup. Verified by parallel run for at least 5 real submissions.
3. **Failure recovery works.** A deliberately failed workflow run (e.g., Drive API outage simulated) shows up in the admin UI as failed, sends a notification to FlowMint, and can be manually replayed once the dependency recovers.
4. **MCP tool surface is complete.** Claude can list/create/update/delete workflows, view run history, replay failed runs, and introspect available step types — all without touching the admin UI.
5. **Documentation is complete.** A new developer (or new Claude session) can read `CLAUDE.md` + `docs/` and understand what the plugin does, how it integrates with FormEngine, how to add a new step type, and how to onboard a new client.
6. **Test coverage > 80%.** Unit tests for each step type. Integration tests for critical paths (a full 725-style workflow end-to-end with real Drive + Printavo sandboxes).
7. **Security reviewed.** API credentials encrypted at rest. No PII in logs. Capability checks on all admin endpoints. Nonces on form-driven actions.
8. **Performance verified.** A workflow with 10 steps completes within 30 seconds end-to-end under normal conditions. Action Scheduler queue depth stays under 100 jobs at peak.

## Phased build plan

| Phase | Title | Hours est | Cumulative | Deliverables |
|---|---|---|---|---|
| 0 | Planning + scaffolding | 3 | 3 | Plugin folder, git repo, design docs (this set) |
| 1 | Foundation | 14 | 17 | Engine, runner, DB, connector, MCP, 5 base steps |
| 2 | Drive + Email | 10 | 27 | Drive integration, email steps, file handling |
| 3 | Printavo + HTTP | 8 | 35 | Printavo GraphQL client, HTTP steps |
| 4 | 725 migration | 6 | 41 | 725 workflows, parallel run, cutover |
| 5 | Production polish | 12 | 53 | Admin UI, notifications, observability, tests, docs |

Total estimate: **~53 hours of focused development** to ship v1.0.

This is within the "polished 60-80 hour v1" budget agreed during planning, with 7-27 hours of buffer for the unknowns that always emerge.

### Phase 0 — Planning + scaffolding (3 hours, in progress)

**Goal:** Lay down the architecture and the design contracts that Phase 1+ will execute against. No runtime code.

**Deliverables:**
- Plugin folder structure created at `wp-content/plugins/flowmint-workflows/`
- Git repo initialized
- Stub `flowmint-workflows.php` with FormEngine dependency check
- All design docs in `docs/` (this is one of them)
- Empty `includes/` subfolders ready for Phase 1 to fill in

**Exit criteria:** Breon has reviewed all docs in `docs/` and signed off on the design. Any disagreements resolved by editing the docs (the docs are the contract; if the docs are wrong, code that follows them will also be wrong).

### Phase 1 — Foundation (14 hours)

**Goal:** A minimal but real workflow runtime that can execute simple workflows end-to-end. No external integrations yet — just FormEngine plumbing, control flow, and the framework that everything else hangs off.

**Deliverables:**
- `flowmint-workflows.php` bootstrap — autoloader, Action Scheduler dependency check, registers actions/filters/REST routes
- `includes/Core/`:
  - `class-fmw-workflow.php` — workflow definition, validation, schema enforcement
  - `class-fmw-workflow-registry.php` — DB-backed registry; load by ID, by form_id
  - `class-fmw-workflow-context.php` — runtime state during execution (data, entry, step outputs, variables)
  - `class-fmw-workflow-executor.php` — runs steps in order, handles errors, emits hooks
  - `class-fmw-workflow-job.php` — Action Scheduler job handler
  - `class-fmw-interpolator.php` — variable substitution (`{{ data.email }}`, `{{ steps.customer.id }}`)
  - `class-fmw-step-base.php` — abstract base class all steps extend
  - `class-fmw-step-registry.php` — registers + retrieves step types by name
- `includes/Database/`:
  - `class-fmw-schema.php` — DDL for `wp_fmw_workflows`, `wp_fmw_workflow_runs`, `wp_fmw_workflow_run_steps`
  - `class-fmw-workflow-repository.php` — CRUD on workflows
  - `class-fmw-run-repository.php` — CRUD + queries on runs
- `includes/Connectors/REST/`:
  - `class-fmw-rest-api.php` — registers `/wp-json/flowmint/v1/connector/...` routes
  - Endpoints for: workflow CRUD, run list, run detail, run replay, step type list, preflight
- `includes/Mcp/`:
  - PHP-side schema definitions for MCP tools (consumed by the connector tool registration mechanism)
- `includes/Steps/Core/` (5 base step types):
  - `class-step-set-variable.php`
  - `class-step-conditional.php` (if/then/else with simple expression evaluator)
  - `class-step-log.php` (info/warning/error to FRE_Logger)
  - `class-step-fre-get-entry.php` (load entry data into context)
  - `class-step-fre-delete-entry.php` (cleanup)
- `includes/Admin/`:
  - `class-fmw-admin.php` — registers admin menu
  - `class-fmw-admin-runs.php` — run history list table + detail view
  - `class-fmw-admin-replay.php` — manual replay handler
- Bootstrap activation hook that creates DB tables on first run
- Listener on `fre_submission_complete` that enqueues an Action Scheduler job
- `tests/Unit/` — at minimum: interpolator tests, executor happy-path test, conditional step test

**Exit criteria:** A trivial workflow (`set_variable` → `log` → `fre_delete_entry`) can be created via the REST API, fires when a form is submitted, runs to completion, and shows up in the admin run history. All without touching any external services.

### Phase 2 — Drive + Email integrations (10 hours)

**Goal:** Add the universal primitives — file handling and customer communication. These are the backbone of every service-business workflow.

**Deliverables:**
- `includes/Connectors/class-fmw-drive-client.php` — wraps the Google Drive PHP SDK with FlowMint conventions (service account auth, retry policy, error normalization)
- `includes/Steps/Drive/` (5 step types):
  - `class-step-drive-find-folder.php`
  - `class-step-drive-find-or-create-folder.php`
  - `class-step-drive-create-folder.php`
  - `class-step-drive-upload-file.php` (with chunked upload for files >5MB)
  - `class-step-drive-share-link.php` (set permissions, return shareable URL)
- `includes/Connectors/class-fmw-email-client.php` — abstraction over `wp_mail` with structured error capture
- `includes/Steps/Email/` (2 step types):
  - `class-step-send-email.php` (basic plain-text or HTML)
  - `class-step-send-email-template.php` (template file + variable interpolation)
- File lifecycle management: after a file is successfully uploaded to Drive, the local copy in `wp-content/uploads/` is deleted. Documented in `docs/ARCHITECTURE.md`.
- Settings UI: paste Drive service account JSON, test connection
- Documentation: `docs/SETUP_GOOGLE_DRIVE.md` is the operator's walkthrough
- Tests for all new steps

**Exit criteria:** A workflow that uploads a form-submitted file to Drive and sends a confirmation email runs end-to-end against a real (sandbox) Drive folder.

### Phase 3 — Printavo + HTTP integrations (8 hours)

**Goal:** Add Printavo (for 725 Print Lab) and generic HTTP steps (for any future client whose CRM/tool we haven't built a dedicated connector for).

**Deliverables:**
- `includes/Connectors/class-fmw-printavo-client.php` — Printavo GraphQL client (auth, query execution, error handling, rate limit awareness)
- `includes/Steps/Printavo/` (4 step types):
  - `class-step-printavo-find-customer.php`
  - `class-step-printavo-create-customer.php`
  - `class-step-printavo-find-or-create-customer.php`
  - `class-step-printavo-create-quote.php`
- `includes/Steps/Http/` (3 step types):
  - `class-step-http-get.php`
  - `class-step-http-post.php`
  - `class-step-http-request.php` (full method/headers/body control)
- Settings UI: Printavo API token entry, test connection
- Documentation: `docs/SETUP_PRINTAVO.md`
- Tests for all new steps (mocked + recorded fixtures for the Printavo GraphQL responses)

**Exit criteria:** A workflow that finds-or-creates a Printavo customer and creates a Quote runs end-to-end against the Printavo sandbox.

### Phase 4 — 725 Print Lab migration (6 hours)

**Goal:** Replace 725's Zapier-based workflows with FlowMint Workflows definitions. Decommission Zapier.

**Deliverables:**
- Workflow JSON definitions in `725 Print Lab/_FlowMint-Workflows-Migration/`:
  - `bulk-order-quote.json` — full Bulk workflow (10 steps)
  - `small-order-request.json` — full Small workflow (10 steps)
- Parallel run setup: FE webhook fires Zapier AND FlowMint Workflows simultaneously for verification period
- Verification checklist: 5+ real submissions confirmed equivalent in both systems
- Cutover: FE webhook switched from Zapier URL to internal action (FormEngine doesn't even need to fire a webhook anymore — FlowMint Workflows listens to the action directly)
- Zapier subscription cancellation
- Documentation: `docs/MIGRATION_FROM_ZAPIER.md` updated with 725 as worked example

**Exit criteria:** 725 production form submissions are processed exclusively by FlowMint Workflows, no Zapier involvement, equivalent outputs verified.

### Phase 5 — Production polish (12 hours)

**Goal:** Take v0.x to v1.0. Bulletproof what's there, document everything, set up FlowMint's operational tooling.

**Deliverables:**
- Admin UI improvements:
  - Run history with filtering (by workflow, status, date)
  - Search runs by entry content
  - Detailed run view with step-by-step expand/collapse, timing, output data
  - Bulk replay multiple failed runs
- Notification system:
  - Slack webhook integration for FlowMint
  - Email notifications when workflows fail permanently
  - Configurable notification rules (e.g., "notify only after 3 consecutive failures")
- Observability:
  - Structured logging via FRE_Logger or Monolog
  - Optional Sentry integration
  - Workflow run metrics (success rate, p95 duration, queue depth)
- Settings UI consolidation: single page for all global configs (Drive, Printavo, Slack, log level)
- Test coverage to >80% (unit + integration)
- Documentation polish: every doc in `docs/` reviewed and updated to reflect actual behavior
- v1.0 release tag, build-release.sh produces a clean zip

**Exit criteria:** All success criteria from the top of this doc are met. v1.0 is tagged.

## Migration plan for 725 Print Lab

Phase 4 is the migration itself. Approach:

1. **Pre-flight:** All FlowMint Workflows phases 0-3 complete. 725's existing Zaps still running production traffic.
2. **Parallel run setup:** Configure 725's FE form to ALSO fire on `fre_submission_complete` to FlowMint Workflows (not just to the Zapier webhook). Both systems process the same submission.
3. **Verification:** For 5-10 real submissions over a few days, manually compare:
   - FormEngine entry deletion timing (both should delete the entry)
   - Drive folder + file equivalence (FlowMint creates a separate folder during parallel run, named `[FMW-PARALLEL] {original folder name}` to avoid collision)
   - Printavo Quote equivalence (FlowMint creates a separate Quote during parallel run, prefixed `[FMW]` for visibility)
   - Email equivalence (FlowMint sends to a different test address during parallel run)
4. **Sign-off:** Roderick or Breon confirms FlowMint outputs are equivalent or better than Zapier.
5. **Cutover:** Disable the Zapier webhook on the FE form (`webhook_enabled: false`). FlowMint Workflows is now the sole processor. FlowMint switches its workflow config from "parallel mode" (separate folder/quote/email targets) to "production mode" (real folder/quote/email targets).
6. **Decommission:** Turn off the Zapier Zaps. Cancel the Zapier subscription after 30 days of clean FlowMint operation.

Risk mitigation: during parallel run, both systems do real work. Drive gets two folders, Printavo gets two Quotes. This is intentional — it's how we verify equivalence. Cleanup is part of the cutover.

## Future plans (post-v1.0)

These ideas are captured here so they don't get lost, but none are committed work for v1.0:

- **More step types:** ShopVox, QuickBooks, HubSpot, Mailchimp, Twilio, AirTable, Notion, Slack message posting (vs just notifications), DocuSign, Stripe payments
- **Visual workflow builder:** drag-and-drop UI for clients who want to self-service. Not v1 because most FlowMint clients won't want this.
- **Multi-tenant SaaS:** centralized workflow runtime serving multiple WP installs. Maybe never — per-client install is simpler operationally.
- **Workflow templates:** pre-built workflow JSONs for common patterns (lead-capture, quote-request, appointment-booking) that can be copied into a new client's install in seconds.
- **Real-time monitoring dashboard:** beyond the run history table, a live view of what's currently executing.
- **A/B testing of workflow versions:** route 10% of submissions to a new workflow, compare outcomes.
- **Workflow versioning + rollback:** git-style version history with the ability to roll back to a previous definition.
- **Sub-workflows:** a workflow can call another workflow.
- **Scheduled workflows:** cron triggers, not just form submissions.
- **Approval steps:** human-in-the-loop, workflow pauses for approval before continuing.
- **Open-source / commercial release:** distribute the plugin publicly (with a paid tier for premium connectors / support).

## How this plugin will evolve

The single most important architectural property to preserve as the plugin grows: **steps are isolated, composable, well-tested units**. Every new step type goes through the same contract:

1. New PHP class extending `FMW_Step_Base`
2. Implements `type()`, `display_name()`, `config_schema()`, `output_schema()`, `execute(FMW_Workflow_Context $ctx)`
3. Ships with at least one unit test
4. Documented in `docs/STEP_LIBRARY.md`

This contract keeps the plugin scalable. New clients with new tools = new step classes, no architectural rework.

The second most important property: **workflow JSON is forward-compatible**. v1.0 workflow definitions should still work in v2.0. We add new fields conservatively, never remove fields, and version the JSON schema if breaking changes ever become necessary.

---

**Phase 0 status:** in progress. This document is part of the deliverable. Other docs in this folder elaborate the design specifics. After Breon reviews and signs off on the design, Phase 1 begins.
