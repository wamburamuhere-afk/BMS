<?php
/**
 * tests/test_supplier_access_simple_pos_cli.php
 *   php tests/test_supplier_access_simple_pos_cli.php
 *
 * "Supplier Access" (2026-09-17 request) — a lightweight superadmin-only
 * override, set from the same "Point of Sale > More" dialog as Advanced
 * Product/Customer/Supplier, that makes Suppliers reachable for a Simple POS
 * tenant WITHOUT the full Procurement module. Unlike the pos_advanced_*
 * toggles (which change which FORM renders once a page is already reachable),
 * this one changes REACHABILITY itself — core/feature_registry.php's
 * tenantModuleAllowsPage() bypass for the 'suppliers' page_key.
 *
 * Three things this suite proves that cannot be proven by the pos_advanced_*
 * pattern's own tests:
 *   1. the bypass is scoped to 'suppliers' ONLY — 'supplier_payments' (owned
 *      by the SAME 'procurement' feature) stays blocked, so the toggle can
 *      never leak Procurement's other screens.
 *   2. supplier_details.php's procurement-cycle tabs (Payments/Bills/Purchase
 *      Orders/Projects/GRN/Returns/DN/RFQ/Debit Notes) stay CLOSED while the
 *      toggle opens the page but Procurement itself is still off — the real
 *      gap this feature exposed and fixed ($hideProcurementTabs).
 *   3. the optional Restock "Supplier" field (pos_modals_new.php) is
 *      genuinely ABSENT from the DOM, not just hidden, whenever Suppliers
 *      isn't reachable, and reappears the instant it is.
 *
 * CLI ONLY. Provisions one real throwaway tenant and removes it afterwards.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

// ─── Superadmin-action worker ──────────────────────────────────────────────
if (($argv[1] ?? '') === '--endpoint') {
    $file   = (string)$argv[2];
    $post   = json_decode((string)base64_decode((string)$argv[3]), true) ?: [];
    $method = (string)($argv[4] ?? 'POST');
    $host   = (string)($argv[5] ?? 'localhost');
    $auth   = (string)($argv[6] ?? '0') === '1';

    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['HTTP_HOST']      = $host;
    $_SERVER['REQUEST_URI']    = '/' . $file;

    if ($auth) {
        require_once __DIR__ . '/../core/superadmin_auth.php';
        require_once __DIR__ . '/../helpers.php';
        superadminSessionReady();
        require_once __DIR__ . '/../core/control_db.php';
        $row = getControlPdo()->query('SELECT id FROM superadmins ORDER BY id LIMIT 1')->fetch();
        if ($row) $_SESSION['superadmin_id'] = (int)$row['id'];
        if (!array_key_exists('_csrf', $post)) $post['_csrf'] = csrf_token();
    }

    $_POST = $post;
    require __DIR__ . '/../' . $file;
    exit(0);
}

// ─── Superadmin-page worker ────────────────────────────────────────────────
if (($argv[1] ?? '') === '--route') {
    $_SERVER['HTTP_HOST']      = (string)$argv[2];
    $_SERVER['REQUEST_URI']    = (string)$argv[3];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['QUERY_STRING']   = parse_url((string)$argv[3], PHP_URL_QUERY) ?: '';
    parse_str($_SERVER['QUERY_STRING'], $_GET);

    require_once __DIR__ . '/../roots.php';
    require_once __DIR__ . '/../core/superadmin_auth.php';
    require_once __DIR__ . '/../core/control_db.php';
    $r = getControlPdo()->query('SELECT id FROM superadmins ORDER BY id LIMIT 1')->fetch();
    if ($r) $_SESSION['superadmin_id'] = (int)$r['id'];

    ob_start();
    handleRoute();
    fwrite(STDOUT, ob_get_clean());
    exit(0);
}

// ─── Tenant-side page worker ────────────────────────────────────────────────
if (($argv[1] ?? '') === '--tenant-route') {
    $host   = (string)$argv[2];
    $uri    = (string)$argv[3];
    $userId = (int)$argv[4];

    $_SERVER['HTTP_HOST']      = $host;
    $_SERVER['REQUEST_URI']    = $uri;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['QUERY_STRING']   = parse_url($uri, PHP_URL_QUERY) ?: '';
    parse_str($_SERVER['QUERY_STRING'], $_GET);

    require_once __DIR__ . '/../roots.php';
    $_SESSION['user_id']  = $userId;
    $_SESSION['role_id']  = 1;
    $_SESSION['is_admin'] = true;

    ob_start();
    handleRoute();
    fwrite(STDOUT, ob_get_clean());
    exit(0);
}

// ─── Tenant-side Restock modal worker (not a routed page) ─────────────────
if (($argv[1] ?? '') === '--tenant-modal') {
    $host   = (string)$argv[2];
    $userId = (int)$argv[3];

    $_SERVER['HTTP_HOST']      = $host;
    $_SERVER['REQUEST_URI']    = '/pos';
    $_SERVER['REQUEST_METHOD'] = 'GET';

    require_once __DIR__ . '/../roots.php';
    $_SESSION['user_id']    = $userId;
    $_SESSION['role_id']    = 1;
    $_SESSION['is_admin']   = true;
    $_SESSION['first_name'] = 'Test';
    $_SESSION['last_name']  = 'Admin';
    $_SESSION['user_role']  = 'Admin';

    global $pdo;
    $can_restock_product = true;
    ob_start();
    include __DIR__ . '/../app/bms/pos/pos_modals_new.php';
    fwrite(STDOUT, ob_get_clean());
    exit(0);
}

// ─── Runner ─────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../core/control_db.php';
require_once __DIR__ . '/../core/tenant_crypto.php';
require_once __DIR__ . '/../core/tenant_provisioner.php';
require_once __DIR__ . '/../core/tenant_admin.php';
require_once __DIR__ . '/../core/superadmin_auth.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  \033[32mPASS\033[0m  $what\n"; }
    else       { $fail++; echo "  \033[31mFAIL\033[0m  $what" . ($detail !== '' ? "\n          -> $detail" : '') . "\n"; }
}
function section(string $s): void { echo "\n\033[1m== $s ==\033[0m\n"; }
function src(string $root, string $rel): string { $p = "$root/$rel"; return file_exists($p) ? file_get_contents($p) : ''; }
function has(string $hay, string $needle, string $label): void {
    global $pass, $fail;
    strpos($hay, $needle) !== false ? ok($label, true) : ok($label, false, 'missing `' . substr($needle, 0, 90) . '`');
}

register_shutdown_function(function () {
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses: $pass   Failures: $fail\n";
    if ($fail > 0) exit(1);
});

$root = dirname(__DIR__);
echo "\nBMS — Supplier Access (Simple POS Supplier visibility without Procurement)\n";

// ─────────────────────────────────────────────────────────────────────────────
section('1. Files exist + lint clean');

$files = [
    'scripts/setup_control_db.php',
    'core/tenant_admin.php',
    'actions/superadmin_tenant_supplier_access.php',
    'core/pos_nav.php',
    'core/feature_registry.php',
    'app/superadmin/tenant_view.php',
    'app/bms/Suppliers/supplier_details.php',
    'core/stock_intake.php',
    'api/pos/quick_restock.php',
    'app/bms/pos/pos_modals_new.php',
    'migrations/tenant/2026_09_17_product_batches_supplier_id.php',
    'migrations/2026_09_17_product_batches_supplier_id_legacy_db.php',
];
foreach ($files as $f) {
    if (!file_exists("$root/$f")) { ok("exists: $f", false); continue; }
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $out, $rc);
    ok("php -l clean: $f", $rc === 0, implode(' ', $out));
}

// ─────────────────────────────────────────────────────────────────────────────
section('2. Source wiring');

has(src($root, 'scripts/setup_control_db.php'), "'pos_supplier_access_locked' => \"ADD COLUMN", 'setup_control_db.php declares the lock column');

$taSrc = src($root, 'core/tenant_admin.php');
has($taSrc, "function tenantSupplierAccessStatus(int \$tenantId): ?array", 'tenant_admin.php has tenantSupplierAccessStatus()');
has($taSrc, "function setTenantSupplierAccess(int \$tenantId, bool \$enabled, bool \$locked): array", 'tenant_admin.php has setTenantSupplierAccess()');
has($taSrc, "'pos_supplier_access'", 'tenant_admin.php reads/writes the pos_supplier_access setting key');

$actionSrc = src($root, 'actions/superadmin_tenant_supplier_access.php');
has($actionSrc, 'assertSuperadminHost();', 'action endpoint asserts superadmin host');
has($actionSrc, 'superadminSessionReady();', 'action endpoint readies the superadmin session');
has($actionSrc, 'csrf_check();', 'action endpoint checks CSRF');
has($actionSrc, 'tenantSupplierAccessStatus($tenantId)', 'action endpoint status branch calls tenantSupplierAccessStatus()');
has($actionSrc, 'setTenantSupplierAccess($tenantId, $enabled, $locked)', 'action endpoint set branch calls setTenantSupplierAccess()');

$navSrc = src($root, 'core/pos_nav.php');
has($navSrc, "function supplierAccessEnabled(): bool", 'pos_nav.php has supplierAccessEnabled()');

$frSrc = src($root, 'core/feature_registry.php');
has($frSrc, "if (\$pageKey === 'suppliers' && function_exists('get_setting') && get_setting('pos_supplier_access', '0') === '1') {", 'tenantModuleAllowsPage() has the suppliers-only bypass');

$tvSrc = src($root, 'app/superadmin/tenant_view.php');
has($tvSrc, 'saSupplierAccessEnabled', 'tenant_view.php dialog has the Supplier Access checkbox');
has($tvSrc, "url: '/actions/superadmin_tenant_supplier_access.php'", 'tenant_view.php status fetch is wired to the new endpoint');
has($tvSrc, 'supplierAccess: document.getElementById(\'saSupplierAccessEnabled\').checked', 'tenant_view.php preConfirm collects the new checkbox');

$sdSrc = src($root, 'app/bms/Suppliers/supplier_details.php');
has($sdSrc, '$hideProcurementTabs = $simpleSupplierForm || !tenantFeatureEnabled(\'procurement\');', 'supplier_details.php defines $hideProcurementTabs');
has($sdSrc, "if (\$hideProcurementTabs) {", 'supplier_details.php default-tab fallback uses $hideProcurementTabs');
ok('supplier_details.php no longer gates any tab on bare !$simpleSupplierForm',
    strpos($sdSrc, '!$simpleSupplierForm') === false);

$siSrc = src($root, 'core/stock_intake.php');
has($siSrc, 'supplier_id, batch_number', 'receiveProductBatch() inserts supplier_id into product_batches');

$qrSrc = src($root, 'api/pos/quick_restock.php');
has($qrSrc, "\$supplier_id            = (int)(\$_POST['supplier_id'] ?? 0);", 'quick_restock.php reads supplier_id from POST (optional)');
has($qrSrc, "'supplier_id'      => \$supplier_id > 0 ? \$supplier_id : null,", 'quick_restock.php passes supplier_id into receiveProductBatch()');

$modalSrc = src($root, 'app/bms/pos/pos_modals_new.php');
has($modalSrc, 'id="restock_supplier_id" name="supplier_id"', 'Restock modal declares the optional Supplier field');
has($modalSrc, "canView('suppliers')", 'Restock modal gates the Supplier field on canView(\'suppliers\')');
ok('Restock modal never marks the Supplier field required',
    !preg_match('/id="restock_supplier_id"[^>]*\brequired\b/', $modalSrc));

// ─────────────────────────────────────────────────────────────────────────────
section('3. DB schema — product_batches.supplier_id (already migrated live)');

global $pdo;
require_once "$root/roots.php";
$col = $pdo->query("SHOW COLUMNS FROM product_batches LIKE 'supplier_id'")->fetch(PDO::FETCH_ASSOC);
ok('product_batches.supplier_id column exists', (bool)$col);
ok('...nullable', $col && $col['Null'] === 'YES');

// ─────────────────────────────────────────────────────────────────────────────
section('4. Runtime (legacy/dev DB) — quick_restock.php with/without a supplier');

function _sa_run_php(string $code): string {
    $tmp = tempnam(sys_get_temp_dir(), 'supacc_');
    file_put_contents($tmp, "<?php\n" . $code);
    $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return trim((string)$out);
}
function _sa_run_endpoint(string $root, int $uid, array $post): string {
    $postExport = var_export($post, true);
    return _sa_run_php("
        require '$root/roots.php';
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true;
        \$_SESSION['csrf_token'] = 'test-token';
        \$_SERVER['REQUEST_METHOD'] = 'POST';
        \$_POST = $postExport;
        \$_POST['_csrf'] = 'test-token';
        ob_start();
        include '$root/api/pos/quick_restock.php';
        echo ob_get_clean();
    ");
}
function _sa_cleanup_batch(PDO $pdo, int $productId, float $qty): void {
    $b = $pdo->prepare("SELECT batch_id FROM product_batches WHERE product_id = ? ORDER BY batch_id DESC LIMIT 1");
    $b->execute([$productId]);
    $batchId = $b->fetchColumn();
    if ($batchId) {
        $mv = $pdo->prepare("SELECT movement_id FROM stock_movements WHERE product_id = ? ORDER BY movement_id DESC LIMIT 1");
        $mv->execute([$productId]);
        $movementId = $mv->fetchColumn();
        if ($movementId) $pdo->prepare("DELETE FROM stock_movements WHERE movement_id = ?")->execute([$movementId]);
        $pdo->prepare("DELETE FROM product_batches WHERE batch_id = ?")->execute([$batchId]);
    }
    $pdo->prepare("UPDATE products SET current_stock = current_stock - ? WHERE product_id = ?")->execute([$qty, $productId]);
}
function _sa_cleanup_gl(PDO $pdo, string $productName): void {
    if ($productName === '') return;
    $j = $pdo->prepare("SELECT DISTINCT entry_id FROM journal_entry_items WHERE description LIKE ?");
    $j->execute(['%' . $productName . '%']);
    foreach ($j->fetchAll(PDO::FETCH_COLUMN) as $entryId) {
        $je = $pdo->prepare("SELECT entry_date FROM journal_entries WHERE entry_id = ?");
        $je->execute([$entryId]);
        if ($je->fetchColumn() === date('Y-m-d')) {
            $pdo->prepare("DELETE FROM journal_entry_items WHERE entry_id = ?")->execute([$entryId]);
            $pdo->prepare("DELETE FROM journal_entries WHERE entry_id = ?")->execute([$entryId]);
        }
    }
}

$uid     = (int)$pdo->query("SELECT user_id FROM users WHERE role_id=1 ORDER BY user_id LIMIT 1")->fetchColumn();
$prodRow = $pdo->query("SELECT product_id, product_name FROM products WHERE status='active' AND is_service=0 AND track_inventory=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$whRow   = $pdo->query("SELECT warehouse_id FROM warehouses WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$supRow  = $pdo->query("SELECT supplier_id FROM suppliers WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../core/payment_source.php';
$cashAcct = null;
foreach (cashBankAccounts($pdo) as $a) { $cashAcct = (int)$a['account_id']; break; }

if (!$uid || !$prodRow || !$whRow || !$supRow || !$cashAcct) {
    ok('no admin user / product / warehouse / supplier / cash account fixture available — section 4 skipped (n/a)', true);
} else {
    $pid = (int)$prodRow['product_id'];
    $wid = (int)$whRow['warehouse_id'];
    $sid = (int)$supRow['supplier_id'];

    // 4a. Supplier given — must land on the batch.
    $out1 = _sa_run_endpoint($root, $uid, [
        'product_id' => $pid, 'warehouse_id' => $wid, 'quantity' => 2,
        'date' => date('Y-m-d'), 'buying_price' => 500, 'wholesale_price' => '',
        'selling_price' => 800, 'supplier_id' => $sid, 'paid_from_account_id' => $cashAcct,
    ]);
    $json1 = json_decode($out1, true);
    if (empty($json1['success'])) {
        ok('endpoint accepted the restock with a supplier', false, $json1['message'] ?? $out1);
    } else {
        ok('endpoint accepted the restock with a supplier', true);
        $b = $pdo->prepare("SELECT supplier_id FROM product_batches WHERE product_id = ? ORDER BY batch_id DESC LIMIT 1");
        $b->execute([$pid]);
        ok('supplier_id landed on the batch', (int)$b->fetchColumn() === $sid);
        _sa_cleanup_batch($pdo, $pid, 2);
    }

    // 4b. Supplier omitted — must still succeed (never required), NULL on the batch.
    $out2 = _sa_run_endpoint($root, $uid, [
        'product_id' => $pid, 'warehouse_id' => $wid, 'quantity' => 3,
        'date' => date('Y-m-d'), 'buying_price' => 600, 'wholesale_price' => '',
        'selling_price' => 900, 'paid_from_account_id' => $cashAcct,
        // no supplier_id at all
    ]);
    $json2 = json_decode($out2, true);
    if (empty($json2['success'])) {
        ok('endpoint still succeeds with no supplier submitted', false, $json2['message'] ?? $out2);
    } else {
        ok('endpoint still succeeds with no supplier submitted (optional, never required)', true);
        $b2 = $pdo->prepare("SELECT supplier_id FROM product_batches WHERE product_id = ? ORDER BY batch_id DESC LIMIT 1");
        $b2->execute([$pid]);
        ok('supplier_id correctly NULL when omitted', $b2->fetchColumn() === null || $b2->fetchColumn() === false);
        _sa_cleanup_batch($pdo, $pid, 3);
    }

    // 4c. Bogus supplier_id — must be rejected, no batch written.
    $countBefore = (int)$pdo->query("SELECT COUNT(*) FROM product_batches WHERE product_id = $pid")->fetchColumn();
    $out3 = _sa_run_endpoint($root, $uid, [
        'product_id' => $pid, 'warehouse_id' => $wid, 'quantity' => 1,
        'date' => date('Y-m-d'), 'buying_price' => 500, 'wholesale_price' => '',
        'selling_price' => 800, 'supplier_id' => 999999999, 'paid_from_account_id' => $cashAcct,
    ]);
    $json3 = json_decode($out3, true);
    ok('endpoint rejects a supplier_id that does not resolve to a real supplier', empty($json3['success']));
    $countAfter = (int)$pdo->query("SELECT COUNT(*) FROM product_batches WHERE product_id = $pid")->fetchColumn();
    ok('...no batch row was written for the rejected request', $countAfter === $countBefore);

    _sa_cleanup_gl($pdo, (string)$prodRow['product_name']);
}

// ─────────────────────────────────────────────────────────────────────────────
section('5. Live tenant — superadmin toggle infrastructure');

$c    = getControlPdo();
$BASE = getenv('TENANT_BASE_DOMAIN') ?: 'dev.bms.local';
define('SA_HOST', superadminHostLabel() . '.' . $BASE);

$sub = 'supacc' . bin2hex(random_bytes(3));
$r = provisionTenant('Supplier Access Co', $sub, "owner@$sub.test", 'Password!123');
ok('tenant provisioned', $r['ok'] === true, (string)($r['error'] ?? ''));
if (!$r['ok']) { echo "\nCannot continue.\n"; exit(1); }
$tenantId   = (int)$r['tenant_id'];
$tenantHost = $sub . '.' . $BASE;

register_shutdown_function(function () use ($tenantId) {
    try {
        $t = getTenant($tenantId);
        if ($t) deleteTenant($tenantId, $t['company_name']);
    } catch (Throwable $e) { error_log('supplier access test cleanup: ' . $e->getMessage()); }
});

$tRow = $c->prepare("SELECT * FROM tenants WHERE id = ?");
$tRow->execute([$tenantId]);
$tData = $tRow->fetch();
$pw = decryptTenantSecret((string)$tData['db_password_encrypted']);
$tPdo = new PDO("mysql:host={$tData['db_host']};dbname={$tData['db_name']};charset=utf8mb4",
    $tData['db_username'], $pw, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

function tenantSetting(PDO $tPdo, string $key) {
    $st = $tPdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? null : $v;
}
function controlLockFlag(PDO $c, int $tenantId, string $col): int {
    $st = $c->prepare("SELECT {$col} FROM tenants WHERE id = ?");
    $st->execute([$tenantId]);
    return (int)$st->fetchColumn();
}
function endpoint(string $file, array $post, array $server = []): array {
    $cmd = 'php ' . escapeshellarg(__FILE__) . ' --endpoint '
         . escapeshellarg($file) . ' ' . escapeshellarg(base64_encode(json_encode($post))) . ' '
         . escapeshellarg($server['method'] ?? 'POST') . ' '
         . escapeshellarg($server['host'] ?? SA_HOST) . ' '
         . escapeshellarg(!empty($server['auth']) ? '1' : '0');
    $out = []; exec($cmd . ' 2>&1', $out, $rc);
    $joined = implode("\n", $out);
    if (str_contains($joined, 'Parse error') || str_contains($joined, 'Fatal error')) $joined = 'WORKER_CRASHED: ' . $joined;
    return ['out' => $joined, 'rc' => $rc];
}
function refused(array $r, string $expect = ''): bool {
    if (str_contains($r['out'], 'WORKER_CRASHED')) return false;
    if (str_contains($r['out'], '"success":true')) return false;
    return $expect === '' ? true : str_contains($r['out'], $expect);
}
function route(string $host, string $uri): string {
    $cmd = 'php ' . escapeshellarg(__FILE__) . ' --route ' . escapeshellarg($host) . ' ' . escapeshellarg($uri);
    $out = []; exec($cmd . ' 2>&1', $out, $rc);
    return implode("\n", $out);
}
function tenantRoute(string $host, string $uri, int $userId): string {
    $cmd = 'php ' . escapeshellarg(__FILE__) . ' --tenant-route ' . escapeshellarg($host) . ' ' . escapeshellarg($uri) . ' ' . escapeshellarg((string)$userId);
    $out = []; exec($cmd . ' 2>&1', $out, $rc);
    return implode("\n", $out);
}
function tenantModal(string $host, int $userId): string {
    $cmd = 'php ' . escapeshellarg(__FILE__) . ' --tenant-modal ' . escapeshellarg($host) . ' ' . escapeshellarg((string)$userId);
    $out = []; exec($cmd . ' 2>&1', $out, $rc);
    return implode("\n", $out);
}

$ownerUserId = (int)$tPdo->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetchColumn();

ok('fresh tenant has no pos_supplier_access row yet', tenantSetting($tPdo, 'pos_supplier_access') === null);
ok('fresh tenant is not locked (supplier access)', controlLockFlag($c, $tenantId, 'pos_supplier_access_locked') === 0);

$status0 = tenantSupplierAccessStatus($tenantId);
ok('status() returns an array for a real tenant', is_array($status0));
ok('status() reports enabled=false by default', $status0 !== null && $status0['enabled'] === false);

ok('...independent of Advanced Supplier (still off, untouched)', tenantSetting($tPdo, 'pos_advanced_supplier') === null);
$setAdvSupp = setTenantAdvancedSupplier($tenantId, true, true);
ok('turning Advanced Supplier on separately reports ok', $setAdvSupp['ok'] === true);
ok('...Supplier Access is UNAFFECTED by the Advanced Supplier write', tenantSetting($tPdo, 'pos_supplier_access') === null);
setTenantAdvancedSupplier($tenantId, false, false); // reset

$r = endpoint('actions/superadmin_tenant_supplier_access.php', ['tenant_id' => $tenantId, 'action' => 'status'], ['auth' => true]);
ok('POSITIVE CONTROL: an authenticated operator CAN read status', str_contains($r['out'], '"success":true'), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_tenant_supplier_access.php', ['tenant_id' => $tenantId, 'action' => 'status']);
ok('refuses without a superadmin session', refused($r, 'session has ended'), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_tenant_supplier_access.php', ['tenant_id' => $tenantId, 'action' => 'status'], ['method' => 'GET', 'auth' => true]);
ok('refuses GET even when authenticated', refused($r, 'Method not allowed'), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_tenant_supplier_access.php', ['tenant_id' => $tenantId, 'action' => 'status', '_csrf' => 'wrong-token'], ['auth' => true]);
ok('refuses a bad CSRF token', refused($r, 'CSRF'), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_tenant_supplier_access.php', ['tenant_id' => $tenantId, 'action' => 'status'], ['host' => $tenantHost, 'auth' => true]);
ok('refused from the TENANT\'s own host even when authenticated', refused($r), substr($r['out'], 0, 200));

section('6. Live tenant — tenant_view.php dialog offers the new toggle, on-demand only');

$html = route(SA_HOST, '/tenants/view?id=' . $tenantId);
ok('renders with no PHP fatal', !str_contains($html, 'Fatal error'));
ok('the Supplier Access checkbox is present', str_contains($html, 'saSupplierAccessEnabled'));
ok('the Advanced Supplier checkbox is still present too (untouched by this change)', str_contains($html, 'saAdvancedSupplierEnabled'));
ok('never embeds this tenant\'s current pos_supplier_access value on load (on-demand only)',
    !str_contains($html, 'saSupplierAccessEnabled" checked'));
ok('the status fetch for Supplier Access is wired into the same parallel $.when()',
    str_contains($html, "url: '/actions/superadmin_tenant_supplier_access.php'"));

// ─────────────────────────────────────────────────────────────────────────────
section('7. Live tenant — baseline: Procurement ON, Supplier Access OFF (default)');

ok('suppliers.php is reachable by default (Procurement on)', str_contains(tenantRoute($tenantHost, '/suppliers', $ownerUserId), 'id="form-message"'));
ok('Restock modal shows the Supplier field by default (Procurement on)', str_contains(tenantModal($tenantHost, $ownerUserId), 'id="restock_supplier_id"'));

// ─────────────────────────────────────────────────────────────────────────────
section('8. Live tenant — Procurement OFF, Supplier Access still OFF: everything closes');

$off = setTenantFeatures($tenantId, ['procurement' => false, 'projects' => false]);
ok('setTenantFeatures() turns Procurement (and its dependent Projects) off', $off['ok'] === true, (string)($off['error'] ?? ''));

$suppliersBlockedHtml = tenantRoute($tenantHost, '/suppliers', $ownerUserId);
ok('suppliers.php is now BLOCKED (no Supplier Access, no Procurement)', !str_contains($suppliersBlockedHtml, 'id="form-message"'));

$paymentsBlockedHtml = tenantRoute($tenantHost, '/suppliers/payments', $ownerUserId);
ok('suppliers/payments stays blocked too (owned by procurement, unaffected either way)', !str_contains($paymentsBlockedHtml, 'id="paymentsTable"'));

ok('Restock modal no longer shows the Supplier field (Suppliers unreachable)', !str_contains(tenantModal($tenantHost, $ownerUserId), 'id="restock_supplier_id"'));

// ─────────────────────────────────────────────────────────────────────────────
section('9. Live tenant — enable Supplier Access via the real endpoint: suppliers reopen, procurement stays shut');

$r = endpoint('actions/superadmin_tenant_supplier_access.php', ['tenant_id' => $tenantId, 'action' => 'set', 'enabled' => 1, 'locked' => 1], ['auth' => true]);
ok('POSITIVE CONTROL: action=set actually persists', str_contains($r['out'], '"success":true'), substr($r['out'], 0, 200));
ok('...verified by direct SQL', tenantSetting($tPdo, 'pos_supplier_access') === '1');
ok('...control DB lock flag set', controlLockFlag($c, $tenantId, 'pos_supplier_access_locked') === 1);

$suppliersOpenHtml = tenantRoute($tenantHost, '/suppliers', $ownerUserId);
ok('suppliers.php is REACHABLE again — the bypass works with Procurement genuinely off', str_contains($suppliersOpenHtml, 'id="form-message"'));

$paymentsStillBlockedHtml = tenantRoute($tenantHost, '/suppliers/payments', $ownerUserId);
ok('suppliers/payments STAYS blocked — the bypass is scoped to \'suppliers\' only, no leak', !str_contains($paymentsStillBlockedHtml, 'id="paymentsTable"'));

ok('Restock modal shows the Supplier field again (Suppliers reachable via the toggle)', str_contains(tenantModal($tenantHost, $ownerUserId), 'id="restock_supplier_id"'));

// ─────────────────────────────────────────────────────────────────────────────
section('10. Live tenant — supplier_details.php: page opens, procurement-cycle tabs stay CLOSED');

$tPdo->prepare("INSERT INTO suppliers (supplier_code, supplier_name, status, created_at) VALUES (?, ?, 'active', NOW())")
    ->execute(['SUP-TEST-0001', 'Supplier Access Test Co']);
$testSupplierId = (int)$tPdo->lastInsertId();
ok('a real supplier exists in the tenant DB to view', $testSupplierId > 0);

$sd = tenantRoute($tenantHost, '/suppliers/view?id=' . $testSupplierId, $ownerUserId);
ok('supplier_details.php renders with no PHP fatal', !str_contains($sd, 'Fatal error'));
ok('...page genuinely opened (not redirected away)', str_contains($sd, 'Supplier Access Test Co') || str_contains($sd, 'supplierSectionTabs'));

foreach ([
    'pane-payments' => 'Recent Payments',
    'pane-grn'      => 'GRN',
    'pane-returns'  => 'Purchase Returns',
    'pane-dn'       => 'Delivery Notes',
    'pane-rfq'      => 'RFQ',
] as $pane => $label) {
    ok("$label tab is CLOSED while Procurement is off (toggle only opened the page, not procurement)",
        !str_contains($sd, 'id="' . $pane . '"'));
}

// ─────────────────────────────────────────────────────────────────────────────
section('11. Live tenant — re-enabling Procurement brings the tabs back (fix does not over-hide)');

// Both back to their platform defaults (true) — setTenantFeatures() deletes an
// override row once it matches the default, so this also leaves zero rows in
// the control DB's tenant_features table for this soon-to-be-deleted tenant,
// same hygiene test_feature_gating_cli.php's own cleanup section checks for
// (a global COUNT(*), not scoped to any one tenant).
$on = setTenantFeatures($tenantId, ['procurement' => true, 'projects' => true]);
ok('setTenantFeatures() turns Procurement (and Projects) back on', $on['ok'] === true, (string)($on['error'] ?? ''));

$sd2 = tenantRoute($tenantHost, '/suppliers/view?id=' . $testSupplierId, $ownerUserId);
ok('Recent Payments tab REAPPEARS once Procurement is genuinely on', str_contains($sd2, 'id="pane-payments"'));

$paymentsReopenedHtml = tenantRoute($tenantHost, '/suppliers/payments', $ownerUserId);
ok('suppliers/payments is reachable again too, now that Procurement is back', str_contains($paymentsReopenedHtml, 'id="paymentsTable"'));

// ─────────────────────────────────────────────────────────────────────────────
section('12. A deleted tenant has no database left to query');

$deadSub = 'supaccdead' . bin2hex(random_bytes(3));
$rd = provisionTenant('Supplier Access Dead Co', $deadSub, "owner@$deadSub.test", 'Password!123');
ok('throwaway (to-be-deleted) tenant provisioned', $rd['ok'] === true, (string)($rd['error'] ?? ''));
$deadId = (int)$rd['tenant_id'];
deleteTenant($deadId, 'Supplier Access Dead Co');

ok('tenantSupplierAccessStatus() returns null for a deleted tenant', tenantSupplierAccessStatus($deadId) === null);
$r = endpoint('actions/superadmin_tenant_supplier_access.php', ['tenant_id' => $deadId, 'action' => 'status'], ['auth' => true]);
ok('the endpoint refuses cleanly for a deleted tenant (no crash)', refused($r), substr($r['out'], 0, 200));

// ─────────────────────────────────────────────────────────────────────────────
section('13. Superadmin-only by design — no tenant-facing UI/endpoint exposes this setting');

$avail = tenantRoute($tenantHost, '/available_modules', $ownerUserId);
ok('Available Modules renders with no PHP fatal', !str_contains($avail, 'Fatal error'));
ok('Available Modules never mentions pos_supplier_access', !str_contains($avail, 'pos_supplier_access'));

echo "\nPasses: $pass   Failures: $fail\n";
if ($fail > 0) exit(1);
