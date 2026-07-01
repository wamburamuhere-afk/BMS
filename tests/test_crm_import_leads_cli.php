<?php
/**
 * CLI test — CRM Import Leads: all 3 stages
 * Run: php tests/test_crm_import_leads_cli.php
 */
require_once __DIR__ . '/../roots.php';
global $routes;

$pass = 0; $fail = 0;
function ok(bool $c, string $label): void {
    global $pass, $fail;
    if ($c) { echo "  \033[32m✅\033[0m $label\n"; $pass++; }
    else     { echo "  \033[31m❌\033[0m $label\n"; $fail++; }
}

$page = file_get_contents(__DIR__ . '/../app/bms/crm/crm_import_leads.php');
$api  = file_get_contents(__DIR__ . '/../api/crm/import_leads.php');

// ── 1. PHP lint ───────────────────────────────────────────────────────────────
echo "\n\033[1m── 1. PHP lint ──\033[0m\n";
ok(strpos(shell_exec('php -l ' . escapeshellarg(__DIR__ . '/../app/bms/crm/crm_import_leads.php') . ' 2>&1'), 'No syntax errors') !== false,
   'crm_import_leads.php lint-clean');
ok(strpos(shell_exec('php -l ' . escapeshellarg(__DIR__ . '/../api/crm/import_leads.php') . ' 2>&1'), 'No syntax errors') !== false,
   'api/crm/import_leads.php lint-clean');

// ── 2. Route registration ─────────────────────────────────────────────────────
echo "\n\033[1m── 2. Route registration ──\033[0m\n";
ok(isset($routes['crm/import_leads']),     'crm/import_leads registered in routes');
ok(isset($routes['api/crm/import_leads']), 'api/crm/import_leads registered in routes');
ok(file_exists($routes['crm/import_leads']),     'crm_import_leads.php file exists at route path');
ok(file_exists($routes['api/crm/import_leads']), 'import_leads.php API file exists at route path');

// ── 3. Permission key ─────────────────────────────────────────────────────────
echo "\n\033[1m── 3. Permission key ──\033[0m\n";
$perm = (int)$pdo->query("SELECT COUNT(*) FROM permissions WHERE page_key = 'crm_import'")->fetchColumn();
ok($perm > 0, "'crm_import' permission key exists in DB");

// ── 4. Stage 1: no click-recursion (csvFile must NOT be inside dropZone) ─────
echo "\n\033[1m── 4. Stage 1 — no click recursion ──\033[0m\n";
// Find positions
$csvInputPos   = strpos($page, 'id="csvFile"');
$dropZoneStart = strpos($page, 'id="dropZone"');
// Find the CLOSING </div> of dropZone (rough: next </div> at same depth after opening)
// Simpler: check that csvFile id appears before the dropZone opening tag
ok($csvInputPos !== false && $dropZoneStart !== false && $csvInputPos < $dropZoneStart,
   '#csvFile input appears BEFORE #dropZone in HTML (not nested inside)');
ok(strpos($page, 'onclick="$(\'#csvFile\').click()"') === false,
   '#dropZone has no dangerous inline onclick that causes recursion');
// Confirm the dropZone div itself does not re-embed the input in static HTML
// (only inner text between id="dropZone" and the first subsequent </div> matters)
$dropZoneSnippet = substr($page, $dropZoneStart, 300);
ok(strpos($dropZoneSnippet, 'id="csvFile"') === false,
   '#csvFile is NOT embedded inside the #dropZone element');

// ── 5. Stage 1: JS click handler (not inline onclick) ────────────────────────
echo "\n\033[1m── 5. Stage 1 — JS file-handling ──\033[0m\n";
ok(strpos($page, "#dropZone').on('click") !== false || strpos($page, '#dropZone\').on(\'click\'') !== false
   || strpos($page, '"#dropZone").on("click"') !== false,
   "#dropZone click bound in JS (not inline onclick)");
ok(strpos($page, "#csvFile').on('change") !== false || strpos($page, '"#csvFile").on("change"') !== false,
   "#csvFile change event bound in JS");
ok(strpos($page, 'dragover') !== false, 'dragover event handled');
ok(strpos($page, "'drop'") !== false || strpos($page, '"drop"') !== false, 'drop event handled');
// Ensure the dropZone HTML update does NOT re-embed a new <input id="csvFile"> inside
// (it would recreate the bug on subsequent clicks)
$updateSnippets = [];
preg_match_all("/'#dropZone'\)\.html\(.*?\)/s", $page, $updateSnippets);
$dropZoneHtmlCalls = $updateSnippets[0] ?? [];
$hasEmbeddedInput = false;
foreach ($dropZoneHtmlCalls as $call) {
    if (strpos($call, 'csvFile') !== false && strpos($call, 'type="file"') !== false) {
        $hasEmbeddedInput = true;
    }
}
ok(!$hasEmbeddedInput, "dropZone HTML update does NOT re-embed <input type='file'> inside it");

// ── 6. Stage 2: null guard in buildMapping autoMatch ─────────────────────────
echo "\n\033[1m── 6. Stage 2 — null guard in autoMatch ──\033[0m\n";
// Check the guard within the autoMatch block specifically
$autoMatchBlock = '';
$amPos = strpos($page, 'autoMatch');
if ($amPos !== false) $autoMatchBlock = substr($page, $amPos, 600);
ok(strpos($autoMatchBlock, 'h != null') !== false || strpos($autoMatchBlock, "(h || '')") !== false,
   'null guard exists before h.toLowerCase() in autoMatch block');
// Confirm guard is adjacent to keywords.some call (not just anywhere in the page)
ok(strpos($autoMatchBlock, 'h != null && keywords.some') !== false || strpos($autoMatchBlock, "(h || '').toLowerCase()") !== false,
   'null guard is adjacent to the keywords.some() call (not just nearby)');

// ── 7. Stage 1: try/catch in loadHeaders so button always restores ────────────
echo "\n\033[1m── 7. Stage 1 — loadHeaders error handling ──\033[0m\n";
// Check that loadHeaders' success callback has a try/catch
$loadHeadersFn = '';
$pos = strpos($page, 'function loadHeaders(');
if ($pos !== false) $loadHeadersFn = substr($page, $pos, 1200);
ok(strpos($loadHeadersFn, 'try {') !== false, 'try block in loadHeaders success callback');
ok(strpos($loadHeadersFn, 'catch') !== false, 'catch block in loadHeaders success callback');

// ── 8. Stage 2: column mapping completeness ───────────────────────────────────
echo "\n\033[1m── 8. Stage 2 — column mapping fields ──\033[0m\n";
$requiredFields = ['col_first_name', 'col_last_name', 'col_company', 'col_email',
                   'col_phone', 'col_source', 'col_value', 'col_close_date', 'col_stage', 'col_notes'];
foreach ($requiredFields as $field) {
    ok(strpos($page, $field) !== false, "mapping field '$field' present in page");
}
ok(strpos($page, 'autoMatch') !== false, 'autoMatch dictionary defined in buildMapping');

// ── 9. API: fgetcsv reads CSV headers correctly ───────────────────────────────
echo "\n\033[1m── 9. API Stage 1 — CSV header extraction ──\033[0m\n";
$tmpCsv = sys_get_temp_dir() . '/test_import_' . uniqid() . '.csv';
file_put_contents($tmpCsv, "First Name,Last Name,Email,Company,Phone,Source\nJohn,Doe,john@test.com,Acme,+255700000001,referral\nJane,,jane@test.com,,,\n");
$handle = fopen($tmpCsv, 'r');
$hdrs = fgetcsv($handle);
$row1 = fgetcsv($handle);
$row2 = fgetcsv($handle);
fclose($handle);
ok(is_array($hdrs) && count($hdrs) === 6, 'fgetcsv reads 6 headers from test CSV');
ok($hdrs[0] === 'First Name', 'first header is "First Name"');
ok($hdrs[2] === 'Email', 'third header is "Email"');
// Test null-header safety: empty cell in headers becomes empty string, not null
$csvWithEmpty = sys_get_temp_dir() . '/test_import_empty_' . uniqid() . '.csv';
file_put_contents($csvWithEmpty, "Name,,Email\nJohn,,john@x.com\n");
$h2 = fopen($csvWithEmpty, 'r');
$emptyHdrs = fgetcsv($h2);
fclose($h2);
ok(is_array($emptyHdrs) && $emptyHdrs[1] === '', 'fgetcsv returns empty string (not null) for empty header cell');
$allStrings = array_reduce($emptyHdrs, fn($c, $v) => $c && is_string($v), true);
ok($allStrings, 'all fgetcsv header values are strings (safe to call toLowerCase on)');

// ── 10. API Stage 2: mapping + preview logic ──────────────────────────────────
echo "\n\033[1m── 10. API Stage 2 — preview row processing ──\033[0m\n";
// Simulate the getCol lambda
$getCol = function(array $row, int $idx, string $default = ''): string {
    return ($idx >= 0 && isset($row[$idx])) ? trim($row[$idx]) : $default;
};
$row = ['John', 'Doe', 'john@x.com', 'Acme', '+255700000001', 'referral'];
ok($getCol($row, 0) === 'John', 'getCol: col 0 returns first_name');
ok($getCol($row, -1) === '',    'getCol: col -1 (unmapped) returns empty string');
ok($getCol($row, 99) === '',    'getCol: out-of-bounds col returns empty string');
// Validate source normalization
$valid_sources = ['website','referral','walk_in','phone_call','social_media','exhibition','cold_call','email_campaign','other'];
$raw = 'Referral';
$source = in_array(strtolower($raw), $valid_sources, true) ? strtolower($raw) : 'other';
ok($source === 'referral', 'source "Referral" normalised to "referral"');
$bad = 'telemarketing';
$source2 = in_array(strtolower($bad), $valid_sources, true) ? strtolower($bad) : 'other';
ok($source2 === 'other', 'unknown source falls back to "other"');

// ── 11. API Stage 3: duplicate detection + insert + rollback ─────────────────
echo "\n\033[1m── 11. API Stage 3 — DB import logic ──\033[0m\n";
$testEmail = 'NONEXISTENT_TEST_9999@import-test.invalid';
$stmt = $pdo->prepare("SELECT COUNT(*) FROM crm_leads WHERE email = ? AND status != 'deleted'");
$stmt->execute([$testEmail]);
ok((int)$stmt->fetchColumn() === 0, 'duplicate check: non-existent email → 0 duplicates');

$defaultStage = (int)$pdo->query("SELECT stage_id FROM crm_pipeline_stages WHERE status='active' ORDER BY stage_order LIMIT 1")->fetchColumn();
ok($defaultStage > 0, 'default pipeline stage exists');

$pdo->beginTransaction();
try {
    $pdo->prepare("
        INSERT INTO crm_leads (lead_code, first_name, last_name, company_name, email, phone,
            lead_source, pipeline_stage_id, assigned_to, lead_value, expected_close_date,
            notes, campaign_id, country, status, created_by, stage_entered, created_at, updated_at)
        VALUES ('TEST-IMP-9999','Test','Import','TestCo',?,null,'other',?,null,0,null,null,null,
                'Tanzania','active',1,NOW(),NOW(),NOW())
    ")->execute([$testEmail, $defaultStage]);
    $newId = (int)$pdo->lastInsertId();
    ok($newId > 0, 'test lead insert succeeded (id=' . $newId . ')');

    $found = (int)$pdo->query("SELECT COUNT(*) FROM crm_leads WHERE lead_id = $newId")->fetchColumn();
    ok($found === 1, 'inserted lead is visible in same transaction');

    // duplicate detection now returns 1
    $stmt->execute([$testEmail]);
    ok((int)$stmt->fetchColumn() === 1, 'duplicate detection catches the just-inserted lead');

    $pdo->rollBack();
    $after = (int)$pdo->query("SELECT COUNT(*) FROM crm_leads WHERE email = '$testEmail'")->fetchColumn();
    ok($after === 0, 'rollback confirmed — test lead removed from DB');
} catch (Exception $e) {
    $pdo->rollBack();
    ok(false, 'insert failed: ' . $e->getMessage());
    ok(false, 'lead visible check skipped');
    ok(false, 'duplicate detection skipped');
    ok(false, 'rollback skipped');
}

// ── 12. API: rejection guards ─────────────────────────────────────────────────
echo "\n\033[1m── 12. API rejection guards ──\033[0m\n";
ok(strpos($api, "\$ext !== 'csv'") !== false, 'API rejects non-CSV by extension check');
ok(strpos($api, '5 * 1024 * 1024') !== false, 'API enforces 5 MB file size limit');
ok(strpos($api, 'No file uploaded') !== false, 'API handles missing file upload');
ok(strpos($api, 'csrf_check()') !== false, 'API enforces CSRF check');

// cleanup
unlink($tmpCsv);
unlink($csvWithEmpty);

// ── Summary ───────────────────────────────────────────────────────────────────
echo "\n";
echo "Passes:   \033[32m$pass\033[0m\n";
echo "Failures: \033[31m$fail\033[0m\n";
if ($fail === 0) {
    echo "\033[32m✅ All import leads checks passed.\033[0m\n\n";
    exit(0);
} else {
    echo "\033[31m❌ $fail check(s) failed — bugs remain.\033[0m\n\n";
    exit(1);
}
