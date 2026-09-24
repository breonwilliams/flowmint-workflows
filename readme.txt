=== FlowMint Workflows ===
Contributors: flowmint
Tags: workflow, automation, form submissions, async, action scheduler
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.12.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turns Promptless Forms submissions and schedules into multi-step workflows: email, HTTP, Google Drive, Printavo and conditions, run in the background.

== Description ==

FlowMint Workflows is a WordPress plugin that listens for `pforms_submission_complete` (Promptless Forms' post-submission action; Promptless Forms was formerly Form Runtime Engine) and runs configurable multi-step workflows asynchronously via Action Scheduler.

Use cases include:

* Uploading form attachments to a Google Drive folder.
* Creating Printavo customers and Quotes from a quote-request form.
* Sending templated customer acknowledgment emails.
* Conditionally branching on form values.
* Running HTTP requests against arbitrary REST APIs.

The plugin includes a built-in MCP connector so Claude Desktop / Claude Cowork can create and inspect workflows over the WordPress REST API.

**Companion to Form Runtime Engine** — FlowMint Workflows requires Promptless Forms 1.8.0+ to be active.

== Installation ==

1. Install and activate **Form Runtime Engine** (required dependency).
2. Upload this plugin folder to `/wp-content/plugins/` or install via WP Admin → Plugins → Add New → Upload Plugin.
3. Activate the plugin through the **Plugins** screen.
4. Run `composer install --no-dev` inside the plugin directory if vendor/ is missing (the build script handles this for distributed ZIPs).
5. Visit **FlowMint Workflows → Run History** to confirm setup.
6. To enable the Claude Cowork MCP connector, go to **FlowMint Workflows → Connector** and follow the setup steps.

Credentials (Drive service account, Printavo API token, Slack webhook, notification email) have no admin screen: they are set with `PUT /wp-json/flowmint/v1/connector/credentials/{key}`, which needs the connector enabled. See docs/CONNECTOR_API.md inside the plugin.

== Frequently Asked Questions ==

= Does FlowMint Workflows require Form Runtime Engine? =

Yes. FlowMint listens to `pforms_submission_complete`, an action that Promptless Forms 1.8.0+ fires after a form submission is fully processed. Without FRE active, FlowMint shows an admin notice and does not initialize.

= Can workflows run synchronously? =

No. All workflows run async via Action Scheduler. The form submission returns immediately; the workflow is enqueued in a background job.

= Where are workflows stored? =

In custom database tables (`{$prefix}fmw_workflows`, `{$prefix}fmw_workflow_runs`, `{$prefix}fmw_workflow_run_steps`). Workflow definitions are JSON; runs and per-step records are structured rows.

= How are credentials secured? =

Sensitive credentials (Drive service account JSON, Printavo API token) are encrypted at rest via the WordPress salts. The connector REST API never exposes plaintext values — it only reports whether each credential key is configured.

== Changelog ==

= 0.12.0 =
* Added: Promptless Forms' Form Entries list now shows what your workflow did with the team email — "Sent by workflow", "Workflow failed" or "Workflow running" — with a link to the run. Needs Promptless Forms 1.12.0. Before this the column had nothing to show, which looked like a delivery failure.

= 0.11.0 =
* Fixed: a run the server cut off part-way (time limit, fatal error, memory) stayed "running" forever, with no retry, no alert and none of the later steps. It is now recovered: retried from that step if it is set to on_error "retry", otherwise marked failed and alerted.
* Changed: each run asks for up to five minutes of PHP time, and Google Drive uploads go in 8 MB parts instead of 1 MB.
* Fixed: a run that could not be queued now sends the failure alert.
* Added: filters for the run time limit, the interrupted-run cut-off, the Drive chunk size and the Drive HTTP client (for testing without Google).

= 0.10.0 =
* Added: HTTP steps can authenticate with a stored credential (auth: { credential, scheme }) instead of a token written into the workflow.
* Changed: requires PHP 8.1. Deleting the plugin keeps workflows, runs and credentials unless FMW_REMOVE_ALL_DATA is set.
* Fixed: retries now run, on steps set to on_error "retry", and resume at the failed step; runs left waiting by earlier versions are marked failed so they can be replayed.
* Fixed: conditions mixing function calls with operators evaluate as written; try_catch steps see earlier values; workflow and form titles are filled in.

= 0.9.0 =
* Added: the ingest step `pre_upsert_records` maps a photo URL per record (`featured_image_url`). Post Runtime downloads each image once, reuses it on every later run and sets it as the record's featured image; a URL that fails is a per-record warning, not a failure. Requires Post Runtime Engine 0.10.0 or newer for the image.

= 0.8.1 =
* Fixed: four Plugin Check errors in the 0.8.0 package (unescaped exception messages in the ingest step). No behaviour change.

= 0.8.0 =
* Added: the ingest step, `pre_upsert_records`. Takes the records a previous step fetched from a system of record, maps each one with a per-record template, and creates or updates the matching Post Runtime record by source and external id — so re-running a scheduled workflow never duplicates and an unchanged record is never rewritten. Two guards make an upstream change loud instead of silent, and records the upstream stops returning are kept or unpublished, never deleted. Requires Post Runtime Engine 0.9.0 or newer.
* Changed: declares compatibility with WordPress 7.1.

WordPress truncates this section at 5,000 characters, so it keeps a rolling window of the
six most recent releases. The complete history lives in CHANGELOG.md in the plugin folder,
and on the GitHub releases page.

== Upgrade Notice ==

= 0.12.0 =
Promptless Forms' entry list now shows what your workflow did with the team email, with a link to the run (needs Promptless Forms 1.12.0). Existing workflows are unaffected.

= 0.11.0 =
Runs cut off by the server no longer stay "running" forever: they are retried or marked failed and alerted. Large Drive uploads get more time. Existing workflows are unaffected.

= 0.10.0 =
Requires PHP 8.1. Retries now actually run (for steps set to on_error "retry") and resume where they failed; runs stranded by earlier versions are marked failed for replay. Adds stored credentials for HTTP steps.

= 0.9.0 =
Imports can map a photo URL per record; Post Runtime downloads each image once and sets it as the featured image (needs Post Runtime Engine 0.10.0+). Existing workflows are unaffected.

= 0.8.1 =
Plugin Check clean-up of the 0.8.0 package; no behaviour change. Safe for all users.

= 0.8.0 =
Adds the Post Runtime ingest step (`pre_upsert_records`) for scheduled, duplicate-free imports from other systems; needs Post Runtime Engine 0.9.0+. Existing workflows are unaffected.

