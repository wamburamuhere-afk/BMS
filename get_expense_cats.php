<?php
require_once 'roots.php';
$stmt = $pdo->query("SELECT category_id, category_name FROM expense_categories WHERE status = 'active' ORDER BY category_name");
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($categories);
?>
