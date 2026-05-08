<?php
require __DIR__ . '/../roots.php';
global $pdo;

try {
    echo "<pre>";
    echo "Starting migration: Material Lists...\n\n";

    // ── Table 1: nip_material_lists ────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS nip_material_lists (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            name        VARCHAR(500) NOT NULL,
            project_id  INT NULL DEFAULT NULL,
            created_by  INT NULL DEFAULT NULL,
            created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (project_id) REFERENCES projects(project_id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✔ Table created (or already exists): nip_material_lists\n";

    // ── Table 2: nip_material_list_nips ────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS nip_material_list_nips (
            id               INT AUTO_INCREMENT PRIMARY KEY,
            material_list_id INT NOT NULL,
            nip_product_id   INT NOT NULL,
            quantity         DECIMAL(15,4) NOT NULL DEFAULT 1.0000,
            created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (material_list_id) REFERENCES nip_material_lists(id) ON DELETE CASCADE,
            FOREIGN KEY (nip_product_id)   REFERENCES products(product_id)    ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✔ Table created (or already exists): nip_material_list_nips\n";

    // ── Table 3: nip_material_component_status ─────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS nip_material_component_status (
            id                   INT AUTO_INCREMENT PRIMARY KEY,
            component_product_id INT NOT NULL UNIQUE,
            status               ENUM('pending','approved') NOT NULL DEFAULT 'pending',
            updated_by           INT NULL DEFAULT NULL,
            updated_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (component_product_id) REFERENCES products(product_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✔ Table created (or already exists): nip_material_component_status\n";

    echo "\n✔ Migration complete. All 3 tables are ready.\n";
    echo "</pre>";

} catch (Exception $e) {
    die("<pre>Migration failed: " . $e->getMessage() . "</pre>");
}
