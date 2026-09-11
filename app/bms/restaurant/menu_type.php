<?php
/**
 * app/bms/restaurant/menu_type.php
 *
 * Phase 30 (pos_upgrade_plan.md §9) — "Menu Type" is not a new concept: a
 * restaurant's menu items are still rows in `products`, and BMS already has
 * a category system for products (`categories` table, `type='product'`,
 * managed at app/bms/product/categories.php). Reusing that page outright —
 * rather than building a parallel "menu type" table/CRUD — is the same
 * reuse-before-adding discipline this whole tier follows (see e.g. Phase
 * 30's Recipes note reusing Phase 23's combo mechanism). This is purely an
 * additional door to the same room, matching the "Settings" hub card's own
 * reasoning — not a relocation of category management.
 */
require_once __DIR__ . '/../../../roots.php';

if (!canView('restaurant_pos')) {
    header('Location: ' . getUrl('unauthorized'));
    exit();
}

header('Location: ' . getUrl('categories'));
exit();
