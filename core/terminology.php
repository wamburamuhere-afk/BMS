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
