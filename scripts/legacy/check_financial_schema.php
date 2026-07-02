<?php
require_once 'roots.php';
global $pdo;

function checkTable($name) {
    global $pdo;
    try {
        $stmt = $pdo->query("DESCRIBE `$name` ");
        echo "### $name ###\n";
        print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) {
        echo "### $name: Error - " . $e->getMessage() . "\n";
    }
}

checkTable('expenses');
checkTable('transactions');
checkTable('books_transactions');
?>
