<?php
// api/get_account_types.php
require_once __DIR__ . '/../../roots.php';
global $pdo, $pdo_accounts;

try {
    $stmt = $pdo->query("SELECT * FROM account_types ORDER BY type_name");
    $types = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => $types
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
