<?php
/**
 * tests/test_restaurant_pay_later_cli.php — restaurant pay-later (2026-11-02).
 *
 * Real code inside ONE outer transaction that is rolled back at the end (inner transactions become savepoints).
 *
 *   A. Payment split rules (paid default, partly paid, credit).
 *   B. A PAID purchase is unchanged: Dr Inventory / Cr Cash, no supplier bill.
 *   C. PARTLY PAID: one bill for the whole purchase, payable in the books, the part paid now paid against it.
 *   D. CREDIT needs a supplier; refused → nothing saved.
 *   E. Suppliers you owe + the ledger figure the dashboard reads.
 *   F. Pay later: part, too much refused, the rest → paid.
 *   G. "Something else" (groceries) partly paid → cost of food account, not stock.
 *   H. New supplier with only a name and a phone number (same name+phone → same supplier).
 *   I. The books balance; the API saves a credit purchase; a retail shop is refused.
 *
 * Exit 0 = all pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/permissions.php";
require_once "$root/core/feature_registry.php";
if (session_status() === PHP_SESSION_NONE) session_start();

$failures = 0; $passes = 0;
register_shutdown_function(function () {
    global $passes, $failures;
    static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$passes\033[0m\n";
    echo "Failures: " . ($failures === 0 ? "\033[32m0\033[0m" : "\033[31m$failures\033[0m") . "\n";
    if ($failures > 0) exit(1);
});
function ok(bool $c, string $m): void { global $passes, $failures; if ($c) { $passes++; echo "  \033[32m✅\033[0m $m\n"; } else { $failures++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function near(float $a, float $b): bool { return abs($a - $b) < 0.011; }

class SavepointPDO extends PDO
{
    private int $depth = 0;
    public function startOuter(): void { parent::beginTransaction(); $this->depth = 1; }
    public function endOuter(): void { if ($this->depth > 0) { parent::rollBack(); $this->depth = 0; } }
    public function beginTransaction(): bool { $this->depth++; $this->exec("SAVEPOINT sp{$this->depth}"); return true; }
    public function commit(): bool { $this->exec("RELEASE SAVEPOINT sp{$this->depth}"); $this->depth--; return true; }
    public function rollBack(): bool { $this->exec("ROLLBACK TO SAVEPOINT sp{$this->depth}"); $this->depth--; return true; }
    public function inTransaction(): bool { return $this->depth > 1; }
}

$pdo = new SavepointPDO('mysql:host=' . DB_SERVER . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USERNAME, DB_PASSWORD);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("SET time_zone = '+03:00'");
$GLOBALS['pdo'] = $pdo;
function val(string $sql, array $p = []) { global $pdo; $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); }
function one(string $sql, array $p = []) { global $pdo; $s = $pdo->prepare($sql); $s->execute($p); return $s->fetch(PDO::FETCH_ASSOC); }
function throws(callable $fn): ?string { try { $fn(); return null; } catch (Throwable $e) { return $e->getMessage(); } }
/** Ledger lines of one entry: [account_id => signed amount (debit +, credit −)] */
function lines(int $entryId): array {
    global $pdo; $o = [];
    $s = $pdo->prepare("SELECT account_id, type, amount FROM journal_entry_items WHERE entry_id = ?"); $s->execute([$entryId]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $o[(int)$r['account_id']] = ($o[(int)$r['account_id']] ?? 0) + ($r['type'] === 'debit' ? 1 : -1) * (float)$r['amount'];
    return $o;
}

$admin = (int)val("SELECT user_id FROM users WHERE is_active = 1 AND role_id = 1 ORDER BY user_id LIMIT 1");
$_SESSION['user_id'] = $admin; $_SESSION['username'] = 'admin'; $_SESSION['role'] = 'admin'; $_SESSION['role_id'] = 1;
$_SESSION['is_admin'] = true; $_SESSION['csrf_token'] = 'test-csrf'; $_SESSION['user_lang'] = 'en';
$GLOBALS['__bms_features'] = ['pos' => true, 'restaurant_pos' => true];

$W = (int)val("SELECT warehouse_id FROM warehouses WHERE status = 'active' ORDER BY warehouse_id LIMIT 1");
$WR = (int)val("SELECT warehouse_id FROM warehouses WHERE status = 'active' AND warehouse_id <> ? ORDER BY warehouse_id LIMIT 1", [$W]);
if (!$W || !$WR) { ok(false, 'fixture: two active shops'); exit; }

$pdo->startOuter();
try {
    $pdo->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('pos_simple_mode','1') ON DUPLICATE KEY UPDATE setting_value='1'");
    $pdo->prepare("UPDATE warehouses SET pos_mode = 'restaurant' WHERE warehouse_id = ?")->execute([$W]);
    $pdo->prepare("UPDATE warehouses SET pos_mode = 'retail' WHERE warehouse_id = ?")->execute([$WR]);
    // The pay-later file must stand on its own: the "Pay" API loads only it (a browser test caught posReceiptAccountId() missing).
    require_once "$root/core/restaurant_supplier_credit.php";
    ok(function_exists('posReceiptAccountId') && function_exists('cashBankAccounts') && function_exists('postGoodsInvoiceAccrual'), 'core/restaurant_supplier_credit.php loads everything it uses (the Pay API needs nothing else)');
    require_once "$root/core/restaurant_purchases.php";
    ok(restaurantShopActive($pdo, $W) && !restaurantShopActive($pdo, $WR), 'fixture: one restaurant shop, one retail shop');

    $sfx = substr(bin2hex(random_bytes(3)), 0, 6);
    $pdo->prepare("INSERT INTO products (product_name, unit, unit_of_measure, cost_price, purchase_price, selling_price, min_selling_price, is_service, track_inventory, warehouse_id, is_menu_item, status, created_by, stock_quantity, current_stock)
                   VALUES (?, 'kg', 'kg', 1000, 1000, 0, 0, 0, 1, ?, 0, 'active', ?, 0, 0)")->execute(["PL Rice $sfx", $W, $admin]);
    $RICE = (int)$pdo->lastInsertId();
    $INV  = (int)inventoryAccountId($pdo);
    $AP   = (int)apAccountId($pdo);
    $CASH = (int)posReceiptAccountId($pdo, 'cash');
    $FOOD = (int)restaurantIngredientsAccountId($pdo);
    ok($INV > 0 && $AP > 0 && $CASH > 0 && $FOOD > 0, 'fixture: Inventory, Accounts Payable, Cash and Cost-of-food accounts exist');
    $sup = restaurantQuickAddSupplier($pdo, "PL Mama Neema $sfx", '0712000111', $admin);
    $S = $sup['supplier_id'];
    $buy = fn(array $o) => restaurantBuyStock($pdo, $o + ['product_id' => $RICE, 'warehouse_id' => $W, 'date' => date('Y-m-d')], $admin);
    $maxJe = fn() => (int)val("SELECT COALESCE(MAX(entry_id),0) FROM journal_entries");

    // ───────────────────────────────────────────────────────────────────────
    section('A. Payment split rules');
    $p = restaurantPaymentSplit(30000, 'paid', 0);
    ok($p['payment'] === 'paid' && near($p['paid'], 30000) && near($p['owed'], 0), 'Paid: everything paid, nothing owed');
    $p = restaurantPaymentSplit(30000, '', 0);
    ok($p['payment'] === 'paid', 'no choice given → Paid (the default)');
    $p = restaurantPaymentSplit(30000, 'partial', 10000);
    ok($p['payment'] === 'partial' && near($p['paid'], 10000) && near($p['owed'], 20000), 'Partly paid 10,000 of 30,000 → owe 20,000');
    $p = restaurantPaymentSplit(30000, 'credit', 999);
    ok($p['payment'] === 'credit' && near($p['paid'], 0) && near($p['owed'], 30000), 'Credit → owe all 30,000');
    ok(throws(fn() => restaurantPaymentSplit(30000, 'partial', 0)) !== null, 'Partly paid with nothing paid now is refused');
    ok(throws(fn() => restaurantPaymentSplit(30000, 'partial', 30000)) !== null, 'Partly paid with everything paid is refused (choose Paid)');

    // ───────────────────────────────────────────────────────────────────────
    section('B. A PAID purchase is unchanged');
    $j0 = $maxJe();
    $bills0 = (int)val("SELECT COUNT(*) FROM supplier_invoices");
    $r = $buy(['quantity' => 10, 'unit_cost' => 1000, 'supplier_id' => $S]);
    ok($r['payment'] === 'paid' && empty($r['bill_id']) && near($r['owed'], 0), 'saved as Paid, with no bill');
    ok((int)val("SELECT COUNT(*) FROM supplier_invoices") === $bills0, 'no supplier bill is created for a paid purchase (even with a supplier chosen)');
    $e = (int)val("SELECT MAX(entry_id) FROM journal_entries WHERE entry_id > ?", [$j0]);
    $l = lines($e);
    ok(near($l[$INV] ?? 0, 10000) && near($l[$CASH] ?? 0, -10000) && !isset($l[$AP]), 'books: Inventory +10,000 · Cash −10,000 · nothing owed (as before)');

    // ───────────────────────────────────────────────────────────────────────
    section('C. PARTLY PAID — 20 kg at 1,500 = 30,000, paid 10,000 now');
    $stock0 = restaurantStockOnHand($pdo, $RICE);
    $j0 = $maxJe();
    $r = $buy(['quantity' => 20, 'unit_cost' => 1500, 'supplier_id' => $S, 'payment' => 'partial', 'amount_paid' => 10000, 'due_date' => date('Y-m-d', strtotime('+7 days'))]);
    $B1 = (int)$r['bill_id'];
    ok($r['payment'] === 'partial' && near($r['paid_now'], 10000) && near($r['owed'], 20000) && $B1 > 0, 'saved: paid now 10,000, owed 20,000, a bill made');
    ok(near(restaurantStockOnHand($pdo, $RICE), $stock0 + 20), 'stock went up by 20 (the goods are received in full)');
    $b = one("SELECT * FROM supplier_invoices WHERE id = ?", [$B1]);
    ok($b && near($b['amount'], 30000) && near($b['amount_paid'], 10000) && $b['status'] === 'partial' && (int)$b['supplier_id'] === $S && (int)$b['warehouse_id'] === $W,
       'the bill: whole 30,000, 10,000 paid, status partial, the supplier and the shop');
    ok($b['invoice_ref'] === $r['reference'] && strpos((string)$b['notes'], '[Restaurant]') === 0 && $b['due_date'] === date('Y-m-d', strtotime('+7 days')), 'the bill carries the purchase reference, the restaurant mark and the pay-by date');
    $acc = (int)val("SELECT entry_id FROM journal_entries WHERE entity_type = 'supplier_invoice' AND entity_id = ? AND status = 'posted'", [$B1]);
    $l = lines($acc);
    ok($acc > 0 && near($l[$INV] ?? 0, 30000) && near($l[$AP] ?? 0, -30000), 'books: the bill posts Inventory +30,000 / Payable 30,000 (the same posting as a Smart BMS bill)');
    $pay = one("SELECT entry_id FROM journal_entries WHERE parent_entity_type = 'supplier_invoice' AND parent_entity_id = ? AND status = 'posted'", [$B1]);
    $l = $pay ? lines((int)$pay['entry_id']) : [];
    ok($pay && near($l[$AP] ?? 0, 10000) && near($l[$CASH] ?? 0, -10000), 'books: the 10,000 paid now is Payable −10,000 / Cash −10,000, linked to its bill');
    ok((int)val("SELECT COUNT(*) FROM supplier_invoice_payments WHERE invoice_id = ?", [$B1]) === 1, 'the payment is in the bill\'s payment list');
    $net = 0.0; foreach ([$acc, (int)$pay['entry_id']] as $x) { $ll = lines($x); $net += ($ll[$AP] ?? 0); }
    ok(near($net, -20000), 'net in the books: 20,000 owed to the supplier');

    // ───────────────────────────────────────────────────────────────────────
    section('D. CREDIT needs a supplier');
    $stock1 = restaurantStockOnHand($pdo, $RICE);
    $billsBefore = (int)val("SELECT COUNT(*) FROM supplier_invoices");
    $m = throws(fn() => $buy(['quantity' => 5, 'unit_cost' => 1000, 'payment' => 'credit']));
    ok($m !== null && stripos($m, 'supplier') !== false, 'credit with no supplier is refused: "' . $m . '"');
    ok(near(restaurantStockOnHand($pdo, $RICE), $stock1) && (int)val("SELECT COUNT(*) FROM supplier_invoices") === $billsBefore, 'nothing was saved (no stock, no bill)');
    $r2 = $buy(['quantity' => 4, 'unit_cost' => 1000, 'supplier_id' => $S, 'payment' => 'credit']);
    $B2 = (int)$r2['bill_id'];
    ok($r2['payment'] === 'credit' && near($r2['owed'], 4000) && val("SELECT status FROM supplier_invoices WHERE id = ?", [$B2]) === 'approved', 'credit 4,000: whole amount owed, bill approved (nothing paid)');
    ok(!val("SELECT 1 FROM journal_entries WHERE parent_entity_type = 'supplier_invoice' AND parent_entity_id = ?", [$B2]), 'no cash leaves for a credit purchase');

    // ───────────────────────────────────────────────────────────────────────
    section('E. Suppliers you owe, and the dashboard figure from the books');
    $debts = restaurantSupplierDebts($pdo, [$W]);
    $mine = array_values(array_filter($debts, fn($d) => in_array((int)$d['id'], [$B1, $B2], true)));
    ok(count($mine) === 2 && near(array_sum(array_column($mine, 'owed')), 24000), 'the list shows both bills: 20,000 + 4,000 = 24,000 owed');
    ok($mine[0]['supplier_name'] === "PL Mama Neema $sfx" && $mine[0]['phone'] === '0712000111', 'each row shows the supplier name and phone');
    $o = restaurantOwedToSuppliers($pdo, [$W]);
    $docOwed = (float)val("SELECT COALESCE(SUM(amount - amount_paid),0) FROM supplier_invoices WHERE status IN ('approved','partial') AND invoice_type='supplier' AND warehouse_id = ?", [$W]);
    ok(near($o['owed'], $docOwed), 'the ledger figure (' . number_format($o['owed']) . ') equals what the bills still owe (' . number_format($docOwed) . ')');
    ok($o['suppliers'] >= 1, 'counts the suppliers owed');

    // ───────────────────────────────────────────────────────────────────────
    section('F. Pay later');
    $owedBefore = restaurantOwedToSuppliers($pdo, [$W])['owed'];
    $p1 = restaurantPaySupplierBill($pdo, $B1, 5000, date('Y-m-d'), 0, $admin);
    ok($p1['status'] === 'partial' && near($p1['remaining'], 15000), 'pay 5,000 → still owed 15,000, status partial');
    ok(near(restaurantOwedToSuppliers($pdo, [$W])['owed'], $owedBefore - 5000), 'the dashboard figure drops by 5,000');
    $m = throws(fn() => restaurantPaySupplierBill($pdo, $B1, 15001, date('Y-m-d'), 0, $admin));
    ok($m !== null, 'paying more than is owed is refused: "' . $m . '"');
    ok(throws(fn() => restaurantPaySupplierBill($pdo, $B1, 0, date('Y-m-d'), 0, $admin)) !== null, 'paying zero is refused');
    ok(throws(fn() => restaurantPaySupplierBill($pdo, $B1, 100, date('Y-m-d', strtotime('+2 days')), 0, $admin)) !== null, 'a payment dated in the future is refused');
    $p2 = restaurantPaySupplierBill($pdo, $B1, 15000, date('Y-m-d'), 0, $admin);
    ok($p2['status'] === 'paid' && near($p2['remaining'], 0), 'pay the remaining 15,000 → paid in full');
    $b = one("SELECT status, amount_paid, payment_date FROM supplier_invoices WHERE id = ?", [$B1]);
    ok($b['status'] === 'paid' && near($b['amount_paid'], 30000) && $b['payment_date'] === date('Y-m-d'), 'the bill is marked paid (30,000 paid)');
    ok((int)val("SELECT COUNT(*) FROM supplier_invoice_payments WHERE invoice_id = ?", [$B1]) === 3, 'three payments recorded on it (10,000 + 5,000 + 15,000)');
    ok(!in_array($B1, array_map('intval', array_column(restaurantSupplierDebts($pdo, [$W]), 'id')), true), 'a paid bill leaves the "Suppliers you owe" list');
    ok(throws(fn() => restaurantPaySupplierBill($pdo, $B1, 1, date('Y-m-d'), 0, $admin)) !== null, 'a paid bill cannot be paid again');
    $apNet = (float)val("SELECT COALESCE(SUM(CASE WHEN jei.type='credit' THEN jei.amount ELSE -jei.amount END),0) FROM journal_entry_items jei JOIN journal_entries je ON je.entry_id = jei.entry_id AND je.status='posted'
                          WHERE jei.account_id = ? AND ((je.entity_type='supplier_invoice' AND je.entity_id = ?) OR (je.parent_entity_type='supplier_invoice' AND je.parent_entity_id = ?))", [$AP, $B1, $B1]);
    ok(near($apNet, 0), 'in the books the bill\'s payable is back to 0');

    // ───────────────────────────────────────────────────────────────────────
    section('G. "Something else" (groceries) partly paid');
    $g = restaurantBuyGroceryOnCredit($pdo, ['name' => "Nyanya $sfx", 'amount' => 8000, 'warehouse_id' => $W, 'date' => date('Y-m-d'), 'supplier_id' => $S, 'payment' => 'partial', 'amount_paid' => 3000], $admin);
    ok(near($g['owed'], 5000) && $g['payment'] === 'partial', 'tomatoes 8,000, paid 3,000 → owe 5,000');
    $acc = (int)val("SELECT entry_id FROM journal_entries WHERE entity_type = 'supplier_invoice' AND entity_id = ?", [$g['bill_id']]);
    $l = lines($acc);
    ok(near($l[$FOOD] ?? 0, 8000) && near($l[$AP] ?? 0, -8000) && !isset($l[$INV]), 'books: Cost of food +8,000 / Payable 8,000 — not stock');
    ok(strpos((string)val("SELECT notes FROM supplier_invoices WHERE id = ?", [$g['bill_id']]), '[Restaurant] Ingredients: Nyanya') === 0, 'the bill reads "Ingredients: Nyanya…" so Recent purchases can list it');
    ok(throws(fn() => restaurantBuyGroceryOnCredit($pdo, ['name' => 'x', 'amount' => 100, 'warehouse_id' => $W, 'supplier_id' => $S, 'payment' => 'paid'], $admin)) !== null, 'a fully paid grocery is not made a bill (it stays an ordinary expense)');
    ok(throws(fn() => restaurantBuyGroceryOnCredit($pdo, ['name' => 'x', 'amount' => 100, 'warehouse_id' => $W, 'payment' => 'credit'], $admin)) !== null, 'grocery on credit needs a supplier');

    // ───────────────────────────────────────────────────────────────────────
    section('H. New supplier — only a name and a phone number');
    ok(!$sup['existing'] && $S > 0, 'a new supplier is created');
    $row = one("SELECT supplier_name, phone, status, supplier_code FROM suppliers WHERE supplier_id = ?", [$S]);
    ok($row['supplier_name'] === "PL Mama Neema $sfx" && $row['phone'] === '0712000111' && $row['status'] === 'active' && $row['supplier_code'] !== '', 'saved with name, phone, a supplier code and active');
    $again = restaurantQuickAddSupplier($pdo, "pl mama neema $sfx", '0712000111', $admin);
    ok($again['existing'] && $again['supplier_id'] === $S, 'the same name and phone again → the same supplier (no duplicate)');
    $noPhone = restaurantQuickAddSupplier($pdo, "PL Juma $sfx", '', $admin);
    ok($noPhone['supplier_id'] > 0 && val("SELECT phone FROM suppliers WHERE supplier_id = ?", [$noPhone['supplier_id']]) === null, 'the phone is optional');
    ok(throws(fn() => restaurantQuickAddSupplier($pdo, '   ', '0712', $admin)) !== null, 'a supplier needs a name');

    // ───────────────────────────────────────────────────────────────────────
    section('I. Books balance · the API · a retail shop');
    $unbalanced = (int)val("SELECT COUNT(*) FROM (SELECT je.entry_id FROM journal_entries je JOIN journal_entry_items jei ON jei.entry_id = je.entry_id
                             WHERE je.entry_id > ? GROUP BY je.entry_id HAVING ABS(SUM(CASE WHEN jei.type='debit' THEN jei.amount ELSE -jei.amount END)) > 0.01) x", [$j0 - 1000 > 0 ? $j0 - 1000 : 0]);
    ok($unbalanced === 0, 'every entry made here balances (debits = credits)');

    $_SERVER['REQUEST_METHOD'] = 'POST'; $_GET = [];
    $_POST = ['_csrf' => 'test-csrf', 'warehouse_id' => $W, 'product_id' => $RICE, 'quantity' => 2, 'unit_cost' => 1200, 'date' => date('Y-m-d'),
              'supplier_id' => $S, 'payment' => 'credit', 'due_date' => ''];
    ob_start();
    try { (function () use ($root) { global $pdo; include "$root/api/restaurant/buy_stock.php"; })(); } catch (Throwable $e) { echo json_encode(['success' => false, 'message' => 'EXC: ' . $e->getMessage()]); }
    $api = json_decode((string)ob_get_clean(), true) ?: [];
    ok(!empty($api['success']) && near((float)($api['owed'] ?? 0), 2400) && !empty($api['bill_id']), 'the Purchases API saves a credit purchase: owed 2,400' . (!empty($api['success']) ? '' : ' — ' . ($api['message'] ?? '')));

    $m = throws(fn() => restaurantBuyStock($pdo, ['product_id' => $RICE, 'warehouse_id' => $WR, 'quantity' => 1, 'unit_cost' => 100, 'supplier_id' => $S, 'payment' => 'credit'], $admin));
    ok($m !== null, 'a retail shop cannot use the restaurant purchase (retail Receive Stock is separate and unchanged)');
} finally {
    $pdo->endOuter();
}
ok(!val("SELECT 1 FROM suppliers WHERE supplier_name LIKE ?", ["PL Mama Neema $sfx"]), 'cleanup: everything was rolled back');
