<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: seed customer_type + 'cash' term into form_lookups...\n";

// customer_type is its own self-growing list; payment_terms + currency are shared
// with suppliers/sub-contractors. Customer payment terms historically include
// "cash" (suppliers use "cod"), so ensure "cash" exists in the shared list.
try {
    if ((int)$pdo->query("SHOW TABLES LIKE 'form_lookups'")->rowCount() === 0) {
        echo "form_lookups table missing — run 2026_06_26_form_lookups.php first.\n";
        exit(1);
    }

    $ins = $pdo->prepare("INSERT IGNORE INTO form_lookups (lookup_key, value, label, sort_order) VALUES (?,?,?,?)");
    $n = 0;

    $types = [
        ['individual','Individual'], ['business','Business'],
        ['government','Government'], ['ngo','NGO'],
    ];
    $i = 0;
    foreach ($types as $t) { $ins->execute(['customer_type', $t[0], $t[1], $i++]); $n += $ins->rowCount(); }

    // Add "cash" to the shared payment_terms list (after cod), keeping existing order.
    $maxOrd = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0)+1 FROM form_lookups WHERE lookup_key='payment_terms'")->fetchColumn();
    $ins->execute(['payment_terms', 'cash', 'Cash', $maxOrd]); $n += $ins->rowCount();

    echo "Seeded $n new option(s).\n";

    // Widen customers.customer_type from a restrictive ENUM to VARCHAR so the
    // "Other → type new" flow can save a typed value (matches suppliers/sub-
    // contractors which are varchar(100)). Idempotent: only alters if still ENUM.
    $col = $pdo->query("SHOW COLUMNS FROM customers LIKE 'customer_type'")->fetch(PDO::FETCH_ASSOC);
    if ($col && stripos($col['Type'], 'enum') === 0) {
        $pdo->exec("ALTER TABLE customers MODIFY COLUMN customer_type VARCHAR(100) NULL DEFAULT 'business'");
        echo "Widened customers.customer_type ENUM -> VARCHAR(100).\n";
    } else {
        echo "customers.customer_type already non-ENUM - skipped.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
