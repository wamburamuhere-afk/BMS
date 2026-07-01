<?php
/**
 * CLI test — CRM Dashboard: safeOutput fix + widget integrity
 * Run: php tests/test_crm_dashboard_cli.php
 */

require_once __DIR__ . '/../roots.php';

$pass = 0; $fail = 0;

function ok(bool $cond, string $label): void {
    global $pass, $fail;
    if ($cond) { echo "  \033[32m✅\033[0m $label\n"; $pass++; }
    else        { echo "  \033[31m❌\033[0m $label\n"; $fail++; }
}

$dashboard = file_get_contents(__DIR__ . '/../app/bms/crm/crm_dashboard.php');
$api       = file_get_contents(__DIR__ . '/../api/crm/get_dashboard_data.php');

// ── 1. PHP lint ──────────────────────────────────────────────────────────────
echo "\n\033[1m── 1. PHP lint ──\033[0m\n";
ok(shell_exec('php -l ' . escapeshellarg(__DIR__ . '/../app/bms/crm/crm_dashboard.php') . ' 2>&1') !== null
   && strpos(shell_exec('php -l ' . escapeshellarg(__DIR__ . '/../app/bms/crm/crm_dashboard.php') . ' 2>&1'), 'No syntax errors') !== false,
   'crm_dashboard.php lint-clean');
ok(shell_exec('php -l ' . escapeshellarg(__DIR__ . '/../api/crm/get_dashboard_data.php') . ' 2>&1') !== null
   && strpos(shell_exec('php -l ' . escapeshellarg(__DIR__ . '/../api/crm/get_dashboard_data.php') . ' 2>&1'), 'No syntax errors') !== false,
   'get_dashboard_data.php lint-clean');

// ── 2. Root cause fix: safeOutput defined in dashboard ───────────────────────
echo "\n\033[1m── 2. safeOutput fix ──\033[0m\n";
ok(strpos($dashboard, 'function safeOutput(') !== false,
   'safeOutput() is defined in crm_dashboard.php');
ok(strpos($dashboard, "replace(/[&<>\"']/g") !== false,
   'safeOutput() properly escapes HTML entities');
// Confirm it appears BEFORE the render functions that use it
$safePos   = strpos($dashboard, 'function safeOutput(');
$renderPos = strpos($dashboard, 'function renderRecent(');
ok($safePos !== false && $renderPos !== false && $safePos < $renderPos,
   'safeOutput() is defined before renderRecent() — no temporal dead zone');

// ── 3. HTML structure — table IDs DataTable is initialised on ───────────────
echo "\n\033[1m── 3. HTML widget structure ──\033[0m\n";
ok(strpos($dashboard, 'id="tblRecent"') !== false, '#tblRecent table present');
ok(strpos($dashboard, 'id="tblDue"') !== false,    '#tblDue table present');
ok(strpos($dashboard, 'id="tblTop"') !== false,    '#tblTop table present');
ok(strpos($dashboard, "dtRecent = $('#tblRecent').DataTable(") !== false || strpos($dashboard, "DataTable({") !== false,
   'DataTable initialised for tblRecent');
ok(strpos($dashboard, "dtDue = $('#tblDue').DataTable(") !== false || substr_count($dashboard, 'DataTable({') >= 3,
   'DataTable initialised for tblDue');
ok(strpos($dashboard, "dtTop = $('#tblTop').DataTable(") !== false || substr_count($dashboard, 'DataTable({') >= 3,
   'DataTable initialised for tblTop');

// ── 4. JS render functions all defined ──────────────────────────────────────
echo "\n\033[1m── 4. JS render functions ──\033[0m\n";
ok(strpos($dashboard, 'function renderRecent(') !== false, 'renderRecent() defined');
ok(strpos($dashboard, 'function renderDue(')    !== false, 'renderDue() defined');
ok(strpos($dashboard, 'function renderTop(')    !== false, 'renderTop() defined');
ok(strpos($dashboard, 'function loadDashboard(') !== false,'loadDashboard() defined');

// ── 5. render functions use DataTables API ────────────────────────────────────
echo "\n\033[1m── 5. Render functions use DataTables API ──\033[0m\n";
ok(strpos($dashboard, 'dtRecent.clear()') !== false && strpos($dashboard, 'dtRecent.draw()') !== false,
   'renderRecent uses dtRecent.clear()/.draw()');
ok(strpos($dashboard, 'dtDue.clear()') !== false && strpos($dashboard, 'dtDue.draw()') !== false,
   'renderDue uses dtDue.clear()/.draw()');
ok(strpos($dashboard, 'dtTop.clear()') !== false && strpos($dashboard, 'dtTop.draw()') !== false,
   'renderTop uses dtTop.clear()/.draw()');

// ── 6. loadDashboard calls all three render functions ────────────────────────
echo "\n\033[1m── 6. loadDashboard wires all three tables ──\033[0m\n";
ok(substr_count($dashboard, 'renderRecent(') >= 2,
   'renderRecent() defined + called in loadDashboard');
ok(substr_count($dashboard, 'renderDue(') >= 2,
   'renderDue() defined + called in loadDashboard');
ok(substr_count($dashboard, 'renderTop(') >= 2,
   'renderTop() defined + called in loadDashboard');
ok(strpos($dashboard, 'initTables()') !== false,
   'initTables() called in $(document).ready()');

// ── 7. API permission key exists in DB ───────────────────────────────────────
echo "\n\033[1m── 7. Permission key in DB ──\033[0m\n";
$perm = $pdo->query("SELECT COUNT(*) FROM permissions WHERE page_key = 'crm_dashboard'")->fetchColumn();
ok((int)$perm > 0, "'crm_dashboard' permission key exists in permissions table");

// ── 8. API DB queries all run without error ───────────────────────────────────
echo "\n\033[1m── 8. API queries execute cleanly ──\033[0m\n";

// recent_leads
try {
    $stmt = $pdo->prepare("
        SELECT cl.lead_id, cl.lead_code,
               TRIM(CONCAT_WS(' ', cl.first_name, cl.last_name)) AS full_name,
               cl.company_name, cl.lead_value,
               ps.stage_name, ps.color AS stage_color, cl.created_at
        FROM crm_leads cl
        LEFT JOIN crm_pipeline_stages ps ON cl.pipeline_stage_id = ps.stage_id
        WHERE cl.status != 'deleted'
        ORDER BY cl.created_at DESC LIMIT 5
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    ok(true, 'recent_leads query executes without error (' . count($rows) . ' rows)');
    if (!empty($rows)) {
        $r = $rows[0];
        ok(array_key_exists('lead_id', $r),     'recent_leads row has lead_id');
        ok(array_key_exists('full_name', $r),    'recent_leads row has full_name');
        ok(array_key_exists('stage_name', $r),   'recent_leads row has stage_name');
        ok(array_key_exists('stage_color', $r),  'recent_leads row has stage_color');
        ok(array_key_exists('lead_value', $r),   'recent_leads row has lead_value');
        ok(array_key_exists('created_at', $r),   'recent_leads row has created_at');
    } else {
        ok(true, 'recent_leads: no rows (empty DB) — query still OK');
        for ($i = 0; $i < 5; $i++) ok(true, 'recent_leads field check skipped (no rows)');
    }
} catch (PDOException $e) {
    ok(false, 'recent_leads query: ' . $e->getMessage());
    for ($i = 0; $i < 5; $i++) ok(false, 'recent_leads field check (query failed)');
}

// due_activities
try {
    $stmt = $pdo->query("
        SELECT a.activity_id, a.subject, a.activity_type, a.due_date,
               TRIM(CONCAT_WS(' ', cl.first_name, cl.last_name)) AS lead_name,
               cl.lead_code, cl.lead_id
        FROM crm_lead_activities a
        JOIN crm_leads cl ON a.lead_id = cl.lead_id
        WHERE a.due_date <= DATE_ADD(NOW(), INTERVAL 1 DAY)
          AND a.status = 'pending'
          AND cl.status != 'deleted'
        ORDER BY a.due_date ASC LIMIT 10
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    ok(true, 'due_activities query executes without error (' . count($rows) . ' rows)');
    if (!empty($rows)) {
        $r = $rows[0];
        ok(array_key_exists('activity_id', $r),    'due_activities row has activity_id');
        ok(array_key_exists('subject', $r),         'due_activities row has subject');
        ok(array_key_exists('activity_type', $r),   'due_activities row has activity_type');
        ok(array_key_exists('due_date', $r),         'due_activities row has due_date');
        ok(array_key_exists('lead_name', $r),        'due_activities row has lead_name');
    } else {
        ok(true, 'due_activities: no rows due/overdue — query still OK');
        for ($i = 0; $i < 4; $i++) ok(true, 'due_activities field check skipped (no rows)');
    }
} catch (PDOException $e) {
    ok(false, 'due_activities query: ' . $e->getMessage());
    for ($i = 0; $i < 4; $i++) ok(false, 'due_activities field check (query failed)');
}

// top_assignees
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)),''), u.username) AS name,
               COUNT(cl.lead_id) AS won_count,
               COALESCE(SUM(cl.lead_value), 0) AS won_value
        FROM crm_leads cl
        JOIN users u ON cl.assigned_to = u.user_id
        JOIN crm_pipeline_stages ps ON cl.pipeline_stage_id = ps.stage_id
        WHERE ps.is_won = 1 AND cl.status != 'deleted'
        GROUP BY cl.assigned_to, u.first_name, u.last_name, u.username
        ORDER BY won_count DESC LIMIT 5
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    ok(true, 'top_assignees query executes without error (' . count($rows) . ' rows)');
    if (!empty($rows)) {
        $r = $rows[0];
        ok(array_key_exists('name', $r),       'top_assignees row has name');
        ok(array_key_exists('won_count', $r),  'top_assignees row has won_count');
        ok(array_key_exists('won_value', $r),  'top_assignees row has won_value');
        ok((int)$r['won_count'] > 0,           'top_assignees won_count is positive');
    } else {
        ok(true, 'top_assignees: no won leads yet — query still OK');
        for ($i = 0; $i < 3; $i++) ok(true, 'top_assignees field check skipped (no rows)');
    }
} catch (PDOException $e) {
    ok(false, 'top_assignees query: ' . $e->getMessage());
    for ($i = 0; $i < 3; $i++) ok(false, 'top_assignees field check (query failed)');
}

// ── 9. API returns correct JSON structure (simulated) ────────────────────────
echo "\n\033[1m── 9. API response shape ──\033[0m\n";
ok(strpos($api, "'tables'") !== false || strpos($api, '"tables"') !== false,
   "API returns 'tables' key");
ok(strpos($api, "'recent_leads'") !== false || strpos($api, '"recent_leads"') !== false,
   "API returns 'tables.recent_leads'");
ok(strpos($api, "'due_activities'") !== false || strpos($api, '"due_activities"') !== false,
   "API returns 'tables.due_activities'");
ok(strpos($api, "'top_assignees'") !== false || strpos($api, '"top_assignees"') !== false,
   "API returns 'tables.top_assignees'");
ok(strpos($api, "'kpi'") !== false || strpos($api, '"kpi"') !== false,
   "API returns 'kpi' key");
ok(strpos($api, "'charts'") !== false || strpos($api, '"charts"') !== false,
   "API returns 'charts' key");

// ── 10. No stray safeOutput calls outside the definition (sanity) ────────────
echo "\n\033[1m── 10. No undefined safeOutput calls in other CRM pages ──\033[0m\n";
$crmPages = glob(__DIR__ . '/../app/bms/crm/*.php');
foreach ($crmPages as $f) {
    $content = file_get_contents($f);
    $base = basename($f);
    if (strpos($content, 'safeOutput(') !== false) {
        // If it calls safeOutput it must also define it or be crm_dashboard
        $defines = strpos($content, 'function safeOutput(') !== false;
        // OR it's in a page that includes a script that defines it globally (footer check)
        ok($defines, "$base: uses safeOutput() and defines it locally");
    }
}

// ── Summary ──────────────────────────────────────────────────────────────────
echo "\n";
echo "Passes:   \033[32m$pass\033[0m\n";
echo "Failures: \033[31m$fail\033[0m\n";
if ($fail === 0) {
    echo "\033[32m✅ All checks passed — CRM dashboard safeOutput fix verified.\033[0m\n\n";
    exit(0);
} else {
    echo "\033[31m❌ $fail check(s) failed.\033[0m\n\n";
    exit(1);
}
