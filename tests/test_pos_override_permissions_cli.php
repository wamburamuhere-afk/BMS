<?php
/**
 * Phase 16 (pos_upgrade_plan.md §8) — POS price/discount-override permission
 * split — CLI test
 *   php tests/test_pos_override_permissions_cli.php
 *
 * Verifies:
 *   1. Files lint clean.
 *   2. The two new page_keys (pos_price_override, pos_discount_override) are
 *      seeded in the live `permissions` table (migration already applied).
 *   3. Wiring: process_sale.php resolves price/discount through
 *      core/pos_override_guard.php (not inline), pos.php gates the discount
 *      button server-side, pos_scripts_new.php exposes the price-edit
 *      affordance behind POS_CAN_PRICE_OVERRIDE.
 *   4. Runtime — resolvePosLineBasePrice():
 *        a. No manual override attempted → server always uses the DB
 *           selling_price, ignoring whatever client['price'] says.
 *        b. Manual override attempted + permitted → client price is honoured.
 *        c. Manual override attempted + NOT permitted → falls back to DB
 *           price for BOTH the base price and the (would-be) requested price.
 *   5. Runtime — assertPosLineDiscountPermitted():
 *        a. Real discount + no permission → throws.
 *        b. Real discount + permission → no throw.
 *        c. Negligible/zero discount → never throws regardless of permission.
 *
 * No DB writes — pure function calls + one read-only permissions check.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/pos_override_guard.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void  { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function src(string $root, string $rel): string { $p = "$root/$rel"; return file_exists($p) ? file_get_contents($p) : ''; }
function has(string $hay, string $needle, string $label): void { strpos($hay, $needle) !== false ? pass($label) : fail("$label — missing `" . substr($needle, 0, 60) . "`"); }

register_shutdown_function(function () {
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

// ─────────────────────────────────────────────────────────────────────────
section('1. Files lint clean');
foreach (['core/pos_override_guard.php', 'api/pos/process_sale.php', 'app/bms/pos/pos.php', 'app/bms/pos/pos_scripts_new.php', 'migrations/tenant/2026_09_08_pos_override_permissions.php'] as $f) {
    $rc = 0; $o = [];
    exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $o, $rc);
    $rc === 0 ? pass("$f lints clean") : fail("php -l failed: $f: " . implode(' ', $o));
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Permissions seeded in the live database');
foreach (['pos_price_override', 'pos_discount_override'] as $key) {
    $stmt = $pdo->prepare("SELECT page_name FROM permissions WHERE page_key = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $row ? pass("permission '$key' exists (\"{$row['page_name']}\")") : fail("permission '$key' NOT found — run migrations/tenant/2026_09_08_pos_override_permissions.php");
}

// ─────────────────────────────────────────────────────────────────────────
section('3. Wiring — the real security boundary lives in process_sale.php, not just the UI');
$sale = src($root, 'api/pos/process_sale.php');
has($sale, "canEdit('pos_price_override')", 'process_sale.php checks pos_price_override');
has($sale, "canEdit('pos_discount_override')", 'process_sale.php checks pos_discount_override');
has($sale, 'resolvePosLineBasePrice(', 'process_sale.php resolves base price via the extracted guard');
has($sale, 'assertPosLineDiscountPermitted(', 'process_sale.php enforces discount permission via the extracted guard');

$posPage = src($root, 'app/bms/pos/pos.php');
has($posPage, "canEdit('pos_discount_override')", 'pos.php gates the Apply Discount button server-side');

$scripts = src($root, 'app/bms/pos/pos_scripts_new.php');
has($scripts, "canEdit('pos_price_override')", 'pos_scripts_new.php reads pos_price_override to build POS_CAN_PRICE_OVERRIDE');
has($scripts, 'function editLinePrice(', 'the manual price-edit affordance exists');
has($scripts, 'manual_price_override', 'a client-side override sets manual_price_override, matched by the server guard');

// ─────────────────────────────────────────────────────────────────────────
section('4. Runtime — resolvePosLineBasePrice()');
$dbProduct = ['selling_price' => 1000.00];

// a. No override attempted — client price ignored entirely, even if hostile.
$r = resolvePosLineBasePrice(['price' => 1.00], $dbProduct, true);
(abs($r['original_price'] - 1000.00) < 0.001 && $r['requested_price'] === null)
    ? pass('no manual_price_override flag → always uses DB selling_price (1000), ignores forged client price (1)')
    : fail('unauthorized/absent override did not resolve to DB price: ' . json_encode($r));

// a2. Same, but WITH manual_price_override missing and permission true — still DB price (permission alone isn't enough, the client must actually be using the affordance).
$r = resolvePosLineBasePrice(['price' => 1.00], $dbProduct, false);
(abs($r['original_price'] - 1000.00) < 0.001)
    ? pass('no override attempted + no permission → still DB price (nothing to deny, nothing forged through)')
    : fail('unexpected result with no override + no permission: ' . json_encode($r));

// b. Override attempted + permitted → client price honoured.
$r = resolvePosLineBasePrice(['price' => 750.00, 'manual_price_override' => true], $dbProduct, true);
(abs($r['original_price'] - 750.00) < 0.001 && $r['requested_price'] === null)
    ? pass('manual override + pos_price_override permitted → client price (750) honoured')
    : fail('permitted override not honoured: ' . json_encode($r));

// c. Override attempted, NOT permitted → forced back to DB price for both original AND requested.
$r = resolvePosLineBasePrice(['price' => 1.00, 'manual_price_override' => true], $dbProduct, false);
(abs($r['original_price'] - 1000.00) < 0.001 && abs($r['requested_price'] - 1000.00) < 0.001)
    ? pass('manual override attempted WITHOUT permission → both original_price and requested_price forced to DB price (1000), forged low price (1) rejected')
    : fail('unauthorized override was not fully denied: ' . json_encode($r));

// ─────────────────────────────────────────────────────────────────────────
section('5. Runtime — assertPosLineDiscountPermitted()');
$threw = false;
try { assertPosLineDiscountPermitted(500.00, false, 'Test Product'); } catch (Exception $e) { $threw = true; $msg = $e->getMessage(); }
$threw ? pass('real discount (500) + no pos_discount_override → throws (' . ($msg ?? '') . ')') : fail('unauthorized discount did NOT throw');

$threw = false;
try { assertPosLineDiscountPermitted(500.00, true, 'Test Product'); } catch (Exception $e) { $threw = true; }
!$threw ? pass('real discount (500) + pos_discount_override → no throw') : fail('permitted discount incorrectly threw');

$threw = false;
try { assertPosLineDiscountPermitted(0.001, false, 'Test Product'); } catch (Exception $e) { $threw = true; }
!$threw ? pass('negligible discount (0.001, float rounding noise) + no permission → no throw (epsilon respected)') : fail('epsilon not respected — false positive on rounding noise');

$threw = false;
try { assertPosLineDiscountPermitted(0.0, false, 'Test Product'); } catch (Exception $e) { $threw = true; }
!$threw ? pass('zero discount + no permission → no throw') : fail('zero discount incorrectly blocked');
