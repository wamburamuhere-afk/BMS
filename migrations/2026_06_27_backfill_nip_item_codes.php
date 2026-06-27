<?php
// Migration: backfill contract_item_no for all NIP products that have none
require_once __DIR__ . '/../roots.php';

try {
    $stmt = $pdo->query(
        "SELECT product_id FROM products
         WHERE is_service = 1
           AND (contract_item_no IS NULL OR contract_item_no = '')
         ORDER BY product_id ASC"
    );
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($rows)) {
        echo "OK: no products need backfilling\n";
        exit(0);
    }

    $upd = $pdo->prepare(
        "UPDATE products SET contract_item_no = ? WHERE product_id = ?"
    );
    foreach ($rows as $id) {
        $code = 'NIP-' . str_pad($id, 5, '0', STR_PAD_LEFT);
        $upd->execute([$code, $id]);
    }

    echo "OK: backfilled " . count($rows) . " product(s) with Item Codes\n";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
