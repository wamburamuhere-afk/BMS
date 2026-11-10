<?php
/**
 * core/restaurant_access.php — who may use which restaurant page, in ONE place.
 *
 * Rule (owner, 2026-11-10): role based. A page a person may use is in their menu; a page they may not use is
 * neither in their menu nor open to them by typing its address. The page gate and its menu link both call the
 * same function below, so "can open" and "sees the link" can never disagree.
 *
 * Everything is decided by permissions (never by role names), so a company's own roles work the same way:
 *  - money (sales totals, unpaid bills, who owes)  → someone who takes payment, or has reports / sales rights
 *  - front of house (tables, reservations)         → someone who takes orders or runs the restaurant
 *  - kitchen screen                                → kitchen_display (rofCanSeeKitchen)
 *  - cooking plan                                   → cooks, whoever gives out stock, whoever sees costs
 *  - menu set-up (add-on options)                   → whoever may edit the restaurant
 */
require_once __DIR__ . '/restaurant_scope.php';

if (!function_exists('raSeesMoney')) {
    /** Sales totals, unpaid amounts and customer debts: whoever takes payment, or has reports / sales rights. */
    function raSeesMoney(): bool
    {
        return isAdmin() || canCreate('pos') || canView('sales_report') || canView('invoices')
            || (function_exists('hasReportsAccess') && hasReportsAccess());
    }
}

if (!function_exists('raCanTables')) {
    /** Tables board and reservations: whoever takes orders (waiter, cashier) or runs the restaurant. */
    function raCanTables(): bool
    {
        return isAdmin() || canView('pos') || canEdit('restaurant_pos');
    }
}

if (!function_exists('raCanKitchen')) {
    function raCanKitchen(PDO $pdo): bool
    {
        if (isAdmin()) return true;
        require_once __DIR__ . '/restaurant_order_flow.php';
        return rofCanSeeKitchen($pdo);
    }
}

if (!function_exists('raCanCookPlan')) {
    /** The Cooking plan: cooks, whoever gives out stock, whoever sees costs. */
    function raCanCookPlan(): bool
    {
        require_once __DIR__ . '/restaurant_cook_plan.php';
        return isAdmin() || rcpCanCook() || rcpCanMoveStock() || restaurantCanSeeCost();
    }
}

if (!function_exists('raCanCookReport')) {
    /** Planned vs actual: cooks (plates only), whoever sees costs, reports. */
    function raCanCookReport(): bool
    {
        require_once __DIR__ . '/restaurant_cook_plan.php';
        return isAdmin() || rcpCanCook() || restaurantCanSeeCost() || (function_exists('hasReportsAccess') && hasReportsAccess());
    }
}

if (!function_exists('raCanSalesReport')) {
    function raCanSalesReport(): bool
    {
        return raSeesMoney();
    }
}

if (!function_exists('raCanWasteReport')) {
    /** Waste & cancellations: whoever approves cancelling cooked food (cashier, manager), reports. */
    function raCanWasteReport(PDO $pdo): bool
    {
        if (isAdmin() || (function_exists('hasReportsAccess') && hasReportsAccess())) return true;
        require_once __DIR__ . '/restaurant_order_flow.php';
        return rofCanConfirm($pdo);
    }
}

if (!function_exists('raCanCredit')) {
    /** Who Owes Me: money — whoever takes payment, or has reports. */
    function raCanCredit(): bool
    {
        return canView('pos') && raSeesMoney();
    }
}

if (!function_exists('raCanAddons')) {
    /** Add-on options is menu set-up: whoever may edit the restaurant. */
    function raCanAddons(): bool
    {
        return isAdmin() || canEdit('restaurant_pos');
    }
}

if (!function_exists('raCanBuy')) {
    function raCanBuy(): bool
    {
        return isAdmin() || (canView('pos') && canView('pos_restock'));
    }
}

if (!function_exists('raNav')) {
    /** Every restaurant menu decision at once (header.php). */
    function raNav(PDO $pdo): array
    {
        return [
            'take'      => canView('pos'),
            'tables'    => raCanTables(),
            'kitchen'   => raCanKitchen($pdo),
            'reserve'   => raCanTables(),
            'credit'    => raCanCredit(),
            'menu'      => canView('restaurant_pos'),
            'cooking'   => raCanCookPlan(),
            'cookrep'   => raCanCookReport(),
            'addons'    => raCanAddons(),
            'buy'       => raCanBuy(),
            'sales'     => raCanSalesReport(),
            'waste'     => raCanWasteReport($pdo),
        ];
    }
}

if (!function_exists('rdAttentionForRole')) {
    /**
     * "Needs your attention" for THIS person: each item only for whoever can act on it, with a link only where
     * they may go (else link ''). Pure: works on rdAttention() rows + raNav().
     *  old bills → front of house (tables) · kitchen waiting → kitchen or front of house · running low → whoever
     *  buys (link Purchases) or cooks (no link) · no cost → whoever sees profit ($money).
     */
    function rdAttentionForRole(array $rows, array $nav, bool $money): array
    {
        $out = [];
        foreach ($rows as $a) {
            $type = $a['type'] ?? '';
            if ($type === 'old_bills') {
                if (empty($nav['tables'])) continue;
                $a['link'] = getUrl('restaurant/tables-board');
            } elseif ($type === 'kitchen_wait') {
                if (empty($nav['kitchen']) && empty($nav['tables'])) continue;
                $a['link'] = !empty($nav['kitchen']) ? getUrl('restaurant/kitchen-dashboard') : getUrl('restaurant/tables-board');
            } elseif ($type === 'low_stock') {
                if (empty($nav['buy']) && empty($nav['cooking'])) continue;
                $a['link'] = !empty($nav['buy']) ? getUrl('restaurant/purchases') : getUrl('restaurant/cooking');
            } else {   // no_cost
                if (!$money) continue;
                $a['link'] = getUrl('restaurant/menu');
            }
            $out[] = $a;
        }
        return $out;
    }
}

if (!function_exists('raDeny')) {
    /** A page's gate: not allowed → the unauthorized page (same as every other gate). */
    function raDeny(bool $allowed): void
    {
        if ($allowed) return;
        $to = getUrl('unauthorized');
        if (!headers_sent()) {
            while (ob_get_level() > 0) ob_end_clean();
            header('Location: ' . $to);
        } else {
            echo '<script>location.replace(' . json_encode($to) . ');</script>';
        }
        exit;
    }
}
