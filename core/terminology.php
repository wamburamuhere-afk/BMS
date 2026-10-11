<?php
/**
 * Tenant-conditional display terminology.
 *
 * A handful of tenants run BMS purely as a retail point of sale and never
 * think of their `warehouses` records as "warehouses" at all - to them
 * every one is a "shop" ("duka"). The underlying data model, columns,
 * variable names and queries never change; only the label a page prints
 * changes, and only on the specific screens that opt into it.
 *
 * Call sites choose per-string which English/Swahili key pair to show and
 * pass isPosCoreScreen for pos.php / the POS dashboard, which always show
 * the shop wording whenever POS is on - everywhere else, the shop wording
 * only appears when Projects is off too, since a tenant that also runs
 * project-based work still needs "Warehouse" for its project stock.
 *
 * Superadmin override (2026-09-16): the automatic pos+projects inference
 * above is a *default*, not the only lever - a tenant that also has
 * Projects on (so the automatic rule alone would never show Shop wording
 * outside pos.php) can still be forced into Shop/Duka everywhere via the
 * "Shop Mode" checkbox on the superadmin Point of Sale panel (same UX
 * pattern as the adjacent "Simple Mode" checkbox: a plain tenant-side
 * system_settings flag, superadmin-only, the tenant's own admin has no
 * self-service control over it). Checking it simply skips the Projects
 * check below; it never forces "Warehouse" wording back on for a tenant
 * the automatic rule would already call Shop - there is no "force off".
 */

if (!function_exists('isShopLabel')) {
    /**
     * Should this request show "Shop"/"Duka" instead of "Warehouse"/"Ghala"?
     *
     * @param bool $isPosCoreScreen True for pos.php / the POS dashboard, which
     *   always use the shop wording whenever POS is enabled, regardless of
     *   whether Projects is also on for this tenant.
     */
    function isShopLabel(bool $isPosCoreScreen = false): bool
    {
        if (!tenantFeatureEnabled('pos')) {
            return false;
        }
        if (function_exists('get_setting') && get_setting('shop_mode', '0') === '1') {
            return true;
        }
        if ($isPosCoreScreen) {
            return true;
        }
        return !tenantFeatureEnabled('projects');
    }
}

if (!function_exists('wLabel')) {
    /**
     * Resolve a "Warehouse"-family string to its "Shop" counterpart when
     * isShopLabel() says so, then run it through the normal t() catalog.
     */
    function wLabel(string $warehouseText, string $shopText, bool $isPosCoreScreen = false): string
    {
        return t(isShopLabel($isPosCoreScreen) ? $shopText : $warehouseText);
    }
}

if (!function_exists('wLabelE')) {
    /** Echoing, HTML-escaped variant of wLabel() - the common case in markup. */
    function wLabelE(string $warehouseText, string $shopText, bool $isPosCoreScreen = false): void
    {
        echo htmlspecialchars(wLabel($warehouseText, $shopText, $isPosCoreScreen));
    }
}

if (!function_exists('warehouseStatusLabel')) {
    /**
     * Display label for a warehouses.status value (active/inactive/maintenance).
     *
     * The plain 'Active' key is shared app-wide (users, products, ...) and its
     * Swahili ("Hai") reads oddly for a shop, so shop/warehouse status uses its
     * own context keys. lang/en.php is empty by design, so English falls back
     * to the plain word passed as t()'s default.
     */
    function warehouseStatusLabel(?string $status): string
    {
        switch (strtolower((string)$status)) {
            case 'active':      return t('Shop status: Active', 'Active');
            case 'inactive':    return t('Shop status: Inactive', 'Inactive');
            case 'maintenance': return t('Shop status: Under Maintenance', 'Under Maintenance');
        }
        return t(ucfirst((string)$status));
    }
}

if (!function_exists('stockMovementLabel')) {
    /**
     * Human label for a stock_movements.movement_type code, instead of the raw
     * code with underscores swapped for spaces ("Sale Out", "Transfer In").
     */
    function stockMovementLabel(?string $type): string
    {
        switch ((string)$type) {
            case 'purchase_in':    return t('Stock Received');
            case 'sale_out':       return t('Sold');
            case 'adjustment_in':  return t('Stock Added (Adjustment)');
            case 'adjustment_out': return t('Stock Removed (Adjustment)');
            case 'transfer_in':    return wLabel('Received from Another Warehouse', 'Received from Another Shop');
            case 'transfer_out':   return wLabel('Sent to Another Warehouse', 'Sent to Another Shop');
            case 'return_in':      return t('Returned by Customer');
            case 'return_out':     return t('Returned to Supplier');
            case 'production_in':  return t('Produced');
            case 'production_out': return t('Used in Production');
            case 'damaged':        return t('Damaged');
            case 'expired':        return t('Expired');
            case 'found':          return t('Found');
            case 'theft':          return t('Theft');
            case 'correction':     return t('Correction');
            case 'issue_out':      return t('Issued Out');
        }
        return ucwords(str_replace('_', ' ', (string)$type));
    }
}
