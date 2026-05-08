<?php
include 'roots.php';
global $pdo;

try {
    $stmt = $pdo->query("SELECT scope_type, COUNT(*) as count FROM project_milestones GROUP BY scope_type");
    $counts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Counts by scope_type:\n";
    print_r($counts);

    $stmt2 = $pdo->query("SELECT * FROM project_milestones LIMIT 5");
    $milestones = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    echo "\nSample records:\n";
    print_r($milestones);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
