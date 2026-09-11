<?php
/**
 * migrations/2026_09_11_pos_restaurant_module_legacy_db.php
 *
 * Mirrors migrations/tenant/2026_09_11_pos_restaurant_module.php
 * (Phase 30 backend/schema — pos_upgrade_plan.md §9) onto the LEGACY /
 * non-tenant database. See migrations/2026_09_10_pos_product_professional_fields_legacy_db.php
 * for why this mirror exists: core/tenant_migration_runner.php only ever
 * touches databases registered in the `tenants` control table, so a host
 * running in single-tenant mode needs its own copy of every tenant migration.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: POS restaurant module on the legacy database (Phase 30 backend)...\n";

try {
    $col = $pdo->query("SHOW COLUMNS FROM warehouses LIKE 'pos_mode'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE warehouses ADD COLUMN pos_mode ENUM('retail','restaurant','hybrid') NOT NULL DEFAULT 'retail'");
        echo "  + warehouses.pos_mode added.\n";
    } else {
        echo "  · warehouses.pos_mode already present.\n";
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `restaurant_floors` (
            `floor_id` INT NOT NULL AUTO_INCREMENT,
            `warehouse_id` INT NOT NULL,
            `name` VARCHAR(100) NOT NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`floor_id`),
            KEY `idx_rf_warehouse` (`warehouse_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + table restaurant_floors ready.\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `restaurant_tables` (
            `table_id` INT NOT NULL AUTO_INCREMENT,
            `warehouse_id` INT NOT NULL,
            `floor_id` INT NOT NULL,
            `table_number` VARCHAR(50) NOT NULL,
            `seats` INT NOT NULL DEFAULT 2,
            `status` ENUM('available','occupied','reserved','cleaning') NOT NULL DEFAULT 'available',
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`table_id`),
            KEY `idx_rt_warehouse` (`warehouse_id`),
            KEY `idx_rt_floor` (`floor_id`),
            KEY `idx_rt_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + table restaurant_tables ready.\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `kitchen_stations` (
            `station_id` INT NOT NULL AUTO_INCREMENT,
            `warehouse_id` INT NOT NULL,
            `name` VARCHAR(100) NOT NULL,
            `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`station_id`),
            KEY `idx_ks_warehouse` (`warehouse_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + table kitchen_stations ready.\n";

    $col = $pdo->query("SHOW COLUMNS FROM products LIKE 'kitchen_station_id'")->fetch();
    if (!$col) {
        $anchor = $pdo->query("SHOW COLUMNS FROM products LIKE 'track_serials'")->fetch();
        $position = $anchor ? " AFTER track_serials" : "";
        $pdo->exec("ALTER TABLE products ADD COLUMN kitchen_station_id INT NULL DEFAULT NULL$position");
        echo "  + products.kitchen_station_id added" . ($position ? "" : " (anchor column 'track_serials' not found — appended at end instead)") . ".\n";
    } else {
        echo "  · products.kitchen_station_id already present.\n";
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `kitchen_tickets` (
            `ticket_id` INT NOT NULL AUTO_INCREMENT,
            `hold_id` INT DEFAULT NULL,
            `warehouse_id` INT NOT NULL,
            `station_id` INT NOT NULL,
            `table_id` INT DEFAULT NULL,
            `status` ENUM('queued','preparing','ready','served') NOT NULL DEFAULT 'queued',
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`ticket_id`),
            KEY `idx_kt_warehouse_station_status` (`warehouse_id`, `station_id`, `status`),
            KEY `idx_kt_hold` (`hold_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + table kitchen_tickets ready.\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `kitchen_ticket_items` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `ticket_id` INT NOT NULL,
            `product_id` INT NOT NULL,
            `quantity` DECIMAL(10,3) NOT NULL DEFAULT 1.000,
            `modifiers_summary` VARCHAR(500) DEFAULT NULL,
            `status` ENUM('queued','preparing','ready','served') NOT NULL DEFAULT 'queued',
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_kti_ticket` (`ticket_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + table kitchen_ticket_items ready.\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `modifier_groups` (
            `group_id` INT NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(150) NOT NULL,
            `selection_type` ENUM('single','multiple') NOT NULL DEFAULT 'single',
            `min_select` INT NOT NULL DEFAULT 0,
            `max_select` INT NOT NULL DEFAULT 1,
            `is_required` TINYINT(1) NOT NULL DEFAULT 0,
            `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
            `created_by` INT DEFAULT NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`group_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + table modifier_groups ready.\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `modifier_options` (
            `option_id` INT NOT NULL AUTO_INCREMENT,
            `group_id` INT NOT NULL,
            `name` VARCHAR(150) NOT NULL,
            `price_adjustment` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`option_id`),
            KEY `idx_mo_group` (`group_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + table modifier_options ready.\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `product_modifier_groups` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `product_id` INT NOT NULL,
            `group_id` INT NOT NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_pmg_product_group` (`product_id`, `group_id`),
            KEY `idx_pmg_group` (`group_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + table product_modifier_groups ready.\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `pos_sale_item_modifiers` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `sale_item_id` INT NOT NULL,
            `option_id` INT DEFAULT NULL,
            `option_name` VARCHAR(150) NOT NULL,
            `price_adjustment` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_psim_sale_item` (`sale_item_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + table pos_sale_item_modifiers ready.\n";

    $col = $pdo->query("SHOW COLUMNS FROM pos_sales LIKE 'sale_type'")->fetch();
    if ($col && strpos($col['Type'], "'dine_in'") === false) {
        $pdo->exec("ALTER TABLE pos_sales MODIFY COLUMN sale_type ENUM('walk_in','customer','online','delivery','dine_in','take_away') DEFAULT 'walk_in'");
        echo "  + pos_sales.sale_type ENUM extended with 'dine_in','take_away'.\n";
    } else {
        echo "  · pos_sales.sale_type already includes 'dine_in'/'take_away'.\n";
    }

    $col = $pdo->query("SHOW COLUMNS FROM pos_sales LIKE 'table_id'")->fetch();
    if (!$col) {
        $anchor = $pdo->query("SHOW COLUMNS FROM pos_sales LIKE 'warehouse_id'")->fetch();
        $position = $anchor ? " AFTER warehouse_id" : "";
        $pdo->exec("ALTER TABLE pos_sales ADD COLUMN table_id INT NULL DEFAULT NULL$position");
        echo "  + pos_sales.table_id added.\n";
    } else {
        echo "  · pos_sales.table_id already present.\n";
    }

    $col = $pdo->query("SHOW COLUMNS FROM pos_sales LIKE 'assigned_to'")->fetch();
    if (!$col) {
        $anchor = $pdo->query("SHOW COLUMNS FROM pos_sales LIKE 'user_id'")->fetch();
        $position = $anchor ? " AFTER user_id" : "";
        $pdo->exec("ALTER TABLE pos_sales ADD COLUMN assigned_to INT NULL DEFAULT NULL$position");
        echo "  + pos_sales.assigned_to added.\n";
    } else {
        echo "  · pos_sales.assigned_to already present.\n";
    }

    $col = $pdo->query("SHOW COLUMNS FROM pos_held_sales LIKE 'warehouse_id'")->fetch();
    if (!$col) {
        $anchor = $pdo->query("SHOW COLUMNS FROM pos_held_sales LIKE 'customer_id'")->fetch();
        $position = $anchor ? " AFTER customer_id" : "";
        $pdo->exec("ALTER TABLE pos_held_sales ADD COLUMN warehouse_id INT NULL DEFAULT NULL$position");
        echo "  + pos_held_sales.warehouse_id added.\n";
    } else {
        echo "  · pos_held_sales.warehouse_id already present.\n";
    }
    $col = $pdo->query("SHOW COLUMNS FROM pos_held_sales LIKE 'table_id'")->fetch();
    if (!$col) {
        $anchor = $pdo->query("SHOW COLUMNS FROM pos_held_sales LIKE 'warehouse_id'")->fetch();
        $position = $anchor ? " AFTER warehouse_id" : "";
        $pdo->exec("ALTER TABLE pos_held_sales ADD COLUMN table_id INT NULL DEFAULT NULL$position");
        echo "  + pos_held_sales.table_id added.\n";
    } else {
        echo "  · pos_held_sales.table_id already present.\n";
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `restaurant_reservations` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `table_id` INT NOT NULL,
            `warehouse_id` INT NOT NULL,
            `customer_id` INT DEFAULT NULL,
            `customer_name` VARCHAR(150) DEFAULT NULL,
            `customer_phone` VARCHAR(30) DEFAULT NULL,
            `reservation_time` DATETIME NOT NULL,
            `party_size` INT NOT NULL DEFAULT 1,
            `status` ENUM('booked','seated','completed','cancelled','no_show') NOT NULL DEFAULT 'booked',
            `notes` TEXT DEFAULT NULL,
            `created_by` INT DEFAULT NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_rr_warehouse_time` (`warehouse_id`, `reservation_time`),
            KEY `idx_rr_table` (`table_id`),
            KEY `idx_rr_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + table restaurant_reservations ready.\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `restaurant_reservation_reminders` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `reservation_id` INT NOT NULL,
            `milestone` INT NOT NULL,
            `sent_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_rrr_reservation_milestone` (`reservation_id`, `milestone`),
            KEY `idx_rrr_reservation` (`reservation_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    echo "  + table restaurant_reservation_reminders ready.\n";

    $exists = $pdo->prepare("SELECT 1 FROM notification_events WHERE event_key = ?");
    $exists->execute(['restaurant.reservation_upcoming']);
    if (!$exists->fetchColumn()) {
        $pdo->prepare("
            INSERT INTO notification_events (event_key, title, description, module, page_key, required_verb, default_severity, scope_aware, is_active, created_at)
            VALUES ('restaurant.reservation_upcoming', 'Reservation upcoming', 'A restaurant table reservation is coming up soon', 'POS', 'restaurant_pos', 'view', 'medium', 1, 1, NOW())
        ")->execute();
        echo "  + notification_events row 'restaurant.reservation_upcoming' seeded.\n";
    } else {
        echo "  · notification_events row 'restaurant.reservation_upcoming' already present.\n";
    }

    $exists = $pdo->prepare("SELECT 1 FROM permissions WHERE page_key = ?");
    $exists->execute(['restaurant_pos']);
    if (!$exists->fetchColumn()) {
        $pdo->prepare("
            INSERT INTO permissions (permission_name, page_key, page_name, description, module_name, is_hidden, created_at)
            VALUES ('', 'restaurant_pos', 'Restaurant POS', 'Floors/Tables, Kitchen Display, Modifier Groups and Reservations for a restaurant/hybrid warehouse', 'Settings', 0, NOW())
        ")->execute();
        echo "  + permission 'restaurant_pos' seeded.\n";
    } else {
        echo "  · permission 'restaurant_pos' already present.\n";
    }

    $warehouses = $pdo->query("SELECT warehouse_id FROM warehouses")->fetchAll(PDO::FETCH_COLUMN);
    $checkFloor = $pdo->prepare("SELECT 1 FROM restaurant_floors WHERE warehouse_id = ?");
    $insFloor   = $pdo->prepare("INSERT INTO restaurant_floors (warehouse_id, name, sort_order) VALUES (?, 'Main Floor', 0)");
    $checkStn   = $pdo->prepare("SELECT 1 FROM kitchen_stations WHERE warehouse_id = ?");
    $insStn     = $pdo->prepare("INSERT INTO kitchen_stations (warehouse_id, name) VALUES (?, 'Main Kitchen')");
    $seededFloors = 0; $seededStations = 0;
    foreach ($warehouses as $wid) {
        $checkFloor->execute([$wid]);
        if (!$checkFloor->fetchColumn()) { $insFloor->execute([$wid]); $seededFloors++; }
        $checkStn->execute([$wid]);
        if (!$checkStn->fetchColumn()) { $insStn->execute([$wid]); $seededStations++; }
    }
    echo "  + seeded {$seededFloors} default floor(s) and {$seededStations} default kitchen station(s) across " . count($warehouses) . " warehouse(s).\n";

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
