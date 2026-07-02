<?php
require_once 'roots.php';
$stmt = $pdo->query("DESCRIBE journal_entries");
echo "### journal_entries ###\n";
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT);

$stmt = $pdo->query("DESCRIBE journal_entry_items");
echo "\n\n### journal_entry_items ###\n";
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT);
?>
