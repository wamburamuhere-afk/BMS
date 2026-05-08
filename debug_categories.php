<?php
require_once 'roots.php';
global $pdo;

echo "--- Account Categories ---\n";
$stmt = $pdo->query("SELECT * FROM account_categories");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    print_r($row);
}
?>
