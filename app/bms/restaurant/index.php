<?php
/**
 * app/bms/restaurant/index.php
 *
 * Phase 30 (pos_upgrade_plan.md §9) — Restaurant sub-hub. Reached from the
 * POS hub's "Restaurant" card (app/bms/pos/pos_dashboard.php), itself gated
 * on canView('restaurant_pos'). Reuses the exact same hub-card idiom one
 * level down instead of inventing a second design for this screen.
 */
ob_start();

require_once __DIR__ . '/../../../roots.php';

$page_title = 'Restaurant';
require_once 'header.php';

if (!canView('restaurant_pos')) {
    header('Location: ' . getUrl('unauthorized'));
    exit();
}

$sub_hub_cards = [
    ['icon' => 'bi-diagram-3',      'label' => t('Floors & Tables'), 'description' => t('Define dining floors and their tables.'), 'url' => 'restaurant/floors'],
    ['icon' => 'bi-egg-fried',      'label' => t('Kitchen Display'), 'description' => t('Live kitchen queue — advance tickets as they cook.'), 'url' => 'restaurant/kitchen-dashboard'],
    ['icon' => 'bi-list-check',     'label' => t('Modifier Group'),  'description' => t('Add-on/option groups linked to menu items.'), 'url' => 'restaurant/modifier-group'],
    ['icon' => 'bi-calendar-check', 'label' => t('Reservations'),    'description' => t('Book and manage table reservations.'), 'url' => 'restaurant/reservations'],
    ['icon' => 'bi-tags',           'label' => t('Menu Type'),       'description' => t('Categorize menu items (uses the shared product categories).'), 'url' => 'restaurant/menu-type'],
];
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h4 class="mb-0 text-primary"><i class="bi bi-shop-window me-2"></i><?= t('Restaurant') ?></h4>
        <a href="<?= getUrl('pos/dashboard') ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> <?= t('Back to POS Hub') ?>
        </a>
    </div>

    <div class="row g-3">
        <?php foreach ($sub_hub_cards as $card): ?>
        <div class="col-6 col-md-4 col-lg-3">
            <a href="<?= getUrl($card['url']) ?>" class="text-decoration-none">
                <div class="card border-0 shadow-sm h-100 p-3 pos-hub-card">
                    <div class="text-center">
                        <div class="fs-2 text-primary mb-2"><i class="bi <?= safe_output($card['icon']) ?>"></i></div>
                        <div class="fw-bold"><?= safe_output($card['label']) ?></div>
                        <div class="small text-muted"><?= safe_output($card['description']) ?></div>
                    </div>
                </div>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<style>
    .pos-hub-card { transition: transform .15s ease, box-shadow .15s ease; }
    .pos-hub-card:hover { transform: translateY(-2px); box-shadow: 0 .5rem 1rem rgba(0,0,0,.1) !important; }
</style>

<?php
require_once 'footer.php';
ob_end_flush();
