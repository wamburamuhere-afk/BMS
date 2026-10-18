<?php
/**
 * app/superadmin/plans.php — reusable pricing/feature bundles.
 *
 * Control database only. Applying a plan to a tenant happens from
 * tenant_view.php (that's the page that already knows which tenant); this
 * page only manages the catalogue itself.
 *
 * Service plans (service_plans_plan.md Phase 1): a plan can also be SOLD — it
 * belongs to a product (Shop Mode, full BMS), has prices per term, extra
 * "what's included" lines and add-ons (extra shop / user). Superadmin is the
 * master of all of this; BJP's own books only read it.
 */
require_once __DIR__ . '/../../core/tenant_admin.php';
require_once __DIR__ . '/../../core/superadmin_ui.php';
require_once __DIR__ . '/../../core/plans.php';
require_once __DIR__ . '/../../helpers.php';

requireSuperadmin();

$me       = currentSuperadmin();
$plans    = [];
$error    = null;
$setup    = false;
$catalog  = false;
$products = [];
$availableFeatures = [];
$planData = [];   // id => everything the edit form needs, handed to JS as JSON

try {
    if (!planTablesReady()) {
        $setup = true;
    } else {
        // Never the reserved 'blank' plan — it exists only for self-
        // registration's provisioning switch (tenant_module_control_plan.md
        // §5.1), not as something an operator manages or applies by hand.
        $plans = array_values(array_filter(listPlans(), fn($p) => $p['plan_key'] !== 'blank'));
        $availableFeatures = getControlPdo()
            ->query("SELECT feature_key, label FROM features WHERE is_available = 1 ORDER BY sort_order, feature_key")
            ->fetchAll();
        $catalog  = planCatalogReady();
        $products = $catalog ? listPlatformProducts() : [];

        foreach ($plans as &$p) {
            $pid = (int)$p['id'];
            // What applying gives: a product's fixed modules, else the plan's ticks.
            $p['_features'] = planNormalizeFeaturesForProduct(planFeatureKeys($pid), $p['product_key'] ?? null);
            $p['_prices']   = $catalog ? planPrices($pid, true) : [];
            $idToTerm = [];
            foreach ($p['_prices'] as $pr) $idToTerm[(int)$pr['id']] = $pr['term_key'];
            $addons = [];
            if ($catalog) {
                foreach (planAddons($pid, true) as $a) {
                    $ap = [];
                    foreach ($a['prices'] as $ppId => $amount) if (isset($idToTerm[$ppId])) $ap[$idToTerm[$ppId]] = $amount;
                    $addons[] = ['id' => (int)$a['id'], 'name' => $a['name'], 'quota' => $a['quota'], 'units' => (int)$a['units'], 'prices' => $ap];
                }
            }
            $p['_addons'] = $addons;
            $planData[$pid] = [
                'id' => $pid, 'name' => $p['name'], 'description' => $p['description'],
                'max_users' => $p['max_users'], 'max_storage_mb' => $p['max_storage_mb'],
                'sort_order' => (int)$p['sort_order'], 'feature_keys' => $p['_features'],
                'product_key' => $p['product_key'] ?? null, 'tagline' => $p['tagline'] ?? null,
                'max_shops' => $p['max_shops'] ?? null, 'tier_rank' => (int)($p['tier_rank'] ?? 0),
                'is_public' => (int)($p['is_public'] ?? 0), 'is_popular' => (int)($p['is_popular'] ?? 0),
                'prices' => array_map(fn($r) => [
                    'id' => (int)$r['id'], 'term_label' => $r['term_label'], 'duration_value' => (int)$r['duration_value'],
                    'duration_unit' => $r['duration_unit'], 'price_tzs' => (int)$r['price_tzs'], 'is_default' => (int)$r['is_default'],
                    'term_key' => $r['term_key'],
                ], $p['_prices']),
                'items'  => $catalog ? array_map(fn($i) => ['label' => $i['label'], 'value' => $i['value']], planItems($pid)) : [],
                'addons' => $addons,
            ];
        }
        unset($p);
    }
} catch (Throwable $e) {
    error_log('superadmin plans: ' . $e->getMessage());
    $error = 'The plan catalogue could not be read.';
}
$productNames = [];
foreach ($products as $pr) $productNames[$pr['product_key']] = $pr['name'];

// Per product: how many plans, how many a customer can actually buy (active +
// public + priced) — a product shown on registration with none of those
// leaves the registration page on its "contact us" state.
$productStats = [];
foreach ($products as $pr) $productStats[$pr['product_key']] = ['plans' => 0, 'sellable' => 0];
foreach ($plans as $p) {
    $k = $p['product_key'] ?? null;
    if ($k === null || !isset($productStats[$k])) continue;
    $productStats[$k]['plans']++;
    if ($p['is_active'] && !empty($p['is_public']) && $p['_prices']) $productStats[$k]['sellable']++;
}
// Each product's fixed modules, as labels — the plan form shows these instead of ticks.
$featureLabels = [];
foreach ($availableFeatures as $f) $featureLabels[$f['feature_key']] = $f['label'];
$productModules = [];
if ($catalog) {
    foreach ($products as $pr) {
        $fixed = platformProductProfile($pr['product_key'])['fixed_features'];
        if ($fixed === null) continue;
        $productModules[$pr['product_key']] = array_values(array_map(fn($k) => (string)($featureLabels[$k] ?? $k), $fixed));
    }
}
$productProfileText = [
    'bms_shop' => 'Point of Sale + Warehouses, Simple Mode on (locked), Shop wording, Supplier Access on — the self-registration setup.',
    'bms_full' => 'Every default module; Simple Mode and Shop wording off.',
];
// Plans grouped under their product (internal plans last).
$planGroups = [];
foreach ($products as $pr) $planGroups[$pr['product_key']] = [];
$planGroups[''] = [];
foreach ($plans as $p) {
    $k = $p['product_key'] ?? '';
    if (!isset($planGroups[$k])) $planGroups[$k] = [];
    $planGroups[$k][] = $p;
}
$planGroups = array_filter($planGroups);
$productData = [];
foreach ($products as $pr) {
    $productData[$pr['product_key']] = [
        'product_key' => $pr['product_key'], 'name' => $pr['name'], 'description' => $pr['description'],
        'sort_order' => (int)$pr['sort_order'], 'show_on_registration' => (int)$pr['show_on_registration'],
        'is_active' => (int)$pr['is_active'],
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>Plans | Platform Administration</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
<style>
    body { background: #fff; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    .detail-card { border: 1px solid #b6ccfe; border-radius: 8px; }
    .detail-card .card-header { background: #e7f0ff; border-bottom: 1px solid #b6ccfe; font-weight: 600; }
    .plan-card.retired { opacity: .6; }
    .feature-pick { background: #f8f9fb; border: 1px solid #e9ecef; border-radius: 6px; }
    .form-section { font-weight: 600; font-size: .9rem; color: #0d6efd; border-bottom: 1px solid #b6ccfe; padding-bottom: 4px; margin-top: .5rem; }
    .badge-public  { background: #0d6efd; color: #fff; }
    .badge-hidden  { background: #e9ecef; color: #495057; }
    .badge-popular { background: #052c65; color: #fff; }
    .badge-product { background: #cfe2ff; color: #084298; }
    .price-list li { display: flex; justify-content: space-between; border-bottom: 1px dashed #e9ecef; padding: 2px 0; }
    .mini-table td, .mini-table th { padding: .3rem; vertical-align: middle; }
    .mini-table input, .mini-table select { min-width: 70px; }
    .product-card { border: 1px solid #b6ccfe; border-radius: 10px; background: #fff; }
    .product-card .pc-head { background: #e7f0ff; border-bottom: 1px solid #b6ccfe; border-radius: 10px 10px 0 0; }
    .product-card.inactive { opacity: .65; }
    .group-title { font-weight: 600; color: #052c65; border-bottom: 2px solid #b6ccfe; padding-bottom: 4px; }
    /* A <form> between .modal-content and .modal-body breaks Bootstrap's scrollable
       dialog; make the form the flex column so only the body scrolls. */
    .modal-dialog-scrollable .modal-content > form { display: flex; flex-direction: column; min-height: 0; overflow: hidden; }
    .product-modules { background: #e7f0ff; border: 1px solid #b6ccfe; border-radius: 8px; }
    .product-modules .mod-chip { display: inline-flex; align-items: center; gap: 4px; background: #fff; border: 1px solid #b6ccfe; color: #084298;
        border-radius: 999px; padding: 2px 10px; font-size: .8rem; font-weight: 600; }
</style>
</head>
<body>

<?php renderSuperadminHeader('plans', $me); ?>

<div class="container-fluid p-3">
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <h5 class="mb-0 fw-bold"><i class="bi bi-box-seam text-primary me-1"></i> Plans</h5>
        <?php if (!$setup && !$error): ?>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#planModal" onclick="openCreate()">
            <i class="bi bi-plus-circle me-1"></i> New plan
        </button>
        <?php endif; ?>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= safe_output($error, '') ?></div>
    <?php elseif ($setup): ?>
        <div class="card detail-card">
            <div class="card-header"><i class="bi bi-tools text-primary me-1"></i> One setup step remains on this server</div>
            <div class="card-body">
                <p>Plan management is not set up on this server yet, so there is nothing to show here.</p>
                <p class="text-muted small">Run this once on this host, then reload this page:</p>
                <pre class="p-3 rounded mb-3" style="background:#e7f0ff;border:1px solid #b6ccfe"><code>php scripts/setup_control_db.php</code></pre>
            </div>
        </div>
    <?php else: ?>
    <?php if (!$catalog): ?>
        <div class="alert alert-primary py-2 small"><i class="bi bi-info-circle me-1"></i>
            Selling plans (prices, add-ons, public offers) needs one more setup step on this server:
            <code>php scripts/setup_control_db.php</code></div>
    <?php endif; ?>
    <?php if ($catalog && $products): ?>
    <div class="group-title mb-2"><i class="bi bi-grid-1x2 me-1"></i> Products</div>
    <p class="text-muted small mb-2">A product decides what a tenant on its plans gets (modes and default modules). Its plans appear on the
        registration page only when <strong>On registration</strong> is set and at least one plan is public, active and priced.</p>
    <div class="row g-3 mb-4">
        <?php foreach ($products as $pr): $k = $pr['product_key']; $st = $productStats[$k] ?? ['plans' => 0, 'sellable' => 0]; ?>
        <div class="col-12 col-lg-6">
            <div class="product-card h-100 <?= $pr['is_active'] ? '' : 'inactive' ?>">
                <div class="pc-head px-3 py-2 d-flex justify-content-between align-items-center gap-2">
                    <span class="fw-semibold text-truncate"><i class="bi bi-<?= $k === 'bms_shop' ? 'shop' : 'building' ?> text-primary me-1"></i><?= safe_output($pr['name'], '') ?></span>
                    <span class="d-flex gap-1 flex-shrink-0">
                        <span class="badge <?= (int)$pr['show_on_registration'] ? 'badge-public' : 'badge-hidden' ?>"><?= (int)$pr['show_on_registration'] ? 'On registration' : 'Not on registration' ?></span>
                        <?php if (!$pr['is_active']): ?><span class="badge bg-secondary">Inactive</span><?php endif; ?>
                    </span>
                </div>
                <div class="p-3">
                    <div class="small text-muted mb-2"><?= safe_output($pr['description'] ?? '', 'No description.') ?></div>
                    <?php if (isset($productProfileText[$k])): ?>
                    <div class="small mb-2"><i class="bi bi-gear me-1 text-primary"></i><?= safe_output($productProfileText[$k], '') ?></div>
                    <?php endif; ?>
                    <div class="small mb-2"><i class="bi bi-box-seam me-1 text-primary"></i><?= (int)$st['plans'] ?> plan<?= $st['plans'] === 1 ? '' : 's' ?>,
                        <?= (int)$st['sellable'] ?> on sale (public, active, priced)</div>
                    <?php if ((int)$pr['show_on_registration'] && $pr['is_active'] && !$st['sellable']): ?>
                    <div class="alert alert-warning py-1 px-2 small mb-2"><i class="bi bi-exclamation-triangle me-1"></i>Shown on registration but nothing is on sale — customers see "contact us".</div>
                    <?php endif; ?>
                    <div class="d-flex gap-2">
                        <button class="btn btn-sm btn-outline-primary" onclick="openProduct('<?= safe_output($k, '') ?>')"><i class="bi bi-pencil me-1"></i> Edit product</button>
                        <button class="btn btn-sm btn-outline-primary" onclick="openCreate('<?= safe_output($k, '') ?>')" data-bs-toggle="modal" data-bs-target="#planModal"><i class="bi bi-plus-circle me-1"></i> Plan</button>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if (!$plans): ?>
        <div class="text-center text-muted py-5">
            <i class="bi bi-inbox fs-1 d-block mb-2"></i>
            No plans yet.
            <div class="mt-3">
                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#planModal" onclick="openCreate()">
                    <i class="bi bi-plus-circle me-1"></i> Create the first plan
                </button>
            </div>
        </div>
    <?php else: ?>
    <p class="text-muted small">
        Applying a plan to a tenant sets its modules and quotas in one click — using the same
        <a href="<?= saUrl('features') ?>">Modules</a> switches and usage limits you can already edit per
        tenant. A tenant stays fully, independently editable afterward; nothing here is a hard link.
        A <strong>public</strong> plan with prices is what customers can buy (registration, Subscription, BJP's invoices).
    </p>
    <?php foreach ($planGroups as $gKey => $gPlans): ?>
    <div class="group-title mb-2 mt-2"><i class="bi bi-<?= $gKey === '' ? 'lock' : 'tags' ?> me-1"></i>
        <?= $gKey === '' ? 'Internal plans (not sold)' : safe_output(($productNames[$gKey] ?? $gKey) . ' plans', '') ?></div>
    <div class="row g-3 mb-3">
        <?php foreach ($gPlans as $p): $nMods = count($p['_features']); ?>
        <div class="col-12 col-md-6 col-xl-4">
            <div class="card plan-card detail-card h-100 <?= $p['is_active'] ? '' : 'retired' ?>">
                <div class="card-header d-flex justify-content-between align-items-center gap-2">
                    <span class="text-truncate"><i class="bi bi-box-seam text-primary me-1"></i><?= safe_output($p['name'], '') ?></span>
                    <span class="d-flex gap-1 flex-shrink-0">
                        <?php if (!empty($p['is_popular'])): ?><span class="badge badge-popular">Most popular</span><?php endif; ?>
                        <?php if (!$p['is_active']): ?><span class="badge bg-secondary">Retired</span>
                        <?php elseif ($catalog): ?>
                            <span class="badge <?= !empty($p['is_public']) ? 'badge-public' : 'badge-hidden' ?>"><?= !empty($p['is_public']) ? 'Public' : 'Hidden' ?></span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="card-body d-flex flex-column">
                    <?php if (!empty($p['product_key'])): ?>
                        <div class="mb-1"><span class="badge badge-product"><?= safe_output($productNames[$p['product_key']] ?? $p['product_key'], '') ?></span>
                        <span class="small text-muted ms-1">Tier <?= (int)$p['tier_rank'] ?></span></div>
                    <?php endif; ?>
                    <?php if (!empty($p['tagline'])): ?><div class="small fw-semibold mb-1"><?= safe_output($p['tagline'], '') ?></div><?php endif; ?>
                    <p class="text-muted small mb-2"><?= safe_output($p['description'] ?? '', 'No description.') ?></p>
                    <div class="small mb-2">
                        <i class="bi bi-people me-1 text-primary"></i><?= $p['max_users'] !== null ? (int)$p['max_users'] . ' max users' : 'Unlimited users' ?><br>
                        <?php if ($catalog): ?>
                        <i class="bi bi-shop me-1 text-primary"></i><?= ($p['max_shops'] ?? null) !== null ? (int)$p['max_shops'] . ' max shop' . ((int)$p['max_shops'] === 1 ? '' : 's') : 'Unlimited shops' ?><br>
                        <?php endif; ?>
                        <i class="bi bi-hdd me-1 text-primary"></i><?= $p['max_storage_mb'] !== null ? number_format((int)$p['max_storage_mb']) . ' MB storage' : 'Unlimited storage' ?><br>
                        <i class="bi bi-grid me-1 text-primary"></i><?= $nMods ?> module<?= $nMods === 1 ? '' : 's' ?> included
                    </div>
                    <?php if ($p['_prices']): ?>
                    <ul class="list-unstyled small price-list mb-2">
                        <?php foreach ($p['_prices'] as $pr): ?>
                        <li><span><?= safe_output($pr['term_label'], '') ?><?= (int)$pr['is_default'] ? ' <i class="bi bi-star-fill text-primary" title="Default"></i>' : '' ?></span>
                            <strong>TZS <?= number_format((int)$pr['price_tzs']) ?></strong></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php elseif ($catalog): ?>
                    <div class="small text-muted mb-2"><i class="bi bi-tag me-1"></i>No prices — not for sale.</div>
                    <?php endif; ?>
                    <?php if ($p['_addons']): ?>
                    <div class="small text-muted mb-2"><i class="bi bi-plus-square me-1"></i>Add-ons: <?= safe_output(implode(', ', array_column($p['_addons'], 'name')), '') ?></div>
                    <?php endif; ?>
                    <div class="small text-muted mb-3"><?= (int)$p['tenants_using'] ?> tenant<?= (int)$p['tenants_using'] === 1 ? '' : 's' ?> currently on this plan</div>
                    <div class="mt-auto d-flex gap-2">
                        <button class="btn btn-sm btn-outline-primary flex-fill" onclick="openEdit(<?= (int)$p['id'] ?>)" data-bs-toggle="modal" data-bs-target="#planModal">
                            <i class="bi bi-pencil me-1"></i> Edit
                        </button>
                        <?php if ($p['is_active']): ?>
                        <button class="btn btn-sm btn-outline-secondary" onclick="toggleActive(<?= (int)$p['id'] ?>, false)" title="Retire">
                            <i class="bi bi-pause-circle"></i>
                        </button>
                        <?php else: ?>
                        <button class="btn btn-sm btn-outline-primary" onclick="toggleActive(<?= (int)$p['id'] ?>, true)" title="Restore">
                            <i class="bi bi-play-circle"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($catalog && $products): ?>
<!-- Edit product modal -->
<div class="modal fade" id="productModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-grid-1x2 me-1"></i> Edit product</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="productForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" id="pp_key">
                    <div id="product-message" class="mb-2"></div>
                    <div class="mb-3">
                        <label class="form-label">Product name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="pp_name" required maxlength="100">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <input type="text" class="form-control" id="pp_desc" maxlength="255">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Sort order</label>
                        <input type="number" class="form-control" id="pp_sort" min="0" max="9999">
                    </div>
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" id="pp_show">
                        <label class="form-check-label" for="pp_show">On registration — its public plans are offered to new customers</label>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="pp_active">
                        <label class="form-check-label" for="pp_active">Active</label>
                    </div>
                    <div class="form-text mt-2">The name is display only — what the product does for a tenant is fixed and does not change with it.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> Save product</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Add/Edit modal -->
<div class="modal fade" id="planModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="planModalTitle"><i class="bi bi-box-seam me-1"></i> New plan</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="planForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" id="f_id" name="id">
                    <div id="plan-message" class="mb-2"></div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Plan name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="f_name" name="name" required maxlength="100">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Sort order</label>
                            <input type="number" class="form-control" id="f_sort" name="sort_order" value="0">
                        </div>
                        <?php if ($catalog): ?>
                        <div class="col-md-3">
                            <label class="form-label">Tier <i class="bi bi-info-circle text-primary" title="Higher = bigger plan. Paying for a higher tier applies at once; a lower tier waits for the end of the paid period."></i></label>
                            <input type="number" class="form-control" id="f_tier" min="0" max="1000" value="0">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Product</label>
                            <select class="form-select select2-static" id="f_product">
                                <option value="">— Not sold (internal plan) —</option>
                                <?php foreach ($products as $pr): ?>
                                <option value="<?= safe_output($pr['product_key'], '') ?>"><?= safe_output($pr['name'], '') ?><?= $pr['is_active'] ? '' : ' (inactive)' ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Tagline</label>
                            <input type="text" class="form-control" id="f_tagline" maxlength="150" placeholder="e.g. For one small shop">
                        </div>
                        <?php endif; ?>
                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <input type="text" class="form-control" id="f_desc" name="description" maxlength="255">
                        </div>
                        <?php if ($catalog): ?>
                        <div class="col-12 d-flex gap-4 flex-wrap">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="f_public">
                                <label class="form-check-label" for="f_public">Public — customers can buy it</label>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="f_popular">
                                <label class="form-check-label" for="f_popular">Mark as "Most popular"</label>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="col-12 form-section"><i class="bi bi-speedometer2 me-1"></i> Limits</div>
                        <div class="col-md-4">
                            <label class="form-label">Max active users</label>
                            <input type="text" inputmode="numeric" class="form-control" id="f_users" name="max_users" placeholder="Unlimited">
                        </div>
                        <?php if ($catalog): ?>
                        <div class="col-md-4">
                            <label class="form-label">Max shops</label>
                            <input type="text" inputmode="numeric" class="form-control" id="f_shops" placeholder="Unlimited">
                        </div>
                        <?php endif; ?>
                        <div class="col-md-4">
                            <label class="form-label">Max storage (MB)</label>
                            <input type="text" inputmode="numeric" class="form-control" id="f_storage" name="max_storage_mb" placeholder="Unlimited">
                        </div>

                        <div class="col-12">
                            <label class="form-label">Included modules</label>
                            <div id="productModules" class="product-modules p-2 d-none"></div>
                            <div id="moduleTicks">
                            <div class="feature-pick p-2">
                                <div class="row row-cols-2 row-cols-md-3">
                                    <?php foreach ($availableFeatures as $f): ?>
                                    <div class="col">
                                        <div class="form-check">
                                            <input class="form-check-input plan-feature" type="checkbox" value="<?= safe_output($f['feature_key'], '') ?>" id="pf_<?= safe_output($f['feature_key'], '') ?>">
                                            <label class="form-check-label small" for="pf_<?= safe_output($f['feature_key'], '') ?>"><?= safe_output($f['label'], '') ?></label>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="form-text">Applying this plan turns these modules ON for a tenant and everything else OFF.</div>
                            </div>
                        </div>

                        <?php if ($catalog): ?>
                        <div class="col-12 form-section d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-tag me-1"></i> Prices</span>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="addPriceRow()"><i class="bi bi-plus-circle me-1"></i> Add term</button>
                        </div>
                        <div class="col-12">
                            <div class="table-responsive">
                                <table class="table table-sm mini-table mb-1">
                                    <thead><tr><th style="width:110px">Length</th><th style="width:120px">Unit</th><th>Name shown</th><th style="width:170px">Price (TZS)</th><th style="width:70px" class="text-center">Default</th><th style="width:44px"></th></tr></thead>
                                    <tbody id="priceRows"></tbody>
                                </table>
                            </div>
                            <div class="form-text">A saved term's length cannot change (issued invoices point at it) — remove it and add a new one. Removed terms are retired, never deleted.</div>
                        </div>

                        <div class="col-12 form-section d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-list-check me-1"></i> What's included (extra lines)</span>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="addItemRow()"><i class="bi bi-plus-circle me-1"></i> Add line</button>
                        </div>
                        <div class="col-12">
                            <table class="table table-sm mini-table mb-1">
                                <thead><tr><th>Line</th><th style="width:35%">Value (optional)</th><th style="width:44px"></th></tr></thead>
                                <tbody id="itemRows"></tbody>
                            </table>
                            <div class="form-text">Users, shops, storage and modules are listed automatically from the fields above — no need to type them here.</div>
                        </div>

                        <div class="col-12 form-section d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-plus-square me-1"></i> Add-ons</span>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="addAddonRow()"><i class="bi bi-plus-circle me-1"></i> Add add-on</button>
                        </div>
                        <div class="col-12">
                            <div class="table-responsive">
                                <table class="table table-sm mini-table mb-1">
                                    <thead id="addonHead"></thead>
                                    <tbody id="addonRows"></tbody>
                                </table>
                            </div>
                            <div class="form-text">Priced per unit for each term (e.g. one extra shop for 6 months). An add-on line on an invoice always follows the plan's term.</div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> Save plan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const SA_CSRF_TOKEN = '<?= csrf_token() ?>';
const CATALOG = <?= $catalog ? 'true' : 'false' ?>;
const PLANS = <?= json_encode((object)$planData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const PRODUCTS = <?= json_encode((object)$productData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const PRODUCT_MODULES = <?= json_encode((object)$productModules, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
$.ajaxSetup({ headers: { 'X-CSRF-Token': SA_CSRF_TOKEN } });

// A sold product fixes its modules (the server applies the same list), so the
// ticks are only for internal plans; a product shows its list read-only.
const PRODUCT_MODE_TEXT = {
    bms_shop: 'Same setup as self-registration: Simple Mode (locked), Shop wording and Supplier Access.',
    bms_full: 'Simple Mode and Shop wording are off for its tenants.',
};
function applyProductRules() {
    const pk = CATALOG ? ($('#f_product').val() || '') : '';
    const mods = PRODUCT_MODULES[pk];
    const box = $('#productModules');
    if (mods) {
        const name = esc((PRODUCTS[pk] || {}).name || 'This product');
        box.html('<div class="small fw-semibold mb-2" style="color:#052c65"><i class="bi bi-lock me-1"></i>' + name
                 + ' always includes these modules — no ticks needed.</div>'
                 + '<div class="d-flex flex-wrap gap-1 mb-2">'
                 + mods.map(m => '<span class="mod-chip"><i class="bi bi-check2"></i>' + esc(m) + '</span>').join('') + '</div>'
                 + '<div class="small text-muted">' + esc(PRODUCT_MODE_TEXT[pk] || '')
                 + ' Extra modules for one customer are granted on that tenant.</div>').removeClass('d-none');
        $('#moduleTicks').addClass('d-none');
    } else {
        box.addClass('d-none').html('');
        $('#moduleTicks').removeClass('d-none');
    }
}
$(document).on('change', '#f_product', applyProductRules);

function openProduct(key) {
    const p = PRODUCTS[key];
    if (!p) return;
    $('#pp_key').val(p.product_key);
    $('#pp_name').val(p.name);
    $('#pp_desc').val(p.description || '');
    $('#pp_sort').val(p.sort_order || 0);
    $('#pp_show').prop('checked', !!p.show_on_registration);
    $('#pp_active').prop('checked', !!p.is_active);
    $('#product-message').html('');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('productModal')).show();
}
$('#productForm').on('submit', function (e) {
    e.preventDefault();
    const btn = $(this).find('[type="submit"]'), orig = btn.html();
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');
    $.ajax({ url: '/actions/superadmin_plans.php', method: 'POST', dataType: 'json', data: {
        _csrf: SA_CSRF_TOKEN, action: 'save_product', product_key: $('#pp_key').val(),
        name: $('#pp_name').val(), description: $('#pp_desc').val(), sort_order: $('#pp_sort').val(),
        show_on_registration: $('#pp_show').is(':checked') ? '1' : '0', is_active: $('#pp_active').is(':checked') ? '1' : '0',
    }}).done(function (res) {
        if (res && res.success) {
            bootstrap.Modal.getInstance(document.getElementById('productModal')).hide();
            Swal.fire({ icon: 'success', title: 'Saved', text: res.message, timer: 1600, showConfirmButton: false }).then(() => location.reload());
        } else {
            $('#product-message').html('<div class="alert alert-danger py-2 mb-0">' + esc((res && res.message) || 'Could not save.') + '</div>');
        }
    }).fail(function (xhr) {
        let msg = 'Could not save.';
        try { const j = JSON.parse(xhr.responseText); if (j && j.message) msg = j.message; } catch (e) {}
        $('#product-message').html('<div class="alert alert-danger py-2 mb-0">' + esc(msg) + '</div>');
    }).always(function () { btn.prop('disabled', false).html(orig); });
});

function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function termKey(v, u) { return parseInt(v, 10) + (u === 'year' ? 'y' : 'm'); }
function defaultLabel(v, u) { v = parseInt(v, 10) || 0; return u === 'year' ? (v === 1 ? '1 year' : v + ' years') : (v === 1 ? '1 month' : v + ' months'); }

$('#planModal').on('shown.bs.modal', function () {
    $(this).find('.select2-static').each(function () {
        if (!$(this).hasClass('select2-hidden-accessible')) {
            $(this).select2({ theme: 'bootstrap-5', dropdownParent: $('#planModal'), width: '100%' });
        }
    });
});

// ── Prices ──
function addPriceRow(p) {
    p = p || { id: 0, duration_value: '', duration_unit: 'month', term_label: '', price_tzs: '', is_default: 0 };
    const saved = p.id > 0;
    const tr = $(`<tr class="price-row" data-id="${p.id || 0}">
        <td><input type="number" min="1" max="120" class="form-control form-control-sm pr-value" value="${esc(p.duration_value)}" ${saved ? 'disabled' : ''}></td>
        <td><select class="form-select form-select-sm pr-unit" ${saved ? 'disabled' : ''}>
            <option value="month" ${p.duration_unit === 'month' ? 'selected' : ''}>month(s)</option>
            <option value="year" ${p.duration_unit === 'year' ? 'selected' : ''}>year(s)</option></select></td>
        <td><input type="text" maxlength="60" class="form-control form-control-sm pr-label" value="${esc(p.term_label)}" placeholder="auto"></td>
        <td><input type="text" inputmode="numeric" class="form-control form-control-sm pr-price" value="${p.price_tzs !== '' ? esc(Number(p.price_tzs).toLocaleString('en-US')) : ''}"></td>
        <td class="text-center"><input type="radio" name="pr_default" class="form-check-input pr-default" ${p.is_default ? 'checked' : ''}></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger" title="Remove"><i class="bi bi-trash"></i></button></td>
    </tr>`);
    tr.find('.btn-outline-danger').on('click', function () { tr.remove(); renderAddonHead(); });
    tr.find('.pr-value, .pr-unit').on('change input', function () {
        const lbl = tr.find('.pr-label');
        if (!lbl.data('touched')) lbl.attr('placeholder', defaultLabel(tr.find('.pr-value').val(), tr.find('.pr-unit').val()));
        renderAddonHead();
    });
    tr.find('.pr-label').on('input', function () { $(this).data('touched', true); renderAddonHead(); });
    $('#priceRows').append(tr);
    renderAddonHead();
}
function collectPriceTerms() {
    const terms = [];
    $('#priceRows .price-row').each(function () {
        const v = $(this).find('.pr-value').val(), u = $(this).find('.pr-unit').val();
        if (!v) return;
        terms.push({ key: termKey(v, u), label: $(this).find('.pr-label').val() || defaultLabel(v, u) });
    });
    return terms;
}

// ── Included lines ──
function addItemRow(i) {
    i = i || { label: '', value: '' };
    const tr = $(`<tr class="item-row">
        <td><input type="text" maxlength="100" class="form-control form-control-sm it-label" value="${esc(i.label)}" placeholder="e.g. Free setup"></td>
        <td><input type="text" maxlength="100" class="form-control form-control-sm it-value" value="${esc(i.value)}"></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger" title="Remove"><i class="bi bi-trash"></i></button></td>
    </tr>`);
    tr.find('button').on('click', () => tr.remove());
    $('#itemRows').append(tr);
}

// ── Add-ons (one price column per term) ──
function renderAddonHead() {
    if (!CATALOG) return;
    const terms = collectPriceTerms();
    let h = '<tr><th>Name</th><th style="width:130px">Raises</th><th style="width:80px">Units</th>';
    terms.forEach(t => { h += `<th style="width:130px">${esc(t.label)}</th>`; });
    h += '<th style="width:44px"></th></tr>';
    $('#addonHead').html(h);
    $('#addonRows .addon-row').each(function () {
        const row = $(this), keep = {};
        row.find('.ad-price').each(function () { keep[$(this).data('term')] = $(this).val(); });
        row.find('.ad-price-cell').remove();
        let cells = '';
        terms.forEach(t => { cells += `<td class="ad-price-cell"><input type="text" inputmode="numeric" class="form-control form-control-sm ad-price" data-term="${esc(t.key)}" value="${esc(keep[t.key] || '')}"></td>`; });
        row.find('.ad-remove').before(cells);
    });
}
function addAddonRow(a) {
    a = a || { id: 0, name: '', quota: 'max_shops', units: 1, prices: {} };
    const tr = $(`<tr class="addon-row" data-id="${a.id || 0}">
        <td><input type="text" maxlength="100" class="form-control form-control-sm ad-name" value="${esc(a.name)}" placeholder="e.g. Extra shop"></td>
        <td><select class="form-select form-select-sm ad-quota">
            <option value="max_shops" ${a.quota === 'max_shops' ? 'selected' : ''}>Shops</option>
            <option value="max_users" ${a.quota === 'max_users' ? 'selected' : ''}>Users</option></select></td>
        <td><input type="number" min="1" max="1000" class="form-control form-control-sm ad-units" value="${esc(a.units)}"></td>
        <td class="ad-remove"><button type="button" class="btn btn-sm btn-outline-danger" title="Remove"><i class="bi bi-trash"></i></button></td>
    </tr>`);
    tr.find('.ad-remove button').on('click', () => tr.remove());
    $('#addonRows').append(tr);
    renderAddonHead();
    Object.keys(a.prices || {}).forEach(k => {
        tr.find(`.ad-price[data-term="${k}"]`).val(Number(a.prices[k]).toLocaleString('en-US'));
    });
}

function resetForm() {
    $('#planForm')[0].reset();
    $('#f_id').val('');
    $('.plan-feature').prop('checked', false);
    $('#plan-message').html('');
    $('#priceRows, #itemRows, #addonRows').empty();
    if (CATALOG) { $('#f_product').val('').trigger('change'); renderAddonHead(); }
}

function openCreate(productKey) {
    resetForm();
    if (CATALOG && productKey) $('#f_product').val(productKey).trigger('change');
    $('#planModalTitle').html('<i class="bi bi-box-seam me-1"></i> New plan');
}

function openEdit(id) {
    const p = PLANS[id];
    if (!p) return;
    resetForm();
    $('#f_id').val(p.id);
    $('#f_name').val(p.name);
    $('#f_desc').val(p.description || '');
    $('#f_users').val(p.max_users !== null ? p.max_users : '');
    $('#f_storage').val(p.max_storage_mb !== null ? p.max_storage_mb : '');
    $('#f_sort').val(p.sort_order || 0);
    (p.feature_keys || []).forEach(function (k) { $('#pf_' + k).prop('checked', true); });
    if (CATALOG) {
        $('#f_product').val(p.product_key || '').trigger('change');   // runs applyProductRules
        $('#f_tagline').val(p.tagline || '');
        $('#f_shops').val(p.max_shops !== null ? p.max_shops : '');
        $('#f_tier').val(p.tier_rank || 0);
        $('#f_public').prop('checked', !!p.is_public);
        $('#f_popular').prop('checked', !!p.is_popular);
        (p.prices || []).forEach(addPriceRow);
        (p.items || []).forEach(addItemRow);
        (p.addons || []).forEach(addAddonRow);
    }
    $('#planModalTitle').html('<i class="bi bi-pencil me-1"></i> Edit plan');
}

$('#planForm').on('submit', function (e) {
    e.preventDefault();
    const btn = $(this).find('[type="submit"]');
    const orig = btn.html();

    const featureKeys = $('.plan-feature:checked').map(function () { return this.value; }).get();
    const data = {
        _csrf: SA_CSRF_TOKEN,
        action: $('#f_id').val() ? 'update' : 'create',
        id: $('#f_id').val(),
        name: $('#f_name').val(),
        description: $('#f_desc').val(),
        max_users: $('#f_users').val(),
        max_storage_mb: $('#f_storage').val(),
        sort_order: $('#f_sort').val(),
        feature_keys: featureKeys,
    };
    if (CATALOG) {
        Object.assign(data, {
            catalog: '1',
            product_key: $('#f_product').val() || '',
            tagline: $('#f_tagline').val(),
            max_shops: $('#f_shops').val(),
            tier_rank: $('#f_tier').val(),
            is_public: $('#f_public').is(':checked') ? '1' : '0',
            is_popular: $('#f_popular').is(':checked') ? '1' : '0',
            prices: $('#priceRows .price-row').map(function () {
                const r = $(this);
                return { id: r.data('id'), duration_value: r.find('.pr-value').val(), duration_unit: r.find('.pr-unit').val(),
                         term_label: r.find('.pr-label').val(), price_tzs: r.find('.pr-price').val().replace(/[,\s]/g, ''),
                         is_default: r.find('.pr-default').is(':checked') ? '1' : '0', is_active: '1' };
            }).get(),
            items: $('#itemRows .item-row').map(function () {
                return { label: $(this).find('.it-label').val(), value: $(this).find('.it-value').val() };
            }).get(),
            addons: $('#addonRows .addon-row').map(function () {
                const r = $(this), prices = {};
                r.find('.ad-price').each(function () { const v = $(this).val().replace(/[,\s]/g, ''); if (v !== '') prices[$(this).data('term')] = v; });
                return { id: r.data('id'), name: r.find('.ad-name').val(), quota: r.find('.ad-quota').val(),
                         units: r.find('.ad-units').val(), is_active: '1', prices: prices };
            }).get(),
        });
    }

    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');
    $.ajax({ url: '/actions/superadmin_plans.php', method: 'POST', dataType: 'json', data: data })
        .done(function (res) {
            if (res && res.success) {
                bootstrap.Modal.getInstance(document.getElementById('planModal')).hide();
                Swal.fire({ icon: 'success', title: 'Saved', text: res.message, timer: 1800, showConfirmButton: false })
                    .then(() => location.reload());
            } else {
                $('#plan-message').html('<div class="alert alert-danger py-2 mb-0">' + esc((res && res.message) || 'Could not save.') + '</div>');
            }
        })
        .fail(function (xhr) {
            let msg = 'Could not save.';
            try { const j = JSON.parse(xhr.responseText); if (j && j.message) msg = j.message; } catch (e) {}
            $('#plan-message').html('<div class="alert alert-danger py-2 mb-0">' + esc(msg) + '</div>');
            $('#planModal .modal-body').scrollTop(0);
        })
        .always(function () { btn.prop('disabled', false).html(orig); });
});

function toggleActive(id, active) {
    Swal.fire({
        title: active ? 'Restore this plan?' : 'Retire this plan?',
        text: active ? 'It becomes available to apply to tenants again.' : 'It can no longer be applied to new tenants or bought. Tenants already on it are unaffected.',
        icon: 'question', showCancelButton: true, confirmButtonColor: '#0d6efd',
        confirmButtonText: active ? 'Restore' : 'Retire',
    }).then(function (r) {
        if (!r.isConfirmed) return;
        $.ajax({
            url: '/actions/superadmin_plans.php', method: 'POST', dataType: 'json',
            data: { _csrf: SA_CSRF_TOKEN, action: 'toggle_active', id: id, active: active ? 1 : 0 },
        }).done(function (res) {
            if (res && res.success) {
                Swal.fire({ icon: 'success', title: 'Done', timer: 1500, showConfirmButton: false }).then(() => location.reload());
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: (res && res.message) || 'Could not update.' });
            }
        }).fail(function () { Swal.fire({ icon: 'error', title: 'Error', text: 'Could not update.' }); });
    });
}
</script>
</body>
</html>
