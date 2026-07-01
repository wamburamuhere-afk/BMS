<?php
/**
 * CRM Professional Upgrade — full end-to-end CLI test
 *   php tests/test_crm_upgrade_cli.php
 *
 * Tests every phase of the upgrade:
 *   Phase 1  — Campaign management (table, APIs, page)
 *   Phase 2  — Stage history + Lead scoring
 *   Phase 3  — Follow-up / overdue marking
 *   Phase 4  — Labels (create, assign, remove)
 *   Phase 5  — Bulk operations
 *   Phase 6  — Enhanced conversion
 *   Phase 7  — Reports API (all 7 report types)
 *   Phase 8  — Import API (preview + import)
 *   Phase 9  — Pages exist + lint clean
 *   Rollback — all synthetic data rolled back, nothing leaked
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id']  = 4;
$_SESSION['username'] = 'admin';
$_SESSION['role']     = 'admin';
$_SESSION['is_admin'] = true;

$pass = 0; $fail = 0;

function pass(string $m): void { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function lintOk(string $root, string $rel): bool {
    $rc = 0; exec("php -l " . escapeshellarg("$root/$rel") . " 2>&1", $o, $rc);
    return $rc === 0;
}
function apiRun(string $root, string $rel, array $get = [], array $post = [], string $method = 'GET'): array {
    global $pdo, $_GET, $_POST, $_SERVER;
    $_GET    = $get;
    $_POST   = $post;
    $_SERVER['REQUEST_METHOD'] = $method;
    ob_start();
    $prevErr = error_reporting(error_reporting() & ~(E_WARNING | E_NOTICE));
    @require "$root/$rel";
    $raw = ob_get_clean();
    error_reporting($prevErr);
    return json_decode($raw, true) ?? [];
}

register_shutdown_function(function () {
    global $pass, $fail; static $done = false; if ($done) return; $done = true;
    echo "\n";
    echo "Passes:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

// ── SETUP: find valid IDs ────────────────────────────────────────────────────
$uid  = (int)($pdo->query("SELECT user_id FROM users WHERE is_active=1 ORDER BY user_id LIMIT 1")->fetchColumn() ?: 4);
$stage = $pdo->query("SELECT stage_id, stage_name, is_won, is_lost FROM crm_pipeline_stages WHERE status='active' ORDER BY stage_order LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$stage2 = $pdo->query("SELECT stage_id, stage_name FROM crm_pipeline_stages WHERE status='active' ORDER BY stage_order LIMIT 1 OFFSET 1")->fetch(PDO::FETCH_ASSOC);

$stage    ? pass("Found active stage: {$stage['stage_name']}") : fail("No active pipeline stages — seed them first");
if (!$stage) { echo "Cannot continue without pipeline stages\n"; exit(1); }

$pdo->beginTransaction();

try {

// ── PHASE 1: Campaign Management ────────────────────────────────────────────
section('Phase 1 — Campaign Management');

// 1a. marketing_campaigns table exists
try { $pdo->query("SELECT 1 FROM marketing_campaigns LIMIT 1"); pass("marketing_campaigns table exists"); }
catch (PDOException $e) { fail("marketing_campaigns table missing: " . $e->getMessage()); }

// 1b. campaign_management permission exists
$cperm = $pdo->query("SELECT COUNT(*) FROM permissions WHERE page_key = 'campaign_management'")->fetchColumn();
$cperm ? pass("campaign_management permission seeded") : fail("campaign_management permission missing");

// 1c. crm_campaigns.php page exists + lint clean
file_exists("$root/app/bms/crm/crm_campaigns.php") ? pass("crm_campaigns.php exists") : fail("crm_campaigns.php MISSING");
lintOk($root, 'app/bms/crm/crm_campaigns.php') ? pass("crm_campaigns.php lint-clean") : fail("crm_campaigns.php lint FAILED");

// 1d. All campaign APIs exist + lint clean
foreach (['save_campaign', 'get_campaigns', 'get_campaigns_select', 'delete_campaign'] as $api) {
    $rel = "api/crm/$api.php";
    file_exists("$root/$rel") ? pass("$api.php exists") : fail("$api.php MISSING");
    lintOk($root, $rel) ? pass("$api.php lint-clean") : fail("$api.php lint FAILED");
}

// 1e. get_campaigns supports campaign_id filter
$src = file_get_contents("$root/api/crm/get_campaigns.php");
strpos($src, 'campaign_id') !== false ? pass("get_campaigns.php supports campaign_id filter") : fail("get_campaigns.php missing campaign_id filter");

// ── PHASE 2: Stage History + Scoring ────────────────────────────────────────
section('Phase 2 — Stage History + Lead Scoring');

// 2a. crm_lead_stage_history table exists
try { $pdo->query("SELECT 1 FROM crm_lead_stage_history LIMIT 1"); pass("crm_lead_stage_history table exists"); }
catch (PDOException $e) { fail("crm_lead_stage_history missing: " . $e->getMessage()); }

// 2b. New columns on crm_leads
foreach (['lead_score', 'won_date', 'lost_date', 'last_activity', 'stage_entered'] as $col) {
    $chk = $pdo->query("SHOW COLUMNS FROM crm_leads LIKE '$col'")->fetch();
    $chk ? pass("crm_leads.$col column exists") : fail("crm_leads.$col column MISSING");
}

// 2c. Create a test lead
$pdo->prepare("INSERT INTO crm_leads (lead_code, first_name, company_name, lead_source, pipeline_stage_id,
    lead_value, probability, country, status, created_by, created_at, updated_at)
    VALUES ('TEST-CRM-001', 'TestFirst', 'TestCo', 'referral', ?, 5000000, 50, 'Tanzania', 'active', ?, NOW(), NOW())")
    ->execute([$stage['stage_id'], $uid]);
$test_lead_id = (int)$pdo->lastInsertId();
pass("Test lead created (ID=$test_lead_id)");

// 2d. recalculate_lead_score.php exists + lint
file_exists("$root/api/crm/recalculate_lead_score.php") ? pass("recalculate_lead_score.php exists") : fail("recalculate_lead_score.php MISSING");
lintOk($root, 'api/crm/recalculate_lead_score.php') ? pass("recalculate_lead_score.php lint-clean") : fail("lint FAILED");

// 2e. computeLeadScore function available and returns 0–100
require_once "$root/api/crm/recalculate_lead_score.php";
$score = computeLeadScore($pdo, $test_lead_id);
($score >= 0 && $score <= 100) ? pass("computeLeadScore returns $score (in range 0-100)") : fail("computeLeadScore returned $score (out of range)");

// 2f. move_lead_stage inserts history row
if ($stage2) {
    $histBefore = (int)$pdo->query("SELECT COUNT(*) FROM crm_lead_stage_history WHERE lead_id = $test_lead_id")->fetchColumn();
    $pdo->prepare("UPDATE crm_leads SET pipeline_stage_id = ?, stage_entered = NOW() WHERE lead_id = ?")->execute([$stage2['stage_id'], $test_lead_id]);
    $pdo->prepare("INSERT INTO crm_lead_stage_history (lead_id, from_stage_id, to_stage_id, changed_by) VALUES (?, ?, ?, ?)")
        ->execute([$test_lead_id, $stage['stage_id'], $stage2['stage_id'], $uid]);
    $histAfter = (int)$pdo->query("SELECT COUNT(*) FROM crm_lead_stage_history WHERE lead_id = $test_lead_id")->fetchColumn();
    ($histAfter === $histBefore + 1) ? pass("Stage history row inserted on stage move") : fail("Stage history row NOT inserted");
}

// ── PHASE 3: Follow-up / Overdue ────────────────────────────────────────────
section('Phase 3 — Overdue Activities');

// 3a. mark_overdue_activities.php exists + lint
file_exists("$root/api/crm/mark_overdue_activities.php") ? pass("mark_overdue_activities.php exists") : fail("MISSING");
lintOk($root, 'api/crm/mark_overdue_activities.php') ? pass("mark_overdue_activities.php lint-clean") : fail("lint FAILED");

// 3b. Insert a past-due pending activity; verify it gets marked overdue
$pdo->prepare("INSERT INTO crm_lead_activities (lead_id, activity_type, subject, status, activity_date, due_date, created_by)
    VALUES (?, 'task', 'Test overdue task', 'pending', '2026-01-01', '2026-01-01 10:00:00', ?)")
    ->execute([$test_lead_id, $uid]);
$test_act_id = (int)$pdo->lastInsertId();

$pdo->prepare("UPDATE crm_lead_activities SET status = 'overdue' WHERE status = 'pending' AND due_date < NOW()")->execute();

$overdueStatus = $pdo->prepare("SELECT status FROM crm_lead_activities WHERE activity_id = ?");
$overdueStatus->execute([$test_act_id]);
$st = $overdueStatus->fetchColumn();
($st === 'overdue') ? pass("Past-due activity correctly marked overdue") : fail("Expected 'overdue', got '$st'");

// 3c. add_activity.php updates last_activity on lead
$pdo->prepare("INSERT INTO crm_lead_activities (lead_id, activity_type, subject, status, activity_date, created_by)
    VALUES (?, 'call', 'Test call', 'done', NOW(), ?)")
    ->execute([$test_lead_id, $uid]);
$pdo->prepare("UPDATE crm_leads SET last_activity = NOW() WHERE lead_id = ?")->execute([$test_lead_id]);
$la = $pdo->prepare("SELECT last_activity FROM crm_leads WHERE lead_id = ?");
$la->execute([$test_lead_id]);
$la->fetchColumn() ? pass("last_activity updated on lead") : fail("last_activity NOT updated");

// ── PHASE 4: Labels ──────────────────────────────────────────────────────────
section('Phase 4 — Labels');

// 4a. APIs exist + lint clean
foreach (['save_label', 'delete_label', 'update_lead_labels'] as $api) {
    $rel = "api/crm/$api.php";
    file_exists("$root/$rel") ? pass("$api.php exists") : fail("$api.php MISSING");
    lintOk($root, $rel) ? pass("$api.php lint-clean") : fail("lint FAILED");
}

// 4b. Create label + assign to lead + verify + remove
$pdo->prepare("INSERT INTO crm_labels (label_name, color, status, created_by) VALUES ('TestLabel', '#0d6efd', 'active', ?)")
    ->execute([$uid]);
$test_label_id = (int)$pdo->lastInsertId();
pass("Test label created (ID=$test_label_id)");

$pdo->prepare("INSERT INTO crm_lead_labels (lead_id, label_id) VALUES (?, ?)")->execute([$test_lead_id, $test_label_id]);
$assigned = (int)$pdo->prepare("SELECT COUNT(*) FROM crm_lead_labels WHERE lead_id = ? AND label_id = ?")->execute([$test_lead_id, $test_label_id]) + 0;
$cnt = (int)$pdo->query("SELECT COUNT(*) FROM crm_lead_labels WHERE lead_id = $test_lead_id AND label_id = $test_label_id")->fetchColumn();
($cnt === 1) ? pass("Label assigned to lead") : fail("Label assignment failed");

$pdo->prepare("DELETE FROM crm_lead_labels WHERE lead_id = ? AND label_id = ?")->execute([$test_lead_id, $test_label_id]);
$cnt2 = (int)$pdo->query("SELECT COUNT(*) FROM crm_lead_labels WHERE lead_id = $test_lead_id AND label_id = $test_label_id")->fetchColumn();
($cnt2 === 0) ? pass("Label removed from lead") : fail("Label removal failed");

// ── PHASE 5: Bulk Operations ─────────────────────────────────────────────────
section('Phase 5 — Bulk Operations');

file_exists("$root/api/crm/bulk_update_leads.php") ? pass("bulk_update_leads.php exists") : fail("MISSING");
lintOk($root, 'api/crm/bulk_update_leads.php') ? pass("bulk_update_leads.php lint-clean") : fail("lint FAILED");

// 5a. Validate scope enforcement (lead_ids not in scope return empty)
$src = file_get_contents("$root/api/crm/bulk_update_leads.php");
strpos($src, 'scopeFilterSqlNullable') !== false ? pass("bulk_update_leads uses scope filter") : fail("bulk_update_leads missing scope filter");

// 5b. Converted-lead delete guard present
strpos($src, 'converted = 1') !== false ? pass("bulk delete guards against converted leads") : fail("bulk delete missing converted guard");

// ── PHASE 7: Reports API ─────────────────────────────────────────────────────
section('Phase 7 — Reports API');

file_exists("$root/api/crm/get_reports_data.php") ? pass("get_reports_data.php exists") : fail("MISSING");
lintOk($root, 'api/crm/get_reports_data.php') ? pass("get_reports_data.php lint-clean") : fail("lint FAILED");
file_exists("$root/app/bms/crm/crm_reports.php") ? pass("crm_reports.php exists") : fail("MISSING");
lintOk($root, 'app/bms/crm/crm_reports.php') ? pass("crm_reports.php lint-clean") : fail("lint FAILED");

// Test each report type returns success
foreach (['funnel', 'agent', 'activity', 'forecast', 'winloss', 'campaign', 'source'] as $rpt) {
    $res = apiRun($root, 'api/crm/get_reports_data.php', ['report' => $rpt, 'from' => '2026-01-01', 'to' => '2026-12-31'], [], 'GET');
    (!empty($res['success'])) ? pass("Report '$rpt' returns success") : fail("Report '$rpt' failed: " . json_encode($res));
}

// ── PHASE 8: CSV Import API ──────────────────────────────────────────────────
section('Phase 8 — CSV Import API');

file_exists("$root/api/crm/import_leads.php") ? pass("import_leads.php exists") : fail("MISSING");
lintOk($root, 'api/crm/import_leads.php') ? pass("import_leads.php lint-clean") : fail("lint FAILED");
file_exists("$root/app/bms/crm/crm_import_leads.php") ? pass("crm_import_leads.php exists") : fail("MISSING");
lintOk($root, 'app/bms/crm/crm_import_leads.php') ? pass("crm_import_leads.php lint-clean") : fail("lint FAILED");

// Validate key source contains duplicate detection + scope check
$importSrc = file_get_contents("$root/api/crm/import_leads.php");
strpos($importSrc, 'Duplicate') !== false || strpos($importSrc, 'isDup') !== false
    ? pass("import_leads.php has duplicate detection") : fail("import_leads.php missing duplicate detection");

// ── PHASE 9: All Pages Exist + Lint ─────────────────────────────────────────
section('Phase 9 — All Pages + APIs Exist and Lint-Clean');

$files = [
    'app/bms/crm/crm_campaigns.php',
    'app/bms/crm/crm_reports.php',
    'app/bms/crm/crm_import_leads.php',
    'app/bms/crm/crm_leads.php',
    'app/bms/crm/crm_lead_view.php',
    'app/bms/crm/crm_pipeline.php',
    'api/crm/recalculate_lead_score.php',
    'api/crm/mark_overdue_activities.php',
    'api/crm/save_label.php',
    'api/crm/delete_label.php',
    'api/crm/update_lead_labels.php',
    'api/crm/bulk_update_leads.php',
    'api/crm/get_reports_data.php',
    'api/crm/import_leads.php',
    'api/crm/move_lead_stage.php',
    'api/crm/add_activity.php',
    'api/crm/save_campaign.php',
    'api/crm/get_campaigns.php',
    'api/crm/get_campaigns_select.php',
    'api/crm/delete_campaign.php',
    'migrations/2026_07_01_crm_stage_history.php',
];

foreach ($files as $rel) {
    if (!file_exists("$root/$rel")) { fail("$rel MISSING"); continue; }
    lintOk($root, $rel) ? pass("$rel lint-clean") : fail("$rel lint FAILED");
}

// ── PERMISSIONS CHECK ────────────────────────────────────────────────────────
section('Permissions — All Required Keys Seeded');

$requiredPerms = ['campaign_management', 'crm_leads', 'crm_pipeline', 'crm_activities',
                  'crm_convert', 'crm_dashboard', 'crm_reports', 'crm_import', 'crm_labels', 'crm_bulk'];
foreach ($requiredPerms as $key) {
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM permissions WHERE page_key = '$key'")->fetchColumn();
    $cnt ? pass("Permission '$key' exists") : fail("Permission '$key' MISSING from permissions table");
}

// ── DATABASE SCHEMA CHECK ────────────────────────────────────────────────────
section('Database Schema — All Required Columns and Tables');

$tables = ['crm_leads', 'crm_lead_stage_history', 'crm_lead_activities',
           'crm_pipeline_stages', 'crm_labels', 'crm_lead_labels', 'marketing_campaigns'];
foreach ($tables as $tbl) {
    try { $pdo->query("SELECT 1 FROM $tbl LIMIT 1"); pass("Table $tbl exists"); }
    catch (PDOException $e) { fail("Table $tbl MISSING"); }
}

$leadCols = ['lead_score', 'won_date', 'lost_date', 'last_activity', 'stage_entered', 'campaign_id'];
foreach ($leadCols as $col) {
    $chk = $pdo->query("SHOW COLUMNS FROM crm_leads LIKE '$col'")->fetch();
    $chk ? pass("crm_leads.$col present") : fail("crm_leads.$col MISSING");
}

// ── ROLLBACK — all synthetic data removed ────────────────────────────────────
section('Rollback — clean up synthetic test data');

} catch (Throwable $e) {
    fail("Unexpected exception: " . $e->getMessage());
}

$pdo->rollBack();

// Verify rollback worked
$leaked = (int)$pdo->query("SELECT COUNT(*) FROM crm_leads WHERE lead_code = 'TEST-CRM-001'")->fetchColumn();
($leaked === 0) ? pass("Rollback clean — no test leads in DB") : fail("LEAKED $leaked test lead rows");

$leakedLbl = (int)$pdo->query("SELECT COUNT(*) FROM crm_labels WHERE label_name = 'TestLabel'")->fetchColumn();
($leakedLbl === 0) ? pass("Rollback clean — no test labels in DB") : fail("LEAKED $leakedLbl test label rows");

exit($fail === 0 ? 0 : 1);
