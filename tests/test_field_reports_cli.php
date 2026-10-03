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
    foreach (['RIPOTI YA MATEMBEZI YA UWANDANI', 'Mahali alipotembelea', 'Jina la mteja', 'Kadi ya biashara', 'Amejiunga', 'Waliojiunga na mfumo wetu', 'Duka la rejareja', 'Ana nia', 'Ndiyo', 'Hapana', 'Chapisha / Hifadhi kama PDF', 'Ripoti hii imechapishwa na'] as $s)
        ok(strpos($sw, $s) !== false, "sw contains '$s'");
    $swDate = frDateLabel($day1, 'sw');
    ok(strpos($sw, $swDate) !== false, "sw date written in Swahili ('$swDate')");
    ok(preg_match('/(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday|January|February|March|April|June|July|August|September|October|November|December)/', strip_tags($sw)) === 0, 'sw report has no English day/month names');
    foreach (['FIELD VISITS REPORT', 'Place visited', 'This document was Printed by', 'Business card'] as $s)
        ok(strpos($sw, $s) === false, "sw does not fall back to English '$s'");
    ok(strpos($sw, '<html lang="sw">') !== false, 'sw page declares lang="sw"');
    ok(preg_match('/@page\s*\{\s*size:\s*A4\s+portrait/', $sw) === 1, 'portrait → @page size A4 portrait');

    [$c, $en] = call('app/bms/field_reports/field_report_print.php', $A, false, 'GET', $range + ['lang' => 'en', 'orient' => 'landscape']);
    ok($c === 200, 'English print renders (200)');
    foreach (['FIELD VISITS REPORT', 'S/No', 'Place visited', 'Client name', 'Business card', 'Free trial link', 'Joined our system', 'Retail shop', 'Interested', 'This document was Printed by'] as $s)
        ok(strpos($en, $s) !== false, "en contains '$s'");
    ok(strpos($en, 'RIPOTI YA MATEMBEZI') === false && strpos($en, 'Ripoti hii imechapishwa') === false, 'en has no Swahili headings/footer');
    ok(preg_match('/@page\s*\{\s*size:\s*A4\s+landscape/', $en) === 1, 'landscape → @page size A4 landscape');
    ok(strpos($en, 'print_footer') !== false || strpos($en, 'print-footer') !== false, 'footer uses the shared .print-footer (same as General Ledger)');
    ok(strpos($en, 'table-layout: fixed') !== false || strpos($en, 'table-layout:fixed') !== false, 'table uses fixed layout (no overflow off the page)');
    ok(strpos($en, 'overflow-wrap') !== false && strpos($en, 'table-header-group') !== false, 'words wrap (never cut) and headers repeat on every page');
    [$c, $multi] = call('app/bms/field_reports/field_report_print.php', $adminId, true, 'GET', ['date_from' => $day1, 'date_to' => $today, 'lang' => 'en']);
    ok($c === 200 && strpos($multi, '>Date<') !== false && strpos($multi, '>Staff<') !== false, 'multi-day all-staff print adds Date + Staff columns');
    [$c, $empty] = call('app/bms/field_reports/field_report_print.php', $A, false, 'GET', ['date_from' => $day2, 'date_to' => $day2, 'lang' => 'sw']);
    ok($c === 200 && strpos($empty, 'Hakuna matembezi yaliyorekodiwa') !== false, 'empty day prints a clean "no visits" row (sw)');
    [, $bad] = call('app/bms/field_reports/field_report_print.php', $A, false, 'GET', $range + ['lang' => 'xx', 'orient' => 'sideways']);
    ok(preg_match('/@page\s*\{\s*size:\s*A4\s+landscape/', $bad) === 1, 'unknown orient falls back to landscape');
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
    ok(strpos($csv, 'RIPOTI YA MATEMBEZI YA UWANDANI') !== false && strpos($csv, 'Mahali alipotembelea') !== false, 'sw export headings translated');
    ok(strpos($csv, "'=HYPERLINK") !== false, 'formula-looking text is neutralised with a leading quote');
    [, $csvEn] = call('api/field_reports/export.php', $A, false, 'GET', $range + ['lang' => 'en']);
    ok(strpos($csvEn, 'FIELD VISITS REPORT') !== false && strpos($csvEn, 'S/No') !== false, 'en export headings in English');

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
