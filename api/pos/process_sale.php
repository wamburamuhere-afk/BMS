<?php
// Phase 6 (pos_upgrade_plan.md) — warehouse_id is now checked against the
// requesting user's own warehouse scope before any stock is touched.
/**
 * API: Process POS Sale
 * Complete sale transaction with inventory update
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
// Respect the caller's saved language preference (set by header.php on their
// last page load) so t()-wrapped messages below come back in the right
// language, not always English.
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../core/stock_ledger.php';
require_once __DIR__ . '/../../core/warehouse_scope.php';
require_once __DIR__ . '/../../core/pos_override_guard.php';
require_once __DIR__ . '/../../core/pos_price_groups.php';
require_once __DIR__ . '/../../core/pos_batch_consumption.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => t('Unauthorized')]);
    exit();
}

if (!canCreate('pos')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => t('Access Denied: you do not have permission to process POS sales')]);
    exit();
}

try {
    global $pdo;
    $pdo->beginTransaction();
    
    // Read JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        throw new Exception("Invalid input data");
    }

    $user_id = $_SESSION['user_id'];
    // Nullable INT columns: the POS frontend sends '' (empty string) for the
    // "General/All Warehouses", "No Project" and "Walk-in" options. The old
    // "?? null" only caught a MISSING key, so '' was bound straight into the INT
    // columns — fine on a non-strict local MySQL (coerced to 0) but rejected by a
    // strict-mode server with "Incorrect integer value: '' for column ...".
    // Coerce empty/blank to a real NULL so sales work under STRICT_TRANS_TABLES.
    $toNullableInt = function ($v) {
        return ($v === null || $v === '' || $v === false) ? null : (int)$v;
    };
    $customer_id  = $toNullableInt($input['customer_id']  ?? null);
    $warehouse_id = $toNullableInt($input['warehouse_id'] ?? null);
    $project_id   = $toNullableInt($input['project_id']   ?? null);
    // Phase 14 (pos_upgrade_plan.md §8) — selling price tiers. 0/absent = no
    // group chosen, every line resolves to plain products.selling_price,
    // identical to pre-Phase-14 behaviour.
    $price_group_id = $toNullableInt($input['price_group_id'] ?? null) ?? 0;
    $payment_method = $input['payment_method'] ?? 'cash';
    $amount_tendered = floatval($input['amount_tendered'] ?? 0);
    $items = $input['items'] ?? [];
    $subtotal = floatval($input['subtotal'] ?? 0);
    $discount_percentage = floatval($input['discount_percentage'] ?? 0);
    $discount_amount = floatval($input['discount_amount'] ?? 0);
    $tax = floatval($input['tax'] ?? 0);
    $total = floatval($input['total'] ?? 0);
    $receipt_number = $input['receipt_number'] ?? ('RCP-' . date('Ymd') . '-' . mt_rand(1000, 9999));
    $split_details = $input['split_details'] ?? null;

    // Phase 8 (pos_upgrade_plan.md §7) — persist the true per-tender breakdown of a
    // split sale so shift-close reconciliation (close_shift.php) and any future
    // Z-report can read it back accurately (previously nothing recorded which
    // portion went to which tender). Only split sales need this column — a
    // non-split sale's tender is already fully described by payment_method +
    // grand_total, so it stays NULL.
    require_once __DIR__ . '/../../core/pos_shift_reporting.php';
    $payment_details_json = ($payment_method === 'split' && is_array($split_details))
        ? buildSplitPaymentLegs($split_details)
        : null;

    // Payment model — how much is actually collected NOW vs put on the customer's
    // account (credit). For non-credit methods the sale is paid in full; for
    // 'credit' the cashier may take a deposit (amount_paid) or nothing. The
    // authoritative payment_status / balance is finalised after the server
    // recomputes the total (see below).
    $is_credit = ($payment_method === 'credit');
    $amount_paid_now = isset($input['amount_paid']) ? floatval($input['amount_paid']) : ($is_credit ? 0.0 : $total);
    if ($amount_paid_now < 0) $amount_paid_now = 0.0;
    if ($is_credit && empty($customer_id)) {
        throw new Exception('Credit sales require a customer — you cannot sell on credit to a walk-in.');
    }

    if (empty($items)) {
        throw new Exception("No items in cart");
    }

    // Warehouse is compulsory — a sale must be drawn from a specific warehouse.
    if (empty($warehouse_id)) {
        throw new Exception("A warehouse must be selected for the sale.");
    }
    if (!userCan('warehouse', $warehouse_id)) {
        throw new Exception("Access denied: this warehouse is not in your assigned scope.");
    }

    // Check for active shift — pos_sales.shift_id is NOT NULL, so this must be
    // validated before the insert, not silently passed through as null.
    $stmt = $pdo->prepare("SELECT shift_id, register_id FROM cash_register_shifts WHERE user_id = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$user_id]);
    $shift = $stmt->fetch(PDO::FETCH_ASSOC);
    $shift_id = $shift['shift_id'] ?? null;
    if (!$shift_id) {
        throw new Exception("Please start a cash register shift before completing a sale.");
    }

    // Phase 8 (pos_upgrade_plan.md §7) — denormalise the register onto the sale
    // itself (pos_sales.register_id/register_name already existed on this schema
    // but were never populated, always sitting at the column default). Stamping
    // it at sale time — rather than deriving it later via a join through
    // shift_id — matches how customer_name is already denormalised alongside
    // customer_id, and keeps a sale's register a fixed fact of the sale even if
    // the shift row is later touched for any reason.
    $register_id = (int)($shift['register_id'] ?? 1);
    $regNameStmt = $pdo->prepare("SELECT register_name FROM pos_registers WHERE register_id = ?");
    $regNameStmt->execute([$register_id]);
    $register_name = $regNameStmt->fetchColumn() ?: null;

    // pos_sales.payment_method is a DB enum that does NOT include 'split' (it has
    // 'mixed' for exactly this case) — inserting the frontend's raw 'split' value
    // was silently coerced to '' by MySQL's non-strict enum handling (confirmed
    // live: one pre-existing sale row already has payment_method=''). Store the
    // real enum value while keeping $payment_method ('split') for the branching
    // logic below, which mirrors the frontend's own naming.
    $db_payment_method = ($payment_method === 'split') ? 'mixed' : $payment_method;

    // Insert sale
    $stmt = $pdo->prepare("
        INSERT INTO pos_sales (
            receipt_number, shift_id, user_id, customer_id, warehouse_id, project_id,
            subtotal, discount_percentage, discount_amount, tax_amount, grand_total,
            payment_method, amount_tendered, change_given, payment_details, register_id, register_name,
            sale_status, payment_status, sale_date, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', 'pending', NOW(), NOW())
    ");

    $change = $input['change_given'] ?? ($amount_tendered - $total);

    $stmt->execute([
        $receipt_number,
        $shift_id,
        $user_id,
        $customer_id,
        $warehouse_id,
        $project_id,
        $subtotal,
        $discount_percentage,
        $discount_amount,
        $tax,
        $total,
        $db_payment_method,
        $amount_tendered,
        $change,
        $payment_details_json,
        $register_id,
        $register_name
    ]);
    
    $sale_id = $pdo->lastInsertId();
    
    // Insert sale items and update inventory
    // Recalculate totals server-side for security
    $calculated_subtotal = 0;
    $calculated_discount = 0;
    $calculated_tax = 0;
    $calculated_total = 0;
    
    // Build project stock subquery for validation
    $product_ids = array_column($items, 'product_id');
    $placeholders = str_repeat('?,', count($product_ids) - 1) . '?';
    
    $project_stock_subquery = "0";
    if ($project_id > 0) {
        $project_stock_subquery = "(SELECT COALESCE(SUM(CASE WHEN movement_type = 'in' THEN quantity ELSE -quantity END), 0) 
                                    FROM stock_movements sm 
                                    WHERE sm.product_id = p.product_id 
                                    AND sm.project_id = ? " . ($warehouse_id ? "AND sm.warehouse_id = ?" : "") . ")";
    }

    $fetchStmt = $pdo->prepare("
        SELECT p.product_id, p.product_name, p.selling_price, p.min_selling_price, p.is_service, 
               COALESCE(SUM(ps.stock_quantity - IFNULL(ps.reserved_quantity, 0)), 0) as general_available,
               $project_stock_subquery as project_available,
               COALESCE(SUM(IFNULL(ps.reserved_quantity, 0)), 0) as current_warehouse_reserved
        FROM products p
        LEFT JOIN product_stocks ps ON p.product_id = ps.product_id " . ($warehouse_id ? "AND ps.warehouse_id = ?" : "") . "
        WHERE p.product_id IN ($placeholders)
        GROUP BY p.product_id
    ");

    $fetchParams = [];
    if ($project_id > 0) {
        $fetchParams[] = $project_id;
        if ($warehouse_id) $fetchParams[] = $warehouse_id;
    }
    if ($warehouse_id) $fetchParams[] = $warehouse_id;
    foreach ($product_ids as $id) $fetchParams[] = $id;

    $fetchStmt->execute($fetchParams);
    $products_db = $fetchStmt->fetchAll(PDO::FETCH_ASSOC);
    $products_map = array_column($products_db, null, 'product_id');

    // Phase 14 (pos_upgrade_plan.md §8) — resolve each product's authoritative
    // price for the chosen price group BEFORE the item loop below, so
    // core/pos_override_guard.php::resolvePosLineBasePrice() (which reads
    // $db_product['selling_price']) is automatically group-aware without
    // needing to know price groups exist at all. Sparse: a product with no
    // override row for this group keeps its plain selling_price untouched.
    $groupPrices = resolveGroupPrices($pdo, $price_group_id, $product_ids);
    foreach ($groupPrices as $pid => $price) {
        if (isset($products_map[$pid])) {
            $products_map[$pid]['selling_price'] = $price;
        }
    }

    // Insert sale items and update inventory
    $itemStmt = $pdo->prepare("
        INSERT INTO pos_sale_items (
            sale_id, product_id, product_name, quantity, unit_price, 
            tax_rate, tax_amount, discount_rate, discount_amount, line_total
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stockStmt = $pdo->prepare("
        UPDATE products 
        SET stock_quantity = stock_quantity - ?,
            current_stock = current_stock - ?
        WHERE product_id = ?
    ");
    
    // Phase 16 (pos_upgrade_plan.md §8) — loss-control permission split. These
    // are the real security boundary; the client-side hiding in pos.php/
    // pos_scripts_new.php is UX only, never trusted on its own.
    $can_price_override    = canEdit('pos_price_override');
    $can_discount_override = canEdit('pos_discount_override');

    foreach ($items as $item) {
        $pid = $item['product_id'];
        $db_product = $products_map[$pid] ?? null;

        if (!$db_product) {
            throw new Exception("Product ID $pid not found");
        }

        // Validate Price
        $requested_price = floatval($item['discounted_price'] ?? $item['price'] ?? 0);
        $min_price = floatval($db_product['min_selling_price']);

        // Allow a small epsilon for float comparison
        if ($requested_price < ($min_price - 0.01)) {
            throw new Exception("Price for '{$db_product['product_name']}' is below minimum selling price.");
        }
        
        // Calculate item values
        $qty = floatval($item['quantity']);

        // RESERVATION CHECK: Ensure we don't sell project-reserved stock (unless it's THIS project)
        if (!$db_product['is_service']) {
            $general_avail = floatval($db_product['general_available']);
            $project_avail = floatval($db_product['project_available'] ?? 0);
            $total_allowed = $general_avail + ($project_id > 0 ? $project_avail : 0);

            if ($qty > $total_allowed) {
                $msg = "Insufficient stock for '{$db_product['product_name']}'. ";
                if ($project_id > 0) {
                    $msg .= "Available for this project: $total_allowed (General: $general_avail, Project Reserved: $project_avail)";
                } else {
                    $msg .= "Available (excluding projects): $general_avail";
                }
                throw new Exception($msg);
            }
        }

        // Phase 16 (pos_upgrade_plan.md §8) — base price resolved server-side,
        // never trusted from the client unless explicitly overridden by a
        // permitted cashier (see core/pos_override_guard.php).
        $priceResolution = resolvePosLineBasePrice($item, $db_product, $can_price_override);
        $original_price = $priceResolution['original_price'];
        if ($priceResolution['requested_price'] !== null) {
            $requested_price = $priceResolution['requested_price'];
        }
        $tax_rate = floatval($item['tax_rate']);
        $discount_percent = floatval($item['discount_percent']);

        $item_original_total = $original_price * $qty;
        $item_discounted_total = $requested_price * $qty;

        $item_discount_amount = $item_original_total - $item_discounted_total;

        // A real discount on this line requires pos_discount_override.
        // Rejects outright rather than silently clamping: an unauthorized
        // discount attempt on a live sale is worth surfacing as an error, not
        // quietly overriding the price the cashier saw on screen.
        assertPosLineDiscountPermitted($item_discount_amount, $can_discount_override, $db_product['product_name']);
        $item_tax_amount = $item_discounted_total * ($tax_rate / 100);
        
        // Update global sums
        $calculated_subtotal += $item_original_total;
        $calculated_discount += $item_discount_amount;
        $calculated_tax += $item_tax_amount;
        
        // Prepare DB record
        $itemStmt->execute([
            $sale_id,
            $pid,
            $db_product['product_name'], // Use DB name to be safe
            $qty,
            $original_price,
            $tax_rate,
            $item_tax_amount,
            $discount_percent,
            $item_discount_amount,
            $item_discounted_total // line_total (excluding tax usually, or including? let's assume excluding since tax is separate)
        ]);
        $sale_item_id = (int)$pdo->lastInsertId();

        // Update stock (Only for non-service products)
        if (!$db_product['is_service']) {
            // Phase 17b (pos_upgrade_plan.md §8) — FEFO batch consumption, a
            // bookkeeping layer on top of the product_stocks decrement below,
            // not a replacement for it. No-op for a product with no open
            // batches in this warehouse (not batch-tracked).
            consumeFefoBatches($pdo, $pid, (int)$warehouse_id, $qty, $sale_item_id);

            // 1. Global Update
            $stockStmt->execute([ $qty, $qty, $pid ]);

            // 2. Warehouse Update
            if ($warehouse_id) {
                 // Logic: If it's a project sale, we deduct from reserved_quantity first.
                 $reserved_deduction = 0;
                 if ($project_id > 0) {
                     $current_reserved = floatval($db_product['current_warehouse_reserved']);
                     $reserved_deduction = min($qty, $current_reserved);
                 }
                 
                 $psStmt = $pdo->prepare("
                    UPDATE product_stocks 
                    SET stock_quantity = IFNULL(stock_quantity, 0) - ?,
                        reserved_quantity = GREATEST(0, IFNULL(reserved_quantity, 0) - ?)
                    WHERE product_id = ? AND warehouse_id = ?
                 ");
                 $psStmt->execute([ $qty, $reserved_deduction, $pid, $warehouse_id ]);
            }

            // 3. Log Stock Movement (sale_out — was the invalid 'out' literal
            //    which MySQL silently truncated to '' and showed as "OTHER").
            recordStockMovement($pdo, [
                'product_id'       => $pid,
                'warehouse_id'     => $warehouse_id,
                'project_id'       => $project_id,
                'movement_type'    => 'sale_out',
                'quantity'         => $qty,
                'reference_id'     => $sale_id,
                'reference_type'   => 'pos_sale',
                'reference_number' => $receipt_number,
                'created_by'       => $_SESSION['user_id'],
                'notes'            => "POS Sale #$receipt_number",
            ]);
        }
    }
    
    $calculated_total = ($calculated_subtotal - $calculated_discount) + $calculated_tax;

    // Phase 11 (pos_upgrade_plan.md §7) — loyalty point redemption. Applied as
    // an additional discount on top of the server-recomputed total (never
    // trusting a client-supplied discount amount); folded into
    // $calculated_discount so it flows through the existing UPDATE below with
    // no new columns needed. Points can only be redeemed against a registered
    // customer — a walk-in sale can't have accrued any to begin with.
    require_once __DIR__ . '/../../core/pos_loyalty.php';
    $loyalty_points_redeemed = 0;
    $redeem_points_requested = max(0, (int)($input['redeem_points'] ?? 0));
    if ($redeem_points_requested > 0) {
        $redeemResult = redeemLoyaltyPoints($pdo, $customer_id, $redeem_points_requested, $sale_id, $user_id);
        if ($redeemResult['error']) {
            throw new Exception($redeemResult['error']);
        }
        $loyalty_points_redeemed = $redeemResult['points'];
        $loyalty_discount = min($redeemResult['discount'], $calculated_total);
        $calculated_discount += $loyalty_discount;
        $calculated_total = round($calculated_total - $loyalty_discount, 2);
    }

    // Finalise the payment model against the server-recomputed total.
    $amount_paid_now = max(0.0, min($amount_paid_now, $calculated_total));
    if ($amount_paid_now >= $calculated_total - 0.01)      $final_payment_status = 'paid';
    elseif ($amount_paid_now > 0.01)                       $final_payment_status = 'partial';
    else                                                   $final_payment_status = 'pending';
    $balance_due = round($calculated_total - $amount_paid_now, 2);

    // Update the main sale record with calculated values + authoritative status.
    $updateSaleStmt = $pdo->prepare("
        UPDATE pos_sales
        SET subtotal = ?, discount_amount = ?, tax_amount = ?, grand_total = ?, payment_status = ?
        WHERE sale_id = ?
    ");
    $updateSaleStmt->execute([
        $calculated_subtotal,
        $calculated_discount,
        $calculated_tax,
        $calculated_total,
        $final_payment_status,
        $sale_id
    ]);

    // Phase 11 — earn loyalty points on the final (post-redemption) total.
    $loyalty_points_earned = awardLoyaltyPoints($pdo, $customer_id, $sale_id, $calculated_total, $user_id);
    if ($loyalty_points_earned > 0 || $loyalty_points_redeemed > 0) {
        $pdo->prepare("UPDATE pos_sales SET loyalty_points_earned = ?, loyalty_points_redeemed = ? WHERE sale_id = ?")
            ->execute([$loyalty_points_earned, $loyalty_points_redeemed, $sale_id]);
    }

    // Record the initial payment (deposit/full) against the sale, if any was collected.
    // Guarded: the pos_sale_payments table ships with migration 2026_06_08; on a server
    // where that migration has not run yet, skip the payment-ledger row rather than fail
    // the whole sale (mirrors the table-existence guard in api/pos/get_sales.php).
    if ($amount_paid_now > 0.01) {
        $hasPayTable = false;
        try { $hasPayTable = (bool)$pdo->query("SHOW TABLES LIKE 'pos_sale_payments'")->fetch(); } catch (Throwable $e) {}
        if ($hasPayTable) {
            $pmRow = in_array($payment_method, ['cash','card','mobile_money','bank_transfer','voucher','loyalty_points'], true) ? $payment_method : 'cash';
            $pdo->prepare("
                INSERT INTO pos_sale_payments (sale_id, amount, payment_method, reference, notes, received_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ")->execute([$sale_id, $amount_paid_now, $pmRow, $receipt_number, ($is_credit ? 'Deposit at sale' : 'Paid at sale'), $user_id]);
        }
    }

    // Record cash transaction if shift exists — only the CASH actually received.
    $cash_received = ($payment_method === 'cash' || ($is_credit && $amount_paid_now > 0)) ? $amount_paid_now : 0.0;
    if ($shift_id) {
        if ($cash_received > 0) {
            $pdo->prepare("
                INSERT INTO cash_register_transactions (
                    shift_id, transaction_type, amount, payment_method, reference_number, sale_id, created_by, created_at
                ) VALUES (?, 'sale', ?, 'cash', ?, ?, ?, NOW())
            ")->execute([$shift_id, $cash_received, $receipt_number, $sale_id, $user_id]);
        } elseif ($payment_method === 'split' && isset($split_details['cash']) && $split_details['cash'] > 0) {
            $pdo->prepare("
                INSERT INTO cash_register_transactions (
                    shift_id, transaction_type, amount, payment_method, reference_number, created_by, created_at
                ) VALUES (?, 'sale', ?, 'cash', ?, ?, NOW())
            ")->execute([$shift_id, $split_details['cash'], $receipt_number, $user_id]);
        }
    }

    // IN-5 (money.md): post the sale to the canonical ledger — revenue + COGS.
    //   Revenue: Dr Cash/Bank (paid) + Dr AR (balance) / Cr Sales / Cr Output VAT
    //   COGS:    Dr COGS / Cr Inventory  (Σ qty × products.cost_price)
    // Best-effort: never fails the sale (postPosSale does not throw); idempotent.
    require_once __DIR__ . '/../../core/sales_posting.php';
require_once __DIR__ . '/../../core/bank_register.php';  // recordBankTransaction
    $glPost = postPosSale(
        $pdo, (int)$sale_id, $payment_method, (float)$amount_paid_now, (float)$balance_due,
        (float)$calculated_total, (float)$calculated_tax, date('Y-m-d'), $receipt_number,
        $project_id !== null ? (int)$project_id : null, (int)$user_id
    );
    // Accountability: a sale is never blocked by accounting, but if it could not post to the
    // ledger (e.g. a control account isn't configured) we record a warning so the missing
    // double-entry is visible and recoverable — never silently lost.
    if (empty($glPost['revenue'])) {
        logActivity($pdo, $user_id, 'POS Sale GL warning',
            "POS Sale #$receipt_number (id $sale_id) did NOT post to the ledger: " . ($glPost['reason'] ?: 'unknown'));
    }

    // Bank register — deposit for the cash/payment received at the POS terminal
    if ($amount_paid_now > 0) {
        try {
            $posAccount = posReceiptAccountId($pdo, $payment_method);
            if ($posAccount) {
                recordBankTransaction($pdo, (int)$posAccount, $amount_paid_now, 'deposit',
                    date('Y-m-d'), $receipt_number,
                    "POS Sale #{$receipt_number}", $user_id);
            }
        } catch (Throwable $e) {
            // Best-effort: never block the sale for a bank register failure
            error_log("POS bank register warning #{$receipt_number}: " . $e->getMessage());
        }
    }

    $pdo->commit();

    // Log the activity
    $username = $_SESSION['username'] ?? 'User';
    logActivity($pdo, $user_id, 'Create POS Sale', "$username created POS Sale #$receipt_number (Total: " . number_format($calculated_total, 2) . ")");
    
    echo json_encode([
        'success' => true,
        'message' => $balance_due > 0.01
            ? sprintf(t('Sale recorded on credit. Balance due: %s'), number_format($balance_due, 2))
            : t('Sale completed successfully'),
        'sale_id' => $sale_id,
        'receipt_number' => $receipt_number,
        'payment_status' => $final_payment_status,
        'amount_paid' => round($amount_paid_now, 2),
        'balance_due' => $balance_due,
        'loyalty_points_earned' => $loyalty_points_earned,
        'loyalty_points_redeemed' => $loyalty_points_redeemed
    ]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
