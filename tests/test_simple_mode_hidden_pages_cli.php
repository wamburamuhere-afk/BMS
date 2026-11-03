<?php
/**
 * tests/test_simple_mode_hidden_pages_cli.php — POS Simple Mode (Smart POS)
 * hides AND refuses the pages its UI never shows.
 *
 *   php tests/test_simple_mode_hidden_pages_cli.php
 *
 *   1. list integrity: every key is a real, always-on permission row; none of
 *      the pages Simple Mode itself uses is on it
 *   2. Simple Mode ON: every listed key refused — admin included; every kept
 *      key still allowed. Simple Mode OFF: nothing changes
 *   3. Roles & Permissions matrix: listed rows hidden in Simple Mode (grants
 *      preserved, not deleted), shown again when it is off
 *   4. router: listed routes unavailable for a Smart POS tenant, kept ones open
 *   5. pages: Reports hub drops Financial Reports and 404s ?report=balance_sheet;
 *      Sales Report's customer search works without financial_reports;
 *      Customers "View Account" link follows financial_reports
 *   6. Simple Mode OFF regression: the same pages behave exactly as before
 *
 * CLI ONLY. Every setting/role/user it touches is restored on exit.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);
require_once $root . '/roots.php';
require_once $root . '/core/role_permission_ui.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $what\n"; }
    else       { $fail++; echo "  FAIL  $what\n"; }
}
function section(string $s): void { echo "\n== $s ==\n"; }

/** Run PHP in a fresh process (get_setting() caches per process). */
function runPhp(string $code): string {
    $tmp = tempnam(sys_get_temp_dir(), 'smh_');
    file_put_contents($tmp, "<?php\n" . $code);
    $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return trim((string)$out);
}
function setSimpleMode(string $v): void {
    global $root;
    runPhp("require '$root/roots.php'; save_setting('pos_simple_mode', " . var_export($v, true) . ");");
}
/** Fresh-process probe as admin (or $roleId), optional Smart POS feature map. JSON back. */
function probe(string $body, int $roleId = 1, bool $smartPos = false): array {
    global $root;
    $feat = $smartPos ? smartPosFeatures() : null;
    $out = runPhp("require '$root/roots.php'; require_once '$root/core/role_permission_ui.php';
        \$_SESSION = ['user_id' => 1, 'role_id' => $roleId, 'is_admin' => " . ($roleId === 1 ? 'true' : 'false') . ",
                      'user_role' => '" . ($roleId === 1 ? 'Admin' : 'Staff') . "'];
        " . ($roleId !== 1 ? "loadUserPermissions($roleId);" : '') . "
        " . ($feat !== null ? '$GLOBALS[\'__bms_features\'] = ' . var_export($feat, true) . ';' : '') . "
        $body");
    $j = json_decode(substr($out, (int)strpos($out, '{')), true);
    if (!is_array($j)) { echo "    probe output: " . substr($out, 0, 400) . "\n"; return []; }
    return $j;
}
/** Call a JSON API as a non-admin user with their role's real permissions → [decoded, http code]. */
function apiAs(string $file, int $uid, int $roleId, array $get): array {
    global $root;
    $out = runPhp("\$_SERVER['REQUEST_METHOD'] = 'GET'; \$_SERVER['HTTP_HOST'] = 'localhost';
        require '$root/roots.php';
        \$_SESSION = ['user_id' => $uid, 'role_id' => $roleId, 'is_admin' => false, 'user_role' => 'Staff', 'csrf_token' => 't'];
        loadUserPermissions($roleId);
        \$_GET = " . var_export($get, true) . ";
        register_shutdown_function(function () { echo \"\\nHTTP_CODE=\" . (http_response_code() ?: 200); });
        chdir('$root'); include '$root/$file';");
    preg_match('/HTTP_CODE=(\d+)/', $out, $m);
    $json = trim(preg_replace('/HTTP_CODE=\d+\s*$/', '', $out));
    return [json_decode(substr($json, (int)strpos($json, '{')), true), (int)($m[1] ?? 0)];
}
function smartPosFeatures(): array {
    $f = [];
    foreach (allFeatureKeys() as $k) $f[$k] = in_array($k, ['pos', 'warehouses'], true);
    return $f;
}
/** Render one page via tests/helpers/page_request.php → [body, http code]. */
function page(string $file, int $uid, bool $admin, array $get = [], bool $smartPos = true): array {
    global $root;
    $cmd = 'php ' . escapeshellarg("$root/tests/helpers/page_request.php") . ' ' . escapeshellarg($file)
         . ' ' . $uid . ' ' . ($admin ? '1' : '0') . ' ' . escapeshellarg(json_encode((object)$get))
         . ($smartPos ? ' ' . escapeshellarg(json_encode(smartPosFeatures())) : '');
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    preg_match('/HTTP_CODE=(\d+)/', $err, $m);
    return [$out, (int)($m[1] ?? 0), $err];
}

$hidden = simpleModeHiddenPageKeys();
$adminId = (int)$pdo->query("SELECT u.user_id FROM users u JOIN roles r ON r.role_id = u.role_id
                              WHERE r.is_admin = 1 AND u.is_active = 1 ORDER BY u.user_id LIMIT 1")->fetchColumn();
if ($adminId <= 0) { echo "No active admin user — cannot run page checks.\n"; exit(1); }
// Pages Simple Mode itself uses — must never be refused by it.
$kept = ['dashboard', 'customers', 'customer_details', 'customer_registration', 'edit_customer',
         'products', 'categories', 'stock_adjustments', 'warehouses', 'pos', 'pos_config_settings',
         'pos_restock', 'pos_past_sales', 'expenses', 'expense_report', 'inventory_report',
         'sales_report', 'reports', 'suppliers', 'supplier_payments', 'users', 'user_roles',
         'system_settings', 'profile', 'help'];

$modeBefore = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'pos_simple_mode'")->fetchColumn();
$cleanup = [];
register_shutdown_function(function () use (&$cleanup, $modeBefore) {
    global $pdo, $pass, $fail, $root;
    foreach (array_reverse($cleanup) as $fn) { try { $fn(); } catch (Throwable $e) {} }
    if ($modeBefore === false) {
        $pdo->exec("DELETE FROM system_settings WHERE setting_key = 'pos_simple_mode'");
    } else {
        setSimpleMode((string)$modeBefore);
    }
    echo "\nPassed: $pass   Failed: $fail\n";
    if ($fail > 0) exit(1);
});

echo "\nBMS — POS Simple Mode hides and refuses the pages it never shows\n";

// ─────────────────────────────────────────────────────────────────────────────
section('1. List integrity');
$permKeys = $pdo->query("SELECT page_key FROM permissions")->fetchAll(PDO::FETCH_COLUMN);
foreach ($hidden as $k) {
    ok("'$k' exists in permissions", in_array($k, $permKeys, true));
    // Always-on, or owned only by a module Smart POS has on (locations -> warehouses).
    ok("'$k' is reachable for a Smart POS tenant without Simple Mode",
       featureForPageKey($k) === [] || array_intersect(featureForPageKey($k), ['pos', 'warehouses']) !== []);
}
ok('no duplicates in the list', count($hidden) === count(array_unique($hidden)));
ok('no page Simple Mode uses is on the list — found: ' . implode(', ', array_intersect($kept, $hidden)),
   array_intersect($kept, $hidden) === []);

// ─────────────────────────────────────────────────────────────────────────────
section('2. Entitlement — Simple Mode ON');
setSimpleMode('1');
$keysJson = var_export(array_merge($hidden, $kept), true);
$r = probe("\$o = []; foreach ($keysJson as \$k) \$o[\$k] = ['allow' => tenantModuleAllowsPage(\$k), 'view' => canView(\$k), 'edit' => canEdit(\$k)];
            echo json_encode(\$o);");
foreach ($hidden as $k) {
    ok("ON: '$k' refused by tenantModuleAllowsPage", ($r[$k]['allow'] ?? null) === false);
    ok("ON: admin cannot view/edit '$k'", ($r[$k]['view'] ?? null) === false && ($r[$k]['edit'] ?? null) === false);
}
foreach ($kept as $k) {
    ok("ON: '$k' still allowed", ($r[$k]['allow'] ?? null) === true);
}
ok('ON: admin still views expenses (Simple Mode bypass intact)', ($r['expenses']['view'] ?? null) === true);
ok('ON: admin still views stock_adjustments (Transfer Stock)',   ($r['stock_adjustments']['view'] ?? null) === true);
ok('ON: admin still views reports (mobile Reports tab)',        ($r['reports']['view'] ?? null) === true);

section('2b. Entitlement — Simple Mode OFF (no change from before)');
setSimpleMode('0');
$r = probe("\$o = []; foreach ($keysJson as \$k) \$o[\$k] = tenantModuleAllowsPage(\$k) && canView(\$k); echo json_encode(\$o);");
foreach (array_merge($hidden, $kept) as $k) {
    if ($k === 'expenses' || $k === 'suppliers' || $k === 'supplier_payments') continue;   // module-owned, no tenant here
    ok("OFF: admin views '$k'", ($r[$k] ?? null) === true);
}

// ─────────────────────────────────────────────────────────────────────────────
section('3. Roles & Permissions matrix');
$idByKey = $pdo->query("SELECT page_key, permission_id FROM permissions")->fetchAll(PDO::FETCH_KEY_PAIR);
$matrixProbe = "\$m = loadRolePermissionMatrix(\$pdo); echo json_encode(['visible' => array_column(\$m['visible'], 'page_key'), 'preserved' => \$m['preserved_ids']]);";
setSimpleMode('1');
$m = probe($matrixProbe, 1, true);
$visibleHiddenRow = [];
$isHiddenRow = $pdo->query("SELECT page_key, COALESCE(is_hidden,0) FROM permissions")->fetchAll(PDO::FETCH_KEY_PAIR);
foreach ($hidden as $k) {
    if ((int)($isHiddenRow[$k] ?? 0) === 1) continue;   // never in the matrix anyway
    ok("ON: '$k' not in the matrix", !in_array($k, $m['visible'] ?? [], true));
    ok("ON: '$k' grant preserved on save (not deleted)", in_array((int)$idByKey[$k], array_map('intval', $m['preserved'] ?? []), true));
}
foreach (['customers', 'products', 'pos', 'warehouses', 'sales_report', 'inventory_report', 'categories', 'stock_adjustments', 'reports'] as $k) {
    if ((int)($isHiddenRow[$k] ?? 0) === 1) continue;
    ok("ON: '$k' still in the matrix", in_array($k, $m['visible'] ?? [], true));
}
setSimpleMode('0');
$m = probe($matrixProbe, 1, true);
foreach ($hidden as $k) {
    if ((int)($isHiddenRow[$k] ?? 0) === 1) continue;
    ok("OFF: '$k' back in the matrix", in_array($k, $m['visible'] ?? [], true));
}

// Real save round-trip (rolled back): a hidden grant survives a matrix save.
setSimpleMode('1');
$save = probe("
    \$pdo->beginTransaction();
    \$rid = (int)\$pdo->query('SELECT role_id FROM roles WHERE role_id <> 1 ORDER BY role_id LIMIT 1')->fetchColumn();
    \$bs  = (int)\$pdo->query(\"SELECT permission_id FROM permissions WHERE page_key = 'balance_sheet'\")->fetchColumn();
    \$pdo->prepare('DELETE FROM role_permissions WHERE role_id = ? AND permission_id = ?')->execute([\$rid, \$bs]);
    \$pdo->prepare('INSERT INTO role_permissions (role_id, permission_id, can_view) VALUES (?, ?, 1)')->execute([\$rid, \$bs]);
    saveRolePermissionGrants(\$pdo, \$rid, [], loadRolePermissionMatrix(\$pdo));
    \$still = (int)\$pdo->query(\"SELECT COUNT(*) FROM role_permissions WHERE role_id = \$rid AND permission_id = \$bs AND can_view = 1\")->fetchColumn();
    \$pdo->rollBack();
    echo json_encode(['still' => \$still, 'rid' => \$rid]);", 1, true);
ok('ON: saving a role keeps its hidden Balance Sheet grant (restored when Simple Mode is turned off)',
   ($save['still'] ?? 0) === 1);

// ─────────────────────────────────────────────────────────────────────────────
section('4. Router — Smart POS tenant (pos + warehouses only)');
setSimpleMode('1');
[$out] = page('--routes', $adminId, true);
$routes = json_decode($out, true) ?: [];
ok('route map read', $routes !== []);
foreach (['balance_sheet', 'cash_flow', 'income_statement', 'trial_balance', 'ledger_report', 'tax_report',
          'chart_of_accounts', 'transactions', 'inventory_valuation', 'locations', 'customer_groups', 'customer_import'] as $rt) {
    if (!array_key_exists($rt, $routes)) continue;
    ok("ON: route '$rt' unavailable", $routes[$rt] === false);
}
foreach (['dashboard', 'customers', 'products', 'pos', 'warehouses', 'expenses', 'sales_report', 'inventory_report', 'expense_report', 'reports'] as $rt) {
    if (!array_key_exists($rt, $routes)) continue;
    ok("ON: route '$rt' still available", $routes[$rt] === true);
}

// ─────────────────────────────────────────────────────────────────────────────
section('5. Pages — Simple Mode ON');
[$body, $code] = page('app/bms/invoice/reports.php', $adminId, true);
ok("ON: Reports hub opens ($code)", $code === 200 && str_contains($body, 'Inventory Reports'));
ok('ON: Reports hub has no Financial Reports card', !str_contains($body, 'bi-calculator me-2"></i> Financial Reports') && !str_contains($body, '?report=balance_sheet'));
foreach (['balance_sheet', 'cash_flow', 'trial_balance', 'general_ledger', 'journal_mappings'] as $rep) {
    [$b, $c] = page('app/bms/invoice/reports.php', $adminId, true, ['report' => $rep]);
    ok("ON: Reports hub ?report=$rep refused (404, got $c)", $c === 404);
}
[$b, $c] = page('app/bms/invoice/reports.php', $adminId, true, ['report' => 'low_stock']);
ok("ON: Reports hub ?report=low_stock still opens ($c)", $c === 200);

[$b, $c] = page('app/constant/reports/balance_sheet.php', $adminId, true);
ok("ON: Balance Sheet page refused for admin (got $c)", $c !== 200 || !str_contains($b, 'Balance Sheet'));
[$b, $c] = page('app/constant/reports/sales_report.php', $adminId, true);
ok("ON: Sales Report still opens ($c)", $c === 200);

// A cashier-like role that has Sales Report but not Financial Reports.
$pdo->prepare("INSERT INTO roles (role_name, description, created_at) VALUES (?, 'test', NOW())")->execute(['__smh_test_role']);
$testRole = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO role_permissions (role_id, permission_id, can_view) VALUES (?, ?, 1)")
    ->execute([$testRole, (int)$idByKey['sales_report']]);
$pdo->prepare("INSERT INTO users (username, email, first_name, last_name, role_id, password, is_active) VALUES (?, ?, 'Smh', 'Cashier', ?, 'x', 1)")
    ->execute(['__smh_cashier', '__smh@test.local', $testRole]);
$testUser = (int)$pdo->lastInsertId();
$cleanup[] = function () use ($pdo, $testRole, $testUser) {
    $pdo->prepare("DELETE FROM users WHERE user_id = ?")->execute([$testUser]);
    $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?")->execute([$testRole]);
    $pdo->prepare("DELETE FROM roles WHERE role_id = ?")->execute([$testRole]);
};
[$j, $c] = apiAs('api/account/search_customers.php', $testUser, $testRole, ['q' => 'a']);
ok("ON: Sales Report user can search customers without financial_reports ($c)", $c === 200 && is_array($j['results'] ?? null));
[$b, $c] = page('api/account/search_customers.php', $adminId, true, ['q' => 'a']);
ok("ON: admin's Sales Report customer search works ($c)", $c === 200);

// Customers list: the "View Account" (customer_statement) link follows financial_reports.
$advBefore = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'pos_advanced_customer'")->fetchColumn();
$cleanup[] = function () use ($advBefore) {
    global $root;
    runPhp("require '$root/roots.php'; " . ($advBefore === false
        ? "\$pdo->exec(\"DELETE FROM system_settings WHERE setting_key = 'pos_advanced_customer'\");"
        : "save_setting('pos_advanced_customer', " . var_export((string)$advBefore, true) . ");"));
};
runPhp("require '$root/roots.php'; save_setting('pos_advanced_customer', '1');");
[$b, $c] = page('app/bms/customer/customers.php', $adminId, true);
ok("ON + advanced customer: customers page opens ($c)", $c === 200);
ok('ON + advanced customer: "View Account" link is switched off', substr_count($b, 'row.total_invoices > 0 && false') === 1
   && substr_count($b, 'customer.total_invoices > 0 && false') === 1);

// ─────────────────────────────────────────────────────────────────────────────
section('6. Simple Mode OFF — pages behave as before');
setSimpleMode('0');
[$body, $code] = page('app/bms/invoice/reports.php', $adminId, true, [], false);
ok("OFF: Reports hub opens ($code)", $code === 200);
ok('OFF: Reports hub shows Financial Reports card', str_contains($body, 'bi-calculator me-2"></i> Financial Reports') && str_contains($body, '?report=balance_sheet'));
[$b, $c] = page('app/bms/invoice/reports.php', $adminId, true, ['report' => 'balance_sheet'], false);
ok("OFF: Reports hub ?report=balance_sheet opens ($c)", $c === 200);
[$b, $c] = page('app/bms/customer/customers.php', $adminId, true, [], false);
ok('OFF + advanced customer: "View Account" link is on for admin', substr_count($b, 'row.total_invoices > 0 && true') === 1);
[$j, $c] = apiAs('api/account/search_customers.php', $testUser, $testRole, ['q' => 'a']);
ok("OFF: Sales Report user can search customers ($c)", $c === 200 && is_array($j['results'] ?? null));
// The gate still refuses a user with neither report.
$pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?")->execute([$testRole]);
[$j, $c] = apiAs('api/account/search_customers.php', $testUser, $testRole, ['q' => 'a']);
ok("OFF: user with no report permission still refused ($c)", $c === 403);
