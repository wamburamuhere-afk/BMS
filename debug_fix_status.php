<?php
require_once 'roots.php';
global $pdo;

echo "Purchase Returns with blank or suspicious status:\n";
$stmt = $pdo->query("SELECT purchase_return_id, return_number, status FROM purchase_returns WHERE status = '' OR status IS NULL OR status NOT IN ('pending', 'approved', 'rejected', 'completed', 'cancelled')");
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($results);

if (!empty($results)) {
    echo "\nFixing these to 'pending' if they are indeed blank...\n";
    $stmtUpdate = $pdo->prepare("UPDATE purchase_returns SET status = 'pending' WHERE purchase_return_id = ?");
    foreach ($results as $row) {
        $stmtUpdate->execute([$row['purchase_return_id']]);
        echo "Updated Return ID {$row['purchase_return_id']} to 'pending'.\n";
    }
}
?>
