# FlowMint Workflows — AI Patterns & Gotchas

For AI sessions working ON this plugin's codebase (writing or modifying its source). For AI sessions creating workflow definitions (using the plugin), see `STEP_LIBRARY.md` + `REFERENCE_PATTERNS.md`.

## Writing new step types

The most common reason to touch this plugin's code is adding a new step type (e.g., a client needs HubSpot integration that isn't in the v1 library). Follow the established contract.

### Skeleton

```php
<?php
/**
 * Step: <Display Name>
 *
 * <One-paragraph description of what this step does and when to use it.>
 *
 * @package FlowMintWorkflows
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FMW_Step_<Type_Name> extends FMW_Step_Base {

    public static function type(): string {
        return '<service>_<verb>_<noun>'; // snake_case, unique
    }

    public static function display_name(): string {
        return '<Service>: <Action description>';
    }

    public static function description(): string {
        return 'One-paragraph description for admin UI and step-types REST endpoint.';
    }

    public static function config_schema(): array {
        return [
            'type' => 'object',
            'required' => ['<required_field_name>'],
            'properties' => [
                '<required_field_name>' => [
                    'type' => 'string',
                    'description' => 'What this field is for.',
                ],
                '<optional_field_name>' => [
                    'type' => 'string',
                    'default' => '<default value>',
                    'description' => '...',
                ],
            ],
        ];
    }

    public static function output_schema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string'],
                'created_at' => ['type' => 'string', 'format' => 'date-time'],
                // ...
            ],
        ];
    }

    public static function has_side_effects(): bool {
        return true; // false for read-only steps
    }

    public function execute( FMW_Workflow_Context $context ): array {
        // Read interpolated config (already substituted by executor)
        $config = $this->config;

        // Read entry / data / steps from context
        $email = $config['email'];

        // Get connector client (singleton, lazily-loaded credentials)
        $client = FMW_Service_Client_Registry::get( '<service_name>' );

        // Idempotency: if this is a retry, check if a previous attempt already created the resource
        $idempotency_key = $context->get_run_id() . ':' . $this->step_name;

        try {
            $result = $client->do_thing( $email, [
                'idempotency_key' => $idempotency_key,
            ] );
        } catch ( FMW_Service_Auth_Exception $e ) {
            throw new FMW_Step_Exception( 'auth_failed', $e->getMessage() );
        } catch ( FMW_Service_Rate_Limit_Exception $e ) {
            throw new FMW_Step_Exception( 'rate_limited', $e->getMessage(), [ 'retry_after' => $e->retry_after ] );
        } catch ( FMW_Service_Exception $e ) {
            throw new FMW_Step_Exception( 'external_' . ( $e->status >= 500 ? '5xx' : '4xx' ), $e->getMessage() );
        }

        // Return structured output (matches output_schema)
        return [
            'id' => $result->id,
            'created_at' => $result->created_at->format( DATE_ATOM ),
        ];
    }
}

// Register the step at plugin boot.
FMW_Step_Registry::register( FMW_Step_<Type_Name>::class );
```

### Required tests for any new step

In `tests/Unit/Steps/<Category>/<Name>Test.php`:

1. **config_schema validation:** invalid configs are rejected
2. **happy path:** valid config + mocked client → expected output
3. **error handling:** mocked auth failure → throws `FMW_Step_Exception` with `code=auth_failed`
4. **idempotency:** retry with same run_id returns existing resource (mocked client behavior)
5. **interpolation:** config with `{{ vars }}` works correctly

### Documenting the step

Update `docs/STEP_LIBRARY.md` with:
- Display name + category
- Side effects
- Config schema (JSON example)
- Output schema (JSON example)
- One usage example
- Idempotency notes if applicable

## Writing new service connectors

When adding a service that doesn't exist yet (e.g., adding ShopVox alongside Printavo for a future client):

### Skeleton

```php
class FMW_<Service>_Client {
    private $api_token;
    private $http_client;

    public function __construct( string $api_token ) {
        $this->api_token = $api_token;
        $this->http_client = new FMW_Http_Client( /* config */ );
    }

    public static function from_credentials(): self {
        $token = FMW_Credential_Store::get( '<service>_api_token' );
        if ( empty( $token ) ) {
            throw new FMW_Service_Auth_Exception( 'Credential <service>_api_token not configured' );
        }
        return new self( $token );
    }

    public function find_thing( string $key ): ?array {
        try {
            $response = $this->http_client->get( "https://api.<service>.com/v1/things/{$key}", [
                'headers' => [ 'Authorization' => "Bearer {$this->api_token}" ],
            ] );
        } catch ( FMW_Http_4xx_Exception $e ) {
            if ( $e->status === 404 ) return null; // not found is not an error
            if ( $e->status === 401 ) throw new FMW_Service_Auth_Exception( 'Invalid token' );
            throw new FMW_Service_Exception( $e->getMessage(), $e->status );
        } catch ( FMW_Http_5xx_Exception $e ) {
            throw new FMW_Service_Exception( $e->getMessage(), $e->status );
        }
        return $response->body;
    }

    public function test(): array {
        // Called by /credentials/<key>/test endpoint
        // Make a benign call that proves auth works
        $response = $this->http_client->get( '...whoami endpoint...' );
        return [ 'service_account' => $response->body['email'] ];
    }
}
```

Register in plugin bootstrap:
```php
FMW_Service_Client_Registry::register( '<service>', function() {
    return FMW_<Service>_Client::from_credentials();
} );
```

## Idempotency strategies

This is the highest-risk area of the plugin. Every state-changing step must be idempotent across retries. Three strategies, in order of preference:

### Strategy 1: Native idempotency keys

Many APIs accept an `Idempotency-Key` header (Stripe, Square) or similar mechanism. Use it.

```php
$response = $this->http_client->post( $url, [
    'headers' => [ 'Idempotency-Key' => $context->get_run_id() . ':' . $this->step_name ],
    'body' => $payload,
] );
```

### Strategy 2: Pre-check by unique attribute

If the API doesn't support idempotency keys, check if the resource already exists before creating.

```php
$existing = $this->client->find_by_run_id( $run_id ); // custom field on the resource
if ( $existing ) return $existing;
$created = $this->client->create( ... );
return $created;
```

This requires the API to support a custom field or query that includes the run_id. Slower (extra round-trip) but reliable.

### Strategy 3: Local idempotency record

For services with NO support for either, maintain a local idempotency record:

```php
// At step start
$lock_key = "fmw_idempotency:{$run_id}:{$step_name}";
$existing = get_transient( $lock_key );
if ( $existing ) return $existing; // returns the previous output

// Do the thing
$result = $this->client->create( ... );

// Cache the result for 1 hour (longer than max retry window)
set_transient( $lock_key, $result, HOUR_IN_SECONDS );
return $result;
```

The trade-off: if the WP install loses the transient (cache flush, DB issue) between attempts, you lose idempotency protection. Use only for low-stakes operations.

## Variable interpolation gotchas

The `{{ ... }}` syntax is parsed by `FMW_Interpolator`. Some patterns:

### Empty values

```php
{{ data.nonexistent_field }}  // → "" (empty string, not undefined)
{{ data.maybe_field || 'default' }}  // → "default" if maybe_field is empty
```

### Type coercion

Variables resolve to STRINGS in template contexts. For numeric / boolean comparisons, use `FMW_Expression`:

```
{{ data.estimated_quantity }}  → "50_100" (a string)

In a conditional expression:
"{{ data.estimated_quantity == '50_100' }}"  → string compare, works
"{{ length(data.notes) > 100 }}"  → length() returns int, > does numeric compare
```

### Array fields

If a form field is multi-select (e.g., `apparel_types: ["tshirts", "hoodies"]`), interpolating `{{ data.apparel_types }}` gives a comma-separated string by default: `"tshirts, hoodies"`.

For more control:
- `{{ data.apparel_types | join(' / ') }}` (filter syntax — Phase 5)
- Use `set_variable` to pre-format the value with custom logic

### Missing variables warn

```php
{{ data.misspelled_field_naem }}  // → "" but logs a WARNING in run history
```

This is intentional — typos in workflows should surface in run logs, not silently produce broken output.

### Nested access

```
{{ steps.create_quote.id }}              ✓ works
{{ steps.create_quote.lineitems.0.id }}  ✓ works (array index)
{{ steps.create_quote.metadata.deep.value }}  ✓ works (deep nesting)
```

## Action Scheduler patterns

### Enqueueing a workflow run

```php
$run_id = FMW_Run_Repository::create_pending( $workflow_id, $entry_id, $form_id );

as_enqueue_async_action(
    'fmw_run_workflow',          // hook name
    [ $run_id ],                 // args passed to the handler
    'fmw'                         // group (used in admin UI for filtering)
);
```

The `fmw_run_workflow` action is registered in `FMW_Workflow_Job::register_handler()`.

### Retry policy

When a step fails inside the workflow, the executor decides whether to retry based on:
- The step's `on_error` config (`fail`, `continue`, `retry`)
- The workflow's `max_retries` setting
- The current `retry_count` on the run

If retrying, the executor throws an exception that Action Scheduler catches and reschedules with backoff.

### Avoiding the worker timeout

PHP has a max execution time. Action Scheduler workers run as PHP processes and inherit this. If a single step takes longer than ~25 seconds, the worker is at risk of timeout.

For long-running steps (large file uploads, slow API calls):
- Use chunked patterns where possible (Drive's resumable upload)
- Set `step.timeout_seconds` in the workflow config to a value LESS than the PHP max execution time
- For genuinely long operations, split into multiple steps with `delay` between them

## Database access patterns

Don't write raw SQL. Use the repository classes:

```php
// Workflows
$workflow = FMW_Workflow_Repository::get( $workflow_id );
$workflow = FMW_Workflow_Repository::get_for_form( $form_id );  // returns first enabled workflow
$id = FMW_Workflow_Repository::create( $data );
FMW_Workflow_Repository::update( $id, $changes );
FMW_Workflow_Repository::delete( $id );

// Runs
$run = FMW_Run_Repository::get( $run_id );
$runs = FMW_Run_Repository::list( [ 'status' => 'failed', 'limit' => 50 ] );
$run_id = FMW_Run_Repository::create_pending( ... );
FMW_Run_Repository::update_status( $run_id, 'completed', $duration_ms );

// Run steps
$run_step = FMW_Run_Step_Repository::create_pending( $run_id, $step_index, $step_name );
FMW_Run_Step_Repository::update_status( $run_step_id, 'success', $output, $duration_ms );
```

The repositories handle the WP-prefixed table names and use prepared statements throughout.

## Logging conventions

```php
// In a step's execute() method
FMW_Logger::info( 'Step started', [
    'run_id' => $context->get_run_id(),
    'step_name' => $this->step_name,
    'step_type' => static::type(),
] );

// On error (before throwing)
FMW_Logger::error( 'Step failed', [
    'run_id' => $context->get_run_id(),
    'step_name' => $this->step_name,
    'error' => $e->getMessage(),
] );
```

Don't log sensitive values. Email addresses are masked by the logger automatically (`b***@example.com`). API tokens NEVER appear in logs.

For verbose debug logs (only enabled when `FMW_DEBUG` constant is true in wp-config.php):

```php
if ( defined( 'FMW_DEBUG' ) && FMW_DEBUG ) {
    FMW_Logger::debug( 'Verbose state', [ ... ] );
}
```

## Testing patterns

### Unit tests (no DB, no external services)

Use Brain Monkey to mock WP functions. Mock external service clients with PHPUnit's `createMock`.

```php
class CreateQuoteStepTest extends UnitTestCase {
    public function test_happy_path() {
        $client = $this->createMock( FMW_Printavo_Client::class );
        $client->method( 'create_quote' )->willReturn( [ 'id' => '12345', 'visual_id' => 'INQ-001' ] );

        FMW_Service_Client_Registry::register( 'printavo', function() use ( $client ) { return $client; } );

        $step = new FMW_Step_Printavo_Create_Quote( [
            'config' => [ 'customer_id' => '999', 'user_id' => 1, 'invoice_status_id' => 1, 'nickname' => 'Test' ],
            'step_name' => 'create_quote',
        ] );

        $context = new FMW_Workflow_Context( /* test data */ );

        $output = $step->execute( $context );

        $this->assertEquals( '12345', $output['id'] );
    }
}
```

### Integration tests (real DB via WP test harness, mocked externals)

In `tests/Integration/`. Use the WP test harness to get a real DB. Mock external HTTP via `pre_http_request` filter.

```php
class WorkflowExecutionTest extends IntegrationTestCase {
    public function test_full_workflow_runs() {
        // Create a workflow definition
        FMW_Workflow_Repository::create( [ 'id' => 'test-workflow', /* ... */ ] );

        // Trigger via fre_submission_complete
        do_action( 'fre_submission_complete', $entry_id, 'test-form', $data );

        // Wait for Action Scheduler
        $this->process_action_scheduler_queue();

        // Assert run completed
        $runs = FMW_Run_Repository::list( [ 'workflow_id' => 'test-workflow' ] );
        $this->assertCount( 1, $runs );
        $this->assertEquals( 'completed', $runs[0]['status'] );
    }
}
```

## Plugin gotchas

### FormEngine's `fre_submission_complete` only fires when an entry is created

If `settings.store_entries` is false on a form, FE doesn't create an entry, doesn't fire the action. Workflows on such forms will never run. Validate at workflow create time that the form has `store_entries: true`.

### Action Scheduler queue may back up under heavy load

Default WP installs run AS via wp_cron, which fires opportunistically. If the WP site has low traffic, the queue may not process for hours.

For production deployments, set up a real cron:
```
* * * * * curl -s https://example.com/wp-cron.php > /dev/null 2>&1
```

Alternatively, deploy a dedicated worker process. Documented in `docs/SETUP_PRODUCTION.md` (future doc).

### File deletion timing

`fre_delete_entry` deletes the entry AND its files. If a step earlier in the workflow uploaded the file to Drive but the upload step failed before getting to `drive_share_link` (and the workflow halts), retries will try to upload again — but the file might already have been deleted by an earlier run's `fre_delete_entry`.

Solution: NEVER put `fre_delete_entry` early in a workflow. ALWAYS at the end, after all file-dependent steps complete.

Validate this in `FMW_Workflow_Validator`: warn if `fre_delete_entry` appears before any step that reads files.

### Step name conflicts

Step names within a workflow must be unique. The validator enforces this on workflow create/update. If a workflow has duplicate step names, the second one shadows the first when `{{ steps.<name> }}` is referenced.

Don't allow this. Validate at create time.

### Long-running runs and PHP memory limits

A workflow run holds all step outputs in memory in the FMW_Workflow_Context. If a step returns a 50MB file blob, that's in memory for the rest of the run.

Steps should return REFERENCES (URLs, IDs) not contents. The `drive_upload_file` step's output includes file size and URL but NOT the file bytes.

## Common pull request review points

When reviewing a new step type or change:

- [ ] Does it have unit tests?
- [ ] Are config_schema and output_schema accurate?
- [ ] Is `has_side_effects` correct?
- [ ] If side_effects=true, is there an idempotency strategy?
- [ ] Are sensitive values redacted in logs?
- [ ] Does the step handle external auth/rate-limit errors gracefully?
- [ ] Is the new step documented in STEP_LIBRARY.md?
- [ ] Is CHANGELOG.md updated?
- [ ] Does the step name follow conventions (snake_case, `<service>_<verb>_<noun>`)?
- [ ] Is the step registered via FMW_Step_Registry::register()?
