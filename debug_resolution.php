<?php
require_once 'roots.php';
global $pdo;

$account_type_name = 'asset';
$stmt = $pdo->prepare("SELECT type_id FROM account_types WHERE type_name = ?");
$stmt->execute([$account_type_name]);
$type = $stmt->fetch(PDO::FETCH_ASSOC);

echo "Resolved type_id for 'asset': " . ($type ? $type['type_id'] : 'NOT FOUND') . "\n";

$stmt = $pdo->query("SELECT * FROM account_types");
echo "All account types:\n";
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    print_r($row);
}
?>
