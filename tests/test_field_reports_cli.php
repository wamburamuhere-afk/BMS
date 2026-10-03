<?php
/**
 * tests/test_field_reports_cli.php — Field Reports (marketing) module.
 *
 * Every endpoint runs in its own PHP process as a forged user
 * (tests/helpers/field_reports_request.php), so these are the real request paths:
 *   1. registry + schema       — module off by default, tables/permission exist
 *   2. module gate             — module OFF blocks pages, APIs, nav for everyone (admin too)
 *   3. ownership               — staff A never sees/changes staff B's visits (list, print,
 *                                export, edit, delete, joined, phone check); admin sees all
 *   4. validation              — required fields, future date, phone, GPS, "other"
 *   5. submit-day flow         — submitted, then "updated after it was submitted"
 *   6. print report            — Kiswahili + English, landscape + portrait, footer
 *   7. Excel export            — BOM, translated headings, formula guard
 *   8. stats                   — a client is counted once (normalised phone)
 *
 * Fixtures: two existing non-admin users in DIFFERENT roles, both given FULL
 * field_visits rights (so only the ownership rule can stop them), plus admin.
 * Every row this test writes is removed at the end.
 *
 *   php tests/test_field_reports_cli.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../roots.php';
require_once ROOT_DIR . '/core/field_reports.php';
require_once ROOT_DIR . '/core/field_reports_schema.php';

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  ✓ $m\n"; } else { $fail++; echo "  ✗ $m\n"; } }
function section($t) { echo "\n── $t\n"; }

/** Run an endpoint as a user → [http code, raw body, decoded json|null]. */
function call(string $endpoint, int $uid, bool $admin, string $method = 'GET', array $params = [], ?array $features = null, ?array $perms = null): array
{
    $perms = $perms ?? ['view' => true, 'create' => true, 'edit' => true, 'delete' => true];
    $args = [PHP_BINARY, __DIR__ . '/helpers/field_reports_request.php', $endpoint, (string)$uid, $admin ? '1' : '0',
             json_encode($perms), $method, json_encode($params)];
    if ($features !== null) $args[] = json_encode($features);
    $proc = proc_open($args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOT_DIR);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
    $code = preg_match('/HTTP_CODE=(\d+)/', $err, $m) ? (int)$m[1] : 0;
    if (preg_match('/(Fatal error|Warning|Notice|Deprecated|Uncaught)/', $err . $out, $w)) echo "    ! PHP said '{$w[1]}' in $endpoint: " . substr(trim($err . ' ' . $out), 0, 300) . "\n";
    return [$code, $out, json_decode($out, true)];
}

// ── Fixtures ────────────────────────────────────────────────────────────
$adminId = (int)$pdo->query("SELECT u.user_id FROM users u JOIN roles r ON r.role_id = u.role_id WHERE r.is_admin = 1 AND u.is_active = 1 ORDER BY u.user_id LIMIT 1")->fetchColumn();
$staff = $pdo->query("SELECT u.user_id, u.role_id FROM users u JOIN roles r ON r.role_id = u.role_id
                      WHERE r.is_admin = 0 AND u.is_active = 1 ORDER BY u.user_id")->fetchAll(PDO::FETCH_ASSOC);
$A = (int)($staff[0]['user_id'] ?? 0);
$B = 0;
foreach ($staff as $s) if ((int)$s['role_id'] !== (int)($staff[0]['role_id'] ?? 0)) { $B = (int)$s['user_id']; break; }
if (!$adminId || !$A || !$B) { echo "Need one admin and two active non-admin users in different roles.\n"; exit(1); }
echo "Fixtures: admin #$adminId, staff A #$A, staff B #$B (different roles, full field_visits rights)\n";

$today = date('Y-m-d');
$day1  = date('Y-m-d', strtotime('-3 days'));
$day2  = date('Y-m-d', strtotime('-2 days'));
$tag   = 'FRTEST-' . bin2hex(random_bytes(3));
$created = [];
$daysBefore = $pdo->query("SELECT day_id FROM field_report_days")->fetchAll(PDO::FETCH_COLUMN);
$logStart = date('Y-m-d H:i:s', time() - 2);

function visit(array $over = []): array
{
    global $tag, $day1;
    return $over + [
        'visit_date' => $day1, 'visit_time' => '10:15', 'location' => "Kariakoo $tag",
        'client_name' => "Client $tag", 'client_phone' => '0712 345 678', 'business_type' => 'retail',
        'gave_business_card' => '1', 'gave_trial_link' => '1', 'gave_training' => '0',
        'interest' => 'interested', 'notes' => "Note $tag",
    ];
}
function save(int $uid, bool $admin, array $data): array { return call('api/field_reports/save.php', $uid, $admin, 'POST', $data); }

try {
    // ═════════════════════════════════════════════════════════════════
    section('1. Registry + schema');
    $reg = bmsFeatureRegistry();
    ok(isset($reg['field_reports']), "registry has 'field_reports'");
    ok(($reg['field_reports']['default'] ?? null) === false, 'module is OFF by default');
    ok(in_array('field_visits', $reg['field_reports']['page_keys'] ?? [], true), "module owns page key 'field_visits'");
    ok(featureForPath('app/bms/field_reports/field_visits.php') === 'field_reports', 'page path belongs to the module');
    ok(featureForPath('api/field_reports/list.php') === 'field_reports', 'api path belongs to the module');
    fieldReportsEnsureSchema($pdo); fieldReportsEnsureSchema($pdo);
    ok(true, 'schema ensure is idempotent (ran twice)');
    ok((bool)$pdo->query("SHOW TABLES LIKE 'field_visits'")->fetch(), 'table field_visits exists');
    ok((bool)$pdo->query("SHOW TABLES LIKE 'field_report_days'")->fetch(), 'table field_report_days exists');
    ok((int)$pdo->query("SELECT COUNT(*) FROM permissions WHERE page_key = 'field_visits'")->fetchColumn() === 1, "permission 'field_visits' seeded once");
    require_once ROOT_DIR . '/core/control_db.php';
    if (controlDbReady()) {
        $row = getControlPdo()->query("SELECT default_enabled FROM features WHERE feature_key = 'field_reports'")->fetch(PDO::FETCH_ASSOC);
        ok($row !== false && (int)$row['default_enabled'] === 0, 'control-DB catalogue row exists with default_enabled = 0 (stays off for tenants)');
    }

    // ═════════════════════════════════════════════════════════════════
    section('2. Module gate (OFF for the tenant)');
    $off = ['field_reports' => false];
    $GLOBALS['__bms_features'] = $off;
    ok(bmsFeatureBlockingPath('app/bms/field_reports/field_visits.php') === 'field_reports', 'page URL is blocked when off');
    ok(bmsFeatureBlockingPath('api/field_reports/save.php') === 'field_reports', 'API URL is blocked when off');
    ok(!tenantModuleAllowsPage('field_visits'), 'page key not allowed when off');
    $_SESSION['is_admin'] = true;
    ok(!canView('field_visits'), 'even the admin cannot view when off (nav link hidden)');
    unset($_SESSION['is_admin']);
    $GLOBALS['__bms_features'] = ['field_reports' => true];
    ok(tenantModuleAllowsPage('field_visits') && bmsFeatureBlockingPath('api/field_reports/list.php') === null, 'allowed once the superadmin turns it on');
    $GLOBALS['__bms_features'] = null;
    [$c] = call('api/field_reports/list.php', $adminId, true, 'GET', [], $off);
    ok($c === 403, "list API as admin with module off → 403 (got $c)");
    [$c] = save($A, false, visit());
    // (module on here) sanity: staff can create
    ok($c === 200, "with module on, staff A can save (got $c)");
    $pdo->prepare("DELETE FROM field_visits WHERE location = ?")->execute(["Kariakoo $tag"]);
    $hdr = file_get_contents(ROOT_DIR . '/header.php');
    ok(substr_count($hdr, "canView('field_visits')") >= 2, 'header nav + phone More sheet links are gated by canView(field_visits)');

    // No permission at all → blocked
    [$c] = call('api/field_reports/list.php', $A, false, 'GET', [], null, ['view' => false]);
    ok($c === 403, "user without field_visits permission → 403 (got $c)");

    // ═════════════════════════════════════════════════════════════════
    section('3. Ownership — staff see only their own; admin sees all');
    [$c, , $j] = save($A, false, visit(['client_name' => "Asha A $tag", 'client_phone' => '0712000111']));
    $aVisit = (int)($j['visit_id'] ?? 0); $created[] = $aVisit;
    ok($c === 200 && $aVisit > 0, 'staff A recorded a visit');
    [$c, , $j] = save($B, false, visit(['client_name' => "Baraka B $tag", 'client_phone' => '0755000222', 'location' => "Mbezi $tag"]));
    $bVisit = (int)($j['visit_id'] ?? 0); $created[] = $bVisit;
    ok($c === 200 && $bVisit > 0, 'staff B recorded a visit');

    $range = ['date_from' => $day1, 'date_to' => $day1];
    [, , $j] = call('api/field_reports/list.php', $A, false, 'GET', $range);
    $names = array_column($j['rows'] ?? [], 'client_name');
    ok(in_array("Asha A $tag", $names, true), 'A sees own visit');
    ok(!in_array("Baraka B $tag", $names, true), "A does NOT see B's visit");
    [, , $j] = call('api/field_reports/list.php', $A, false, 'GET', $range + ['user_id' => (string)$B]);
    $names = array_column($j['rows'] ?? [], 'client_name');
    ok(($j['scope_user_id'] ?? null) === $A && !in_array("Baraka B $tag", $names, true), "A asking ?user_id=B still gets only A's own (param ignored)");
    [, , $j] = call('api/field_reports/list.php', $A, false, 'GET', $range + ['user_id' => '']);
    ok(($j['scope_user_id'] ?? null) === $A, "A asking for 'all staff' still gets only A");
    ok(($j['staff_summary'] ?? null) === [], 'non-admin gets no per-staff summary');
    [, , $j] = call('api/field_reports/list.php', $B, false, 'GET', $range);
    $names = array_column($j['rows'] ?? [], 'client_name');
    ok(in_array("Baraka B $tag", $names, true) && !in_array("Asha A $tag", $names, true), "B sees own only, not A's");

    [, , $j] = call('api/field_reports/list.php', $adminId, true, 'GET', $range);
    $names = array_column($j['rows'] ?? [], 'client_name');
    ok(in_array("Asha A $tag", $names, true) && in_array("Baraka B $tag", $names, true), 'admin sees both A and B');
    $sumIds = array_column($j['staff_summary'] ?? [], 'user_id');
    ok(in_array($A, array_map('intval', $sumIds), true) && in_array($B, array_map('intval', $sumIds), true), 'admin gets the per-staff summary for both');
    [, , $j] = call('api/field_reports/list.php', $adminId, true, 'GET', $range + ['user_id' => (string)$B]);
    $names = array_column($j['rows'] ?? [], 'client_name');
    ok(in_array("Baraka B $tag", $names, true) && !in_array("Asha A $tag", $names, true), 'admin can narrow to one staff member');

    // Writes on someone else's visit
    [$c] = save($A, false, visit(['visit_id' => $bVisit, 'client_name' => 'HACKED']));
    ok($c === 403, "A cannot edit B's visit (got $c)");
    [$c] = call('api/field_reports/delete.php', $A, false, 'POST', ['visit_id' => $bVisit]);
    ok($c === 403, "A cannot delete B's visit (got $c)");
    [$c] = call('api/field_reports/toggle_joined.php', $A, false, 'POST', ['visit_id' => $bVisit, 'joined' => 1]);
    ok($c === 403, "A cannot mark B's client as joined (got $c)");
    $b = $pdo->query("SELECT client_name, status, joined FROM field_visits WHERE visit_id = $bVisit")->fetch(PDO::FETCH_ASSOC);
    ok($b['client_name'] === "Baraka B $tag" && $b['status'] === 'active' && (int)$b['joined'] === 0, "B's visit is untouched in the database");
    [, , $j] = call('api/field_reports/list.php', $A, false, 'GET', $range);
    $row = array_values(array_filter($j['rows'] ?? [], fn($r) => (int)$r['visit_id'] === $aVisit))[0] ?? [];
    ok(!empty($row['can_edit']) && !empty($row['can_delete']), 'A can edit/delete own visit (row flags)');

    // Phone check — never leaks another staff member's client
    [, , $j] = call('api/field_reports/check_phone.php', $A, false, 'GET', ['phone' => '+255 755 000 222']);
    ok(is_array($j) && array_key_exists('match', $j) && $j['match'] === null, "A's phone check does not reveal B's client");
    [, , $j] = call('api/field_reports/check_phone.php', $A, false, 'GET', ['phone' => '255712000111']);
    ok(($j['match']['client_name'] ?? '') === "Asha A $tag" && ($j['match']['staff_name'] ?? null) === null, 'A is warned about own earlier visit (no staff name)');
    [, , $j] = call('api/field_reports/check_phone.php', $adminId, true, 'GET', ['phone' => '0755000222']);
    ok(($j['match']['client_name'] ?? '') === "Baraka B $tag" && !empty($j['match']['staff_name']), 'admin phone check sees any staff member + who visited');

    // Print + export as A asking for B
    [$c, $html] = call('app/bms/field_reports/field_report_print.php', $A, false, 'GET', $range + ['user_id' => (string)$B, 'lang' => 'en']);
    ok($c === 200 && strpos($html, "Asha A $tag") !== false && strpos($html, "Baraka B $tag") === false, "A's print with ?user_id=B shows only A's visits");
    [$c, $csv] = call('api/field_reports/export.php', $A, false, 'GET', $range + ['user_id' => (string)$B, 'lang' => 'en']);
    ok($c === 200 && strpos($csv, "Asha A $tag") !== false && strpos($csv, "Baraka B $tag") === false, "A's Excel export with ?user_id=B has only A's visits");
    [, $html] = call('app/bms/field_reports/field_report_print.php', $adminId, true, 'GET', $range + ['lang' => 'en']);
    ok(strpos($html, "Asha A $tag") !== false && strpos($html, "Baraka B $tag") !== false, 'admin all-staff print shows both');

    // Admin may correct any visit; the owner stays the owner
    [$c] = save($adminId, true, visit(['visit_id' => $bVisit, 'client_name' => "Baraka B $tag", 'notes' => "Admin fixed $tag", 'location' => "Mbezi $tag", 'client_phone' => '0755000222']));
    $owner = (int)$pdo->query("SELECT user_id FROM field_visits WHERE visit_id = $bVisit")->fetchColumn();
    ok($c === 200 && $owner === $B, 'admin can edit B\'s visit and B stays the owner');

    // ═════════════════════════════════════════════════════════════════
    section('4. Validation');
    $cases = [
        'visit_date'     => [['visit_date' => date('Y-m-d', strtotime('+1 day'))], 'future date'],
        'location'       => [['location' => ''], 'missing place'],
        'client_name'    => [['client_name' => ' '], 'missing name'],
        'client_phone'   => [['client_phone' => '12345'], 'short phone'],
        'business_type'  => [['business_type' => 'bogus'], 'unknown business type'],
        'business_other' => [['business_type' => 'other', 'business_other' => ''], "'other' without description"],
        'visit_time'     => [['visit_time' => '25:99'], 'bad time'],
        'interest'       => [['interest' => 'maybe'], 'bad interest'],
    ];
    foreach ($cases as $field => [$over, $label]) {
        [$c, , $j] = save($A, false, visit($over));
        ok($c === 422 && isset($j['errors'][$field]), "rejects $label → 422 on '$field'");
    }
    [$c, , $j] = save($A, false, visit(['latitude' => '-6.8', 'longitude' => '']));
    ok($c === 422 && isset($j['errors']['location']), 'rejects half a GPS pair');
    [$c, , $j] = save($A, false, visit(['latitude' => '-6.81612', 'longitude' => '39.28034', 'gps_accuracy_m' => '12.4', 'client_name' => "GPS $tag", 'client_phone' => '0688111222']));
    $gpsId = (int)($j['visit_id'] ?? 0); $created[] = $gpsId;
    $g = $pdo->query("SELECT latitude, longitude, gps_accuracy_m, phone_normalized FROM field_visits WHERE visit_id = $gpsId")->fetch(PDO::FETCH_ASSOC);
    ok($c === 200 && abs($g['latitude'] + 6.81612) < 1e-5 && (int)$g['gps_accuracy_m'] === 12, 'optional GPS saved with accuracy');
    ok($g['phone_normalized'] === '255688111222', 'phone normalised 0688… → 255688…');
    [$c] = save($A, false, visit(['client_name' => "NoGPS $tag", 'client_phone' => '0655111222', 'business_type' => 'other', 'business_other' => 'Bodaboda spares']));
    ok($c === 200, 'visit without GPS and with "other" business saves');
    $created[] = (int)$pdo->query("SELECT MAX(visit_id) FROM field_visits WHERE client_name = " . $pdo->quote("NoGPS $tag"))->fetchColumn();
    [$c] = call('api/field_reports/save.php', $A, false, 'GET', visit());
    ok($c === 405, "save via GET → 405 (got $c)");
    [$c] = call('api/field_reports/save.php', $A, false, 'POST', visit(['_csrf' => 'wrong']));
    ok($c === 419 || $c === 403, "save with a bad CSRF token is refused (got $c)");

    // ═════════════════════════════════════════════════════════════════
    section('5. Submit-day flow');
    [$c, , $j] = call('api/field_reports/submit_day.php', $A, false, 'POST', ['date' => $day2]);
    ok($c === 422, 'cannot submit a day with no visits');
    [$c, , $j] = call('api/field_reports/submit_day.php', $A, false, 'POST', ['date' => $day1]);
    ok($c === 200 && !empty($j['submitted_time']), "A submits the $day1 report");
    [, , $j] = call('api/field_reports/list.php', $A, false, 'GET', $range);
    ok(($j['day_status']['visit_count'] ?? 0) >= 1 && (int)($j['day_status']['changed_after_submit'] ?? 1) === 0, 'list shows "submitted", not changed');
    [$c] = save($A, false, visit(['client_name' => "Late $tag", 'client_phone' => '0622333444']));
    $created[] = (int)$pdo->query("SELECT MAX(visit_id) FROM field_visits WHERE client_name = " . $pdo->quote("Late $tag"))->fetchColumn();
    [, , $j] = call('api/field_reports/list.php', $A, false, 'GET', $range);
    ok($c === 200 && (int)($j['day_status']['changed_after_submit'] ?? 0) === 1, 'adding a visit to a past submitted day → "updated after it was submitted"');
    [, $html] = call('app/bms/field_reports/field_report_print.php', $A, false, 'GET', $range + ['lang' => 'sw']);
    ok(strpos($html, 'imebadilishwa baada ya kuwasilishwa') !== false, 'print (sw) shows the changed-after-submit note');
    [, , $j] = call('api/field_reports/submit_day.php', $A, false, 'POST', ['date' => $day1]);
    [, , $j] = call('api/field_reports/list.php', $A, false, 'GET', $range);
    ok((int)($j['day_status']['changed_after_submit'] ?? 1) === 0, 're-submitting clears the changed flag');
    [, , $j] = call('api/field_reports/list.php', $B, false, 'GET', $range);
    ok(($j['day_status'] ?? null) === null, "B does not see A's submission (B has not submitted)");
    [$c, , $j] = call('api/field_reports/submit_day.php', $A, false, 'POST', ['date' => date('Y-m-d', strtotime('+1 day'))]);
    ok($c === 422, 'cannot submit a future day');

    // Joined toggle (own) + soft delete (own)
    [$c] = call('api/field_reports/toggle_joined.php', $A, false, 'POST', ['visit_id' => $aVisit, 'joined' => 1]);
    ok($c === 200 && (int)$pdo->query("SELECT joined FROM field_visits WHERE visit_id = $aVisit")->fetchColumn() === 1, 'A marks own client as joined');
    [$c] = call('api/field_reports/delete.php', $A, false, 'POST', ['visit_id' => $gpsId]);
    ok($c === 200 && $pdo->query("SELECT status FROM field_visits WHERE visit_id = $gpsId")->fetchColumn() === 'deleted', 'own delete is a soft delete');
    [, , $j] = call('api/field_reports/list.php', $A, false, 'GET', $range);
    ok(!in_array("GPS $tag", array_column($j['rows'] ?? [], 'client_name'), true), 'deleted visit no longer listed');

    // ═════════════════════════════════════════════════════════════════
    section('6. Print report — Kiswahili & English, landscape & portrait');
    [$c, $sw] = call('app/bms/field_reports/field_report_print.php', $A, false, 'GET', $range + ['lang' => 'sw', 'orient' => 'portrait']);
    ok($c === 200, 'Kiswahili print renders (200)');
    foreach (['RIPOTI YA ZIARA ZA WATEJA', 'Mahali alipotembelea', 'Jina la mteja', '>Alichopewa<', 'Amejiunga', 'Waliojiunga na mfumo wetu', 'Duka la rejareja', 'Ana nia', 'Ndiyo', 'Hapana', 'Chapisha / Hifadhi kama PDF', 'Ripoti hii imechapishwa na'] as $s)
        ok(strpos($sw, $s) !== false, "sw contains '$s'");
    $swDate = frDateLabel($day1, 'sw');
    ok(strpos($sw, $swDate) !== false, "sw date written in Swahili ('$swDate')");
    ok(preg_match('/(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday|January|February|March|April|June|July|August|September|October|November|December)/', strip_tags($sw)) === 0, 'sw report has no English day/month names');
    foreach (['CUSTOMER VISITS REPORT', 'Place visited', 'This document was Printed by', 'Business card'] as $s)
        ok(strpos($sw, $s) === false, "sw does not fall back to English '$s'");
    ok(strpos($sw, '<html lang="sw">') !== false, 'sw page declares lang="sw"');
    ok(preg_match('/@page\s*\{\s*size:\s*A4;/', $sw) === 1, '@page is plain A4 — portrait/landscape is the print dialog\'s Layout');
    ok(strpos($sw, '@media (orientation: portrait)') !== false && strpos($sw, '.k-card, .k-trial, .k-training { display: none; }') !== false, 'portrait (from the print dialog) merges card/trial/training into one column');

    [$c, $en] = call('app/bms/field_reports/field_report_print.php', $A, false, 'GET', $range + ['lang' => 'en', 'orient' => 'landscape']);
    ok($c === 200, 'English print renders (200)');
    foreach (['CUSTOMER VISITS REPORT', 'S/No', 'Place visited', 'Client name', '>Card<', '>Trial<', 'Joined our system', 'Retail shop', 'Interested', 'This document was Printed by'] as $s)
        ok(strpos($en, $s) !== false, "en contains '$s'");
    ok(strpos($en, 'RIPOTI YA MATEMBEZI') === false && strpos($en, 'Ripoti hii imechapishwa') === false, 'en has no Swahili headings/footer');
    ok(strpos($en, 'class="k-card c"') !== false && strpos($en, 'class="k-given"') !== false, 'both column sets are in the page; CSS shows the right one');
    ok(strpos($en, 'print_footer') !== false || strpos($en, 'print-footer') !== false, 'footer uses the shared .print-footer (same as General Ledger)');
    ok(strpos($en, 'table-layout: fixed') !== false || strpos($en, 'table-layout:fixed') !== false, 'table uses fixed layout (no overflow off the page)');
    ok(strpos($en, 'overflow-wrap') !== false && strpos($en, 'table-header-group') !== false, 'words wrap (never cut) and headers repeat on every page');
    [$c, $multi] = call('app/bms/field_reports/field_report_print.php', $adminId, true, 'GET', ['date_from' => $day1, 'date_to' => $today, 'lang' => 'en']);
    ok($c === 200 && strpos($multi, '>Date<') !== false && strpos($multi, '>Staff<') !== false, 'multi-day all-staff print adds Date + Staff columns');
    [$c, $empty] = call('app/bms/field_reports/field_report_print.php', $A, false, 'GET', ['date_from' => $day2, 'date_to' => $day2, 'lang' => 'sw']);
    ok($c === 200 && strpos($empty, 'Hakuna ziara zilizorekodiwa') !== false, 'empty day prints a clean "no visits" row (sw)');
    [, $bad] = call('app/bms/field_reports/field_report_print.php', $A, false, 'GET', $range + ['lang' => 'xx', 'orient' => 'sideways']);
    ok(strpos($bad, '<html lang="en">') !== false && strpos($bad, 'orient=') === false, 'unknown lang → user default; old orient param simply ignored');
    // XSS: typed text is escaped
    [$c, , $j] = save($A, false, visit(['client_name' => "<script>x</script> $tag", 'client_phone' => '0699888777']));
    $created[] = (int)($j['visit_id'] ?? 0);
    [, $html] = call('app/bms/field_reports/field_report_print.php', $A, false, 'GET', $range + ['lang' => 'en']);
    ok(strpos($html, '<script>x</script>') === false && strpos($html, '&lt;script&gt;x') !== false, 'client text is HTML-escaped on the print page');

    // ═════════════════════════════════════════════════════════════════
    section('7. Excel (CSV) export');
    [$c, , $j] = save($A, false, visit(['client_name' => "=HYPERLINK(\"x\") $tag", 'client_phone' => '0677666555']));
    $created[] = (int)($j['visit_id'] ?? 0);
    [$c, $csv] = call('api/field_reports/export.php', $A, false, 'GET', $range + ['lang' => 'sw']);
    ok($c === 200 && substr($csv, 0, 3) === "\xEF\xBB\xBF", 'CSV starts with a UTF-8 BOM (Excel-safe)');
    ok(strpos($csv, 'RIPOTI YA ZIARA ZA WATEJA') !== false && strpos($csv, 'Mahali alipotembelea') !== false, 'sw export headings translated');
    ok(strpos($csv, "'=HYPERLINK") !== false, 'formula-looking text is neutralised with a leading quote');
    [, $csvEn] = call('api/field_reports/export.php', $A, false, 'GET', $range + ['lang' => 'en']);
    ok(strpos($csvEn, 'CUSTOMER VISITS REPORT') !== false && strpos($csvEn, 'S/No') !== false, 'en export headings in English');

    // ═════════════════════════════════════════════════════════════════
    section('8. Stats — a client counted once');
    $s = frStats([
        ['client_phone' => '0712 000 999', 'phone_normalized' => '255712000999', 'client_name' => 'X', 'location' => 'Kariakoo', 'gave_business_card' => 1, 'gave_trial_link' => 0, 'gave_training' => 1, 'joined' => 1],
        ['client_phone' => '+255712000999', 'phone_normalized' => '255712000999', 'client_name' => 'X again', 'location' => 'kariakoo ', 'gave_business_card' => 1, 'gave_trial_link' => 1, 'gave_training' => 0, 'joined' => 1],
        ['client_phone' => '0755000000', 'phone_normalized' => '255755000000', 'client_name' => 'Y', 'location' => 'Mbezi', 'gave_business_card' => 0, 'gave_trial_link' => 0, 'gave_training' => 0, 'joined' => 0],
    ]);
    ok($s['visits'] === 3, '3 visits');
    ok($s['people'] === 2, 'same phone in two formats = 1 person (2 people)');
    ok($s['places'] === 2, "'Kariakoo' / 'kariakoo ' = 1 place (2 places)");
    ok($s['joined'] === 1, 'a client who joined is counted once');
    ok($s['cards'] === 2 && $s['trials'] === 1 && $s['trainings'] === 1, 'card/trial/training counts');
    ok(frNormalizePhone('0712 345 678') === '255712345678' && frNormalizePhone('712345678') === '255712345678' && frNormalizePhone('+255 712 345 678') === '255712345678', 'phone normalisation formats');
    ok(frDateLabel('2026-10-02', 'sw') === 'Ijumaa, 2 Oktoba 2026' && strpos(frDateLabel('2026-10-02', 'en'), 'Friday') === 0, 'date labels: sw "Ijumaa, 2 Oktoba 2026" / en "Friday…"');

    // ═════════════════════════════════════════════════════════════════
    section('9. Main page renders + lint');
    foreach (array_merge(glob(ROOT_DIR . '/api/field_reports/*.php'), glob(ROOT_DIR . '/app/bms/field_reports/*.php'),
             [ROOT_DIR . '/core/field_reports.php', ROOT_DIR . '/core/field_reports_report.php', ROOT_DIR . '/core/field_reports_schema.php', ROOT_DIR . '/lang/sw.php']) as $f) {
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($f) . ' 2>&1', $o, $rc);
        ok($rc === 0, 'lint ' . str_replace(ROOT_DIR . '/', '', str_replace('\\', '/', $f)));
    }
    $page = file_get_contents(ROOT_DIR . '/app/bms/field_reports/field_visits.php');
    ok(strpos($page, "autoEnforcePermission('field_visits')") !== false, 'page enforces field_visits permission');
    ok(preg_match('/<select[^>]*id="fStaff"/', $page) && strpos($page, '<?php if ($is_admin): ?>') !== false, 'staff picker is admin-only');
    ok(strpos($page, 'alert(') === false && strpos($page, 'confirm(') === false, 'no native alert/confirm (SweetAlert only)');
    ok(strpos(file_get_contents(ROOT_DIR . '/roots.php'), "'field_reports/print'") !== false, "route 'field_reports/print' registered");
    $sw = include ROOT_DIR . '/lang/sw.php';
    $missing = [];
    foreach (array_merge(array_values(frBusinessTypes()), array_values(frInterestLabels())) as $l) if (!isset($sw[$l])) $missing[] = $l;
    ok(!$missing, 'every business type + interest label has a Swahili translation' . ($missing ? ' (missing: ' . implode(', ', $missing) . ')' : ''));

    // ═════════════════════════════════════════════════════════════════
    section('10. Phase 1 — API language, labelled phone badges, one clock');
    [, , $j] = call('api/field_reports/list.php', $A, false, 'GET', $range + ['__lang' => 'sw']);
    $lbl = array_column($j['rows'] ?? [], 'business_label');
    ok(in_array('Duka la rejareja', $lbl, true) && !in_array('Retail shop', $lbl, true), 'API speaks the user\'s language: "Duka la rejareja", not "Retail shop"');
    [$c, , $j] = call('api/field_reports/save.php', $A, false, 'POST', visit(['client_name' => '', '__lang' => 'sw']));
    ok($c === 422 && ($j['errors']['client_name'] ?? '') === 'Andika jina la mteja.', 'validation messages in Swahili for a Swahili user');
    [, , $j] = call('api/field_reports/list.php', $A, false, 'GET', $range + ['__lang' => 'en']);
    ok(in_array('Retail shop', array_column($j['rows'] ?? [], 'business_label'), true), 'English user still gets English');
    $future = date('H:i', time() + 3600);
    if ($future > date('H:i')) {   // not across midnight
        [$c, , $j] = save($A, false, visit(['visit_date' => $today, 'visit_time' => $future, 'client_phone' => '0611222333']));
        ok($c === 422 && isset($j['errors']['visit_time']), "today's visit at $future (an hour ahead) is rejected");
    }
    [$c, , $j] = save($A, false, visit(['visit_date' => $today, 'visit_time' => '', 'client_name' => "Now $tag", 'client_phone' => '0611222334']));
    $t = $pdo->query("SELECT visit_time FROM field_visits WHERE visit_id = " . (int)($j['visit_id'] ?? 0))->fetchColumn();
    $created[] = (int)($j['visit_id'] ?? 0);
    ok($c === 200 && $t && abs(strtotime($today . ' ' . $t) - time()) < 120, "an empty time on today's visit is stored as the server's now ($t)");
    [$c] = save($A, false, visit(['visit_date' => $day1, 'visit_time' => '23:30', 'client_name' => "Past $tag", 'client_phone' => '0611222335']));
    ok($c === 200, 'a late time on an earlier day is fine');
    $created[] = (int)$pdo->query("SELECT MAX(visit_id) FROM field_visits WHERE client_name = " . $pdo->quote("Past $tag"))->fetchColumn();
    $page = file_get_contents(ROOT_DIR . '/app/bms/field_reports/field_visits.php');
    ok(strpos($page, 'ynL(L.cardShort, r.gave_business_card)') !== false && strpos($page, 'ynL(L.trialShort') !== false && strpos($page, 'ynL(L.trainingShort') !== false, 'phone card badges carry their names');
    ok(strpos($page, 'const now = serverNow();') !== false && strpos($page, 'getHours()') === false, 'default time comes from the server clock, not the phone');

    // ═════════════════════════════════════════════════════════════════
    section('11. Phase 2 — "Ziara za Wateja", phone-first form, GPS place suggestion');
    $reg = bmsFeatureRegistry()['field_reports'];
    ok($reg['label'] === 'Customer Visits (Marketing)' && in_array('Field Reports (Marketing)', $reg['previous_labels'] ?? [], true), 'registry: new label, old one kept as previous_labels');
    require_once ROOT_DIR . '/core/control_db.php';
    if (controlDbReady()) {
        $cp = getControlPdo();
        $orig = $cp->query("SELECT label FROM features WHERE feature_key = 'field_reports'")->fetchColumn();
        $cp->exec("UPDATE features SET label = 'Field Reports (Marketing)' WHERE feature_key = 'field_reports'");
        syncFeatureCatalogue();
        ok($cp->query("SELECT label FROM features WHERE feature_key = 'field_reports'")->fetchColumn() === 'Customer Visits (Marketing)', 'catalogue: the label we shipped is renamed on sync');
        $cp->exec("UPDATE features SET label = 'Our Own Name' WHERE feature_key = 'field_reports'");
        syncFeatureCatalogue();
        ok($cp->query("SELECT label FROM features WHERE feature_key = 'field_reports'")->fetchColumn() === 'Our Own Name', "catalogue: an operator's own label is never overwritten");
        $cp->prepare("UPDATE features SET label = ? WHERE feature_key = 'field_reports'")->execute([$orig === 'Our Own Name' ? 'Customer Visits (Marketing)' : ($orig ?: 'Customer Visits (Marketing)')]);
    }
    fieldReportsEnsureSchema($pdo);
    ok($pdo->query("SELECT page_name FROM permissions WHERE page_key = 'field_visits'")->fetchColumn() === 'Customer Visits', 'roles screen: permission now "Customer Visits"');
    $sw = include ROOT_DIR . '/lang/sw.php';
    ok(($sw['Customer Visits'] ?? '') === 'Ziara za Wateja' && ($sw['Record a visit'] ?? '') === 'Rekodi Ziara', 'sw: "Ziara za Wateja" / "Rekodi Ziara"');
    ok(substr_count(file_get_contents(ROOT_DIR . '/header.php'), "t('Customer Visits')") === 2, 'nav + phone More sheet use the new name');
    [$c, $html] = call('app/bms/field_reports/field_visits.php', $A, false, 'GET', ['__lang' => 'sw']);
    ok($c === 200 && strpos($html, 'Ziara za Wateja') !== false && strpos($html, 'Ripoti za Uwandani') === false, 'page renders as "Ziara za Wateja" (sw)');
    $posPhone = strpos($html, 'id="vPhone"'); $posName = strpos($html, 'id="vName"'); $posDate = strpos($html, 'id="vDate"');
    ok($posPhone !== false && $posPhone < $posName && $posName < $posDate, 'form order: Phone → Name → … → (hidden) Date/Time');
    ok(strpos($html, 'modal-fullscreen-sm-down') !== false, 'form is full-screen on phones');
    ok(substr_count($html, 'class="btn-check" name="gave_') === 3 && substr_count($html, 'class="btn-check fr-interest" name="interest"') === 3, 'big tap buttons for card/trial/training and the three responses');
    ok(strpos($html, 'id="vWhenFields"') !== false && preg_match('#class="row g-2 d-none" id="vWhenFields"#', $html) === 1, 'date/time hidden behind "Change date / time"');
    ok(strpos($html, 'id="mainStats"') !== false && strpos($html, 'id="moreStats"') !== false, 'three main numbers + "More statistics"');

    // returning client
    [$c, , $j] = save($A, false, visit(['client_name' => "Mama Rose $tag", 'client_phone' => '0744111222', 'business_type' => 'salon', 'location' => "Sinza $tag"]));
    $created[] = (int)($j['visit_id'] ?? 0);
    [$c, , $j] = save($A, false, visit(['client_name' => "Mama Rose $tag", 'client_phone' => '0744 111 222', 'business_type' => 'salon', 'location' => "Sinza $tag", 'visit_date' => $day2]));
    $created[] = (int)($j['visit_id'] ?? 0);
    [, , $j] = call('api/field_reports/check_phone.php', $A, false, 'GET', ['phone' => '+255744111222']);
    $m = $j['match'] ?? [];
    ok(($m['client_name'] ?? '') === "Mama Rose $tag" && ($m['business_type'] ?? '') === 'salon' && ($m['location'] ?? '') === "Sinza $tag" && (int)($m['visits'] ?? 0) === 2,
       'known number returns name, business, place and visit count (2) for the form to fill');
    [, , $j] = call('api/field_reports/check_phone.php', $B, false, 'GET', ['phone' => '0744111222']);
    ok(array_key_exists('match', $j ?? []) && $j['match'] === null, "staff B learns nothing about A's client");

    // GPS place suggestion
    [$c, , $j] = save($A, false, visit(['client_name' => "Geo $tag", 'client_phone' => '0733444555', 'location' => "Mwenge Sokoni $tag", 'latitude' => '-6.771200', 'longitude' => '39.226500', 'gps_accuracy_m' => '15']));
    $geoId = (int)($j['visit_id'] ?? 0); $created[] = $geoId;
    [, , $j] = call('api/field_reports/nearby.php', $A, false, 'GET', ['lat' => '-6.771900', 'lng' => '39.226900']);   // ~90 m away
    ok(($j['place']['location'] ?? '') === "Mwenge Sokoni $tag" && ($j['place']['distance_m'] ?? 999) < 150, 'GPS ~90 m away suggests "Mwenge Sokoni" (' . ($j['place']['distance_m'] ?? '?') . ' m)');
    [, , $j] = call('api/field_reports/nearby.php', $A, false, 'GET', ['lat' => '-6.780000', 'lng' => '39.226500']);   // ~1 km
    ok(($j['place']['location'] ?? null) !== "Mwenge Sokoni $tag", 'nothing suggested from 1 km away');
    [, , $j] = call('api/field_reports/nearby.php', $B, false, 'GET', ['lat' => '-6.771200', 'lng' => '39.226500']);
    ok(($j['place']['location'] ?? null) !== "Mwenge Sokoni $tag", "staff B is never shown A's places");
    [, , $j] = call('api/field_reports/nearby.php', $adminId, true, 'GET', ['lat' => '-6.771200', 'lng' => '39.226500']);
    ok(($j['place']['location'] ?? '') === "Mwenge Sokoni $tag", 'admin gets the suggestion from any staff member');
    [$c] = call('api/field_reports/nearby.php', $A, false, 'GET', ['lat' => '999', 'lng' => 'x']);
    ok($c === 422, 'invalid coordinates → 422');

    // ═════════════════════════════════════════════════════════════════
    section('12. Phase 3 — follow-up, joined button, own day, GPS ✓ in reports');
    $cols = $pdo->query("SHOW COLUMNS FROM field_visits")->fetchAll(PDO::FETCH_COLUMN);
    ok(in_array('follow_up_date', $cols, true) && in_array('follow_up_done_at', $cols, true) && in_array('follow_up_done_by', $cols, true), 'follow-up columns exist (migration ran)');
    [$c, , $j] = save($A, false, visit(['follow_up_date' => date('Y-m-d', strtotime($day1 . ' -1 day'))]));
    ok($c === 422 && isset($j['errors']['follow_up_date']), 'follow-up before the visit → 422');
    [$c, , $j] = save($A, false, visit(['follow_up_date' => date('Y-m-d', strtotime($day1 . ' +400 days'))]));
    ok($c === 422 && isset($j['errors']['follow_up_date']), 'follow-up more than a year ahead → 422');
    $due = date('Y-m-d', strtotime($day1 . ' +1 day'));   // in the past → overdue
    [$c, , $j] = save($A, false, visit(['client_name' => "Follow $tag", 'client_phone' => '0722555666', 'follow_up_date' => $due]));
    $fuId = (int)($j['visit_id'] ?? 0); $created[] = $fuId;
    ok($c === 200, 'visit with a follow-up date saved');
    [, , $j] = call('api/field_reports/list.php', $A, false, 'GET', ['date' => $today]);
    $fu = array_values(array_filter($j['follow_ups'] ?? [], fn($f) => (int)$f['visit_id'] === $fuId))[0] ?? null;
    ok($fu && $fu['days_overdue'] >= 1, 'A sees the follow-up in "To follow up" (overdue ' . ($fu['days_overdue'] ?? '?') . ' days), whatever date is filtered');
    [, , $j] = call('api/field_reports/list.php', $B, false, 'GET', ['date' => $today]);
    ok(!in_array($fuId, array_map('intval', array_column($j['follow_ups'] ?? [], 'visit_id')), true), "B does not see A's follow-ups");
    [, , $j] = call('api/field_reports/list.php', $adminId, true, 'GET', ['date' => $today]);
    ok(in_array($fuId, array_map('intval', array_column($j['follow_ups'] ?? [], 'visit_id')), true), 'admin (all staff) sees it');
    [$c] = call('api/field_reports/followup_done.php', $B, false, 'POST', ['visit_id' => $fuId, 'done' => 1]);
    ok($c === 403, "B cannot mark A's follow-up done (got $c)");
    [$c] = call('api/field_reports/followup_done.php', $A, false, 'POST', ['visit_id' => $aVisit, 'done' => 1]);
    ok($c === 422, 'a visit without a follow-up date cannot be "followed up"');
    [$c] = call('api/field_reports/followup_done.php', $A, false, 'POST', ['visit_id' => $fuId, 'done' => 1]);
    $doneAt = $pdo->query("SELECT follow_up_done_at FROM field_visits WHERE visit_id = $fuId")->fetchColumn();
    [, , $j] = call('api/field_reports/list.php', $A, false, 'GET', ['date' => $today]);
    ok($c === 200 && $doneAt && !in_array($fuId, array_map('intval', array_column($j['follow_ups'] ?? [], 'visit_id')), true), 'A marks it followed up → leaves the list, time recorded');
    [$c] = save($A, false, visit(['visit_id' => $fuId, 'client_name' => "Follow $tag", 'client_phone' => '0722555666', 'follow_up_date' => date('Y-m-d', strtotime($due . ' +1 day'))]));
    ok($c === 200 && $pdo->query("SELECT follow_up_done_at FROM field_visits WHERE visit_id = $fuId")->fetchColumn() === null, 'a NEW follow-up date re-opens it');
    [$c] = save($A, false, visit(['visit_id' => $fuId, 'client_name' => "Follow $tag", 'client_phone' => '0722555666', 'follow_up_date' => date('Y-m-d', strtotime($due . ' +1 day')), 'notes' => "edit $tag"]));
    call('api/field_reports/followup_done.php', $A, false, 'POST', ['visit_id' => $fuId, 'done' => 1]);
    [$c] = save($A, false, visit(['visit_id' => $fuId, 'client_name' => "Follow $tag", 'client_phone' => '0722555666', 'follow_up_date' => date('Y-m-d', strtotime($due . ' +1 day')), 'notes' => "edit again $tag"]));
    ok($pdo->query("SELECT follow_up_done_at FROM field_visits WHERE visit_id = $fuId")->fetchColumn() !== null, 'editing other fields keeps "followed up"');
    call('api/field_reports/followup_done.php', $A, false, 'POST', ['visit_id' => $fuId, 'done' => 0]);
    call('api/field_reports/toggle_joined.php', $A, false, 'POST', ['visit_id' => $fuId, 'joined' => 1]);
    [, , $j] = call('api/field_reports/list.php', $A, false, 'GET', ['date' => $today]);
    ok(!in_array($fuId, array_map('intval', array_column($j['follow_ups'] ?? [], 'visit_id')), true), 'a client who joined is no longer in "To follow up"');
    [, $html] = call('app/bms/field_reports/field_visits.php', $A, false, 'GET', []);
    ok(strpos($html, 'data-act="joined"') !== false && strpos($html, 'function joinedBtn') !== false, 'a direct "Joined" button on every card/row');

    // admin's own day while looking at all staff
    [$c, , $j] = save($adminId, true, visit(['visit_date' => $day2, 'client_name' => "Boss $tag", 'client_phone' => '0700999888']));
    $created[] = (int)($j['visit_id'] ?? 0);
    call('api/field_reports/submit_day.php', $adminId, true, 'POST', ['date' => $day2]);
    [, , $j] = call('api/field_reports/list.php', $adminId, true, 'GET', ['date' => $day2, 'user_id' => '']);
    ok(!empty($j['day_status']['submitted_time']) && (int)($j['own_visit_count'] ?? 0) >= 1, "admin viewing ALL staff still sees their own day's submission");

    // reports
    [, $html] = call('app/bms/field_reports/field_report_print.php', $A, false, 'GET', ['date' => $day1, 'lang' => 'sw']);
    ok(strpos($html, 'Ufuatiliaji') !== false && strpos($html, 'Mwenge Sokoni ' . $tag . ' (GPS ✓)') !== false, 'print (sw): Follow-up column + "(GPS ✓)" on confirmed places');
    [, $csv] = call('api/field_reports/export.php', $A, false, 'GET', ['date' => $day1, 'lang' => 'en']);
    ok(strpos($csv, 'Follow-up') !== false && strpos($csv, 'GPS ✓') !== false && strpos($csv, 'CUSTOMER VISITS REPORT') !== false, 'Excel (en): Follow-up column, GPS ✓, new title');

    section('13. Live-test fixes (shop.demo, 2026-10-03)');
    $page = file_get_contents(ROOT_DIR . '/app/bms/field_reports/field_visits.php');
    ok(preg_match('#id="visitModal"[^>]*data-no-autoclose="true"#', $page) === 1, 'visit form opts out of footer.php\x27s "close any modal after a successful POST" (Save & Add Another kept closing it)');
    ok(strpos($page, 'fr-follow-actions') !== false && strpos($page, 'white-space: nowrap') !== false, 'follow-up buttons stay on one compact row');
    [, $html] = call('app/bms/field_reports/field_report_print.php', $A, false, 'GET', ['date' => $day1, 'lang' => 'sw']);
    ok(strpos($html, '>Mwitikio<') !== false && strpos($html, '>Nia<') === false, 'report column says "Mwitikio" like the page (not "Nia")');

    section('14. Print friendliness (second live test)');
    [, $p] = call('app/bms/field_reports/field_report_print.php', $A, false, 'GET', ['date' => $day1, 'lang' => 'sw', 'orient' => 'landscape']);
    ok(strpos($p, 'overflow-wrap: break-word') !== false && strpos($p, 'overflow-wrap: anywhere') === false && !preg_match('#th \{[^}]*text-transform: uppercase#', $p), 'headings wrap at spaces only (no uppercase squeeze, no mid-word breaks)');
    ok(strpos($p, 'class="table-wrap"') !== false && strpos($p, 'min-width: 1000px') !== false && strpos($p, 'Telezesha pembeni') !== false, 'on a phone screen the table scrolls sideways (with a hint) instead of letters stacking');
    ok(strpos($p, 'if (window.opener) { window.close(); }') !== false, 'Close works even when the report was not opened as a new tab');
    [, $pp] = call('app/bms/field_reports/field_report_print.php', $A, false, 'GET', ['date' => $day1, 'lang' => 'sw', 'orient' => 'portrait']);
    ok(strpos($pp, 'min-width: 720px') !== false && strpos($pp, 'min-width: 1000px') !== false, 'phone screen: 1000 px sideways, 720 px when held upright');
    [, $csv] = call('api/field_reports/export.php', $A, false, 'GET', ['date' => $day1, 'lang' => 'en']);
    ok(strpos($csv, 'Business card') !== false && strpos($csv, 'Free trial link') !== false, 'Excel keeps the full column names');
    $page = file_get_contents(ROOT_DIR . '/app/bms/field_reports/field_visits.php');
    ok(strpos($page, 'id="reportModal"') === false && strpos($page, "window.open(PRINT_URL + '?' + $.param(Object.assign(filters(), { lang: USER_LANG })), '_blank')") !== false, 'one click: the report opens straight away for the page filters, in the user\'s language (no options dialog)');
    ok(strpos($page, "t('Print report')") !== false, 'button says "Chapisha Ripoti"');
    // Both orientations from one page: every heading has a width class; widths per orientation
    preg_match('#<thead><tr>(.*?)</tr></thead>#s', $p, $th);
    preg_match_all('#class="k-([a-z_]+)#', $th[1] ?? '', $ks);
    $cssOk = true; foreach ($ks[1] as $k) if (strpos($p, ".k-$k{width:") === false) $cssOk = false;
    ok($ks[1] && $cssOk, 'every column has a width for landscape (and the portrait set its own)');
    ok(strpos($th[1] ?? '', '>Alichopewa<') !== false && strpos($th[1] ?? '', '>Kadi<') !== false, 'headings: Kadi/Majaribio/Mafunzo (landscape) + Alichopewa (portrait)');
    ok(strpos($p, 'class="lang-alt"') !== false && strpos($p, 'Ukurasa:') === false, 'toolbar: Print · Excel · other language · Close (no page/orientation buttons)');
} finally {
    // ── Cleanup: only what this run created ───────────────────────────
    $pdo->prepare("DELETE FROM field_visits WHERE location LIKE ? OR client_name LIKE ? OR notes LIKE ?")
        ->execute(["%$tag%", "%$tag%", "%$tag%"]);
    $pdo->prepare("DELETE FROM field_report_days WHERE user_id IN (?, ?, ?)" . ($daysBefore ? ' AND day_id NOT IN (' . implode(',', array_map('intval', $daysBefore)) . ')' : ''))
        ->execute([$A, $B, $adminId]);
    try {
        $pdo->prepare("DELETE FROM activity_log WHERE user_id IN (?, ?, ?) AND created_at >= ? AND (description LIKE '%field visit%' OR description LIKE '%field report%')")
            ->execute([$A, $B, $adminId, $logStart]);
    } catch (PDOException $e) { /* activity log layout differs — leave it */ }
    $left = (int)$pdo->query("SELECT COUNT(*) FROM field_visits WHERE client_name LIKE " . $pdo->quote("%$tag%"))->fetchColumn();
    echo "\nCleanup: " . ($left === 0 ? 'all test rows removed' : "$left rows LEFT BEHIND") . "\n";
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
