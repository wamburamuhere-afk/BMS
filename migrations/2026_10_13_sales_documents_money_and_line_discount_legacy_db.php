<?php
/**
 * migrations/2026_10_13_sales_documents_money_and_line_discount_legacy_db.php
 * Legacy-DB mirror of migrations/tenant/2026_10_13_sales_documents_money_and_line_discount.php.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting legacy migration: sales_documents_money_and_line_discount...
";

// MODIFY keeps each column's own NULL-ability and default; only columns still
// narrower than DECIMAL(15,2) are touched, so re-running is a no-op.
$widen = [
    'quotation_items'   => ['unit_price', 'tax_amount', 'discount_amount', 'line_total'],
    'sales_order_items' => ['unit_price', 'tax_amount', 'discount_amount', 'line_total'],
    'invoice_items'     => ['unit_price', 'tax_amount', 'line_total'],
    'invoices'          => ['subtotal', 'tax_amount', 'discount_amount', 'shipping_cost', 'grand_total', 'paid_amount', 'balance_due', 'output_vat_posted'],
];
$hasTable = fn(string $t): bool => (bool)$pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->fetch();
$column   = function (string $t, string $c) use ($pdo) {
    $st = $pdo->query("SHOW COLUMNS FROM `$t` LIKE " . $pdo->quote($c));
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
};

try {
    foreach ($widen as $table => $cols) {
        if (!$hasTable($table)) { echo "  · $table absent — skipped\n"; continue; }
        foreach ($cols as $col) {
            $info = $column($table, $col);
            if (!$info) continue;
            if (!preg_match('/^decimal\((\d+),(\d+)\)/i', $info['Type'], $m)) continue;
            if ((int)$m[1] >= 15) continue;
            $null = strtoupper($info['Null']) === 'YES' ? 'NULL' : 'NOT NULL';
            $def  = $info['Default'] === null
                ? (strtoupper($info['Null']) === 'YES' ? ' DEFAULT NULL' : '')
                : ' DEFAULT ' . $pdo->quote($info['Default']);
            $pdo->exec("ALTER TABLE `$table` MODIFY `$col` DECIMAL(15,2) $null$def");
            echo "  + $table.$col widened to DECIMAL(15,2)\n";
        }
    }

    if ($hasTable('invoice_items')) {
        if (!$column('invoice_items', 'discount_percent')) {
            $pdo->exec("ALTER TABLE invoice_items ADD COLUMN discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER tax_amount");
            echo "  + invoice_items.discount_percent added\n";
        }
        if (!$column('invoice_items', 'discount_amount')) {
            $pdo->exec("ALTER TABLE invoice_items ADD COLUMN discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER discount_percent");
            echo "  + invoice_items.discount_amount added\n";
        }
    }

    // Cached invoiced quantity per sales-order line, from invoices that are not
    // cancelled. Recomputed outright, so it is correct however often it runs.
    if ($hasTable('sales_order_items') && $hasTable('invoice_items') && $hasTable('invoices')) {
        $n = $pdo->exec("
            UPDATE sales_order_items soi
               SET soi.quantity_invoiced = (
                   SELECT COALESCE(SUM(ii.quantity), 0)
                     FROM invoice_items ii
                     JOIN invoices i ON i.invoice_id = ii.invoice_id AND i.status <> 'cancelled'
                    WHERE ii.order_item_id = soi.order_item_id)
        ");
        echo "  · sales_order_items.quantity_invoiced recomputed ($n row(s) changed)\n";
    }

    echo "Migration complete: sales_documents_money_and_line_discount.\n";
} catch (PDOException $e) {
    echo "Migration FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
