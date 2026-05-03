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

### Option A: Admin UI (Phase 5+)

1. WP Admin → FlowMint Workflows → Settings
2. Section "Notifications"
3. Paste webhook URL into "Slack Webhook URL"
4. Optionally set "Notification rules" (e.g., "Notify after 3 consecutive failures" — defaults to "Notify on every permanent failure")
5. Click "Save"
6. Click "Send Test Notification" — Slack channel gets a test message

### Option B: REST API

```
PUT /wp-json/flowmint/v1/connector/credentials/slack_webhook
Authorization: Basic <base64 of user:apppassword>
Content-Type: application/json

{
  "value": "https://hooks.slack.com/services/T.../B.../..."
}
```

Test:
```
POST /wp-json/flowmint/v1/connector/credentials/slack_webhook/test
```

### Option C: Via Claude / MCP

> "Configure the Slack webhook URL: <paste>"

## Step 3: Set notification preferences (optional)

By default, FlowMint posts to Slack on EVERY permanent workflow failure. To customize, set the option `fmw_notification_rules` (Phase 5 admin UI exposes this; for now, set via WP CLI or directly in DB):

```php
update_option('fmw_notification_rules', [
    'on_first_failure' => true,           // notify immediately on first failure
    'on_consecutive_failures' => 0,        // OR after N consecutive failures (0 = disabled)
    'workflow_filter' => null,             // OR specific workflow_id (null = all)
    'min_severity' => 'failed',            // 'failed' or 'warning' or 'info'
    'rate_limit_minutes' => 5,             // dedupe identical errors within N minutes
]);
```

For typical FlowMint operations: `on_first_failure: true`, `rate_limit_minutes: 5` is sane.

## What the Slack message looks like

```
🔴 Workflow failed: 725-bulk-order-quote
Run ID: 42
Form: bulk-order-quote (entry 5)
Failed step: drive_upload_file
Error: Drive API timeout after 30s
Retries exhausted (3/3)

🔗 View run details: https://725printlab.com/wp-admin/admin.php?page=fmw-runs&run_id=42
```

The deep link goes to the run detail page in the client's WordPress admin, where FlowMint can:
- See the exact step config that ran
- See the partial output from successful steps
- See the error message and stack trace
- Manually replay once the issue is fixed

## Email notifications as fallback / alternative

If Slack isn't configured, the plugin falls back to email. Configure via:

```
PUT /wp-json/flowmint/v1/connector/credentials/notification_email
{ "value": "alerts@flowmint.dev" }
```

Same delivery rules, just email instead of Slack.

You can configure BOTH (Slack + email) — useful for redundancy or for stakeholders who prefer one or the other.

## Notification channels for v1

| Channel | Setup difficulty | Recommended |
|---|---|---|
| Slack incoming webhook | Easy (5 min) | ✓ Primary |
| Email (wp_mail) | None (uses WP defaults) | Fallback |
| Discord webhook | Easy (similar to Slack) | Phase 5 |
| Microsoft Teams webhook | Medium (different format) | Future |
| PagerDuty | Hard (full integration) | Future |
| Custom webhook (your own endpoint) | Easy (HTTP POST) | Phase 5 |

For v1, Slack + email are the focus. Other channels added as need arises.

## Notifications WITHIN workflows

Some workflows might WANT to send a Slack message as part of their normal flow (e.g., "new high-priority lead, ping the team"). For that, use the `slack_notify` step type (different from notification-on-failure):

```json
{
  "name": "high_priority_alert",
  "type": "slack_notify",
  "config": {
    "channel": "{{ env.slack_team_webhook }}",
    "message": "🚨 New high-priority lead from {{ data.full_name }}: {{ steps.create_quote.url }}",
    "skip_if": "{{ data.budget_range != '5000_plus' }}"
  }
}
```

(`slack_notify` is in the Phase 5 step library — used by workflows themselves, distinct from the failure-notification system.)

## Troubleshooting

### "Test notification" succeeds but real failures don't trigger Slack

Check `fmw_notification_rules`. If `on_first_failure: false` and `on_consecutive_failures: 5`, you need 5 failures in a row before notification fires.

### Webhook URL was working but stopped

Slack webhook URLs don't expire by default. Possible causes:
- Someone deleted the Slack app or revoked the webhook
- Channel was deleted/archived
- Workspace permissions changed

Re-create the webhook (Step 1) and update the credential.

### Notifications spam the channel

The default `rate_limit_minutes: 5` should prevent this. If it's still happening:
- A workflow might be failing in a tight retry loop
- Check the Action Scheduler queue for many queued actions of the same type
- Investigate root cause; pause the affected workflow temporarily via `PATCH /workflows/<id>` with `enabled: false`
