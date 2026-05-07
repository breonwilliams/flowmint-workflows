# Troubleshooting & Gotchas (First-Deployment Lessons)

Captured during the first FMW production deployment (725 Print Lab,
2026-05-03 → 2026-05-04). Each entry below cost real cycles to discover —
read this BEFORE adding a new connector, debugging a "but it works locally"
issue, or onboarding a new client.

## Connector & API gotchas

### Printavo: `variables` field MUST be a JSON object (not array)

PHP's `wp_json_encode([])` produces JSON `[]`, but Printavo's GraphQL
endpoint requires `variables` to be a JSON object (`{}`). Sending an empty
array literally crashes Printavo's API with a 500 Internal Server Error.

**Fix in code:** cast to `(object)` before serialization in the choke
point. See `FMW_Printavo_Client::query()` — variables is always
`(object) $variables` regardless of caller.

**Pattern to apply to other GraphQL connectors:** any new GraphQL
connector that accepts a variables map should cast to (object) before
encoding. Empty associative arrays serialize as `[]` in PHP unless cast.

### Printavo: requests need an explicit `Accept: application/json` header

`wp_remote_request` doesn't send an Accept header by default. Browsers do.
Without one, Printavo's web router falls back to HTML routing for the
POST and you get a 500 HTML error page back instead of the JSON GraphQL
error response. Same URL + same auth = different result depending on
whether Accept is present.

**Fix in code:** every Printavo request includes `'Accept' =>
'application/json'`. See `FMW_Printavo_Client::query()`.

**Pattern:** for any external API request that goes through
`wp_remote_request`, default to including an explicit Accept header
matching the response format you want. Don't rely on server inference.

### Printavo: schema field names migrated in v2

Pre-v2 → v2 renames we hit:
- `Account.name` → `Account.companyName`
- `Account.contactEmail` → `Account.companyEmail`
- `Contact.companyName` → REMOVED. companyName lives on `Customer` now.
  Traverse `contact.customer.companyName` to read it.
- `ContactCreateInput` → `ContactInput`. `contactCreate` mutation now
  requires a parent Customer ID: `contactCreate(id: ID, input: ContactInput)`
- `invoiceCreate` mutation → `quoteCreate` mutation, with
  `QuoteCreateInput` instead of `InvoiceCreateInput`. Required fields:
  `contact: IDInput!`, `customerDueAt: ISO8601Date!`,
  `dueAt: ISO8601DateTime!`. The Quote attaches to a Contact, not a
  Customer (Printavo derives the Customer from the Contact's relationship).
- Quote fields: `customerNote` is the long-form description (not
  `description`), `productionNote` is the internal note (not
  `production_note`), `owner: IDInput` is the assignee (not `user_id`).

**Mutation responses are the entity directly, NOT a payload wrapper.** The
old pattern `mutation { contactCreate(input: ...) { contact { id } errors { ... } } }`
no longer works. Use `mutation { contactCreate(...) { id email firstName ... } }` —
select fields directly.

**General lesson:** for any new GraphQL connector, run schema
introspection (`__type(name: "X") { fields { name type { name } } }`) AS
THE FIRST STEP before writing the client. Don't trust pre-existing docs;
the live schema is authoritative.

### Printavo: account API access has a separate "active" state

The web UI works but every API query returns "Unauthorized: Account not
active." Burst API usage during testing or a plan-tier change can flip the
account into this state. The web UI continues working unaffected, which
makes it look like our code is broken.

**Diagnostic:** if the same query that worked an hour ago suddenly returns
"Account not active" for ALL endpoints (account, contacts, customers,
mutations), it's account-level. Check Printavo's plan/billing page or
contact Printavo support.

## Storage & file system gotchas

### Google Drive: service accounts have ZERO storage quota in non-Workspace contexts

By default, service accounts in a standalone GCP project have no Drive
storage quota. They can create folders (which don't consume storage) but
cannot create FILES of any size — even a 100-byte text file fails with
"Service Accounts do not have storage quota. Leverage shared drives, or
use OAuth delegation instead."

**This affects ALL Drive file operations:** `drive_upload_file` (customer
design uploads), `drive_create_text_file` (submission records), any future
file-creation step.

**Three resolution paths** (Google's recommended order):
1. **Workspace Shared Drive** (cleanest, no code changes) — files in
   Shared Drives consume the org's pool, not any individual's quota.
   Service accounts are allowed to own files there. Move target parent
   folders into a Shared Drive, share the Drive (not the folders) with the
   service account email.
2. **OAuth user delegation** — switch from service-account auth to OAuth
   user-impersonation flow. Requires Workspace admin to enable
   domain-wide delegation. Files end up owned by the impersonated user.
3. **Switch from service account to user OAuth entirely** — client
   OAuth-authorizes FMW with their Google account; FMW does Drive
   operations as that user. No quota issue. Larger code change (~rc8 OAuth
   flow).

**Pattern:** for any new client onboarding, recommend Shared Drive setup
in the welcome doc. Don't assume a regular Drive folder will work.

### GoDaddy shared hosting blocks outbound SMTP on common mail ports

Port 587 (STARTTLS) and 465 (SMTPS) connections from GoDaddy shared
hosting servers to external SMTP servers (smtp.gmail.com, etc.) hit
"Connection timed out". GoDaddy actively blocks these as anti-spam.

**Symptoms:** WP Mail SMTP test email fails with `Connection timed out`
even though credentials are valid.

**Fix:** use a transactional email service that delivers via HTTPS API
(port 443) instead of SMTP — SendLayer, SendGrid, Mailgun, Brevo, Postmark,
Resend. WP Mail SMTP has dedicated mailers for each. HTTPS is never
blocked because the entire web depends on it.

**For client onboarding:** never assume SMTP-from-host works. Plan on a
transactional service from day one OR confirm the host explicitly allows
outbound SMTP before committing to it.

## WordPress / Action Scheduler gotchas

### WP-cron is unreliable; install a real cron for production

Action Scheduler depends on WP-cron firing, which only happens when
someone hits a frontend page. On low-traffic sites, queued workflow runs
can sit for 10+ minutes before AS picks them up. Real customer
submissions might trigger frontend hits more reliably than admin
submissions, but it's still flaky.

**Fix per client:** add an OS-level cron via the host's cron management
(GoDaddy: cPanel → Cron Jobs):

```
* * * * * curl -s https://CLIENT_DOMAIN/wp-cron.php > /dev/null 2>&1
```

This runs every minute and triggers wp-cron, which triggers Action
Scheduler. Workflow runs become near-instant after submission.

**For client onboarding:** include cron setup as part of the deployment
checklist. Don't ship FMW to production without a real cron.

### Composer's `platform_check.php` can lock the plugin to one PHP version

Composer's autoloader generates a `vendor/composer/platform_check.php`
that aborts plugin loading if the installed PHP version doesn't match the
build environment. If the build host has PHP 8.1 and the production host
has PHP 8.0, the plugin fatals.

**Fix in `composer.json`:**

```json
"config": {
  "platform-check": false
}
```

This produces a no-op `platform_check.php`. Plugin loads on any PHP 7.4+
host regardless of build host's PHP version.

### Action Scheduler must be `require_once`'d, not just Composer-autoloaded

Composer's PSR-4 autoload is NOT enough for Action Scheduler. AS uses
its own `ActionScheduler_Versions` loader to deduplicate across plugins
(every plugin can ship its own copy; the highest version at runtime
wins). To register your copy, you must explicitly:

```php
require_once FMW_PLUGIN_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
```

If you skip this, `as_enqueue_async_action()` is undefined even though
Composer can autoload AS classes. Workflows queue but never dispatch.

## Build & deployment gotchas

### Drive folder owned-by-service-account doesn't appear in regular Drive search

When the service account creates a folder inside a parent that's owned by
a real user (e.g., Roderick's My Drive), the new folder is OWNED by the
service account. The user's Drive UI shows it inside the parent (because
the parent is theirs), but Drive's search box defaults to "My Drive" only
and excludes service-account-owned items.

**Symptom:** "I created a folder via the API but I can't find it in
search."

**Fix for diagnostics:** navigate by URL to the parent folder ID, then
look at the file list. Don't rely on search.

**Fix for production:** when the storage moves to a Shared Drive (which
fixes the quota issue), ownership becomes "Shared Drive" not "service
account" and search works normally.

### Workflow JSON validation passes locally but fails on first POST to production

The `validate()` endpoint (used by `/test`) checks step types and step
config shape. The full `validate_full()` check (run on POST) ALSO
verifies that `form_id` exists in FormEngine. A workflow may pass `/test`
with the local FRE registry but fail POST on production if the form_id
isn't registered there yet.

**Fix:** verify form_id exists on the target environment BEFORE calling
POST workflows. Use `formengine_list_forms` to confirm.

## Patterns for adding a new connector

Apply these proactively when shipping the next industry connector
(HousecallPro, ServiceTitan, HoneyBook, etc.):

1. **Run schema introspection FIRST** (for GraphQL connectors). Verify
   field names against the live API, not docs.
2. **Cast input maps to `(object)` before encoding** for any GraphQL
   request that takes variables.
3. **Set explicit `Accept: application/json` header** on every request.
4. **Test ONE field at a time** when discovering schema. The error message
   on a single missing field is much clearer than on five.
5. **Build a test step with minimal config** (e.g. `account { id }`) to
   isolate auth from query shape.
6. **Treat connector errors as structured codes**, not free-text
   messages. Make sure each connector's exception throws map to
   `auth_failed`, `external_4xx`, `external_5xx`, `rate_limited`,
   `invalid_input`, etc.
7. **Document the schema migration risk in the connector's setup doc.**
   APIs evolve; what worked at write time may not work at read time.
