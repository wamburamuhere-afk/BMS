<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: create dedicated quotations + quotation_items tables...\n";

try {
    // 1. Create the quotations table mirroring sales_orders exactly.
    //    CREATE TABLE ... LIKE guarantees an identical structure.
    $pdo->exec("CREATE TABLE IF NOT EXISTS quotations LIKE sales_orders");
    echo "Table quotations ready.\n";

    // 2. Create the quotation_items table mirroring sales_order_items.
    $pdo->exec("CREATE TABLE IF NOT EXISTS quotation_items LIKE sales_order_items");
    echo "Table quotation_items ready.\n";

    // 3. Copy existing quotations out of sales_orders (idempotent + non-destructive).
    //    Use the INTERSECTION of columns present on both tables — over time the
    //    sales_orders table has gained new columns (workflow signatures, etc.)
    //    via later migrations that don't touch `quotations`, so a naive
    //    SHOW COLUMNS FROM sales_orders list would reference columns that don't
    //    exist on the destination and blow up the INSERT. The intersection
    //    keeps this safe to re-run forever, regardless of schema drift.
    //    The source rows are deliberately LEFT in sales_orders untouched; they
    //    are already excluded from the Sales Orders list (WHERE is_quote = 0).
    $soCols   = $pdo->query("SHOW COLUMNS FROM sales_orders")->fetchAll(PDO::FETCH_COLUMN);
    $quoCols  = $pdo->query("SHOW COLUMNS FROM quotations")->fetchAll(PDO::FETCH_COLUMN);
    $shared   = array_values(array_intersect($soCols, $quoCols));
    $soColSql = '`' . implode('`,`', $shared) . '`';
    $movedHeaders = $pdo->exec("
        INSERT IGNORE INTO quotations ($soColSql)
        SELECT $soColSql FROM sales_orders WHERE is_quote = 1
    ");
    echo "Copied {$movedHeaders} existing quotation header row(s) into quotations.\n";

    // 4. Copy the matching line items. Same intersection guard against schema
    //    drift on the items tables.
    $soItemCols  = $pdo->query("SHOW COLUMNS FROM sales_order_items")->fetchAll(PDO::FETCH_COLUMN);
    $quoItemCols = $pdo->query("SHOW COLUMNS FROM quotation_items")->fetchAll(PDO::FETCH_COLUMN);
    $sharedItems = array_values(array_intersect($soItemCols, $quoItemCols));
    $itemColSql  = '`' . implode('`,`', $sharedItems) . '`';
    $movedItems = $pdo->exec("
        INSERT IGNORE INTO quotation_items ($itemColSql)
        SELECT $itemColSql FROM sales_order_items
        WHERE order_id IN (SELECT sales_order_id FROM sales_orders WHERE is_quote = 1)
    ");
    echo "Copied {$movedItems} existing quotation item row(s) into quotation_items.\n";

    // 5. Add the quotation-specific `quote_valid_until` column (the "Valid Until"
    //    date). sales_orders never actually had this column, so it is added here
    //    on the dedicated quotations table only. Idempotent via the SHOW COLUMNS
    //    check.
    $hasValidUntil = $pdo->query("SHOW COLUMNS FROM quotations LIKE 'quote_valid_until'")->fetch();
    if (!$hasValidUntil) {
        $pdo->exec("ALTER TABLE quotations ADD COLUMN quote_valid_until DATE NULL");
        echo "Added quote_valid_until column to quotations.\n";
    } else {
        echo "Column quote_valid_until already present.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
