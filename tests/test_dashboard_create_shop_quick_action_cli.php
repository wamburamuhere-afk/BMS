<?php
/**
 * Dashboard Quick Actions — "Create Shop" button (Simple POS only)
 *   php tests/test_dashboard_create_shop_quick_action_cli.php
 *
 * Request: a dedicated "Create Shop" entry, visible only when Simple POS is
 * on, opening the warehouse registration form (warehouses.php already
 * treats a warehouse row as a "shop" for these tenants — see
 * core/terminology.php's wLabel()/isShopLabel()).
 *
 * 2026-09-16 follow-up: user reported the entry wasn't positioned where
 * asked — "between Add Customer and Add Product" — and clarified they meant
 * the visible "Quick Links" tile grid (the section whose own empty-state
 * text literally says "No quick actions available"), not just the header's
 * dropdown menu. Fixed in BOTH surfaces: the dropdown now has Create Shop
 * between Add Customer and Add Product (was after Add Supplier); a matching
 * tile was added to the Quick Links grid, in the same relative position
 * (right after Add Customer, before Add Supplier — Add Product follows
 * Add Supplier there, so this still sits between the two named buttons).
 *
 * The page already had a generic "Add Warehouse"/"Add Shop" dropdown entry
 * for non-Simple-POS tenants (label chosen by wLabel(), a DIFFERENT
 * condition than Simple Mode). That entry is untouched, just no longer
 * adjacent to the Simple-Mode one (they're mutually exclusive per request,
 * not elseif-chained, since they're no longer next to each other).
 *
 *   A. STATIC  — dashboard.php lints clean.
 *   B. WIRING  — dropdown: Create Shop sits between Add Customer and Add
 *                Product; non-Simple-Mode fallback entry unchanged and still
 *                gated the opposite way. Quick Links tile: present, gated
 *                identically, positioned between Add Customer and Add
 *                Supplier. $ql_has_links accounts for the new tile so the
 *                section doesn't show "no quick actions" when it's the only
 *                one available. All three occurrences link to
 *                warehouses.php?action=add (confirmed there to auto-open
 *                the Add Warehouse/Shop modal).
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
section('2. Source wiring — dropdown');
$dash = src($root, 'app/dashboard.php');
has($dash, "if (\$pos_simple_mode && canCreate('warehouses')):", 'Simple-Mode dropdown entry requires BOTH simple mode AND warehouse-create permission');
has($dash, "if (!\$pos_simple_mode && canCreate('warehouses')):", 'non-Simple-Mode dropdown fallback gated the opposite way (mutually exclusive, not adjacent any more)');
has($dash, "<?= wLabel('Add Warehouse', 'Add Shop') ?>", 'non-Simple-Mode dropdown fallback text is unchanged from before');

$custPos = strpos($dash, "<?= t('Add Customer') ?>");
$shopPos = strpos($dash, "<?= t('Create Shop') ?>");
$prodPos = strpos($dash, "<?= t('Add Product') ?>");
($custPos !== false && $shopPos !== false && $prodPos !== false && $custPos < $shopPos && $shopPos < $prodPos)
    ? pass('Dropdown: Create Shop sits between Add Customer and Add Product')
    : fail('Dropdown ordering wrong — Create Shop is not between Add Customer and Add Product');

// ─────────────────────────────────────────────────────────────────────────
section('3. Source wiring — Quick Links tile grid');
has($dash, "\$ql_has_links = canView('pos') || canCreate('invoices') || canCreate('customers')", '$ql_has_links still starts the same way');
has($dash, "|| (\$pos_simple_mode && canCreate('warehouses'))", '$ql_has_links accounts for the new tile (section would wrongly show "no quick actions" otherwise)');
has($dash, "<div class=\"mt-2\"><?= t('Create Shop') ?></div>", 'Quick Links grid has a Create Shop tile');

$custTilePos = strpos($dash, "<div class=\"mt-2\"><?= t('Add Customer') ?></div>");
$shopTilePos = strpos($dash, "<div class=\"mt-2\"><?= t('Create Shop') ?></div>");
$supTilePos  = strpos($dash, "<div class=\"mt-2\"><?= t('Add Supplier') ?></div>");
($custTilePos !== false && $shopTilePos !== false && $supTilePos !== false && $custTilePos < $shopTilePos && $shopTilePos < $supTilePos)
    ? pass('Quick Links tile: Create Shop sits between Add Customer and Add Supplier (i.e. between Customer and Product)')
    : fail('Quick Links tile ordering wrong');

// ─────────────────────────────────────────────────────────────────────────
section('4. Both surfaces target the same destination');
$dashLines = explode("\n", $dash);
$hrefCount = 0;
foreach ($dashLines as $line) {
    if (strpos($line, "getUrl('warehouses') ?>?action=add") !== false) $hrefCount++;
}
$hrefCount === 3
    ? pass('Exactly 3 links to warehouses.php?action=add exist (dropdown x2 + tile) — no stray extra copy')
    : fail("Expected 3 occurrences of the warehouses?action=add link, found $hrefCount");

$warehousesPage = src($root, 'app/bms/stock/warehouses.php');
has($warehousesPage, "if (urlParams.get('action') === 'add') {", 'warehouses.php actually honours ?action=add (auto-opens the Add modal)');

// ─────────────────────────────────────────────────────────────────────────
section('5. Translation coverage');
require_once "$root/core/i18n.php";
loadLanguage('sw');
$sw = t('Create Shop');
($sw !== 'Create Shop' && $sw !== '')
    ? pass("'Create Shop' has a real Swahili translation ('$sw')")
    : fail("'Create Shop' falls back to raw English under sw locale");
