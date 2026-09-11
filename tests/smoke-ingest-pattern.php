<?php
/**
 * Smoke: the external system-of-record ingest pattern, end to end (LOCAL).
 *
 * A fixture feed served over HTTP from the uploads directory, a throwaway
 * Post Runtime record type, a scheduled workflow created through the
 * workflow repository, runs executed through the real job handler. Then:
 * same feed → unchanged; edited feed → updated; a record dropped from the
 * feed → drafted; an empty feed → the run fails and nothing is drafted; a
 * renamed field → the run fails. Cleans up after itself.
 *
 *   php -d mysqli.default_socket=… tests/smoke-ingest-pattern.php
 *
 * @package FlowMintWorkflows\Tests
 */
$wp_load = ''; $dir = __DIR__;
for ($i = 0; $i < 8; $i++) { $dir = dirname($dir); if (file_exists($dir . '/wp-load.php')) { $wp_load = $dir . '/wp-load.php'; break; } }
if ('' === $wp_load) { fwrite(STDERR, "WordPress not found.\n"); exit(1); }
require_once $wp_load;

$pass = 0; $fail = 0;
function check($cond, $label, $detail = '') { global $pass, $fail; if ($cond) { $pass++; echo "  ✓ {$label}\n"; } else { $fail++; echo "  ✗ {$label}" . ($detail !== '' ? "\n      {$detail}" : '') . "\n"; } }

$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
wp_set_current_user((int) $admins[0]);
$plugin = pcptpages();
$cpt    = 'ingestprog';
$wf_id  = 'smoke-ingest-programs';

// --- Fixture feed, served by the web server from uploads ---------------
$uploads  = wp_upload_dir();
$feed_dir = trailingslashit($uploads['basedir']) . 'fmw-smoke';
// Plain http: Local's certificate is self-signed and http_get verifies
// TLS the way production must; a real feed has a real certificate.
$feed_url = set_url_scheme(trailingslashit($uploads['baseurl']) . 'fmw-smoke/feed.json', 'http');
if (!is_dir($feed_dir)) mkdir($feed_dir, 0755, true);
$write_feed = static function (array $programs) use ($feed_dir) {
    file_put_contents($feed_dir . '/feed.json', wp_json_encode(['programs' => $programs, 'generated' => gmdate('c')]));
};
$programs = [
    ['id' => 4471, 'name' => 'Youth Soccer', 'shortDescription' => 'Ages 6-10', 'startDate' => '2026-10-04T09:00:00-05:00', 'facility' => ['name' => 'Wilson Park'], 'category' => 'Youth'],
    ['id' => 4472, 'name' => 'Adult Tennis', 'shortDescription' => 'All levels', 'startDate' => '2026-10-06T18:00:00-05:00', 'facility' => ['name' => 'Courts'], 'category' => 'Adult'],
    ['id' => 4473, 'name' => 'Senior Swim', 'shortDescription' => 'Mornings', 'startDate' => '2026-10-07T07:00:00-05:00', 'facility' => ['name' => 'Aquatic Center'], 'category' => 'Senior'],
];
$write_feed($programs);
$probe = wp_remote_get($feed_url, ['sslverify' => false]);
check(!is_wp_error($probe) && wp_remote_retrieve_response_code($probe) === 200, 'fixture feed is reachable over HTTP', is_wp_error($probe) ? $probe->get_error_message() : (string) wp_remote_retrieve_response_code($probe));

// --- Record type -----------------------------------------------------------
if (!$plugin->cpts->exists($cpt)) {
    $plugin->cpts->register($cpt, ['slug' => $cpt, 'label_singular' => 'Program', 'label_plural' => 'Programs', 'public' => true, 'has_archive' => true, 'show_in_rest' => true, 'supports' => ['title', 'editor', 'excerpt'], 'taxonomies' => ['category'], 'hero_layout' => 'stacked', 'default_icon' => 'mdi:calendar-star']);
    $plugin->post_fields->define($cpt, ['key' => 'event_start', 'label' => 'Starts', 'display_type' => 'date', 'card_position' => 'headline', 'single_position' => 'meta_strip', 'date_format' => 'custom', 'date_format_string' => 'M j, Y', 'all_day' => false, 'semantic_role' => 'event_start']);
    $plugin->post_fields->define($cpt, ['key' => 'event_location', 'label' => 'Location', 'display_type' => 'text', 'card_position' => 'meta_strip', 'single_position' => 'meta_strip', 'semantic_role' => 'event_location']);
    $plugin->cpts->register_all_with_wp();
}

// --- Workflow (Pattern 8), through the repository ----------------------------
$config = [
    'trigger' => ['type' => 'schedule', 'interval' => 'daily', 'hour' => 4, 'minute' => 30],
    'steps'   => [
        ['name' => 'fetch', 'type' => 'http_get', 'config' => ['url' => $feed_url, 'headers' => ['Accept' => 'application/json'], 'timeout_seconds' => 30]],
        ['name' => 'upsert', 'type' => 'pre_upsert_records', 'config' => [
            'post_type' => $cpt, 'source' => 'recdesk', 'records' => '{{ steps.fetch.body.programs }}',
            'map' => ['external_id' => '{{ item.id }}', 'title' => '{{ item.name }}', 'excerpt' => '{{ item.shortDescription }}',
                      'fields' => ['event_start' => '{{ item.startDate }}', 'event_location' => '{{ item.facility.name }}'],
                      'taxonomies' => ['category' => '{{ item.category }}']],
            'expect_min_records' => 2, 'max_failure_ratio' => 0.1, 'missing_upstream' => 'draft',
        ]],
        ['name' => 'report', 'type' => 'log_info', 'config' => ['message' => 'Programs sync: {{ steps.upsert.created_count }} new, {{ steps.upsert.updated_count }} changed, {{ steps.upsert.unchanged_count }} same, {{ steps.upsert.drafted_count }} withdrawn, {{ steps.upsert.failed_count }} failed.']],
    ],
];
$validation = FMW_Workflow_Validator::validate($config);
check(empty($validation['errors']), 'workflow config validates', json_encode($validation));
if (FMW_Workflow_Repository::get($wf_id)) FMW_Workflow_Repository::delete($wf_id, true);
$created = FMW_Workflow_Repository::create(['id' => $wf_id, 'title' => 'Smoke: programs ingest', 'enabled' => true, 'config' => $config, 'managed_by' => 'connector']);
check(!is_wp_error($created), 'scheduled workflow created', is_wp_error($created) ? $created->get_error_message() : '');

$run = static function () use ($wf_id) {
    $run_id = FMW_Run_Repository::create_pending_scheduled($wf_id);
    // The job handler rethrows retryable failures so Action Scheduler can
    // retry with backoff; stand in for Action Scheduler here.
    for ($attempt = 0; $attempt < 6; $attempt++) {
        try { FMW_Workflow_Job::handle($run_id); } catch (\Throwable $e) { /* AS would reschedule */ }
        $status = FMW_Run_Repository::get($run_id)['status'] ?? '';
        if (in_array($status, ['completed', 'failed', 'cancelled'], true)) break;
    }
    $row   = FMW_Run_Repository::get($run_id);
    $steps = FMW_Run_Step_Repository::list_for_run($run_id);
    $upsert = null;
    foreach ($steps as $s) { if (($s['step_name'] ?? '') === 'upsert') $upsert = $s; }
    return ['run' => $row, 'upsert' => $upsert, 'output' => $upsert && !empty($upsert['output_snapshot']) ? json_decode($upsert['output_snapshot'], true) : null];
};
$pd = $plugin->post_data;

echo "\nFirst run: creates\n";
$r = $run();
check(($r['run']['status'] ?? '') === 'completed', 'run completed', json_encode($r['run']));
check(($r['output']['created_count'] ?? -1) === 3, 'three records created', json_encode($r['output']));
$soccer = $pd->find_external($cpt, 'recdesk', '4471');
check($soccer > 0 && get_post_status($soccer) === 'publish' && get_the_title($soccer) === 'Youth Soccer', 'record is published with the mapped title');
check(($pd->get_field_values($soccer)['event_location'] ?? '') === 'Wilson Park', 'nested path mapped into a field', json_encode($pd->get_field_values($soccer)));
check(has_term('Youth', 'category', $soccer), 'taxonomy term mapped');
check(wp_remote_retrieve_response_code(wp_remote_get(get_permalink($soccer), ['sslverify' => false])) === 200, 'record renders over HTTP');

echo "\nSecond run, same feed: unchanged\n";
$r = $run();
check(($r['run']['status'] ?? '') === 'completed' && ($r['output']['unchanged_count'] ?? -1) === 3 && ($r['output']['created_count'] ?? -1) === 0, 'all unchanged, nothing created', json_encode($r['output']));

echo "\nEdited feed: updated\n";
$programs[0]['facility']['name'] = 'Riverside Park';
$write_feed($programs);
$r = $run();
check(($r['output']['updated_count'] ?? -1) === 1 && ($r['output']['unchanged_count'] ?? -1) === 2, 'one updated, two unchanged', json_encode($r['output']));
check(($pd->get_field_values($soccer)['event_location'] ?? '') === 'Riverside Park', 'the changed field was written');

echo "\nRecord dropped upstream: drafted, never deleted\n";
$swim = $pd->find_external($cpt, 'recdesk', '4473');
array_pop($programs);
$write_feed($programs);
$r = $run();
check(($r['output']['drafted_ids'] ?? []) === [$swim], 'the missing record was drafted', json_encode($r['output']));
check(get_post_status($swim) === 'draft' && get_post($swim) !== null, 'it still exists, as a draft');

echo "\nEmpty feed: loud failure, nothing drafted\n";
$write_feed([]);
$r = $run();
check(($r['run']['status'] ?? '') === 'failed', 'run failed', json_encode($r['run']));
check(strpos((string) ($r['upsert']['error_message'] ?? ''), 'expected at least 2') !== false, 'the error says what was expected', json_encode($r['upsert']));
check(get_post_status($soccer) === 'publish', 'the published records were not drafted');

echo "\nRenamed field upstream: loud failure, successes kept\n";
$renamed = array_map(static function ($p) { $p['programId'] = $p['id']; unset($p['id']); return $p; }, $programs);
$write_feed($renamed);
$r = $run();
check(($r['run']['status'] ?? '') === 'failed' && strpos((string) ($r['upsert']['error_message'] ?? ''), 'of 2 record(s) failed') !== false, 'failure ratio tripped with a readable message', json_encode($r['upsert']['error_message'] ?? $r['run']));

// --- Cleanup -----------------------------------------------------------------
FMW_Workflow_Repository::delete($wf_id, true);
$req = new WP_REST_Request('DELETE', '/' . PCPTPages_REST_NAMESPACE . '/' . PCPTPages_REST_BASE . '/cpts/' . $cpt);
$req->set_url_params(['slug' => $cpt]); $req->set_param('purge_data', true);
(new PCPTPages_Connector_API())->handle_delete_cpt($req);
global $wpdb;
foreach ($wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", $cpt)) as $pid) { wp_delete_object_term_relationships((int) $pid, ['category']); wp_delete_post((int) $pid, true); }
// Term counts are stale here (invalidation was deferred), so judge by relationships, not by count.
foreach (['Youth', 'Adult', 'Senior'] as $name) { $t = get_term_by('name', $name, 'category'); if ($t && (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d", $t->term_taxonomy_id)) === 0) wp_delete_term($t->term_id, 'category'); }
$tomb = get_option(PCPTPages_Connector_API::DELETED_CPTS_OPTION, []);
if (is_array($tomb) && isset($tomb[$cpt])) { unset($tomb[$cpt]); update_option(PCPTPages_Connector_API::DELETED_CPTS_OPTION, $tomb, false); }
@unlink($feed_dir . '/feed.json'); @rmdir($feed_dir);

echo "\n{$pass} passed, {$fail} failed\n\n";
exit($fail === 0 ? 0 : 1);
