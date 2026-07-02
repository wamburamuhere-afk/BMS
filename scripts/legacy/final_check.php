<?php
require_once 'includes/config.php';
$stmt = $pdo->query("SELECT DISTINCT module_name FROM permissions");
$modules = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo "Current Modules: " . implode(', ', $modules) . "\n\n";

$stmt = $pdo->query("SELECT page_key, module_name FROM permissions LIMIT 20");
$perms = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach($perms as $p) {
    echo "{$p['page_key']} -> {$p['module_name']}\n";
}
?>
