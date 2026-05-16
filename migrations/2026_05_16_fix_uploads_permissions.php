<?php
require_once __DIR__ . '/../roots.php';

echo "Starting migration: Fix uploads/purchase_orders directory permissions...\n";

try {
    $dir = __DIR__ . '/../uploads/purchase_orders';

    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
        echo "Created uploads/purchase_orders directory.\n";
    }

    if (!chmod($dir, 0775)) {
        // chmod may fail if process doesn't own the dir — try 0777 as fallback
        chmod($dir, 0777);
        echo "Set uploads/purchase_orders permissions to 0777 (fallback).\n";
    } else {
        echo "Set uploads/purchase_orders permissions to 0775.\n";
    }

    echo "Migration complete.\n";
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
