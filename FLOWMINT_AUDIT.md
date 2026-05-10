# FlowMint Workflows — Architectural Audit

Prepared 2026-05. Same format as `FORM_RUNTIME_AUDIT.md` and `POST_RUNTIME_AUDIT.md`. Synthesizes a top-to-bottom code review against the documented architecture, with specific attention to the integration boundary with Form Runtime Engine and the credential-handling paths.

**Plugin version audited:** 0.4.0-rc7 (Phases 0–3 complete; Phase 4 — 725 Print Lab — deployed to production with workflow runs executing successfully end-to-end).

The bottom line up front: **FlowMint is the most disciplined of the three plugins audited this cycle.** The architecture document was written before code, and the code closely matches it. Decomposition is clean (no god objects), credential handling is industry-standard from the start (AES-256-GCM with random IV per encryption — better than FRE), the FRE integration boundary is explicit and one-way. The biggest gap is the same gap FRE had until recently: **zero automated test coverage.** And because FlowMint is in active production at 725 Print Lab, that gap carries higher business risk than FRE's did.

Findings graded **Critical** (do soon — actively limiting velocity or accepting real risk), **Important** (real improvements but not urgent), **Nice-to-have** (polish), and **Non-issue** (audit-flagged but verified as fine).

---

## Critical

### C1. No automated test coverage on a production plugin

**Where:** the entire plugin. `tests/` directory is empty. `phpunit.xml` is scaffolded but no test files exist.

**Why it's critical now (specifically: at v0.4.0-rc7 with 725 Print Lab in production):**

- FlowMint is **executing real workflows in production** — every code change carries direct risk to a paying client's daily operations. Without tests, every refactor and bug fix is a blind change.
- The plugin has 25+ classes across 6 subsystems (Core, Steps, Connectors, Database, Admin, Mcp). A change in one corner can ripple through dependencies that aren't obvious from a code reading.
- Idempotency is documented as critical (`docs/ARCHITECTURE.md` §"Idempotency") for steps that create external resources (Printavo Quotes, Drive folders). A regression in idempotency means a retry creates a duplicate Quote in production. There's nothing automated catching that.
- The FRE integration is the runtime entry point. A change to the listener (priority, hook name, error handling) immediately affects every workflow run. A test failure is the right way to catch that.
- Same situation FRE was in until this audit cycle, but with a higher-stakes "wrong = customer-visible bug" cost because of the production deployment.

**Recommended fix (mirrors what worked for FRE):**

- **Phase 0a** (security-sensitive paths first, ~6 hours): unit tests for `FMW_Credential_Store::encrypt`/`decrypt` round-trip + corruption + salt-rotation paths (4–6 tests), `FMW_Workflow_Validator` (5–8 tests covering schema rejection paths), `FMW_Interpolator` and `FMW_Expression` (the `{{ ... }}` substitution and skip_if evaluator are XSS / injection candidates — 8–12 tests covering missing-variable defaults, fallback `||`, expression operator precedence).
- **Phase 0b** (executor + integration, ~6 hours): integration tests against the WP test harness covering: submission listener picks up `fre_submission_complete` and enqueues correctly, executor runs steps in sequence, on_error policies (`fail` / `continue` / `retry`) behave per docs, run_step records get written with correct status.
- **Phase 0c** (per-step coverage, ~10 hours): one focused test per step class, exercising both happy path and primary error path. The step base class is small (138 lines) — a generic harness can cover most steps.

**Effort:** ~22 hours total for the phase-0 scaffolding. Nontrivial, but unblocks everything else AND closes the production-risk window the current zero-coverage state represents. Compare with FRE's audit C3 estimate (~25 hours) and PRE's C1 (~10 hours): same work, similar shape, similar order of magnitude.

---

## Important

### I1. Submission listener doesn't check Action Scheduler enqueue return value

**Where:** `includes/Core/class-fmw-submission-listener.php` line 77.

```php
as_enqueue_async_action(
    'fmw_run_workflow',
    [ $run_id ],
    'fmw' // group
);
```

**The problem:** `as_enqueue_async_action()` returns the action ID on success or `0` on failure. The listener doesn't check the return. If enqueueing fails (rare but possible — Action Scheduler insert failure, DB connection lost mid-request), the run row stays in `queued` state forever with no scheduled job to pick it up. Silent orphan rows accumulate.

**Why it matters for production:** the housekeeping job (`docs/ARCHITECTURE.md` §"Housekeeping") cleans up old run records by age, but a `queued`-forever row is genuinely confusing in the admin UI ("why is this stuck?") and harder to spot than a `failed` row. The condition is rare but the silent-failure mode is worse than the rare event itself.

**Recommended fix:**

```php
$action_id = as_enqueue_async_action( 'fmw_run_workflow', [ $run_id ], 'fmw' );

if ( $action_id === 0 ) {
    FMW_Run_Repository::mark_failed(
        $run_id,
        'enqueue_failed',
        'Action Scheduler returned 0 — could not enqueue async action.'
    );
    FMW_Logger::error( 'Workflow enqueue failed', [
        'run_id'      => $run_id,
        'workflow_id' => $workflow->id(),
    ] );
    return;
}
```

**Effort:** ~1 hour including a test that simulates the enqueue-failure path.

### I2. Credential store conflates "not configured" with "decryption failed"

**Where:** `includes/Database/class-fmw-credential-store.php` lines 30–43.

```php
public static function get( $key ) {
    // ...
    $stored = get_option( self::option_name( $key ), null );
    if ( $stored === null || $stored === false || $stored === '' ) {
        return null;
    }

    return self::decrypt( $stored );  // also returns null on failure
}
```

**The problem:** the `get()` method returns `null` for both "no credential stored" and "credential stored but couldn't be decrypted" (e.g., after a host migration that rotated `wp_salt`, or a corrupted bundle byte). Callers can't distinguish the two cases — the admin sees "Twilio not configured" when the real story is "Twilio credentials present but unreadable, please re-enter them."

This is the same audit finding as FRE's Twilio Wave 1 item I3, and the fix mirrors the one that just shipped for FRE: have `decrypt()` return `WP_Error` on failure (vs `null`), have `get()` propagate that distinction, and have the `is_configured()` method become a real "is the credential present and readable" check rather than just "is the option non-empty."

**Why it matters for production:** if a host migration loses the old salt or someone runs `wp salt regenerate` without realizing the consequences, EVERY connector silently looks unconfigured. Workflows queued against those connectors fail with auth errors, the admin re-saves credentials thinking they were missing, the old encrypted bundles sit orphaned in `wp_options`. With the fix, the admin sees an explicit "credentials unreadable, please re-enter" message and knows what happened.

**Recommended fix:** lift the FRE Twilio Wave 1 pattern — `decrypt_value()` returns `WP_Error('credential_unreadable')` on failure; `get()` propagates as `WP_Error`; `from_settings()`-equivalent code in connector clients distinguishes the two cases.

**Effort:** ~3 hours including unit tests for the new contract.

### I3. Printavo and Drive client error handling not visible in audit scope

**Where:** `includes/Connectors/class-fmw-printavo-client.php` (491 lines), `includes/Connectors/class-fmw-drive-client.php` (428 lines).

**Why it's flagged:** these are the largest connector classes and they touch external services with rate limits, auth tokens, and idempotency requirements. The architecture doc lays out clear contracts for each (5xx → retry, 4xx → fail, 429 → retry with extra delay, auth errors → notify admin), but **without tests, there's no guarantee the implementation matches the contract.** A single misclassified error type means workflows retry when they shouldn't (creating duplicate Printavo Quotes) or fail when they should retry (transient 5xx from Drive).

This isn't a "the code is wrong" finding — the code may be correct. It's a "the code is unverified" finding. The fix is the test scaffolding from C1.

**Recommended fix:** when C1 Phase 0c lands, the Printavo and Drive client tests should specifically exercise: 5xx → retry, 4xx → fail, 429 → rate-limit retry, auth error → fail-with-admin-notification. Mock the HTTP client at the wp_remote_request boundary.

**Effort:** included in C1 Phase 0c.

### I-NEW. Compound expressions with bare function calls don't work as documented (surfaced during Wave 1 testing)

**Where:** `includes/Core/class-fmw-expression.php` parse_and_evaluate(), and `includes/Core/class-fmw-interpolator.php` resolve_expression().

**The problem:** the architecture doc and step library examples show compound expressions like:

```
{{ length(data.notes) > 100 && !is_empty(data.full_name) }}
```

But these don't actually work in practice. The flow that breaks:

1. `FMW_Expression::evaluate()` strips the outer `{{ ... }}` → leaves `length(data.notes) > 100 && !is_empty(data.full_name)`
2. `is_simple_value_expression()` returns false (has `>` and `&&` at top level)
3. `parse_and_evaluate()` runs
4. The placeholder substitution looks for `{{ ... }}` blocks INSIDE the expression — but there are none, because the outer ones already got stripped
5. The tokenizer treats `length` as a bare identifier, falls back to context-path resolution, gets empty string
6. The comparison `'' > 100` evaluates to false (PHP coercion)
7. Whole expression silently returns wrong result

**Production impact:** any workflow in the wild that wrote a compound `skip_if` or conditional with bare function calls is silently getting incorrect skip decisions. The workflow doesn't crash — it just makes the wrong choice. Could be the difference between "send notification email" and "skip notification" depending on which side of the broken comparison the author wanted.

**Surfaced by:** `tests/Unit/ExpressionTest.php::test_length_used_in_comparison` — currently marked `markTestSkipped` with a pointer to this audit entry.

**Recommended fix:** add a function-call placeholder pre-pass in `parse_and_evaluate()` that runs BEFORE the existing `{{ }}` placeholder pass. Pattern: walk the expression looking for `name(args)` patterns, resolve each via `interpolator->resolve_function()`, replace with a `__FMW_VAL_N__` placeholder. The existing tokenizer already understands those placeholders.

Limitation note for whoever implements: the simple `name([^()]*)` regex doesn't handle nested function calls (`contains(data.tags, length(data.x))`). Either iterate until no more matches, or write a recursive parser. Iterate-until-stable is simpler and matches the "small expression evaluator" design philosophy.

**Effort:** ~3 hours including unit tests for the patched parser.

**Workaround until then:** workflow authors can split function calls into separate steps:

```json
{
  "name": "notes_length",
  "type": "set_variable",
  "config": { "var": "notes_len", "value": "{{ length(data.notes) }}" }
},
{
  "name": "decide",
  "type": "conditional",
  "config": { "if": "{{ vars.notes_len > 100 }}" }
}
```

The single-`{{ }}` form `{{ length(data.notes) }}` works fine via the interpolator's whole-string fast path; only compound expressions with bare function calls are affected.

---

### I4. Idempotency contract documented but not exercised

**Where:** `docs/ARCHITECTURE.md` §"Idempotency" describes the contract:

> Steps that create external resources (Printavo Quote, Drive folder, etc.) MUST be idempotent. The contract: each run has a unique `run_id`. Steps include the `run_id` in an idempotency key sent to the external service. On retry, the step checks if a resource was already created with this `run_id` and returns the existing resource instead of creating a new one.

**The problem:** I didn't trace each create-step to verify the contract is honored in code. With zero tests, there's no automated proof that:

- `printavo_create_quote` checks for an existing Quote with this run_id before creating
- `drive_create_folder` pre-checks by listing children of the parent
- `send_email` deduplicates via the documented SHA256 check + transient

For a workflow that runs once and succeeds, this doesn't matter. For a workflow that fails on step 7 of 10 and retries, it's the difference between "the customer gets one Quote" and "the customer gets multiple duplicate Quotes that someone has to clean up by hand in production."

**Recommended fix:** add a focused test per create-step verifying the idempotency contract. Each test runs the step twice with the same context (simulating a retry) and asserts: only one external resource was created, and both invocations returned the same resource ID.

**Effort:** included in C1 Phase 0c, but flag separately because it's the highest-stakes test category for a production plugin.

---

## Nice-to-have

- **N1.** Architecture doc mentions `FMW_DEBUG` constant for gating verbose logging. Confirm that constant is checked everywhere debug-level information might leak (credential values, full API responses, file contents).
- **N2.** No `bin/build-release.sh` script visible — but FRE has one and it's how production deployments are built. If FlowMint releases are being built manually, mirror FRE's build-release pattern.
- **N3.** Step-type registry uses string-keyed lookup (`get_class($step_type)`). A future v2 could move to interface-keyed registration (similar to FRE's field-type pattern) for stronger compile-time guarantees.
- **N4.** `FMW_Logger` is wrapped around `FRE_Logger` (per architecture doc) — if FRE is deactivated, what happens to FMW logging? A defensive `class_exists` check + WP-native `error_log` fallback would be more robust.
- **N5.** The expression evaluator (`class-fmw-expression.php`, 344 lines) is custom code with security implications (skip_if on submitted data → could a malicious form submission exploit a parser bug?). Worth a focused security review before v1.0 — but it's smaller than FRE's webhook validator and the surface is well-bounded.
- **N6.** The interpolator (`{{ var }}` substitution) is also custom code. Same comment as N5 — focused unit tests around edge cases (escape sequences, nested braces, missing variables) are worth pinning.

---

## Non-issues (audit-flagged but verified as correct)

### NI1. Credential encryption is industry-standard, not weak

`FMW_Credential_Store` uses **AES-256-GCM** (authenticated encryption — detects ciphertext tampering, unlike CBC which doesn't), with a **random IV per encryption operation**, a **per-install nonce** combined with `wp_salt('auth')` for key derivation, and a **versioned bundle format** (chr(1) prefix → forward-compatible if the cipher changes in v2). This is significantly better than FRE's Twilio implementation (which had deterministic IV — audit item CR1 in `docs/twilio/CREDENTIAL_ENCRYPTION_AUDIT.md`). FlowMint did the right thing from the start. The only related concern is I2 above (failure mode UX), which is small.

### NI2. Submission listener wires the correct hook at the correct priority

The listener uses `fre_submission_complete` (the modern hook that fires AFTER files are attached) at priority 100 (so other listeners that may modify entry data have already run). This avoids the staleness bug caught in FRE's `WebhookDispatchTest` during this audit cycle, and it's what `docs/INTEGRATION_FRE.md` documents.

### NI3. One-way dependency on FormEngine is honored cleanly

FlowMint reads from FormEngine (entry data, registry lookups, the submission hook). FormEngine has no awareness of FlowMint. Deactivating FlowMint leaves FormEngine fully functional. Deactivating FormEngine surfaces an admin notice and disables FMW's listener; FMW data is preserved for reactivation. This is exactly what `docs/INTEGRATION_FRE.md` promises.

### NI4. No god objects

Largest class is `FMW_Printavo_Client` at 491 lines. None of FRE's god-object classes (`FRE_Submission_Handler` at 995, `FRE_Connector_API` at 1159, `FRE_Renderer` at 982) have a counterpart here. Step library is well-decomposed (one class per step, each <130 lines). Architecture decisions made before code paid off.

### NI5. Async execution model is the right choice

Action Scheduler is the de-facto WordPress async-job standard in 2026. Choosing it over `wp_cron` means workflows survive low-traffic conditions, retry with exponential backoff is built in, and the scheduling surface is observable via Action Scheduler's own admin UI. The architecture doc's reasoning here is sound.

### NI6. Database schema is properly indexed

Three custom tables, all InnoDB, with sensible composite indexes (`(workflow_id, created_at)`, `(status, created_at)`, `(form_id, created_at)`). Single-statement queries should not become N+1 problems even at scale.

---

## Comparison with PRE and FRE (post-this-cycle)

| Aspect | FlowMint (v0.4.0-rc7) | FRE (post-Wave-1) | PRE (v0.3.0) |
|---|---|---|---|
| Bootstrap | Singleton on `plugins_loaded` | Same | Same |
| Largest class | `FMW_Printavo_Client` 491 lines | `FRE_Connector_API` 1159 lines | `PRE_Connector_API` 1333 lines |
| Test count | 0 | 224 unit + 93 integration | 53 unit + 43 integration |
| Credential encryption | AES-256-GCM, random IV, versioned bundle | AES-256-CBC, deterministic IV (Wave 2 will fix) | n/a (no credentials stored) |
| Production status | **Live at 725 Print Lab** | Pre-launch / dev sites only | Pre-launch / Phase 4 ready |
| Architecture-doc-first design | Yes | Partially | Yes |
| God object risk | None today | C1 + C2 flagged | C2 flagged (connector class) |

**Architectural verdict:** FlowMint is the cleanest of the three at the scaffolding level. The architecture-doc-first discipline paid off — the code closely matches the documented intent, decomposition is good, security choices are modern. The **single material gap is test coverage**, which compounds the risk of being in production. Test scaffolding for FlowMint is the highest-priority work across all three plugins right now, specifically because FlowMint is the only one currently running real customer workflows.

---

## Recommended sequencing

If you tackle this work, the right order is:

**Wave 1 (do first — closes the production-risk window):**
1. **C1 Phase 0a** — security-sensitive unit tests (credential store, validator, interpolator, expression). ~6 hours. Same shape as FRE's Wave 1 work this cycle.
2. **I2** — credential store WP_Error contract on decryption failure. ~3 hours. Matches the FRE Twilio Wave 1 fix already shipped.
3. **I1** — submission listener checks enqueue return value. ~1 hour, plus test.

**Wave 2 (next — completes the safety net):**
1. **C1 Phase 0b** — executor + listener integration tests. ~6 hours.
2. **C1 Phase 0c** — per-step tests with idempotency assertions for create-steps. ~10 hours.

**Wave 3 (later):**
1. **N1–N6** — polish items as time permits.
2. Any architectural enhancements for v1.0 (interface-keyed step registry, etc.).

Wave 1 is genuinely independent of Waves 2/3 and can ship in a single focused session. **Treat it as the prerequisite for any larger refactor or feature work on the plugin while it's running in production.** The credential-store + listener fixes are pure-win improvements with no compatibility risk; the test scaffolding is what makes the bigger Wave 2/3 work safe.

---

**Architectural recommendation for v1.0 ship:** before v1.0 (and especially before onboarding a second production client beyond 725 Print Lab), close C1 Phase 0a + 0b minimum. Onboarding a second client onto an untested production plugin doubles the blast radius of any latent bug. The test scaffolding work is what moves the plugin from "works for one client we monitor closely" to "works for clients we onboard and stop watching."
