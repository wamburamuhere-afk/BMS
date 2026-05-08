<?php
require_once 'roots.php';
try {
    // Modify column to have a default value of 0.00
    $pdo->exec("ALTER TABLE project_milestones MODIFY COLUMN weight_percent decimal(5,2) NOT NULL DEFAULT 0.00");
    echo json_encode(['success' => true, 'message' => 'Database schema updated successfully']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
