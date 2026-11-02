<?php
/**
 * core/restaurant_supplier_credit.php — buy on credit and pay suppliers later (restaurant pay-later, 2026-11-02).
 *
 * A restaurant purchase defaults to PAID (unchanged: Dr Inventory / Cr cash). It can instead be
 * PARTLY PAID or on CREDIT. Then the unpaid part is a debt to the supplier, recorded the same way the full
 * BMS records a supplier bill — so there is ONE payable, never a second system:
 *
 *   1. a supplier_invoices row (invoice_type 'supplier', status 'approved' → 'partial' → 'paid');
 *   2. its payable through postGoodsInvoiceAccrual(): Dr Inventory (or the cost-of-food account for groceries)
 *      / Cr Accounts Payable — the very posting a Bill makes when it is approved;
 *   3. the part paid now (and every later payment) through restaurantPaySupplierBill(), which mirrors
 *      api/received_invoices.php "record_payment": Dr Accounts Payable / Cr the cash account, a
 *      supplier_invoice_payments row, amount_paid + status on the bill, the ledger entry linked to its Bill.
 *
 * Net effect for 30,000 bought with 10,000 paid now: Dr Inventory 30,000 · Cr Cash 10,000 · Cr Payable 20,000.
 * A restaurant bill is marked by notes starting "[Restaurant]". The full BMS sees and can pay it like any Bill.
 * Suppliers can be added with just a name and a phone number (like a credit customer at the retail till).
 */
require_once __DIR__ . '/restaurant_scope.php';
require_once __DIR__ . '/gl_accounts.php';
require_once __DIR__ . '/payment_source.php';
require_once __DIR__ . '/code_generator.php';
require_once __DIR__ . '/purchase_posting.php';
require_once __DIR__ . '/money_guard.php';
require_once __DIR__ . '/bank_register.php';
require_once __DIR__ . '/pos_nav.php';
require_once __DIR__ . '/sales_posting.php';    // posReceiptAccountId() — the Smart POS cash account

if (!defined('RESTAURANT_BILL_MARK')) define('RESTAURANT_BILL_MARK', '[Restaurant] ');

if (!function_exists('restaurantCanBuy')) {
    /** The permission the Purchases page already uses (same as the retail Receive Stock). */
    function restaurantCanBuy(): bool
    {
        return hasPermission('adjust_stock') || hasPermission('pos_restock') || isAdmin();
    }
}

if (!function_exists('restaurantPaymentSplit')) {
    /**
     * Work out what is paid now and what is owed.
     * @param string $payment 'paid' (default) | 'partial' | 'credit'
     * @return array{payment:string,paid:float,owed:float}
     */
    function restaurantPaymentSplit(float $total, string $payment, $amountPaid): array
    {
        $payment = in_array($payment, ['paid', 'partial', 'credit'], true) ? $payment : 'paid';
        $total = round($total, 2);
        if ($payment === 'paid' || $total <= 0) return ['payment' => 'paid', 'paid' => $total, 'owed' => 0.0];
        if ($payment === 'credit') return ['payment' => 'credit', 'paid' => 0.0, 'owed' => $total];
        $paid = round((float)$amountPaid, 2);
        if ($paid <= 0) throw new Exception(t('Enter how much you paid now.'));
        if ($paid >= $total) throw new Exception(t('The amount paid now must be less than the total. If you paid it all, choose Paid.'));
        return ['payment' => 'partial', 'paid' => $paid, 'owed' => round($total - $paid, 2)];
    }
}

if (!function_exists('restaurantCheckSupplier')) {
    /** An active supplier, or an exception. */
    function restaurantCheckSupplier(PDO $pdo, int $supplierId): void
    {
        $sc = $pdo->prepare("SELECT supplier_id FROM suppliers WHERE supplier_id = ? AND status = 'active'");
        $sc->execute([$supplierId]);
        if (!$sc->fetchColumn()) throw new Exception(t('Selected supplier was not found.'));
    }
}

if (!function_exists('restaurantQuickAddSupplier')) {
    /**
     * Add a supplier with only a name and a phone number. The same name + phone already there → that supplier.
     * @return array{supplier_id:int,supplier_name:string,existing:bool}
     */
    function restaurantQuickAddSupplier(PDO $pdo, string $name, string $phone, int $userId): array
    {
        $name  = trim(preg_replace('/\s+/', ' ', $name));
        $phone = trim($phone);
        if ($name === '') throw new Exception(t("Please enter the supplier's name."));
        if (mb_strlen($name) > 255) throw new Exception(t('The name is too long.'));
        if (mb_strlen($phone) > 50) throw new Exception(t('The phone number is too long.'));
        $f = $pdo->prepare("SELECT supplier_id, supplier_name FROM suppliers
                             WHERE status = 'active' AND LOWER(supplier_name) = LOWER(?) AND COALESCE(phone, '') = ? LIMIT 1");
        $f->execute([$name, $phone]);
        if ($row = $f->fetch(PDO::FETCH_ASSOC)) return ['supplier_id' => (int)$row['supplier_id'], 'supplier_name' => $row['supplier_name'], 'existing' => true];

        $code = nextCode($pdo, 'SUP');
        $pdo->prepare("INSERT INTO suppliers (supplier_code, supplier_name, phone, status, created_at, created_by)
                       VALUES (?, ?, ?, 'active', NOW(), ?)")
            ->execute([$code, $name, $phone !== '' ? $phone : null, $userId]);
        $id = (int)$pdo->lastInsertId();
        logActivity($pdo, $userId, "Supplier $name ($code) added from restaurant Purchases");
        return ['supplier_id' => $id, 'supplier_name' => $name, 'existing' => false];
    }
}

if (!function_exists('restaurantPaySupplierBill')) {
    /**
     * Pay part or all of what is owed on a supplier bill (mirrors api/received_invoices.php "record_payment", no WHT).
     * Runs inside the caller's transaction when one is open; otherwise opens its own.
     * @return array{invoice_id:int,paid:float,amount_paid:float,remaining:float,status:string,transaction_id:int}
     */
    function restaurantPaySupplierBill(PDO $pdo, int $invoiceId, float $amount, string $date, int $accountId, int $userId): array
    {
        $amount = round($amount, 2);
        if ($amount <= 0) throw new Exception(t('Payment amount must be greater than zero.'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) throw new Exception(t('Enter a valid date.'));
        if ($date > date('Y-m-d')) throw new Exception(t('The date cannot be in the future.'));
        if ($accountId <= 0 && posSimpleModeEnabled()) $accountId = (int)(posReceiptAccountId($pdo, 'cash') ?? 0);
        if ($accountId <= 0) throw new Exception(t('Select where this was paid from.'));
        $valid = false;
        foreach (cashBankAccounts($pdo) as $a) { if ((int)$a['account_id'] === $accountId) { $valid = true; break; } }
        if (!$valid) throw new Exception(t('Invalid Paid-From account.'));

        $own = !$pdo->inTransaction();
        if ($own) $pdo->beginTransaction();
        try {
            $st = $pdo->prepare("SELECT id, status, invoice_ref, amount, amount_paid, project_id FROM supplier_invoices WHERE id = ? AND status != 'deleted' FOR UPDATE");
            $st->execute([$invoiceId]);
            $inv = $st->fetch(PDO::FETCH_ASSOC);
            if (!$inv) throw new Exception(t('Bill not found.'));
            if (!in_array($inv['status'], ['approved', 'partial'], true)) throw new Exception(t('This bill is already paid.'));
            $total = (float)$inv['amount'];
            $already = (float)$inv['amount_paid'];
            $remaining = round($total - $already, 2);
            if ($amount > $remaining + 0.005) {
                throw new Exception(sprintf(t('The payment (%1$s) is more than what is owed (%2$s).'), number_format($amount, 2), number_format($remaining, 2)));
            }
            $newPaid = round($already + $amount, 2);
            $newStatus = ($newPaid >= $total - 0.005) ? 'paid' : 'partial';

            // Dr Accounts Payable / Cr the cash account — fails loudly (throws) so nothing is half-saved.
            $txn = postOutflowOrFail($pdo, 'received_invoice_payment', $accountId, defaultPayableAccountId($pdo), $amount, $date,
                $inv['invoice_ref'], "Supplier bill {$inv['invoice_ref']} — payment" . ($newStatus === 'partial' ? ' (partial)' : ''),
                $inv['project_id'] ? (int)$inv['project_id'] : null);
            $pdo->prepare("UPDATE journal_entries SET parent_entity_type = 'supplier_invoice', parent_entity_id = ?
                            WHERE entity_type = 'books_transaction' AND entity_id = ? AND parent_entity_id IS NULL")
                ->execute([$invoiceId, (int)$txn]);
            $pdo->prepare("INSERT INTO supplier_invoice_payments (invoice_id, payment_date, amount, payment_method, payment_account_id, reference, journal_txn_id, recorded_by)
                           VALUES (?, ?, ?, 'cash', ?, NULL, ?, ?)")
                ->execute([$invoiceId, $date, $amount, $accountId, $txn, $userId]);
            $pdo->prepare("UPDATE supplier_invoices SET amount_paid = ?, status = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$newPaid, $newStatus, $invoiceId]);
            if ($newStatus === 'paid') {
                $pdo->prepare("UPDATE supplier_invoices SET payment_date = ?, payment_method = 'cash', payment_account_id = ?, payment_transaction_id = ?, payment_recorded_by = ? WHERE id = ?")
                    ->execute([$date, $accountId, $txn, $userId, $invoiceId]);
            }
            recordBankTransaction($pdo, $accountId, $amount, 'withdrawal', $date, $inv['invoice_ref'], "Supplier bill {$inv['invoice_ref']} payment", $userId);
            logActivity($pdo, $userId, "Paid " . number_format($amount, 2) . " on supplier bill {$inv['invoice_ref']} — " . ($newStatus === 'paid' ? 'fully paid' : 'still owed ' . number_format($total - $newPaid, 2)));
            if ($own) $pdo->commit();
        } catch (Throwable $e) {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return ['invoice_id' => $invoiceId, 'paid' => $amount, 'amount_paid' => $newPaid, 'remaining' => round($total - $newPaid, 2), 'status' => $newStatus, 'transaction_id' => (int)$txn];
    }
}

if (!function_exists('restaurantRecordSupplierBill')) {
    /**
     * Record what a purchase leaves owing: a Bill for the WHOLE purchase, its payable posted, and the part paid now
     * paid against it. Must run inside the caller's transaction (the stock intake and this succeed or fail together).
     *
     * @param array $b supplier_id, warehouse_id, date, total, paid, ref, notes, [due_date], [cost_account_id] (null = Inventory), [cash_account_id]
     * @return array{invoice_id:int,owed:float,status:string}
     */
    function restaurantRecordSupplierBill(PDO $pdo, array $b, int $userId): array
    {
        if (!$pdo->inTransaction()) throw new LogicException('restaurantRecordSupplierBill must run inside a transaction');
        $total = round((float)$b['total'], 2);
        $paid  = round((float)($b['paid'] ?? 0), 2);
        $due   = !empty($b['due_date']) ? (string)$b['due_date'] : null;
        if ($due !== null && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) || strtotime($due) === false)) throw new Exception(t('Enter a valid date.'));
        if ($due !== null && $due < $b['date']) throw new Exception(t('The date to pay by cannot be before the purchase date.'));

        $pdo->prepare("INSERT INTO supplier_invoices
                (invoice_type, supplier_id, invoice_ref, date_raised, date_recorded, due_date, warehouse_id, amount, cost_account_id, amount_paid, status, notes, recorded_by, subtotal, tax_amount)
                VALUES ('supplier', ?, ?, ?, CURDATE(), ?, ?, ?, ?, 0, 'approved', ?, ?, ?, 0)")
            ->execute([(int)$b['supplier_id'], (string)$b['ref'], (string)$b['date'], $due, (int)$b['warehouse_id'], $total,
                       !empty($b['cost_account_id']) ? (int)$b['cost_account_id'] : null, RESTAURANT_BILL_MARK . (string)$b['notes'], $userId, $total]);
        $invoiceId = (int)$pdo->lastInsertId();

        // The payable — exactly the posting a Smart BMS Bill makes on approval.
        $acc = postGoodsInvoiceAccrual($pdo, $invoiceId, $userId);
        if (empty($acc['posted'])) {
            throw new Exception(t('Could not record what is owed in the books — please check that the Inventory and Accounts Payable accounts are set up.'));
        }
        $status = 'approved';
        if ($paid > 0) {
            $r = restaurantPaySupplierBill($pdo, $invoiceId, $paid, (string)$b['date'], (int)($b['cash_account_id'] ?? 0), $userId);
            $status = $r['status'];
        }
        return ['invoice_id' => $invoiceId, 'owed' => round($total - $paid, 2), 'status' => $status];
    }
}

if (!function_exists('restaurantSupplierDebts')) {
    /**
     * Open supplier bills (not fully paid) for the given shops, oldest due first.
     * @param int[] $warehouseIds already scope-checked
     */
    function restaurantSupplierDebts(PDO $pdo, array $warehouseIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $warehouseIds)));
        if (!$ids) return [];
        $in = implode(',', $ids);
        return $pdo->query("
            SELECT si.id, si.invoice_ref, si.supplier_id, s.supplier_name, s.phone, si.date_raised, si.due_date,
                   si.amount, si.amount_paid, ROUND(si.amount - si.amount_paid, 2) AS owed, si.status,
                   TRIM(REPLACE(COALESCE(si.notes, ''), '" . RESTAURANT_BILL_MARK . "', '')) AS what,
                   (si.due_date IS NOT NULL AND si.due_date < CURDATE()) AS overdue
              FROM supplier_invoices si
              JOIN suppliers s ON s.supplier_id = si.supplier_id
             WHERE si.status IN ('approved', 'partial') AND si.invoice_type = 'supplier' AND si.warehouse_id IN ($in)
          ORDER BY (si.due_date IS NULL), si.due_date, si.date_raised, si.id")->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('restaurantOwedToSuppliers')) {
    /**
     * What the restaurant owes its suppliers — from the ONE ledger: the Accounts Payable balance of the entries that
     * belong to the shops' supplier bills (each bill's payable, and every payment linked to that bill).
     * @param int[] $warehouseIds already scope-checked
     * @return array{owed:float,suppliers:int,overdue:int}
     */
    function restaurantOwedToSuppliers(PDO $pdo, array $warehouseIds): array
    {
        $out = ['owed' => 0.0, 'suppliers' => 0, 'overdue' => 0];
        $ids = array_values(array_filter(array_map('intval', $warehouseIds)));
        $ap = apAccountId($pdo);
        if (!$ids || !$ap) return $out;
        $in = implode(',', $ids);
        try {
            $bills = "SELECT id FROM supplier_invoices WHERE invoice_type = 'supplier' AND status != 'deleted' AND warehouse_id IN ($in)";
            $st = $pdo->prepare("
                SELECT COALESCE(SUM(CASE WHEN jei.type = 'credit' THEN jei.amount ELSE -jei.amount END), 0)
                  FROM journal_entry_items jei
                  JOIN journal_entries je ON je.entry_id = jei.entry_id AND je.status = 'posted'
                 WHERE jei.account_id = ?
                   AND ((je.entity_type = 'supplier_invoice' AND je.entity_id IN ($bills))
                     OR (je.parent_entity_type = 'supplier_invoice' AND je.parent_entity_id IN ($bills)))");
            $st->execute([(int)$ap]);
            $out['owed'] = round((float)$st->fetchColumn(), 2);
            $row = $pdo->query("SELECT COUNT(DISTINCT supplier_id) AS s, SUM(due_date IS NOT NULL AND due_date < CURDATE()) AS o
                                  FROM supplier_invoices WHERE status IN ('approved','partial') AND invoice_type = 'supplier' AND warehouse_id IN ($in)")
                       ->fetch(PDO::FETCH_ASSOC) ?: [];
            $out['suppliers'] = (int)($row['s'] ?? 0);
            $out['overdue'] = (int)($row['o'] ?? 0);
        } catch (Throwable $e) {
            error_log('restaurantOwedToSuppliers: ' . $e->getMessage());
        }
        return $out;
    }
}

if (!function_exists('restaurantBuyGroceryOnCredit')) {
    /**
     * "Something else" (not counted — tomatoes, greens) bought PARTLY PAID or on CREDIT. Fully paid groceries keep the
     * existing path (an ordinary paid expense). The cost lands on the same cost-of-food account the expense uses.
     * @param array $in name, amount, warehouse_id, date, supplier_id, payment ('partial'|'credit'), [amount_paid], [due_date]
     * @return array{bill_id:int,reference:string,total:float,paid_now:float,owed:float,payment:string}
     */
    function restaurantBuyGroceryOnCredit(PDO $pdo, array $in, int $userId): array
    {
        $name   = trim((string)($in['name'] ?? ''));
        $amount = round((float)($in['amount'] ?? 0), 2);
        $wh     = (int)($in['warehouse_id'] ?? 0);
        $date   = !empty($in['date']) ? (string)$in['date'] : date('Y-m-d');
        $sup    = (int)($in['supplier_id'] ?? 0);
        if (!restaurantShopActive($pdo, $wh)) throw new Exception(t('This shop is not set up as a restaurant.'));
        if ($name === '') throw new Exception(t('Enter what you bought.'));
        if (mb_strlen($name) > 200) throw new Exception(t('The name is too long.'));
        if ($amount <= 0) throw new Exception(t('Enter the amount.'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) throw new Exception(t('Enter a valid date.'));
        if ($date > date('Y-m-d')) throw new Exception(t('The date cannot be in the future.'));
        $split = restaurantPaymentSplit($amount, (string)($in['payment'] ?? 'credit'), $in['amount_paid'] ?? 0);
        if ($split['owed'] <= 0) throw new Exception(t('Choose Partly paid or Credit.'));
        if ($sup <= 0) throw new Exception(t('Choose the supplier you owe.'));
        restaurantCheckSupplier($pdo, $sup);
        $costAcc = restaurantIngredientsAccountId($pdo);
        if (!$costAcc) throw new Exception(t('The cost of food account is not set up — contact your administrator.'));
        $cash = posSimpleModeEnabled() ? (int)(posReceiptAccountId($pdo, 'cash') ?? 0) : 0;
        $ref = nextCode($pdo, 'RPB');

        $pdo->beginTransaction();
        try {
            $bill = restaurantRecordSupplierBill($pdo, [
                'supplier_id' => $sup, 'warehouse_id' => $wh, 'date' => $date, 'total' => $amount, 'paid' => $split['paid'],
                'ref' => $ref, 'due_date' => $in['due_date'] ?? null, 'cost_account_id' => $costAcc, 'cash_account_id' => $cash,
                'notes' => 'Ingredients: ' . $name,
            ], $userId);
            logActivity($pdo, $userId, "Restaurant purchase (not counted) $name — " . number_format($amount, 2) . ", owed " . number_format($split['owed'], 2) . " ($ref)");
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return ['bill_id' => $bill['invoice_id'], 'reference' => $ref, 'total' => $amount, 'paid_now' => $split['paid'], 'owed' => $split['owed'], 'payment' => $split['payment']];
    }
}
