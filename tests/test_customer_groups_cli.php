<?php
/**
 * tests/test_customer_groups_cli.php
 *   php tests/test_customer_groups_cli.php
 *
 * 1. Customer Groups APIs (the pages called eight endpoints that never existed):
 *    create / duplicate refused / get / update / static add + remove members /
 *    dynamic group filled from its rule, refreshed, members can't be hand-edited /
 *    unknown rule field refused / legacy rule reported / CSV export / soft delete.
 * 2. Each API is gated on the customer_groups permission + CSRF for writes, and
 *    the pages use buildUrl() (no relative 'api/…' URLs, no second jQuery).
 * 3. CSV supplier import: new suppliers get the company-format code from
 *    nextCode('SUP') plus a ledger sub-account; an updated supplier's legacy code
 *    is upgraded; a failing row burns no number.
 *
 * Every API call runs in its own PHP process as a logged-in admin with a valid
 * CSRF token. Test rows are removed at the end.
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/code_generator.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function check(bool $ok, string $label, $detail = null): void {
    $ok ? pass($label) : fail($label . ($detail !== null ? ' — ' . (is_string($detail) ? $detail : json_encode($detail)) : ''));
}

$cleanup = ['groups' => [], 'suppliers' => [], 'customers' => []];
register_shutdown_function(function () use (&$cleanup) {
    global $pdo, $pass, $fail; static $done = false; if ($done) return; $done = true;
    if ($pdo instanceof PDO) {
        foreach ($cleanup['groups'] as $gid) {
            $pdo->prepare("DELETE FROM customer_group_customers WHERE group_id = ?")->execute([$gid]);
            $pdo->prepare("DELETE FROM customer_groups WHERE group_id = ?")->execute([$gid]);
        }
        foreach ($cleanup['suppliers'] as $sid) {
            $pdo->prepare("DELETE FROM audit_logs WHERE entity_type = 'supplier' AND entity_id = ?")->execute([$sid]);
            $pdo->prepare("DELETE FROM suppliers WHERE supplier_id = ?")->execute([$sid]);
        }
        foreach ($cleanup['customers'] as $cid) {
            $pdo->prepare("DELETE FROM customers WHERE customer_id = ?")->execute([$cid]);
        }
    }
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

echo "\n\033[1m═══ Customer Groups + supplier import codes ═══\033[0m\n";

// ── 2 (static). gates + page wiring ────────────────────────────────────────
section('APIs are gated; pages call them correctly');
$apis = [
    'api/create_customer_group.php' => "canCreate('customer_groups')",
    'api/update_customer_group.php' => "canEdit('customer_groups')",
    'api/get_customer_group.php'    => "canView('customer_groups')",
    'api/delete_customer_group.php' => "canDelete('customer_groups')",
    'api/add_group_members.php'     => "canEdit('customer_groups')",
    'api/remove_group_member.php'   => "canEdit('customer_groups')",
    'api/refresh_dynamic_group.php' => "canEdit('customer_groups')",
    'api/export_group_members.php'  => "canView('customer_groups')",
];
foreach ($apis as $rel => $gate) {
    $src = (string)@file_get_contents("$root/$rel");
    check($src !== '' && strpos($src, 'isAuthenticated()') !== false && strpos($src, $gate) !== false, "$rel — login + $gate");
    if (!in_array($rel, ['api/get_customer_group.php', 'api/export_group_members.php'], true)) {
        check(strpos($src, 'csrf_check()') !== false && strpos($src, "REQUEST_METHOD'] !== 'POST'") !== false, "$rel — POST + CSRF only");
    }
}
foreach (['app/bms/customer/customer_groups.php', 'app/bms/customer/customer_group_details.php', 'app/bms/customer/customer_group_members.php'] as $rel) {
    $src = (string)file_get_contents("$root/$rel");
    check(!preg_match("/(url:|href =) '(api\\/|customer_group_members\\.php|customer_details\\.php)/", $src), "$rel — no relative URLs");
    check(stripos($src, 'jquery-3.6.0') === false && stripos($src, 'bootstrap@5.1.3') === false, "$rel — no second jQuery / Bootstrap");
    check(strpos($src, "status != 'deleted'") !== false, "$rel — hides deleted groups");
    check(strpos($src, 'autoEnforcePermission') < strpos($src, 'require_once HEADER_FILE'), "$rel — permission checked before the header");
}
foreach (['app/bms/customer/customer_groups.php', 'app/bms/customer/customer_group_members.php'] as $rel) {
    check(strpos((string)file_get_contents("$root/$rel"), 'BMSSerial.column()') !== false, "$rel — sortable S/No column");
}

if (!($pdo instanceof PDO)) { echo "  (no database — skipped)\n"; exit; }
$adminId = (int)$pdo->query("SELECT user_id FROM users WHERE role_id = 1 ORDER BY user_id LIMIT 1")->fetchColumn();
if ($adminId <= 0) { echo "  (no admin user — skipped)\n"; exit; }

/** Call one endpoint in its own process; returns [decoded JSON|null, raw body]. */
function call(string $rel, array $data, string $method = 'POST'): array
{
    global $root, $adminId;
    $stub = <<<'PHP'
<?php
session_start();
$_SESSION['user_id'] = (int)$argv[3]; $_SESSION['role_id'] = 1; $_SESSION['is_admin'] = true;
$_SESSION['csrf_token'] = 'cli-test-token';
$_SERVER['REQUEST_METHOD'] = $argv[4];
$_SERVER['HTTP_X_CSRF_TOKEN'] = 'cli-test-token';
$_SERVER['SCRIPT_NAME'] = '/bms/index.php';
$_SERVER['HTTP_HOST'] = 'localhost';
$_GET = []; $_POST = [];
if ($argv[4] === 'GET') { parse_str($argv[2] ?? '', $_GET); } else { parse_str($argv[2] ?? '', $_POST); $_POST['_csrf'] = 'cli-test-token'; }
ob_start(); ob_start();
include $argv[1];
while (ob_get_level() > 1) ob_end_flush();
echo ob_get_clean();
PHP;
    $tmp = sys_get_temp_dir() . '/bms_cg_' . getmypid() . '.php';
    file_put_contents($tmp, $stub);
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' ' . escapeshellarg("$root/$rel") . ' '
         . escapeshellarg(http_build_query($data)) . ' ' . (int)$adminId . ' ' . escapeshellarg($method)
         . ' 2>' . (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null');
    $out = (string)shell_exec($cmd);
    @unlink($tmp);
    $pos = strpos($out, '{');
    $j = $pos === false ? null : json_decode(substr($out, $pos), true);
    return [is_array($j) ? $j : null, $out];
}
$members = function (int $gid): array {
    global $pdo;
    $st = $pdo->prepare("SELECT customer_id FROM customer_group_customers WHERE group_id = ? ORDER BY customer_id");
    $st->execute([$gid]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
};

// Fixture customers: three with a unique city so the dynamic rule is predictable.
$tag = 'CLIGRP' . getmypid();
$cids = [];
foreach (['A', 'B', 'C'] as $i => $n) {
    $pdo->prepare("INSERT INTO customers (customer_name, customer_code, city, customer_type, status, created_by, created_at)
                   VALUES (?, ?, ?, 'Business', 'active', ?, NOW())")
        ->execute(["$tag customer $n", "$tag-$n", $i < 2 ? "$tag city" : "$tag elsewhere", $adminId]);
    $cids[] = (int)$pdo->lastInsertId();
}
$cleanup['customers'] = $cids;

// ── 1. APIs ────────────────────────────────────────────────────────────────
section('Static group');
[$r] = call('api/create_customer_group.php', ['group_name' => "$tag static", 'group_type' => 'static', 'description' => 'probe', 'color' => '#ff0000']);
$gid = (int)($r['id'] ?? 0);
check(!empty($r['success']) && $gid > 0, 'create static group', $r);
if ($gid) $cleanup['groups'][] = $gid;
[$r] = call('api/create_customer_group.php', ['group_name' => strtolower("$tag STATIC"), 'group_type' => 'static']);
check(empty($r['success']) && stripos((string)$r['message'], 'already exists') !== false, 'duplicate name refused (case-insensitive)', $r);
[$r] = call('api/create_customer_group.php', ['group_name' => '', 'group_type' => 'static']);
check(empty($r['success']), 'empty name refused', $r);
[$r] = call('api/create_customer_group.php', ['group_name' => "$tag bad", 'group_type' => 'weird']);
check(empty($r['success']), 'unknown group type refused', $r);

[$r] = call('api/get_customer_group.php', ['id' => $gid], 'GET');
check(!empty($r['success']) && $r['data']['group_name'] === "$tag static" && $r['data']['color'] === '#ff0000', 'get returns the group', $r);

[$r] = call('api/add_group_members.php', ['group_id' => $gid, 'customer_ids' => [$cids[0], $cids[1], $cids[1], 999999999]]);
check(!empty($r['success']) && (int)$r['added'] === 2 && $members($gid) === [$cids[0], $cids[1]], 'add members (duplicates + unknown ids ignored)', $r);
[$r] = call('api/add_group_members.php', ['group_id' => $gid, 'customer_ids' => [$cids[0]]]);
check(!empty($r['success']) && (int)$r['added'] === 0, 'adding an existing member adds nothing', $r);
[$r] = call('api/remove_group_member.php', ['group_id' => $gid, 'customer_id' => $cids[0]]);
check(!empty($r['success']) && $members($gid) === [$cids[1]], 'remove a member', $r);
[$r] = call('api/remove_group_member.php', ['group_id' => $gid, 'customer_id' => $cids[0]]);
check(empty($r['success']), 'removing a non-member is refused', $r);
[$r] = call('api/refresh_dynamic_group.php', ['group_id' => $gid]);
check(empty($r['success']), 'a static group cannot be refreshed', $r);

[$r, $raw] = call('api/export_group_members.php', ['group_id' => $gid], 'GET');
$lines = array_values(array_filter(explode("\n", trim(preg_replace('/^\xEF\xBB\xBF/', '', $raw)))));
check(count($lines) === 2 && strpos($lines[0], 'Customer') !== false && strpos($lines[1], "$tag customer B") !== false, 'CSV export: header + the member', $lines);

section('Dynamic group');
[$r] = call('api/create_customer_group.php', ['group_name' => "$tag dynamic", 'group_type' => 'dynamic',
    'rule_field' => 'city', 'rule_operator' => 'equals', 'rule_value' => "$tag city"]);
$did = (int)($r['id'] ?? 0);
if ($did) $cleanup['groups'][] = $did;
check(!empty($r['success']) && $members($did) === [$cids[0], $cids[1]], 'created and filled from its rule (city = …)', $r);
[$r] = call('api/create_customer_group.php', ['group_name' => "$tag dyn2", 'group_type' => 'dynamic', 'rule_field' => 'c.city; DROP TABLE x', 'rule_value' => 'x']);
check(empty($r['success']), 'unknown rule field refused', $r);
[$r] = call('api/create_customer_group.php', ['group_name' => "$tag dyn3", 'group_type' => 'dynamic', 'rule_field' => 'city', 'rule_value' => '']);
check(empty($r['success']), 'empty rule value refused', $r);
[$r] = call('api/add_group_members.php', ['group_id' => $did, 'customer_ids' => [$cids[2]]]);
check(empty($r['success']), 'members of a dynamic group cannot be added by hand', $r);

$pdo->prepare("UPDATE customers SET city = ? WHERE customer_id = ?")->execute(["$tag city", $cids[2]]);
$pdo->prepare("UPDATE customers SET status = 'deleted' WHERE customer_id = ?")->execute([$cids[0]]);
[$r] = call('api/refresh_dynamic_group.php', ['group_id' => $did]);
check(!empty($r['success']) && $members($did) === [$cids[1], $cids[2]] && (int)$r['added'] === 1 && (int)$r['removed'] === 1,
      'refresh adds the new match and drops the deleted customer', $r);

[$r] = call('api/update_customer_group.php', ['group_id' => $did, 'group_name' => "$tag dynamic", 'group_type' => 'dynamic',
    'rule_field' => 'city', 'rule_operator' => 'starts_with', 'rule_value' => "$tag else"]);
check(!empty($r['success']) && $members($did) === [], 'editing the rule re-applies it (starts_with, now no live match)', ['r' => $r, 'm' => $members($did)]);
[$r] = call('api/update_customer_group.php', ['group_id' => $did, 'group_name' => "$tag dynamic", 'group_type' => 'dynamic',
    'rule_field' => 'city', 'rule_operator' => 'contains', 'rule_value' => '%']);
check(!empty($r['success']) && $members($did) === [], "a '%' in the value is matched literally, not as a wildcard", $members($did));

$pdo->prepare("UPDATE customer_groups SET rules = ? WHERE group_id = ?")->execute([json_encode(['field' => 'marital_status', 'operator' => 'equals', 'value' => 'x']), $did]);
[$r] = call('api/refresh_dynamic_group.php', ['group_id' => $did]);
check(empty($r['success']) && stripos((string)$r['message'], 'no longer') !== false, 'a rule on a field customers no longer have is reported, not run', $r);

[$r] = call('api/update_customer_group.php', ['group_id' => $did, 'group_name' => "$tag static", 'group_type' => 'static']);
check(empty($r['success']) && stripos((string)$r['message'], 'already exists') !== false, 'rename onto another group\'s name refused', $r);

section('Delete');
[$r] = call('api/delete_customer_group.php', ['group_id' => $gid]);
$st = $pdo->query("SELECT status FROM customer_groups WHERE group_id = $gid")->fetchColumn();
check(!empty($r['success']) && $st === 'deleted' && $members($gid) === [$cids[1]], 'soft delete: row and member list kept, status deleted', ['r' => $r, 's' => $st]);
[$r] = call('api/get_customer_group.php', ['id' => $gid], 'GET');
check(empty($r['success']), 'a deleted group is not found', $r);
[$r] = call('api/create_customer_group.php', ['group_name' => "$tag static", 'group_type' => 'static']);
if (!empty($r['id'])) $cleanup['groups'][] = (int)$r['id'];
check(!empty($r['success']), "a deleted group's name can be used again", $r);
[$r] = call('api/delete_customer_group.php', ['group_id' => $gid]);
check(empty($r['success']), 'deleting it again → not found', $r);

// ── 3. supplier import codes ───────────────────────────────────────────────
section('CSV supplier import uses nextCode(\'SUP\')');
$seq = fn() => (int)$pdo->query("SELECT last_no FROM code_sequences WHERE sequence_name = 'SUP'")->fetchColumn();
$importCsv = function (array $rows, string $action, bool $skip = false) use ($root, $adminId) {
    $hdr = ['supplier_name', 'company_name', 'contact_person', 'contact_title', 'email', 'phone', 'mobile', 'fax', 'website', 'address', 'city',
            'state', 'country', 'postal_code', 'tax_id', 'vat_number', 'payment_terms', 'currency', 'bank_name', 'bank_account', 'bank_address', 'description', 'status'];
    $csv = tempnam(sys_get_temp_dir(), 'bms_imp');
    $fh = fopen($csv, 'w'); fputcsv($fh, $hdr);
    foreach ($rows as $row) fputcsv($fh, array_map(fn($h) => $row[$h] ?? '', $hdr));
    fclose($fh);
    $stub = <<<'PHP'
<?php
session_start();
$_SESSION['user_id'] = (int)$argv[2]; $_SESSION['role_id'] = 1; $_SESSION['is_admin'] = true;
$_SESSION['csrf_token'] = 'cli-test-token';
$_SERVER['REQUEST_METHOD'] = 'POST'; $_SERVER['HTTP_X_CSRF_TOKEN'] = 'cli-test-token';
$_SERVER['SCRIPT_NAME'] = '/bms/index.php'; $_SERVER['HTTP_HOST'] = 'localhost';
$_POST = ['_csrf' => 'cli-test-token', 'import_action' => $argv[4]] + ($argv[5] === '1' ? ['skip_errors' => 'on'] : []);
$_FILES = ['import_file' => ['name' => 'i.csv', 'type' => 'text/csv', 'tmp_name' => $argv[3], 'error' => UPLOAD_ERR_OK, 'size' => filesize($argv[3])]];
ob_start(); ob_start();
include $argv[1];
while (ob_get_level() > 1) ob_end_flush();
echo ob_get_clean();
PHP;
    $tmp = sys_get_temp_dir() . '/bms_imp_' . getmypid() . '.php';
    file_put_contents($tmp, $stub);
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' ' . escapeshellarg("$root/api/import_suppliers.php") . ' '
        . (int)$adminId . ' ' . escapeshellarg($csv) . ' ' . escapeshellarg($action) . ' ' . ($skip ? '1' : '0') . ' 2>/dev/null');
    @unlink($tmp); @unlink($csv);
    return json_decode(substr($out, (int)strpos($out, '{')), true);
};
$supRow = function (string $name) {
    global $pdo;
    $st = $pdo->prepare("SELECT supplier_id, supplier_code, ledger_account_id FROM suppliers WHERE supplier_name = ? AND status != 'deleted'");
    $st->execute([$name]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
};
$prefix = companyCodePrefix($pdo);

$s0 = $seq();
$r = $importCsv([['supplier_name' => "$tag supplier", 'country' => 'Tanzania', 'currency' => 'TZS', 'status' => 'active']], 'add_new');
$row = $supRow("$tag supplier");
if ($row) $cleanup['suppliers'][] = (int)$row['supplier_id'];
check(!empty($r['success']) && $row && $row['supplier_code'] === sprintf('%s-SUP-%04d', $prefix, $s0 + 1),
      "new supplier gets the next company code ($prefix-SUP-" . sprintf('%04d', $s0 + 1) . ')', ['r' => $r, 'row' => $row]);
check($row && !empty($row['ledger_account_id']), 'new supplier gets its ledger sub-account', $row);

$pdo->prepare("INSERT INTO suppliers (supplier_name, supplier_code, status, created_by, created_at) VALUES (?, ?, 'active', ?, NOW())")
    ->execute(["$tag legacy", 'SUP12342610', $adminId]);
$cleanup['suppliers'][] = (int)$pdo->lastInsertId();
$s1 = $seq();
$r = $importCsv([['supplier_name' => "$tag legacy", 'phone' => '0700', 'country' => 'Tanzania', 'currency' => 'TZS', 'status' => 'active']], 'add_update');
$row = $supRow("$tag legacy");
check(!empty($r['success']) && $row && $row['supplier_code'] === sprintf('%s-SUP-%04d', $prefix, $s1 + 1), 'updated supplier: legacy SUP… code upgraded', ['r' => $r, 'row' => $row]);

$s2 = $seq();
$r = $importCsv([['supplier_name' => "$tag bad row", 'country' => 'Tanzania', 'currency' => 'TZS', 'status' => 'not-a-status']], 'add_new', true);
check($seq() === $s2 && $supRow("$tag bad row") === null && (int)($r['results']['failed'] ?? 0) === 1, 'a failing row is rolled back and burns no number', $r);
check(strpos((string)file_get_contents("$root/api/import_suppliers.php"), 'rand(') === false, 'import no longer uses rand() for codes');
