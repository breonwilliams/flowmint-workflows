# Setup: Printavo

How to configure FlowMint Workflows' Printavo integration. One-time setup per client (or per FlowMint, if FlowMint manages all client Printavo accounts under one API token — depends on the relationship).

## Overview

Printavo's API is a GraphQL endpoint at `https://www.printavo.com/api/v2`. Authentication is via an API token issued from the Printavo admin UI.

FlowMint Workflows uses the API token to:
- Find customers by email
- Create customers
- Create Quotes / Invoices

The plugin's `FMW_Printavo_Client` class wraps the GraphQL queries with FlowMint conventions: structured error handling, rate limit awareness (429 backoff), idempotency for state-changing operations.

## Prerequisites

- A Printavo account with API access (Printavo Pro plan or higher — verify in current Printavo pricing)
- Admin permission on that Printavo account
- The client's WordPress install with FlowMint Workflows active

## Step 1: Generate an API token in Printavo

1. Log into Printavo (the user with admin permission)
2. Navigate to Account Settings → API & Webhooks (exact path may vary by Printavo version)
3. Click "Generate API Token" or "New Token"
4. Name: `FlowMint Workflows`
5. Permissions: full read + write (the plugin uses customer + invoice mutations)
6. Click Create
7. Copy the token — it's shown ONCE. Save it securely.

If the user can't find the API & Webhooks section, the account may not have API access on its current plan. Contact Printavo support.

## Step 2: Note the Printavo IDs you'll need

Workflow definitions reference Printavo internal IDs for users (sales reps), invoice statuses, etc. You'll need these from the Printavo admin UI:

### User ID (sales rep who "owns" the created Quote)

1. Printavo → Settings → Team
2. Find the user (e.g., the customer service rep handling inquiries)
3. The user ID is in the URL when you click their profile

For 725 Print Lab: User ID `60522` (Alexis Lilly).

### Invoice Status ID

1. Printavo → Settings → Invoice Statuses
2. Find the status that should be applied to website-submitted Quotes (typically named something like "Quote - Inquiry" or "New Lead")
3. The status ID is in the URL when you click to edit it

For 725 Print Lab: Invoice Status ID `416419` (named "Quote - JotForm Inquiry" — legacy name from before this plugin existed).

### Customer ID for testing (optional)

For workflow testing, it helps to have a known test customer. Either create one explicitly via the Printavo UI, or use an existing customer's ID.

For 725 Print Lab: Test Customer ID `10706641` ("TEST — Acme Corp"). Created during the Zapier-era testing; not yet cleaned up.

## Step 3: Configure FlowMint Workflows with the credential

### Option A: WordPress admin UI (Phase 5 onward)

1. WP Admin → FlowMint Workflows → Settings
2. Section "Printavo"
3. Paste the API token into the "API Token" field
4. Click "Save"
5. Click "Test Connection" — should report the connected Printavo account info

### Option B: REST API

```
PUT /wp-json/flowmint/v1/connector/credentials/printavo_api_token
Authorization: Basic <base64 of user:apppassword>
Content-Type: application/json

{
  "value": "<paste API token here>"
}
```

Test:
```
POST /wp-json/flowmint/v1/connector/credentials/printavo_api_token/test
```

### Option C: Via Claude / MCP

> "Configure the Printavo API token credential. Here's the token: <paste>"

Claude calls `workflow_credentials_set` then `workflow_credentials_test`.

## Step 4: Verify with a trivial workflow

```json
{
  "version": "1.0",
  "title": "Printavo smoke test",
  "form_id": "<any test form>",
  "enabled": false,
  "steps": [
    {
      "name": "find_test_customer",
      "type": "printavo_find_customer",
      "config": {
        "email": "test@example.com"
      }
    }
  ]
}
```

The `printavo_find_customer` is read-only — safe to test without creating any production data.

## How idempotency works for Printavo Quote creation

`printavo_create_quote` is the highest-risk step type because creating a duplicate Quote is more visible (and more annoying) than duplicating, say, a log line.

The plugin's idempotency strategy for Quote creation:

1. Each FlowMint workflow run has a unique `run_id` (the row ID in `wp_fmw_workflow_runs`)
2. When `printavo_create_quote` executes, it includes the `run_id` as a custom field on the Quote (Printavo supports custom fields on invoices)
3. On retry, before creating, the step queries Printavo for a Quote with this `run_id` custom field
4. If found, returns the existing Quote (no duplicate created)
5. If not found, creates the Quote and stores the `run_id`

This handles the case where the previous attempt created the Quote but failed to acknowledge success (e.g., network timeout after Printavo's response left their servers but didn't reach our worker).

If the Quote was created and Printavo's confirmation reached us, the run is marked `success` in `wp_fmw_workflow_runs` and won't retry. The idempotency check is the safety net for the rare timeout case.

## Rate limit handling

Printavo's API has rate limits (specific limits TBD — check Printavo docs for current values).

The `FMW_Printavo_Client` class:
- Maintains a per-second request counter
- If the counter approaches the limit, sleeps before sending
- On 429 response, waits the `Retry-After` duration (or 30s default) and retries up to 3 times
- After 3 failed retries due to rate limit, throws `FMW_Step_Exception` with `code = rate_limited` — Action Scheduler retries the whole step with longer backoff

For typical FlowMint client volumes (10-100 submissions/day), rate limits are unlikely to matter. They become relevant if a client has bursts of >50 submissions/minute, in which case workflow latency increases gracefully but no submissions are lost.

## Authentication errors

If the API token is invalid (wrong, expired, revoked):
- All Printavo steps fail with `code = auth_failed`
- FlowMint is notified
- The workflow run is marked failed

To fix: get a new token from Printavo (Step 1 again), update the credential.

## GraphQL queries used by the plugin

For transparency, the plugin uses these GraphQL operations:

### Find customer by email
```graphql
query FindCustomer($email: String!) {
  customers(filter: { email: $email }, first: 1) {
    edges {
      node { id firstName lastName email companyName phone }
    }
  }
}
```

### Create customer
```graphql
mutation CreateCustomer($input: CustomerCreateInput!) {
  customerCreate(input: $input) {
    customer { id email firstName lastName }
    errors { field message }
  }
}
```

### Create Quote / Invoice
```graphql
mutation CreateInvoice($input: InvoiceCreateInput!) {
  invoiceCreate(input: $input) {
    invoice {
      id
      visualId
      customerNote
      createdAt
    }
    errors { field message }
  }
}
```

(These signatures may differ slightly from current Printavo API — the plugin's `FMW_Printavo_Client` class is the source of truth for actual queries used.)

## Troubleshooting

### "Authentication failed"

Token is wrong or expired. Re-issue from Printavo and update via the credential API.

### "Customer with this email already exists" (when calling create_customer directly)

Use `printavo_find_or_create_customer` instead — it handles this case automatically.

### Created Quote doesn't appear in Printavo

Check the run history for the step's output (should include the Quote ID). Then search Printavo by that ID. If the ID exists in Printavo but doesn't match what the team expects to see — check the invoice status ID. Some Printavo accounts hide certain statuses from the default view.

### "Field 'X' not found in Printavo schema"

Printavo occasionally renames or restructures GraphQL fields. The plugin would need an update. Open an issue or update `FMW_Printavo_Client` to match.

## Security notes

- API tokens grant full account access — treat as passwords
- Stored encrypted in `wp_options` (AES-256-GCM)
- Never logged in plaintext
- Rotate annually OR whenever a team member with access leaves

## Multi-account scenarios

If FlowMint manages workflows for multiple clients on the same WP install (multi-tenant — NOT v1 architecture but possibly future), each client would need their own API token. The plugin would need a credential-per-workflow rather than credential-per-install. Out of scope for v1.

For v1's per-client-WP architecture: each WP install has ONE Printavo credential, used by all workflows on that install. Different clients = different WP installs = different credentials.
