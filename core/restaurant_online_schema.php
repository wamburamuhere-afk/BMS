<?php
/**
 * core/restaurant_online_schema.php
 *
 * Schema for the restaurant online menu & ordering (restaurant_online_menu_plan.md).
 * One body shared by the tenant migration and its legacy-database twin, so the
 * two can never drift. Purely additive and idempotent: every column is nullable
 * or defaults to "off", so no existing shop changes until its owner switches
 * the online menu on.
 */

if (!function_exists('restaurantOnlineEnsureSchema')) {
    /**
     * @param callable(string):void $log
     * @return bool true when everything is in place
     */
    function restaurantOnlineEnsureSchema(PDO $pdo, callable $log): bool
    {
        if (!$pdo->query("SHOW TABLES LIKE 'restaurant_tables'")->fetch()
            || !$pdo->query("SHOW COLUMNS FROM warehouses LIKE 'pos_mode'")->fetch()) {
            $log("  ~ restaurant module schema absent — nothing to do.");
            return false;
        }

        $addCol = function (string $table, string $col, string $ddl) use ($pdo, $log): void {
            if ($pdo->query("SHOW COLUMNS FROM `$table` LIKE '$col'")->fetch()) {
                $log("  ~ $table.$col already exists.");
                return;
            }
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN $ddl");
            $log("  + $table.$col added.");
        };
        $addIdx = function (string $table, string $name, string $ddl) use ($pdo, $log): void {
            if ($pdo->query("SHOW INDEX FROM `$table` WHERE Key_name = '$name'")->fetch()) {
                $log("  ~ index $table.$name already exists.");
                return;
            }
            $pdo->exec("ALTER TABLE `$table` ADD $ddl");
            $log("  + index $table.$name added.");
        };

        // Shop-level link + switches. All off by default.
        $addCol('warehouses', 'online_menu_token',   "`online_menu_token` CHAR(32) NULL DEFAULT NULL");
        $addCol('warehouses', 'online_menu_enabled', "`online_menu_enabled` TINYINT(1) NOT NULL DEFAULT 0");
        $addCol('warehouses', 'online_order_table',  "`online_order_table` TINYINT(1) NOT NULL DEFAULT 0");
        $addCol('warehouses', 'online_order_pickup', "`online_order_pickup` TINYINT(1) NOT NULL DEFAULT 0");
        $addIdx('warehouses', 'uq_wh_online_menu_token', "UNIQUE INDEX `uq_wh_online_menu_token` (`online_menu_token`)");

        // One QR per table.
        $addCol('restaurant_tables', 'qr_token', "`qr_token` CHAR(32) NULL DEFAULT NULL");
        $addIdx('restaurant_tables', 'uq_rt_qr_token', "UNIQUE INDEX `uq_rt_qr_token` (`qr_token`)");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `restaurant_online_orders` (
                `order_id`       INT NOT NULL AUTO_INCREMENT,
                `warehouse_id`   INT NOT NULL,
                `table_id`       INT NULL DEFAULT NULL,
                `order_type`     ENUM('table','pickup') NOT NULL DEFAULT 'table',
                `order_code`     VARCHAR(20) NOT NULL,
                `public_ref`     CHAR(32) NOT NULL,
                `customer_name`  VARCHAR(60) NULL DEFAULT NULL,
                `customer_phone` VARCHAR(20) NULL DEFAULT NULL,
                `note`           VARCHAR(200) NULL DEFAULT NULL,
                `items_json`     MEDIUMTEXT NOT NULL,
                `subtotal`       DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                `status`         ENUM('pending','accepted','ready','completed','rejected','cancelled') NOT NULL DEFAULT 'pending',
                `reject_reason`  VARCHAR(200) NULL DEFAULT NULL,
                `hold_id`        INT NULL DEFAULT NULL,
                `handled_by`     INT NULL DEFAULT NULL,
                `client_hash`    CHAR(64) NOT NULL,
                `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`     DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                `accepted_at`    DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (`order_id`),
                UNIQUE KEY `uq_roo_public_ref` (`public_ref`),
                KEY `idx_roo_wh_status` (`warehouse_id`, `status`),
                KEY `idx_roo_client` (`client_hash`, `created_at`),
                KEY `idx_roo_table_status` (`table_id`, `status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $log("  + table restaurant_online_orders ready.");
        return true;
    }
}
