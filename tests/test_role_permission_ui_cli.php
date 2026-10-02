<?php
/**
 * tests/test_role_permission_ui_cli.php — Roles & Permissions matrix is module-aware.
 *
 *   php tests/test_role_permission_ui_cli.php
 *
 *   1. registry gaps closed (mm_shifts -> mobile_money, pos_restock -> pos)
 *   2. relevance map points at real features and real permission rows
 *   3. POS + Warehouses tenant: only relevant rows, POS rows in one tab
 *   4. no tenant resolved: every non-hidden row shows, nothing preserved
 *   5. Review/Approve list matches every canReview/canApprove use in code
 *      and every review/approve notification event (drift guard)
 *   6. real save on the DB (rolled back): hidden-module grants survive,
 *      review/approve only stick on workflow pages, unseen rows can't be granted
 *
 * CLI ONLY. All DB writes happen inside a transaction that is rolled back.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/role_permission_ui.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $what\n"; }
    else       { $fail++; echo "  FAIL  $what\n"; }
}
function section(string $s): void { echo "\n== $s ==\n"; }

/** Simulate a tenant with exactly these features on (null = no tenant resolved). */
function withFeatures(?array $on): void {
    if ($on === null) { unset($GLOBALS['__bms_features']); return; }
    $f = [];
    foreach (allFeatureKeys() as $k) $f[$k] = in_array($k, $on, true);
    $GLOBALS['__bms_features'] = $f;
}
function keysOf(array $rows): array { return array_map(fn($r) => $r['page_key'], $rows); }

$allPerms = $pdo->query("SELECT permission_id, page_key, COALESCE(is_hidden,0) AS is_hidden FROM permissions")->fetchAll(PDO::FETCH_ASSOC);
$idByKey = [];
foreach ($allPerms as $p) $idByKey[$p['page_key']] = (int)$p['permission_id'];

echo "\nBMS — Roles & Permissions matrix: module-aware display\n";

// ─────────────────────────────────────────────────────────────────────────────
section('1. Registry gaps closed');
ok("mm_shifts is owned by mobile_money", featureForPageKey('mm_shifts') === ['mobile_money']);
ok("pos_restock is owned by pos",        featureForPageKey('pos_restock') === ['pos']);

// ─────────────────────────────────────────────────────────────────────────────
section('2. Relevance map integrity');
$features = allFeatureKeys();
foreach (rolePermissionRelevantModules() as $pk => $needs) {
    ok("relevance '$pk' names real features", $needs !== [] && array_diff($needs, $features) === []);
    ok("relevance '$pk' exists in permissions", isset($idByKey[$pk]));
    ok("relevance '$pk' is not already module-owned (would be redundant)", featureForPageKey($pk) === []);
}
foreach (rolePermissionWorkflowPageKeys() as $pk) {
    ok("workflow key '$pk' exists in permissions", isset($idByKey[$pk]));
}
$qv = file_get_contents(__DIR__ . '/../app/bms/sales/quotations/quotation_view.php');
ok("quotations hint is still true (quotation_view gates review on sales_orders)",
   rolePermissionNote('quotations') !== null && str_contains($qv, "canReview('sales_orders')")
   && rolePermissionHasWorkflow('sales_orders') && !rolePermissionHasWorkflow('quotations'));

// ─────────────────────────────────────────────────────────────────────────────
section('3. POS + Warehouses tenant');
withFeatures(['pos', 'warehouses']);
$m = loadRolePermissionMatrix($pdo);
$vis = keysOf($m['visible']);

foreach (['mm_shifts', 'color_settings', 'payment_create', 'attendance_settings', 'policy_management',
          'sms_templates', 'zoom_settings', 'invoices', 'quotations', 'employees', 'payroll', 'tenders',
          'crm_leads', 'purchase_orders', 'grn', 'projects'] as $pk) {
    if (isset($idByKey[$pk])) ok("hidden: $pk", !in_array($pk, $vis, true));
}
foreach (['pos', 'pos_config_settings', 'pos_restock', 'pos_price_override', 'pos_discount_override',
          'warehouses', 'locations', 'products', 'customers', 'trial_balance', 'dashboard'] as $pk) {
    if (isset($idByKey[$pk])) ok("shown: $pk", in_array($pk, $vis, true));
}
$leak = array_filter($vis, fn($pk) => !tenantModuleAllowsPage($pk) || !rolePermissionVisible($pk));
ok('every shown row is allowed for this tenant', $leak === []);

$hiddenRowIds = array_map(fn($p) => (int)$p['permission_id'], array_filter($allPerms, fn($p) => (int)$p['is_hidden'] === 1));
ok('preserved set never includes is_hidden (locked) rows', array_intersect($m['preserved_ids'], $hiddenRowIds) === []);
ok('visible + preserved = all non-hidden rows',
   count($m['visible']) + count($m['preserved_ids']) === count($allPerms) - count($hiddenRowIds));

$tabs = [];
foreach ($m['visible'] as $r) $tabs[rolePermissionTabName($r['page_key'], $r['module_name'])][] = $r['page_key'];
foreach (['pos', 'pos_restock', 'pos_config_settings', 'pos_price_override', 'pos_discount_override'] as $pk) {
    if (isset($idByKey[$pk])) ok("'$pk' is in the Point of Sale tab", in_array($pk, $tabs['Point of Sale'] ?? [], true));
}
foreach ($tabs as $name => $keys) {
    if ($name === 'Point of Sale') continue;
    ok("no POS row outside the Point of Sale tab ($name)", array_filter($keys, fn($k) => str_starts_with($k, 'pos')) === []);
}
ok('POS rows have no Review/Approve', !rolePermissionHasWorkflow('pos') && !rolePermissionHasWorkflow('pos_restock'));

withFeatures(['pos', 'warehouses', 'sales']);
ok('Color Setting returns when Sales is on', rolePermissionVisible('color_settings'));
withFeatures(['pos', 'warehouses', 'procurement']);
ok('Color Setting returns when Procurement is on', rolePermissionVisible('color_settings'));
withFeatures(['pos', 'warehouses', 'mobile_money']);
ok('MM Shifts returns when Mobile Money is on', rolePermissionVisible('mm_shifts'));
withFeatures(['pos', 'warehouses', 'hr']);
ok('Attendance Settings returns when HR is on', rolePermissionVisible('attendance_settings'));

// ─────────────────────────────────────────────────────────────────────────────
section('4. No tenant resolved — everything shows');
withFeatures(null);
$m = loadRolePermissionMatrix($pdo);
$retiredIds = array_values(array_filter(array_map(fn($k) => $idByKey[$k] ?? null, rolePermissionRetiredKeys())));
sort($retiredIds); $pres = $m['preserved_ids']; sort($pres);
ok('only retired rows hidden when no tenant resolved', $pres === $retiredIds);
ok('all other non-hidden rows shown', count($m['visible']) === count($allPerms) - count($hiddenRowIds) - count($retiredIds));

// ─────────────────────────────────────────────────────────────────────────────
section('4b. Retired Tax page (tax_settings)');
ok('tax_settings permission row exists', isset($idByKey['tax_settings']));
foreach ([null, ['pos', 'warehouses'], ['sales', 'procurement', 'pos', 'warehouses']] as $set) {
    withFeatures($set);
    ok('tax_settings hidden from Roles (' . ($set === null ? 'all on' : implode('+', $set)) . ')', !rolePermissionVisible('tax_settings'));
}
withFeatures(null);
$hdr = file_get_contents(__DIR__ . '/../header.php');
ok('header.php has no link to tax_settings', !str_contains($hdr, "getUrl('tax_settings')"));
$tx = file_get_contents(__DIR__ . '/../app/constant/settings/tax_settings.php');
$redirAt = strpos($tx, "header('Location: ' . getUrl('unauthorized'))");
ok('tax_settings.php redirects to unauthorized before any output or save',
   $redirAt !== false && $redirAt < strpos($tx, 'header.php') && $redirAt < strpos($tx, 'save_setting(')
   && $redirAt < strpos($tx, "autoEnforcePermission('tax_settings')"));

// ─────────────────────────────────────────────────────────────────────────────
section('5. Review/Approve list matches the code (drift guard)');
$root = realpath(__DIR__ . '/..');
$used = [];
$dynamic = [];
$dirs = ['app', 'api', 'ajax', 'core', 'includes'];
$files = glob($root . '/*.php');
foreach ($dirs as $d) {
    if (!is_dir("$root/$d")) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$d", FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) if ($f->getExtension() === 'php') $files[] = $f->getPathname();
}
foreach ($files as $f) {
    $src = file_get_contents($f);
    if (preg_match_all("/can(?:Review|Approve)\(\s*['\"]([a-z0-9_]+)['\"]/", $src, $mm)) {
        foreach ($mm[1] as $k) $used[$k] = true;
    }
    if (preg_match("/can(?:Review|Approve)\(\s*\\$/", $src) && !str_ends_with(str_replace('\\', '/', $f), 'core/permissions.php')) {
        $dynamic[] = substr($f, strlen($root) + 1);
    }
}
ok('found canReview/canApprove uses to check (scan worked)', count($used) > 10);

// Retired Tax page: if anything starts reading its settings, un-retire it.
$taxSrcReaders = [];
foreach ($files as $f) {
    if (str_ends_with(str_replace('\\', '/', $f), 'app/constant/settings/tax_settings.php')) continue;
    $src = file_get_contents($f);
    if (preg_match("/(get_?[sS]etting\(\s*|setting_key\s*=\s*)['\"](enable_tax|tax_name|tax_rate|tax_number|tax_type)['\"]/", $src)) {
        $taxSrcReaders[] = substr($f, strlen($root) + 1);
    }
}
ok('no code reads the retired Tax page settings' . ($taxSrcReaders ? ' — read in: ' . implode(', ', $taxSrcReaders) : ''), $taxSrcReaders === []);
$missing = array_diff(array_keys($used), rolePermissionWorkflowPageKeys());
ok('every canReview/canApprove page_key is in the workflow list' . ($missing ? ' — missing: ' . implode(', ', $missing) : ''), $missing === []);
ok('no dynamic canReview/canApprove($var) call the list cannot see' . ($dynamic ? ' — ' . implode(', ', $dynamic) : ''), $dynamic === []);

try {
    $ev = $pdo->query("SELECT DISTINCT page_key FROM notification_events WHERE required_verb IN ('review','approve')")->fetchAll(PDO::FETCH_COLUMN);
    $evMissing = array_diff($ev, rolePermissionWorkflowPageKeys());
    ok('every review/approve notification event page_key is in the workflow list' . ($evMissing ? ' — missing: ' . implode(', ', $evMissing) : ''), $evMissing === []);
} catch (PDOException $e) {
    ok('notification_events readable', false);
}
$stale = array_diff(rolePermissionWorkflowPageKeys(), array_keys($used), $ev ?? []);
ok('no workflow key is stale (unused in code and events)' . ($stale ? ' — stale: ' . implode(', ', $stale) : ''), $stale === []);

// ─────────────────────────────────────────────────────────────────────────────
section('6. Real save (rolled back)');
$need = ['mm_shifts', 'pos', 'customers', 'tenders', 'purchase_orders', 'user_roles', 'tax_settings'];
$have = array_filter($need, fn($k) => isset($idByKey[$k]));
ok('fixture permission rows exist: ' . implode(', ', $need), count($have) === count($need));

if (count($have) === count($need)) {
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO roles (role_name, description, created_at) VALUES (?, 'test', NOW())")
            ->execute(['ZZTEST role matrix ' . uniqid()]);
        $rid = (int)$pdo->lastInsertId();

        $grant = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id, can_view, can_create, can_edit, can_delete, can_review, can_approve) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $grant->execute([$rid, $idByKey['mm_shifts'], 1, 1, 1, 0, 0, 0]);   // module off -> must survive
        $grant->execute([$rid, $idByKey['pos'], 1, 0, 0, 0, 1, 1]);         // shown, stale review/approve
        $grant->execute([$rid, $idByKey['user_roles'], 1, 0, 0, 0, 0, 0]);  // locked row -> cleared as before
        $grant->execute([$rid, $idByKey['tax_settings'], 1, 1, 1, 0, 0, 0]); // retired row -> kept

        $row = function (string $pk) use ($pdo, $rid, $idByKey) {
            $s = $pdo->prepare("SELECT * FROM role_permissions WHERE role_id = ? AND permission_id = ?");
            $s->execute([$rid, $idByKey[$pk]]);
            return $s->fetchAll(PDO::FETCH_ASSOC);
        };

        withFeatures(['pos', 'warehouses']);
        $submitted = [
            $idByKey['pos']             => ['view' => 'on', 'create' => 'on', 'review' => 'on', 'approve' => 'on'],
            $idByKey['customers']       => ['view' => 'on'],
            $idByKey['tenders']         => ['view' => 'on', 'create' => 'on'],   // not on screen
            $idByKey['purchase_orders'] => ['view' => 'on', 'approve' => 'on'],  // not on screen
        ];
        saveRolePermissionGrants($pdo, $rid, $submitted, loadRolePermissionMatrix($pdo));

        $r = $row('mm_shifts');
        ok('hidden-module grant (MM Shifts) survives the save', count($r) === 1 && (int)$r[0]['can_view'] === 1 && (int)$r[0]['can_edit'] === 1);
        $r = $row('pos');
        ok('POS grant saved once with view+create', count($r) === 1 && (int)$r[0]['can_view'] === 1 && (int)$r[0]['can_create'] === 1);
        ok('POS review/approve forced off (no workflow)', (int)$r[0]['can_review'] === 0 && (int)$r[0]['can_approve'] === 0);
        ok('Customers grant saved', count($row('customers')) === 1);
        ok('row not on screen (Tenders) cannot be granted', $row('tenders') === []);
        ok('row not on screen (Purchase Orders) cannot be granted', $row('purchase_orders') === []);
        ok('locked is_hidden row cleared exactly as before', $row('user_roles') === []);
        ok('retired Tax grant survives the save', count($row('tax_settings')) === 1);

        // Unticking everything visible keeps only the hidden grants.
        saveRolePermissionGrants($pdo, $rid, [$idByKey['tax_settings'] => ['view' => 'on']], loadRolePermissionMatrix($pdo));
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM role_permissions WHERE role_id = ?");
        $cnt->execute([$rid]);
        ok('empty save leaves only the preserved grants (MM Shifts + Tax)', (int)$cnt->fetchColumn() === 2 && count($row('mm_shifts')) === 1);
        $r = $row('tax_settings');
        ok('crafted POST cannot change the retired Tax grant', count($r) === 1 && (int)$r[0]['can_edit'] === 1);

        // Module switched back on: grant is there and now editable.
        withFeatures(null);
        ok('re-enabled module: preserved grant visible again', count($row('mm_shifts')) === 1);
        $submitted = [
            $idByKey['purchase_orders'] => ['view' => 'on', 'review' => 'on', 'approve' => 'on'],
        ];
        saveRolePermissionGrants($pdo, $rid, $submitted, loadRolePermissionMatrix($pdo));
        $r = $row('purchase_orders');
        ok('workflow page keeps review+approve', count($r) === 1 && (int)$r[0]['can_review'] === 1 && (int)$r[0]['can_approve'] === 1);
        ok('all rows on screen: unticked MM Shifts now removed', $row('mm_shifts') === []);

        saveRolePermissionGrants($pdo, $rid, $submitted, loadRolePermissionMatrix($pdo));
        ok('saving twice creates no duplicates', count($row('purchase_orders')) === 1);
    } finally {
        $pdo->rollBack();
    }
}

withFeatures(null);
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
