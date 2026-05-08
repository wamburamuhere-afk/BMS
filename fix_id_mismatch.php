<?php
// fix_id_column.php
require_once 'roots.php';
global $pdo;

try {
    echo "Checking 'transactions' table ID column name...\n";
    $stmt = $pdo->query("DESCRIBE transactions");
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $id_col = '';
    foreach ($cols as $c) {
        if ($c['Key'] === 'PRI') {
            $id_col = $c['Field'];
            break;
        }
    }

    echo "Primary Key found: $id_col\n";
    
    if ($id_col === 'id') {
        echo "The column is named 'id', but the code expects 'transaction_id'. Renaming now...\n";
        $pdo->exec("ALTER TABLE transactions CHANGE COLUMN `id` `transaction_id` INT AUTO_INCREMENT");
        echo "Renamed 'id' to 'transaction_id'.\n";
    } else {
        echo "Column name is already '$id_col'. No rename needed.\n";
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
?>
