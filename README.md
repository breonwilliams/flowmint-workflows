# FlowMint Workflows

Async workflow runtime for WordPress. Companion to [Form Runtime Engine](../form-runtime-engine).

**Status:** v0.6.0-rc1 — scheduled triggers landing. See `CHANGELOG.md` for the release notes and `docs/ROADMAP.md` for the longer-term build plan.

## What it does

Triggered by either a FormEngine submission OR a recurring schedule (hourly / twicedaily / daily / weekly), runs configurable multi-step pipelines:

- Upload files to Google Drive
- Create Printavo Quotes / Invoices
- Send customer acknowledgment emails
- Hit external APIs (HTTP/webhooks)
- Bulk-query and delete FormEngine entries (retention sweeps — new in v0.6.0)
- Conditional branching, retries, error handling
- All async via Action Scheduler

See `docs/SCHEDULED_WORKFLOWS.md` for the scheduled-trigger guide.

Workflow definitions are JSON, stored in the WordPress database, created via REST API or MCP tools. No client-facing UI — operated by the FlowMint team.

## Why this exists

Replaces external orchestrators (Zapier, Make.com) for FlowMint client workflows. Benefits:

- **No recurring SaaS cost.** Workflows live in the client's own WordPress install.
- **AI-friendly.** Workflows are JSON, created via natural language through Claude + MCP.
- **No vendor lock-in.** All logic owned by FlowMint, version-controlled, portable.
- **Fast to iterate.** Adding a new field to a workflow = one DB update, not a UI session.

## Documentation

See `CLAUDE.md` (top-level) and `docs/` for full reference. The roadmap is in `docs/ROADMAP.md`.

## License

GPL-2.0-or-later. Source code is internal to FlowMint LLC. Distribution decisions deferred until v1.0 ships.
