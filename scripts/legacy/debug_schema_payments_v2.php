<?php
require_once __DIR__ . '/includes/config.php';
try {
    $stmt = $pdo->query("DESCRIBE payments");
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        echo $row['Field'] . " - " . $row['Type'] . "\n";
    }
} catch (Exception $e) {
    echo $e->getMessage();
}
