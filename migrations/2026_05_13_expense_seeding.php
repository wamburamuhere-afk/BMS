<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting Initial Data Seeding (Sub-Phase 1.2)...\n";

$data = [
    'Operating' => [
        'Fuel & Lubricants',
        'Maintenance & Repairs',
        'Site Supplies',
        'Logistics',
        'Labor Wages'
    ],
    'Fixed' => [
        'Office Rent',
        'Insurance Premiums',
        'Security Services',
        'Internet & Utilities'
    ],
    'Administrative' => [
        'Office Stationery',
        'Staff Welfare',
        'Marketing & Ads',
        'Legal & Audit Fees'
    ]
];

try {
    $pdo->beginTransaction();

    foreach ($data as $typeName => $categories) {
        // Insert or Update Type
        $stmt = $pdo->prepare("INSERT INTO expense_types (name) VALUES (?) ON DUPLICATE KEY UPDATE name=VALUES(name)");
        $stmt->execute([$typeName]);
        
        // Get the ID
        $stmt = $pdo->prepare("SELECT id FROM expense_types WHERE name = ?");
        $stmt->execute([$typeName]);
        $typeId = $stmt->fetchColumn();

        echo "✓ Seeding Categories for '$typeName' (ID: $typeId)...\n";

        foreach ($categories as $catName) {
            $stmt = $pdo->prepare("INSERT IGNORE INTO expense_categories (type_id, name) VALUES (?, ?)");
            $stmt->execute([$typeId, $catName]);
            echo "  - $catName\n";
        }
    }

    $pdo->commit();
    echo "Seeding completed successfully.\n";

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "Seeding failed: " . $e->getMessage() . "\n";
    exit(1);
}
