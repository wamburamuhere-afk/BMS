<?php
/**
 * migrations/2026_05_31_backfill_stock_movement_metadata.php
 *
 * Repairs legacy `stock_movements` rows that were written by older code without
 * a movement_type, reference_number, value or running balance (they show up as
 * "OTHER" with blank reference and 0.00 value/balance in the report).
 *
 * 1. Classifies each blank row from its `notes` / `reference_type`:
 *      GRN      → purchase_in  / purchase_order
 *      inbound  DN → purchase_in / delivery
 *      outbound DN → sale_out    / delivery
 *      pos_sale → sale_out      / pos_sale
 *    and restores reference_number from the notes text.
 * 2. Backfills total_cost = quantity * products.cost_price where it is 0.
 * 3. Recomputes stock_before / stock_after for the WHOLE ledger by replaying
 *    each (product, warehouse) chronologically.
 *
 * Idempotent — safe to run multiple times.
 */
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: backfill stock_movements metadata...\n";

$IN_TYPES = ['purchase_in','adjustment_in','transfer_in','return_in','production_in','found','correction'];

/** Pull a GRN-/DN-style reference code out of a free-text notes string. */
function bf_extract_ref(?string $notes): ?string {
    if (!$notes) return null;
    if (preg_match('/((?:GRN|DN)-\d{6,}-\d+)/i', $notes, $m)) return $m[1];
    if (preg_match('/#\s*([A-Za-z0-9\-]+)/', $notes, $m)) return $m[1];
    return null;
}

try {
    $pdo->beginTransaction();

    // ── 1. Classify & restore blank rows ──────────────────────────────────
    $blanks = $pdo->query("
        SELECT movement_id, reference_id, reference_type, reference_number, notes
          FROM stock_movements
         WHERE (movement_type IS NULL OR movement_type = '')
    ")->fetchAll(PDO::FETCH_ASSOC);

    $upd = $pdo->prepare("
        UPDATE stock_movements
           SET movement_type = ?, reference_type = ?, reference_number = ?
         WHERE movement_id = ?
    ");

    // Outbound-DN detection needs deliveries.dn_type, which may not exist on
    // every host (schema drift across the production fleet). Probe once and
    // skip the lookup gracefully if the table/column is absent — DN rows then
    // default to inbound, which is still a valid IN classification.
    $dnLookup = null;
    try {
        $hasDnType = (bool) $pdo->query("SHOW COLUMNS FROM deliveries LIKE 'dn_type'")->fetchColumn();
        if ($hasDnType) {
            $dnLookup = $pdo->prepare("SELECT dn_type FROM deliveries WHERE delivery_number = ? LIMIT 1");
        }
    } catch (Throwable $e) {
        $dnLookup = null; // deliveries table missing on this host — skip lookup
    }

    $classified = 0;
    foreach ($blanks as $row) {
        $notes = (string)($row['notes'] ?? '');
        $ref_type = $row['reference_type'] ?: null;
        $ref_num  = $row['reference_number'] ?: bf_extract_ref($notes);
        $type     = null;

        if (stripos($notes, 'GRN') === 0 || stripos($notes, 'GRN') !== false && stripos($notes, 'DN') === false) {
            // GRN goods received → stock IN
            $type     = 'purchase_in';
            $ref_type = 'purchase_order';
        } elseif (stripos($notes, 'DN') !== false) {
            // Delivery note → direction depends on dn_type. reference_type must
            // be a valid ENUM value (there is no 'delivery'): inbound deliveries
            // use 'stock_transfer', outbound (customer) use 'sales_order'.
            $type = 'purchase_in';                 // default inbound
            $ref_type = 'stock_transfer';
            if ($ref_num && $dnLookup) {
                $dnLookup->execute([$ref_num]);
                $dn_type = $dnLookup->fetchColumn();
                if ($dn_type === 'outbound') { $type = 'sale_out'; $ref_type = 'sales_order'; }
            }
        } elseif ($row['reference_type'] === 'pos_sale') {
            // POS sale → stock OUT
            $type     = 'sale_out';
            $ref_type = 'pos_sale';
        } else {
            // Unknown origin — leave as a neutral correction so it is no longer
            // "OTHER"; quantity is preserved either way.
            $type = 'correction';
        }

        $upd->execute([$type, $ref_type, $ref_num, $row['movement_id']]);
        $classified++;
    }
    echo "  Classified $classified blank row(s).\n";

    // ── 1b. Repair DN rows whose reference_type was truncated to '' by an
    //        earlier invalid 'delivery' value (idempotent). ─────────────────
    $fix1 = $pdo->exec("
        UPDATE stock_movements
           SET reference_type = 'stock_transfer'
         WHERE (reference_type IS NULL OR reference_type = '')
           AND notes LIKE '%DN%'
           AND movement_type IN ('purchase_in','transfer_in')
    ");
    $fix2 = $pdo->exec("
        UPDATE stock_movements
           SET reference_type = 'sales_order'
         WHERE (reference_type IS NULL OR reference_type = '')
           AND notes LIKE '%DN%'
           AND movement_type IN ('sale_out','issue_out')
    ");
    echo "  Repaired reference_type on " . ($fix1 + $fix2) . " DN row(s).\n";

    // ── 2. Backfill total_cost where missing ──────────────────────────────
    $valStmt = $pdo->exec("
        UPDATE stock_movements sm
          JOIN products p ON sm.product_id = p.product_id
           SET sm.unit_cost  = CASE WHEN sm.unit_cost  > 0 THEN sm.unit_cost  ELSE COALESCE(p.cost_price,0) END,
               sm.total_cost = CASE WHEN sm.total_cost > 0 THEN sm.total_cost ELSE sm.quantity * COALESCE(p.cost_price,0) END
         WHERE (sm.total_cost = 0 OR sm.total_cost IS NULL)
    ");
    echo "  Backfilled value on $valStmt row(s).\n";

    // ── 3. Recompute running balance for the whole ledger ─────────────────
    $all = $pdo->query("
        SELECT movement_id, product_id, warehouse_id, movement_type, quantity
          FROM stock_movements
      ORDER BY product_id,
               warehouse_id,
               DATE(COALESCE(NULLIF(movement_date,'0000-00-00'), created_at)),
               movement_id
    ")->fetchAll(PDO::FETCH_ASSOC);

    $balUpd = $pdo->prepare("UPDATE stock_movements SET stock_before = ?, stock_after = ? WHERE movement_id = ?");
    $running = [];
    $rebalanced = 0;
    foreach ($all as $r) {
        $key    = $r['product_id'] . '|' . ($r['warehouse_id'] ?? 'null');
        $before = $running[$key] ?? 0.0;
        $signed = in_array($r['movement_type'], $IN_TYPES, true) ? (float)$r['quantity'] : -(float)$r['quantity'];
        $after  = $before + $signed;
        $balUpd->execute([$before, $after, $r['movement_id']]);
        $running[$key] = $after;
        $rebalanced++;
    }
    echo "  Recomputed running balance on $rebalanced row(s).\n";

    $pdo->commit();
    echo "Migration complete.\n";

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "MIGRATION FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
