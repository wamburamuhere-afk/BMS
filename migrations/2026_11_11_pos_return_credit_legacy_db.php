<?php
/**
 * migrations/2026_11_11_pos_return_credit_legacy_db.php
 *
 * pos_sale_payments.payment_method gains 'return_credit': when goods come back on a credit sale that is
 * not fully paid, the return first comes off what the customer owes. That part is recorded as a
 * 'return_credit' line (reference = the refund number), so every balance-due figure drops by itself.
 * Only adds an enum value; existing rows are untouched. Idempotent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: POS return credit (legacy database)...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'pos_sale_payments'")->fetch()) {
        echo "  ~ pos_sale_payments not present — nothing to do.\n";
    } else {
        $col = $pdo->query("SHOW COLUMNS FROM pos_sale_payments LIKE 'payment_method'")->fetch(PDO::FETCH_ASSOC);
        if ($col && strpos((string)$col['Type'], "'return_credit'") !== false) {
            echo "  ~ 'return_credit' already allowed.\n";
        } else {
            // Keep every value this database already has; only add the new one.
            if (!preg_match('/^enum\((.*)\)$/i', (string)$col['Type'], $m)) throw new PDOException('payment_method is not an ENUM: ' . $col['Type']);
            $pdo->exec("ALTER TABLE pos_sale_payments MODIFY payment_method ENUM(" . $m[1] . ",'return_credit') NOT NULL DEFAULT 'cash'");
            echo "  + pos_sale_payments.payment_method now allows 'return_credit'.\n";
        }
    }
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
