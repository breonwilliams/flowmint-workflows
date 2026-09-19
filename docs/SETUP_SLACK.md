# Setup: Slack notifications

How to wire FlowMint Workflows to send failure notifications to a Slack channel. Optional but strongly recommended for production.

## Why notifications matter

Workflows run async. If one fails permanently, nobody notices unless something tells them. Without notifications:
- A customer's quote request silently disappears (no email, no Drive folder, no Printavo Quote)
- Roderick wonders why his lead pipeline is dry
- FlowMint reputation suffers

With notifications:
- FlowMint gets a Slack ping the moment a workflow fails
- The ping includes a deep link to the run detail page for diagnosis
- Failed runs can be replayed once the underlying issue is fixed (e.g., Printavo was down for an hour)

## Architecture

FlowMint Workflows uses an **incoming webhook** for Slack — a URL provided by Slack that accepts POST requests with message JSON. No OAuth, no bot user, just a URL.

The plugin posts to this URL on workflow failure. The Slack channel receives a formatted message.

## Step 1: Create a Slack incoming webhook

1. Go to https://api.slack.com/apps
2. Click "Create New App" → "From scratch"
3. App Name: `FlowMint Workflows` (or whatever)
4. Workspace: pick FlowMint's Slack workspace
5. Click "Create App"
6. In the app's settings, click "Incoming Webhooks" in the sidebar
7. Toggle "Activate Incoming Webhooks" to On
8. Scroll down → click "Add New Webhook to Workspace"
9. Pick a channel (e.g., `#flowmint-alerts` — create one if needed)
10. Click "Allow"
11. Copy the webhook URL — looks like `https://hooks.slack.com/services/T...`

**Important:** the webhook URL is the credential. Anyone with it can post to that channel. Treat as a password.

## Step 2: Configure FlowMint Workflows with the webhook URL

There is no admin screen for credentials and no MCP tool that sets one: the REST route below is the only way. It requires the connector to be enabled (**FlowMint Workflows → Connector**) and an Application Password for a user with the `flowmint_manage_workflows` capability.

```
PUT /wp-json/flowmint/v1/connector/credentials/slack_webhook
Authorization: Basic <base64 of user:apppassword>
Content-Type: application/json

{
  "value": "https://hooks.slack.com/services/T.../B.../..."
}
```

The URL must start with `https://`; any other value is ignored and alerts go by email instead.

**The webhook cannot be tested from FlowMint.** `POST /credentials/slack_webhook/test` returns `400 not_testable`, and there is no "send test notification". To check it, post to the URL yourself (`curl -X POST -H 'Content-Type: application/json' -d '{"text":"test"}' <webhook URL>`), or make a disabled copy of a workflow fail on purpose with `settings.max_retries: 0` and replay it.

## When an alert is sent

One alert per run that fails **for good** (`fmw_workflow_run_failed`, fired by `FMW_Workflow_Job` after retries). There are no notification rules, thresholds or dedupe settings — every final failure alerts once. The only controls are two filters for code: `fmw_failure_notification_enabled` (return false to suppress, e.g. on staging) and `fmw_failure_notification_message` (rewrite the text).

**Two gaps to know about:**

- **Slack OR email, never both.** When `slack_webhook` is set, the alert goes to Slack only. The post is sent without waiting for Slack's reply, so if Slack rejects it (webhook revoked, channel archived) the alert is lost — no email is sent in its place (`FMW_Failure_Notifier`).
- **A run waiting to retry sends no alert yet.** A step with `on_error: "retry"` is retried first (after 1, 5, then 15 minutes); the alert goes out only if the last retry fails too. Steps with the default `fail` alert at once.

## What the Slack message looks like

Plain text, four lines:

```
FlowMint workflow FAILED after all retries: "725 Bulk Order → Printavo + Drive" on 725 Print Lab
Error: [external_5xx] Drive API timeout after 30s
Form entry: #5
Inspect + replay: https://725printlab.com/wp-admin/admin.php?page=fmw-runs&run_id=42
```

A scheduled run shows `Trigger: scheduled run (no form entry)` in place of the entry line. The link goes to the run detail page in the client's WordPress admin, where you can:
- See the exact step config that ran
- See the output from the steps that succeeded
- See the error code and message
- Replay once the issue is fixed

## Email instead of Slack

With no `slack_webhook` set, the alert is emailed (`wp_mail`) to the `notification_email` credential, or to the site's admin email when that is unset or not a valid address:

```
PUT /wp-json/flowmint/v1/connector/credentials/notification_email
{ "value": "alerts@flowmint.dev" }
```

Setting both does **not** send both — Slack wins. To get alerts by email, leave `slack_webhook` unset.

## Notification channels for v1

| Channel | Setup difficulty | Status |
|---|---|---|
| Slack incoming webhook | Easy (5 min) | Supported |
| Email (wp_mail) | None (uses WP defaults) | Supported — used only when Slack is not set |
| Discord, Teams, PagerDuty, custom webhook | — | Not built |

## Slack messages WITHIN workflows

A workflow that should post to Slack as part of its normal flow (e.g., "new high-priority lead, ping the team") uses an `http_post` step to a webhook URL. There is no `slack_notify` step type, and the `slack_webhook` credential cannot be read from a step (`{{ env.* }}` holds only `site_name`, `site_url` and `admin_email`), so the URL is written into the step — use a separate webhook from the alerts one:

```json
{
  "name": "high_priority_alert",
  "type": "http_post",
  "skip_if": "{{ data.budget_range != '5000_plus' }}",
  "config": {
    "url": "<TEAM_SLACK_WEBHOOK_URL>",
    "body": { "text": "New high-priority lead from {{ labels.full_name }}: {{ steps.create_quote.url }}" }
  }
}
```

`skip_if` belongs on the step, not inside `config`.

## Troubleshooting

### Real failures don't reach Slack

In order:
- Is the run actually **Failed**? A run sitting in **Queued** is waiting to retry and has not failed for good — see `TROUBLESHOOTING.md`, "Retries".
- Is the webhook still valid? Post to it by hand (Step 2). If Slack rejects it, FlowMint drops the alert silently.
- Does something on the site return false from `fmw_failure_notification_enabled`?

### Webhook URL was working but stopped

Slack webhook URLs don't expire by default. Possible causes:
- Someone deleted the Slack app or revoked the webhook
- Channel was deleted/archived
- Workspace permissions changed

Re-create the webhook (Step 1) and update the credential. Alerts sent in the meantime were lost.

### Notifications spam the channel

Each alert is one run that failed for good, so many alerts mean many failing runs — e.g., a scheduled workflow failing every hour, or many submissions hitting the same broken step. Investigate the root cause; pause the affected workflow temporarily via `PATCH /workflows/<id>` with `enabled: false`.
