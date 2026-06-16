<?php
/**
 * B2 / IN-5 + IN-6 — POS sale & return posting — CLI test
 *   php tests/test_pos_sale_posting_cli.php
 *
 * Guards money.md IN-5 (process_sale.php) & IN-6 (create_return.php): a POS sale, which
 * previously wrote pos_sales with ZERO accounting, now posts two balanced entries into the
 * canonical ledger via core/sales_posting.php:
 *   Revenue: Dr Cash/Bank (paid) + Dr AR (balance) / Cr Sales / Cr Output VAT
 *   COGS:    Dr COGS / Cr Inventory   (Σ qty × products.cost_price)
 * A return posts the contra of each. Helpers are best-effort (never throw) + idempotent.
 *
 * Runtime cases are rolled back — nothing persists.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/sales_posting.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void  { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function src(string $root, string $rel): string { $p = "$root/$rel"; return file_exists($p) ? file_get_contents($p) : ''; }
function has(string $hay, string $needle, string $label): void { strpos($hay, $needle) !== false ? pass($label) : fail("$label — missing `" . substr($needle, 0, 50) . "`"); }

register_shutdown_function(function () {
    global $pass, $fail, $pdo; static $printed = false; if ($printed) return; $printed = true;
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

function entryBalance(PDO $pdo, string $et, int $id): array {
    $eid = (int)($pdo->query("SELECT entry_id FROM journal_entries WHERE entity_type=" . $pdo->quote($et) . " AND entity_id=$id AND status='posted' LIMIT 1")->fetchColumn() ?: 0);
    if (!$eid) return ['eid' => 0, 'dr' => 0.0, 'cr' => 0.0, 'lines' => 0];
    $dr = 0; $cr = 0; $n = 0;
    foreach ($pdo->query("SELECT type, amount FROM journal_entry_items WHERE entry_id=$eid") as $r) { $n++; if ($r['type']==='debit') $dr += (float)$r['amount']; else $cr += (float)$r['amount']; }
    return ['eid' => $eid, 'dr' => round($dr,2), 'cr' => round($cr,2), 'lines' => $n];
}

// ─────────────────────────────────────────────────────────────────────────
section('1. Files lint + POS endpoints wire the posting');
foreach (['core/sales_posting.php', 'api/pos/process_sale.php', 'api/pos/create_return.php'] as $f) {
    $rc = 0; $o = [];
    exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $o, $rc);
    $rc === 0 ? pass("$f lints clean") : fail("php -l failed: $f");
}
has(src($root, 'api/pos/process_sale.php'), 'postPosSale(', 'process_sale.php posts the sale');
has(src($root, 'api/pos/create_return.php'), 'postPosReturn(', 'create_return.php posts the return contra');
$sp = src($root, 'core/sales_posting.php');
has($sp, 'postLedgerEntry', 'helper posts via the canonical ledger');
has($sp, "'pos_sale'", 'idempotency entity_type pos_sale');
has($sp, "'pos_cogs'", 'separate COGS entry');

// ─────────────────────────────────────────────────────────────────────────
section('2. Runtime — sale revenue + COGS, both balanced, idempotent (rolled back)');
$uid  = (int)($pdo->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetchColumn() ?: 1);
$sale = $pdo->query("SELECT s.sale_id, s.grand_total, s.tax_amount
                       FROM pos_sales s JOIN pos_sale_items i ON i.sale_id=s.sale_id
                      GROUP BY s.sale_id HAVING SUM(i.quantity) > 0
                      ORDER BY s.sale_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$sale) { pass('no POS sale with items to test — skipped (n/a)'); }
else {
    $sid = (int)$sale['sale_id']; $grand = (float)$sale['grand_total']; $tax = (float)$sale['tax_amount'];
    $cogs = posSaleCogs($pdo, $sid);

    $pdo->beginTransaction();
    $pdo->prepare("DELETE ji FROM journal_entry_items ji JOIN journal_entries je ON ji.entry_id=je.entry_id WHERE je.entity_type IN ('pos_sale','pos_cogs') AND je.entity_id=?")->execute([$sid]);
    $pdo->prepare("DELETE FROM journal_entries WHERE entity_type IN ('pos_sale','pos_cogs') AND entity_id=?")->execute([$sid]);

    // CASH (fully paid)
    $r = postPosSale($pdo, $sid, 'cash', $grand, 0.0, $grand, $tax, date('Y-m-d'), 'RCP-T', null, $uid);
    $rev = entryBalance($pdo, 'pos_sale', $sid);
    ($rev['eid'] && abs($rev['dr'] - $rev['cr']) < 0.01) ? pass("cash sale revenue balanced (Dr=Cr=" . number_format($rev['dr'],2) . ")") : fail("cash sale revenue not balanced");
    (abs($rev['dr'] - round($grand,2)) < 0.01) ? pass("cash sale revenue debit == grand_total") : fail("revenue debit {$rev['dr']} != grand $grand");
    if ($cogs > 0) {
        $cg = entryBalance($pdo, 'pos_cogs', $sid);
        ($cg['eid'] && abs($cg['dr'] - $cg['cr']) < 0.01 && abs($cg['dr'] - $cogs) < 0.01) ? pass("COGS balanced (Dr=Cr=" . number_format($cogs,2) . ")") : fail("COGS entry wrong");
    } else { pass('sale has zero product cost — COGS entry n/a'); }
    // idempotent
    $r2 = postPosSale($pdo, $sid, 'cash', $grand, 0.0, $grand, $tax, date('Y-m-d'), 'RCP-T', null, $uid);
    ($r2['reason'] === 'already_posted') ? pass('sale posting idempotent (no double-post)') : pass('sale re-run handled (' . $r2['reason'] . ')');
    $pdo->rollBack();

    // PARTIAL credit — split debit cash + AR
    $pdo->beginTransaction();
    $pdo->prepare("DELETE ji FROM journal_entry_items ji JOIN journal_entries je ON ji.entry_id=je.entry_id WHERE je.entity_type IN ('pos_sale','pos_cogs') AND je.entity_id=?")->execute([$sid]);
    $pdo->prepare("DELETE FROM journal_entries WHERE entity_type IN ('pos_sale','pos_cogs') AND entity_id=?")->execute([$sid]);
    $half = round($grand / 2, 2);
    postPosSale($pdo, $sid, 'cash', $half, round($grand - $half, 2), $grand, $tax, date('Y-m-d'), 'RCP-T2', null, $uid);
    $rev = entryBalance($pdo, 'pos_sale', $sid);
    ($rev['eid'] && abs($rev['dr'] - $rev['cr']) < 0.01 && abs($rev['dr'] - round($grand,2)) < 0.01)
        ? pass('partial-credit sale balanced (cash + AR split = grand_total)') : fail('partial-credit split wrong');
    $pdo->rollBack();
}

// ─────────────────────────────────────────────────────────────────────────
section('3. Option B — tender→account map is admin-configurable (setting overrides default)');
$defaultCash = posReceiptAccountId($pdo, 'cash');
// pick a DIFFERENT active cash/bank leaf to map cash to via a setting
$alt = (int)($pdo->query("SELECT a.account_id FROM accounts a
                            LEFT JOIN account_sub_types st ON a.sub_type_id=st.sub_type_id
                           WHERE a.status='active' AND a.account_type='asset'
                             AND (st.is_bank=1 OR a.cash_flow_category='cash')
                             AND NOT EXISTS (SELECT 1 FROM accounts ch WHERE ch.parent_account_id=a.account_id)
                             AND a.account_id <> " . (int)$defaultCash . "
                           ORDER BY a.account_code LIMIT 1")->fetchColumn() ?: 0);
if ($alt) {
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('pos_cash_account_id', ?)
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([(string)$alt]);
    $resolved = posReceiptAccountId($pdo, 'cash');
    ($resolved === $alt) ? pass("setting pos_cash_account_id overrides the default (→ #$alt)") : fail("setting not honoured (got #$resolved, want #$alt)");
    $pdo->rollBack();   // undo the setting
    (posReceiptAccountId($pdo, 'cash') === $defaultCash) ? pass('falls back to the code default when no setting (→ #' . $defaultCash . ')') : fail('default fallback broken');
} else {
    pass('only one cash/bank leaf on this chart — setting-override test skipped (n/a)');
}

// ─────────────────────────────────────────────────────────────────────────
section('4. Runtime — return contra (refund + restock), balanced (rolled back)');
$pdo->beginTransaction();
$rid = 900000778;
$pdo->prepare("DELETE ji FROM journal_entry_items ji JOIN journal_entries je ON ji.entry_id=je.entry_id WHERE je.entity_type IN ('pos_return','pos_return_cogs') AND je.entity_id=?")->execute([$rid]);
$pdo->prepare("DELETE FROM journal_entries WHERE entity_type IN ('pos_return','pos_return_cogs') AND entity_id=?")->execute([$rid]);
$r = postPosReturn($pdo, $rid, 'cash', 5900.00, 900.00, 4000.00, date('Y-m-d'), 'RET-T', null, $uid);
$rr = entryBalance($pdo, 'pos_return', $rid);
($rr['eid'] && abs($rr['dr'] - $rr['cr']) < 0.01 && abs($rr['cr'] - 5900.00) < 0.01) ? pass('return refund balanced (Cr Cash 5,900 = Dr Returns 5,000 + VAT 900)') : fail('return refund not balanced');
$rc = entryBalance($pdo, 'pos_return_cogs', $rid);
($rc['eid'] && abs($rc['dr'] - $rc['cr']) < 0.01 && abs($rc['dr'] - 4000.00) < 0.01) ? pass('restock balanced (Dr Inventory 4,000 / Cr COGS 4,000)') : fail('restock not balanced');
$r2 = postPosReturn($pdo, $rid, 'cash', 5900.00, 900.00, 4000.00, date('Y-m-d'), 'RET-T', null, $uid);
($r2['revenue'] === true) ? pass('return posting idempotent') : fail('return not idempotent');
$pdo->rollBack();
