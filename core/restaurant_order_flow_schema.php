<?php
/**
 * core/restaurant_order_flow_schema.php — schema + rights for the restaurant order flow
 * (restaurant_order_flow_plan.md Phase 1). ONE body for the tenant migration, its legacy twin
 * and the runtime (ready-made roles when the setting is first turned on).
 *
 * Dependency-free on purpose: a tenant migration must never load roots.php (it reconnects $pdo
 * to the main database), so everything here only uses the $pdo it is given.
 *
 *  - kitchen_tickets.status gains 'pending' (waiting for the cashier) and 'cancelled';
 *    who/when columns; closed_at (the ticket's order ended: bill closed or order cancelled).
 *  - kitchen_ticket_items.status gains the same two values.
 *  - restaurant_order_cancellations: every cancelled kitchen line (before or after the kitchen
 *    started), with its cost and — for stock items — the ledger entry of the waste.
 *  - permission rows restaurant_orders + kitchen_display, and back-compat grants so no role
 *    loses what it can do today.
 *  - notification events restaurant.order_to_confirm / restaurant.order_returned.
 *  - stale tickets of tables with no open order are closed (criteria-based, idempotent).
 * Every step is idempotent.
 */

if (!function_exists('rofsStatusEnum')) {
    function rofsStatusEnum(): string
    {
        return "ENUM('pending','queued','preparing','ready','served','cancelled')";
    }
}

if (!function_exists('rofsHasColumn')) {
    function rofsHasColumn(PDO $pdo, string $table, string $col): bool
    {
        $st = $pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($col));
        return (bool)$st->fetch();
    }
}

if (!function_exists('rofsHasTable')) {
    function rofsHasTable(PDO $pdo, string $table): bool
    {
        return (bool)$pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetch();
    }
}

if (!function_exists('rofsEnsureTicketSchema')) {
    /** Ticket statuses + who/when columns + cancellations table. Returns false when the restaurant tables are absent. */
    function rofsEnsureTicketSchema(PDO $pdo, callable $log): bool
    {
        if (!rofsHasTable($pdo, 'kitchen_tickets') || !rofsHasTable($pdo, 'kitchen_ticket_items')) {
            $log('  ~ kitchen_tickets absent (restaurant module never installed) — nothing to do.');
            return false;
        }
        $enum = rofsStatusEnum();
        foreach (['kitchen_tickets', 'kitchen_ticket_items'] as $t) {
            $type = (string)($pdo->query("SHOW COLUMNS FROM `$t` LIKE 'status'")->fetch(PDO::FETCH_ASSOC)['Type'] ?? '');
            if (stripos($type, "'pending'") === false || stripos($type, "'cancelled'") === false) {
                $pdo->exec("ALTER TABLE `$t` MODIFY COLUMN `status` $enum NOT NULL DEFAULT 'queued'");
                $log("  + $t.status now allows pending / cancelled.");
            }
        }
        $cols = [
            'sent_by'       => 'INT NULL DEFAULT NULL',
            'confirmed_by'  => 'INT NULL DEFAULT NULL',
            'confirmed_at'  => 'DATETIME NULL DEFAULT NULL',
            'ready_at'      => 'DATETIME NULL DEFAULT NULL',
            'served_by'     => 'INT NULL DEFAULT NULL',
            'served_at'     => 'DATETIME NULL DEFAULT NULL',
            'cancelled_by'  => 'INT NULL DEFAULT NULL',
            'cancelled_at'  => 'DATETIME NULL DEFAULT NULL',
            'cancel_reason' => 'VARCHAR(255) NULL DEFAULT NULL',
            'closed_at'     => 'DATETIME NULL DEFAULT NULL',
        ];
        foreach ($cols as $c => $def) {
            if (!rofsHasColumn($pdo, 'kitchen_tickets', $c)) {
                $pdo->exec("ALTER TABLE kitchen_tickets ADD COLUMN `$c` $def");
                $log("  + kitchen_tickets.$c added.");
            }
        }
        // order_label / sale_id come from 2026_10_31_restaurant_kitchen_ticket_label; add them here too
        // so this migration never depends on run order.
        if (!rofsHasColumn($pdo, 'kitchen_tickets', 'order_label')) {
            $pdo->exec("ALTER TABLE kitchen_tickets ADD COLUMN `order_label` VARCHAR(80) NULL DEFAULT NULL");
            $log('  + kitchen_tickets.order_label added.');
        }
        if (!rofsHasColumn($pdo, 'kitchen_tickets', 'sale_id')) {
            $pdo->exec("ALTER TABLE kitchen_tickets ADD COLUMN `sale_id` INT NULL DEFAULT NULL, ADD INDEX idx_kt_sale (sale_id)");
            $log('  + kitchen_tickets.sale_id added.');
        }
        $idx = $pdo->query("SHOW INDEX FROM kitchen_tickets WHERE Key_name = 'idx_kt_table_open'")->fetch();
        if (!$idx) {
            $pdo->exec("ALTER TABLE kitchen_tickets ADD INDEX idx_kt_table_open (table_id, closed_at)");
            $log('  + index kitchen_tickets(table_id, closed_at).');
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `restaurant_order_cancellations` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `warehouse_id` INT NOT NULL,
                `table_id` INT NULL DEFAULT NULL,
                `ticket_id` INT NULL DEFAULT NULL,
                `sale_id` INT NULL DEFAULT NULL,
                `product_id` INT NOT NULL,
                `quantity` DECIMAL(10,3) NOT NULL DEFAULT 0,
                `stage` ENUM('before_kitchen','after_kitchen') NOT NULL,
                `source` VARCHAR(30) NOT NULL DEFAULT 'cancel',
                `reason` VARCHAR(255) NULL DEFAULT NULL,
                `est_cost` DECIMAL(15,2) NOT NULL DEFAULT 0,
                `posted_cost` DECIMAL(15,2) NOT NULL DEFAULT 0,
                `journal_entry_id` INT NULL DEFAULT NULL,
                `cancelled_by` INT NULL DEFAULT NULL,
                `approved_by` INT NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_roc_wh_date` (`warehouse_id`, `created_at`),
                KEY `idx_roc_ticket` (`ticket_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $log('  + table restaurant_order_cancellations ready.');
        return true;
    }
}

if (!function_exists('rofsPermissionId')) {
    function rofsPermissionId(PDO $pdo, string $pageKey): int
    {
        $st = $pdo->prepare("SELECT permission_id FROM permissions WHERE page_key = ? LIMIT 1");
        $st->execute([$pageKey]);
        return (int)$st->fetchColumn();
    }
}

if (!function_exists('rofsRoleColumns')) {
    /** The verb columns this database's role_permissions actually has. */
    function rofsRoleColumns(PDO $pdo): array
    {
        $have = [];
        foreach ($pdo->query("SHOW COLUMNS FROM role_permissions")->fetchAll(PDO::FETCH_ASSOC) as $c) $have[$c['Field']] = true;
        return array_values(array_filter(['can_view', 'can_create', 'can_edit', 'can_delete', 'can_review', 'can_approve'], fn($c) => isset($have[$c])));
    }
}

if (!function_exists('rofsGrant')) {
    /**
     * Give a role verbs on a page key. Adds a row when none exists; when one exists, only turns
     * verbs ON (never removes what an admin granted). $verbs: ['view','create','edit','delete','approve'].
     */
    function rofsGrant(PDO $pdo, int $roleId, int $permId, array $verbs): void
    {
        if ($roleId <= 0 || $permId <= 0) return;
        $cols = rofsRoleColumns($pdo);
        $want = [];
        foreach ($cols as $c) $want[$c] = in_array(substr($c, 4), $verbs, true) ? 1 : 0;
        $st = $pdo->prepare("SELECT * FROM role_permissions WHERE role_id = ? AND permission_id = ? LIMIT 1");
        $st->execute([$roleId, $permId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $names = implode(', ', array_merge(['role_id', 'permission_id'], array_keys($want)));
            $marks = implode(', ', array_fill(0, count($want) + 2, '?'));
            $pdo->prepare("INSERT INTO role_permissions ($names) VALUES ($marks)")->execute(array_merge([$roleId, $permId], array_values($want)));
            return;
        }
        $set = [];
        foreach ($want as $c => $v) if ($v === 1 && empty($row[$c])) $set[] = "`$c` = 1";
        if ($set) $pdo->prepare("UPDATE role_permissions SET " . implode(', ', $set) . " WHERE role_id = ? AND permission_id = ?")->execute([$roleId, $permId]);
    }
}

if (!function_exists('rofsEnsurePermissions')) {
    /**
     * Permission rows restaurant_orders + kitchen_display, and the back-compat grants:
     *   roles that can view restaurant_pos → kitchen_display view + edit (they could advance tickets before)
     *   roles that can create pos          → restaurant_orders view/create/edit/delete/approve (they did all of it before)
     * Only grants are ever added; nothing an admin set is removed.
     */
    function rofsEnsurePermissions(PDO $pdo, callable $log): void
    {
        if (!rofsHasTable($pdo, 'permissions') || !rofsHasTable($pdo, 'role_permissions')) {
            $log('  ~ no permissions tables — skipped.');
            return;
        }
        $rows = [
            'restaurant_orders' => ['Restaurant Orders', 'Take table orders and send them to the kitchen (create/edit), cancel items the kitchen has not started (delete), confirm orders to the kitchen and approve cancelling food already cooking (approve).'],
            'kitchen_display'   => ['Kitchen Screen', 'See kitchen orders (view) and move them Preparing → Ready (edit).'],
        ];
        $created = [];
        foreach ($rows as $key => [$name, $desc]) {
            if (rofsPermissionId($pdo, $key)) { $log("  ~ permission '$key' already present."); continue; }
            $pdo->prepare("INSERT INTO permissions (permission_name, page_key, page_name, description, module_name, is_hidden, created_at)
                           VALUES ('', ?, ?, ?, 'Settings', 0, NOW())")->execute([$key, $name, $desc]);
            $created[$key] = true;
            $log("  + permission '$key' created.");
        }
        $ordersId  = rofsPermissionId($pdo, 'restaurant_orders');
        $kitchenId = rofsPermissionId($pdo, 'kitchen_display');
        // Back-compat grants only when the row is new: a later run must never re-grant what an admin took away.
        if (!empty($created['kitchen_display'])) {
            $rp = rofsPermissionId($pdo, 'restaurant_pos');
            if ($rp) {
                $st = $pdo->prepare("SELECT DISTINCT role_id FROM role_permissions WHERE permission_id = ? AND can_view = 1");
                $st->execute([$rp]);
                $n = 0;
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $rid) { rofsGrant($pdo, (int)$rid, $kitchenId, ['view', 'edit']); $n++; }
                $log("  + kitchen_display view+edit given to $n role(s) that can view Restaurant POS.");
            }
        }
        if (!empty($created['restaurant_orders'])) {
            $pos = rofsPermissionId($pdo, 'pos');
            if ($pos) {
                $st = $pdo->prepare("SELECT DISTINCT role_id FROM role_permissions WHERE permission_id = ? AND can_create = 1");
                $st->execute([$pos]);
                $n = 0;
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $rid) { rofsGrant($pdo, (int)$rid, $ordersId, ['view', 'create', 'edit', 'delete', 'approve']); $n++; }
                $log("  + restaurant_orders (all verbs) given to $n role(s) that can sell on POS.");
            }
        }
    }
}

if (!function_exists('rofsReadyRoles')) {
    /** role name => [description, [page_key => verbs]] */
    function rofsReadyRoles(): array
    {
        return [
            'Waiter' => ['Restaurant waiter: takes table orders and sends them; marks food received from the kitchen. Cannot take payment.', [
                'pos' => ['view'], 'restaurant_pos' => ['view'], 'restaurant_orders' => ['view', 'create', 'edit'],
            ]],
            'Cashier' => ['Restaurant cashier: confirms orders to the kitchen, takes payment and closes bills, approves cancelling cooked food.', [
                'pos' => ['view', 'create'], 'restaurant_pos' => ['view'],
                'restaurant_orders' => ['view', 'create', 'edit', 'delete', 'approve'], 'kitchen_display' => ['view'],
            ]],
            'Cook' => ['Restaurant cook: uses the kitchen screen (Preparing → Ready). No till, no money.', [
                'restaurant_pos' => ['view'], 'kitchen_display' => ['view', 'edit'],
            ]],
            'Restaurant Manager' => ['Restaurant manager: everything in the restaurant flow, including voiding a paid sale.', [
                'pos' => ['view', 'create', 'edit', 'delete'], 'restaurant_pos' => ['view', 'create', 'edit'],
                'restaurant_orders' => ['view', 'create', 'edit', 'delete', 'approve'], 'kitchen_display' => ['view', 'edit'],
                'pos_config_settings' => ['view', 'edit'], 'pos_dashboard' => ['view'],
                'restaurant_costing' => ['view'],   // skipped while the row does not exist yet (rofsGrant needs its id)
                // buys food (Purchases) and gives out the stock a cook asks for on the Cooking plan
                'pos_restock' => ['view', 'create', 'edit'],
            ]],
        ];
    }
}

if (!function_exists('rofsUpgradeReadyRoles')) {
    /**
     * Rights added to the ready-made roles after they were first created (e.g. the manager's stock right).
     * Only OUR roles are touched: same name AND the description we wrote — a company's own "Cashier" never.
     * Rights are only ever added, never removed. Returns "role: page_key" for each grant made. Idempotent.
     */
    function rofsUpgradeReadyRoles(PDO $pdo, callable $log): array
    {
        if (!rofsHasTable($pdo, 'roles') || !rofsHasTable($pdo, 'role_permissions')) return [];
        $done = [];
        $find = $pdo->prepare("SELECT role_id FROM roles WHERE LOWER(TRIM(role_name)) = LOWER(?) AND description = ? LIMIT 1");
        $has  = $pdo->prepare("SELECT 1 FROM role_permissions WHERE role_id = ? AND permission_id = ? LIMIT 1");
        foreach (rofsReadyRoles() as $name => [$desc, $grants]) {
            $find->execute([$name, $desc]);
            $rid = (int)$find->fetchColumn();
            if (!$rid) continue;
            foreach ($grants as $key => $verbs) {
                $pid = rofsPermissionId($pdo, $key);
                if (!$pid) continue;
                $has->execute([$rid, $pid]);
                if ($has->fetchColumn()) continue;   // a right the owner already set (or changed) is left alone
                rofsGrant($pdo, $rid, $pid, $verbs);
                $done[] = "$name: $key";
                $log("  + role '$name' given $key (" . implode(', ', $verbs) . ').');
            }
        }
        return $done;
    }
}

if (!function_exists('rofsEnsureReadyRoles')) {
    /**
     * Create Waiter / Cashier / Cook / Restaurant Manager — each only when no role of that name
     * exists (an existing "Cashier" of a retail company is never touched). Returns names created.
     */
    function rofsEnsureReadyRoles(PDO $pdo, callable $log): array
    {
        if (!rofsHasTable($pdo, 'roles') || !rofsHasTable($pdo, 'role_permissions') || !rofsPermissionId($pdo, 'restaurant_orders')) return [];
        $made = [];
        $find = $pdo->prepare("SELECT role_id FROM roles WHERE LOWER(TRIM(role_name)) = LOWER(?) LIMIT 1");
        foreach (rofsReadyRoles() as $name => [$desc, $grants]) {
            $find->execute([$name]);
            if ($find->fetchColumn()) { $log("  ~ role '$name' already exists — left as it is."); continue; }
            $pdo->prepare("INSERT INTO roles (role_name, is_admin, description, created_at) VALUES (?, 0, ?, NOW())")->execute([$name, $desc]);
            $rid = (int)$pdo->lastInsertId();
            foreach ($grants as $key => $verbs) {
                $pid = rofsPermissionId($pdo, $key);
                if ($pid) rofsGrant($pdo, $rid, $pid, $verbs);
            }
            $made[] = $name;
            $log("  + role '$name' created.");
        }
        return $made;
    }
}

if (!function_exists('rofsEnsureEvents')) {
    /**
     * restaurant.order_to_confirm — to everyone who may confirm (restaurant_orders approve), in-app.
     * restaurant.order_returned  — to the waiter who sent it (record owner), in-app.
     * An event that already exists (and its rules) is never touched.
     */
    function rofsEnsureEvents(PDO $pdo, callable $log): void
    {
        if (!rofsHasTable($pdo, 'notification_events') || !rofsHasTable($pdo, 'notification_rules')) {
            $log('  ~ notification engine tables absent — events skipped.');
            return;
        }
        $events = [
            'restaurant.order_to_confirm' => ['Order waiting for the cashier', 'A waiter sent an order; the cashier must confirm it before the kitchen starts.', 'high', 'restaurant_orders', 'approve', 'permission'],
            'restaurant.order_returned'   => ['Order returned by the cashier', 'The cashier did not confirm an order and sent it back to the waiter.', 'medium', 'restaurant_orders', 'view', 'record_owner'],
        ];
        $exists = $pdo->prepare("SELECT 1 FROM notification_events WHERE event_key = ?");
        foreach ($events as $key => [$title, $desc, $sev, $page, $verb, $target]) {
            $exists->execute([$key]);
            if ($exists->fetchColumn()) { $log("  ~ event $key already present."); continue; }
            $pdo->prepare("INSERT INTO notification_events (event_key, title, description, module, page_key, required_verb, default_severity, scope_aware, is_active, created_at)
                           VALUES (?, ?, ?, 'Restaurant', ?, ?, ?, 1, 1, NOW())")->execute([$key, $title, $desc, $page, $verb, $sev]);
            $pdo->prepare("INSERT INTO notification_rules (event_key, target_type, target_id, channel_email, channel_inapp, digest, is_active, created_at, updated_at)
                           VALUES (?, ?, NULL, 0, 1, 'immediate', 1, NOW(), NOW())")->execute([$key, $target]);
            $log("  + event $key registered (in-app to " . ($target === 'permission' ? 'everyone who may confirm' : 'the waiter') . ').');
        }
    }
}

if (!function_exists('rofsCloseStaleTickets')) {
    /**
     * Close tickets whose order is already over (criteria only, never ids):
     *  - table tickets of a table that has NO open ('held') order → closed (sale was paid or order dropped);
     *  - pickup/hold tickets whose held order is no longer 'held' and has no table → closed;
     *  - any ticket older than 12 hours still on the kitchen screen → pending: cancelled, others: served.
     * Returns the number of tickets touched. Idempotent.
     */
    function rofsCloseStaleTickets(PDO $pdo): int
    {
        if (!rofsHasColumn($pdo, 'kitchen_tickets', 'closed_at')) return 0;
        $n = 0;
        $n += $pdo->exec("UPDATE kitchen_tickets kt
                             SET kt.closed_at = NOW()
                           WHERE kt.closed_at IS NULL AND kt.table_id IS NOT NULL
                             AND NOT EXISTS (SELECT 1 FROM pos_held_sales h WHERE h.table_id = kt.table_id AND h.status = 'held')");
        $n += $pdo->exec("UPDATE kitchen_tickets kt
                             SET kt.closed_at = NOW()
                           WHERE kt.closed_at IS NULL AND kt.table_id IS NULL AND kt.hold_id IS NOT NULL
                             AND NOT EXISTS (SELECT 1 FROM pos_held_sales h WHERE h.hold_id = kt.hold_id AND h.status = 'held')");
        $n += $pdo->exec("UPDATE kitchen_tickets
                             SET status = 'cancelled', cancelled_at = NOW(), cancel_reason = 'Not confirmed within 12 hours', closed_at = COALESCE(closed_at, NOW())
                           WHERE status = 'pending' AND created_at < NOW() - INTERVAL 12 HOUR");
        $n += $pdo->exec("UPDATE kitchen_tickets
                             SET status = 'served', served_at = COALESCE(served_at, NOW()), closed_at = COALESCE(closed_at, NOW())
                           WHERE status IN ('queued','preparing','ready') AND created_at < NOW() - INTERVAL 12 HOUR");
        $pdo->exec("UPDATE kitchen_ticket_items kti JOIN kitchen_tickets kt ON kt.ticket_id = kti.ticket_id
                       SET kti.status = kt.status
                     WHERE kt.status IN ('served','cancelled') AND kti.status <> kt.status");
        return (int)$n;
    }
}

if (!function_exists('rofsHasRestaurantShop')) {
    function rofsHasRestaurantShop(PDO $pdo): bool
    {
        try {
            if (!rofsHasColumn($pdo, 'warehouses', 'pos_mode')) return false;
            return (bool)$pdo->query("SELECT 1 FROM warehouses WHERE pos_mode IN ('restaurant','hybrid') LIMIT 1")->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('restaurantOrderFlowEnsureSchema')) {
    /** The whole Phase 1, for both migrations. */
    function restaurantOrderFlowEnsureSchema(PDO $pdo, callable $log): void
    {
        $ticketsOk = rofsEnsureTicketSchema($pdo, $log);
        rofsEnsurePermissions($pdo, $log);
        rofsEnsureEvents($pdo, $log);
        if ($ticketsOk) {
            $n = rofsCloseStaleTickets($pdo);
            $log("  + stale kitchen tickets closed: $n.");
            if (rofsHasRestaurantShop($pdo)) {
                $made = rofsEnsureReadyRoles($pdo, $log);
                if (!$made) $log('  ~ ready-made restaurant roles: nothing new.');
            }
            rofsUpgradeReadyRoles($pdo, $log);   // roles made earlier get the rights added since
        }
    }
}
