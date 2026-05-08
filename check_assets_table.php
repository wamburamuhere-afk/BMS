<?php
require_once 'roots.php';
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'assets'");
    if ($stmt->fetch()) {
        echo "Table 'assets' exists.\n";
        $stmt = $pdo->query("DESCRIBE assets");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo $row['Field'] . " - " . $row['Type'] . "\n";
        }
    } else {
        echo "Table 'assets' does not exist.\n";
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
