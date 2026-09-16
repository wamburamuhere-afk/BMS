<?php
/**
 * Dashboard Quick Actions — "Create Shop" button (Simple POS only)
 *   php tests/test_dashboard_create_shop_quick_action_cli.php
 *
 * Request: a dedicated "Create Shop" Quick Actions entry, visible only when
 * Simple POS is on, opening the warehouse registration form (warehouses.php
 * already treats a warehouse row as a "shop" for these tenants — see
 * core/terminology.php's wLabel()/isShopLabel()).
 *
 * The page already had a generic "Add Warehouse"/"Add Shop" Quick Actions
 * entry (label chosen by wLabel(), which depends on POS+Projects/shop_mode —
 * a DIFFERENT condition than Simple Mode). Adding a second, differently-
 * labelled entry pointing at the same destination would just be a confusing
 * duplicate for a Simple POS tenant, so the fix branches: Simple Mode on ->
 * exactly one entry, labelled "Create Shop"; Simple Mode off -> exactly the
 * original entry, unchanged, so non-Simple-POS tenants see zero behavior
 * change.
 *
 *   A. STATIC  — dashboard.php lints clean.
 *   B. WIRING  — both branches present, mutually exclusive on $pos_simple_mode,
 *                both still gated on canCreate('warehouses'), both link to
 *                warehouses.php?action=add (confirmed there to auto-open the
 *                Add Warehouse/Shop modal).
 *   C. TRANSLATION — 'Create Shop' resolves to a real Swahili string, not a
 *                raw English fallback.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";

$pass = 0; $fail = 0;
function pass(string $m): void  { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function src(string $root, string $rel): string { $p = "$root/$rel"; return file_exists($p) ? file_get_contents($p) : ''; }
function has(string $hay, string $needle, string $label): void { strpos($hay, $needle) !== false ? pass($label) : fail("$label — missing `" . substr($needle, 0, 70) . "`"); }

register_shutdown_function(function () {
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

// ─────────────────────────────────────────────────────────────────────────
section('1. Static');
$out = []; $rc = 0;
exec('php -l ' . escapeshellarg("$root/app/dashboard.php") . ' 2>&1', $out, $rc);
$rc === 0 ? pass('app/dashboard.php') : fail('php -l failed: ' . implode(' ', $out));

// ─────────────────────────────────────────────────────────────────────────
section('2. Source wiring');
$dash = src($root, 'app/dashboard.php');
has($dash, "if (\$pos_simple_mode && canCreate('warehouses')):", 'Simple-Mode branch requires BOTH simple mode AND warehouse-create permission');
has($dash, "<?= t('Create Shop') ?>", "Simple-Mode branch uses the literal 'Create Shop' label");
has($dash, "elseif (canCreate('warehouses')):", 'non-Simple-Mode branch keeps the original permission-only gate');
has($dash, "<?= wLabel('Add Warehouse', 'Add Shop') ?>", 'non-Simple-Mode branch is completely unchanged from before');

$dashLines = explode("\n", $dash);
$hrefCount = 0;
foreach ($dashLines as $i => $line) {
    if (strpos($line, "getUrl('warehouses') ?>?action=add") !== false) $hrefCount++;
}
$hrefCount === 2
    ? pass('Exactly 2 links to warehouses.php?action=add exist (one per branch) — no stray third copy')
    : fail("Expected 2 occurrences of the warehouses?action=add link, found $hrefCount");

$warehousesPage = src($root, 'app/bms/stock/warehouses.php');
has($warehousesPage, "if (urlParams.get('action') === 'add') {", 'warehouses.php actually honours ?action=add (auto-opens the Add modal)');

// ─────────────────────────────────────────────────────────────────────────
section('3. Translation coverage');
require_once "$root/core/i18n.php";
loadLanguage('sw');
$sw = t('Create Shop');
($sw !== 'Create Shop' && $sw !== '')
    ? pass("'Create Shop' has a real Swahili translation ('$sw')")
    : fail("'Create Shop' falls back to raw English under sw locale");
