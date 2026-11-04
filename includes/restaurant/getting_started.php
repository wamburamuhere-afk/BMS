<?php
/**
 * includes/restaurant/getting_started.php — "Getting started" checklist
 * (restaurant_plan.md Phase 1c). Included by the restaurant hub (and later the
 * restaurant dashboard). Expects: $pdo, and $rgsWarehouses = restaurant shops in
 * scope (core/restaurant_scope.php::restaurantWarehousesForSelect()).
 * Renders nothing when there is no restaurant shop or every step is done.
 */
if (empty($rgsWarehouses) || !restaurantModuleOn($pdo)) return;

$rgsShop  = $rgsWarehouses[0];
$rgsWid   = (int)$rgsShop['warehouse_id'];
$rgsSteps = restaurantGettingStarted($pdo, $rgsWid);
$rgsDone  = count(array_filter($rgsSteps, fn($s) => $s['done']));
if ($rgsDone === count($rgsSteps)) return;
$rgsCanEdit = canEdit('restaurant_pos');
$rgsSuggest = [
    ['name' => t('Tea'),          'price' => 500,  'type' => 'dish'],
    ['name' => t('Coffee'),       'price' => 500,  'type' => 'dish'],
    ['name' => t('Mandazi'),      'price' => 200,  'type' => 'dish'],
    ['name' => t('Chapati'),      'price' => 300,  'type' => 'dish'],
    ['name' => t('Lunch'),        'price' => 5000, 'type' => 'dish'],
    ['name' => t('Chicken soup'), 'price' => 3000, 'type' => 'dish'],
    ['name' => t('Fish soup'),    'price' => 3000, 'type' => 'dish'],
    ['name' => t('Chips'),        'price' => 2500, 'type' => 'dish'],
    ['name' => t('Water'),        'price' => 500,  'type' => 'bought'],
    ['name' => t('Soda'),         'price' => 700,  'type' => 'bought'],
];
?>
<div class="card border-0 shadow-sm mb-4 rgs-card" id="rgsCard">
    <div class="card-body p-3 p-md-4">
        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
            <div class="min-w-0">
                <h5 class="mb-1 fw-bold"><i class="bi bi-rocket-takeoff text-primary me-2"></i><?= t('Getting started') ?></h5>
                <div class="small text-muted"><?= sprintf(t('Set up %s and take your first order.'), safe_output($rgsShop['warehouse_name'])) ?></div>
            </div>
            <span class="badge rounded-pill flex-shrink-0" style="background:#0d6efd;"><?= $rgsDone ?>/<?= count($rgsSteps) ?></span>
        </div>
        <div class="progress mb-3" style="height:6px;"><div class="progress-bar" style="width:<?= round($rgsDone / count($rgsSteps) * 100) ?>%"></div></div>
        <div class="list-group list-group-flush">
            <?php foreach ($rgsSteps as $s): ?>
            <div class="list-group-item px-0 d-flex align-items-center gap-2 flex-wrap">
                <i class="bi <?= $s['done'] ? 'bi-check-circle-fill text-primary' : 'bi-circle text-muted' ?> fs-5"></i>
                <span class="flex-grow-1 <?= $s['done'] ? 'text-muted text-decoration-line-through' : 'fw-semibold' ?>"><?= safe_output($s['label']) ?></span>
                <?php if (!$s['done']): ?>
                    <?php if ($s['key'] === 'tables' && $rgsCanEdit): ?>
                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#rgsTablesModal"><?= t('Add tables') ?></button>
                    <?php elseif ($s['key'] === 'menu' && $rgsCanEdit): ?>
                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#rgsMenuModal"><?= t('Quick add') ?></button>
                    <a href="<?= getUrl($s['url']) ?>" class="btn btn-sm btn-outline-primary"><?= t('Open Menu') ?></a>
                    <?php else: ?>
                    <a href="<?= getUrl($s['url']) ?>" class="btn btn-sm btn-outline-primary"><?= t('Open') ?> <i class="bi bi-arrow-right"></i></a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php if ($rgsCanEdit): ?>
<div class="modal fade" id="rgsTablesModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><?= t('Add tables') ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="rgsTablesForm" autocomplete="off">
                <div class="modal-body">
                    <label class="form-label fw-semibold"><?= t('How many tables do you have?') ?></label>
                    <input type="number" class="form-control form-control-lg text-center" name="count" min="1" max="100" value="5" inputmode="numeric" required>
                    <div class="small text-muted mt-2"><?= t('They are named T1, T2, T3… You can rename them later.') ?></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i><?= t('Save') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/form_kit.php'; rfKitAssets(); $rgsCur = htmlspecialchars(rfMoneyPrefix()); ?>
<style>
.rgs-head, .rgs-row { display: grid; grid-template-columns: 28px minmax(0, 1fr) 150px 96px; gap: 8px; align-items: center; }
.rgs-head { font-size: 11.5px; font-weight: 700; color: #6c757d; text-transform: uppercase; letter-spacing: .05em; padding: 0 0 6px; border-bottom: 1px solid #eef0f3; }
.rgs-row { padding: 8px 0; border-bottom: 1px solid #f1f3f5; }
.rgs-row:last-child { border-bottom: 0; }
.rgs-row .form-control { font-size: 14px; min-height: 38px; border-radius: 8px; }
.rgs-row .rf-money .form-control { border-radius: 0 8px 8px 0; }
.rgs-row .rf-money .rf-cur { min-width: 46px; border-radius: 8px 0 0 8px; }
.rgs-row .rgs-pick { width: 1.35em; height: 1.35em; margin: 0; }
.rgs-nostock { color: #adb5bd; text-align: center; font-size: 12px; }
@media (max-width: 575.98px) {
    .rgs-head { display: none; }
    .rgs-row { grid-template-columns: 28px minmax(0, 1fr) minmax(0, 1fr); grid-template-areas: "pick name name" ". price stock"; }
    .rgs-row .rgs-pick { grid-area: pick; } .rgs-row .rgs-name { grid-area: name; } .rgs-row .rgs-pricecell { grid-area: price; } .rgs-row .rgs-stockcell { grid-area: stock; }
}
</style>
<div class="modal fade rf-modal" id="rgsMenuModal" tabindex="-1" aria-labelledby="rgsMenuTitle">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="rgsMenuTitle"><i class="bi bi-journal-richtext"></i><?= t('What do you sell?') ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="<?= htmlspecialchars(t('Close')) ?>"></button>
            </div>
            <form id="rgsMenuForm" autocomplete="off">
                <div class="modal-body">
                    <div class="rf-help mt-0 mb-3"><?= t('Tick what you sell and set your price. You can change everything later on the Menu.') ?></div>
                    <?php
                    $rgsGroups = [
                        'dish'   => ['icon' => 'ri ri-cooking-pot', 'title' => t('Dishes'), 'stock' => false],
                        'bought' => ['icon' => 'ri ri-cup-soda',    'title' => t('Drinks (counted)'), 'stock' => true],
                    ];
                    foreach ($rgsGroups as $rgsType => $g):
                        $rows = array_filter($rgsSuggest, fn($it) => $it['type'] === $rgsType);
                        if (!$rows) continue; ?>
                    <div class="rf-sec">
                        <div class="rf-sec-title"><i class="<?= $g['icon'] ?>"></i><?= $g['title'] ?></div>
                        <div class="rgs-head"><span></span><span><?= t('Item') ?></span><span><?= t('Selling price') ?></span><span><?= $g['stock'] ? t('Stock') : '' ?></span></div>
                        <?php foreach ($rows as $it): ?>
                        <div class="rgs-row" data-type="<?= $it['type'] ?>">
                            <input class="form-check-input rgs-pick" type="checkbox" aria-label="<?= htmlspecialchars($it['name']) ?>">
                            <input type="text" class="form-control rgs-name" value="<?= htmlspecialchars($it['name']) ?>" maxlength="150" aria-label="<?= htmlspecialchars(t('Item')) ?>">
                            <div class="rgs-pricecell"><div class="input-group rf-money"><span class="input-group-text rf-cur"><?= $rgsCur ?></span>
                                <input type="number" class="form-control rgs-price" value="<?= (int)$it['price'] ?>" min="0" step="any" inputmode="decimal" aria-label="<?= htmlspecialchars(t('Selling price')) ?>"></div></div>
                            <div class="rgs-stockcell">
                                <?php if ($it['type'] === 'bought'): ?>
                                <input type="number" class="form-control text-end rgs-stock" placeholder="<?= htmlspecialchars(t('Stock')) ?>" min="0" step="any" inputmode="decimal" aria-label="<?= htmlspecialchars(t('Stock')) ?>">
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                    <div class="rf-help mt-2"><i class="bi bi-info-circle me-1"></i><?= t('Water and soda are counted as stock; dishes sell without stock.') ?></div>
                </div>
                <div class="modal-footer rf-foot">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i><?= t('Add to menu') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    const URL = '<?= buildUrl('api/restaurant/quick_setup.php') ?>';
    const BASE = { _csrf: <?= json_encode(csrf_token()) ?>, warehouse_id: <?= $rgsWid ?> };
    const S = { saving: <?= json_encode(t('Saving...')) ?>, error: <?= json_encode(t('Error')) ?>, serverError: <?= json_encode(t('Server error. Please try again.')) ?>, tickOne: <?= json_encode(t('Tick at least one item.')) ?> };
    function send(form, data, modalId) {
        const btn = $(form).find('[type="submit"]'); const orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> ' + S.saving);
        $.ajax({ url: URL, type: 'POST', data: Object.assign({}, BASE, data), dataType: 'json',
            success: function (res) {
                if (res.success) {
                    bootstrap.Modal.getInstance(document.getElementById(modalId)).hide();
                    Swal.fire({ icon: 'success', title: res.message, timer: 1800, showConfirmButton: false }).then(() => location.reload());
                } else Swal.fire({ icon: 'error', title: S.error, text: res.message });
            },
            error: () => Swal.fire({ icon: 'error', title: S.error, text: S.serverError }),
            complete: () => btn.prop('disabled', false).html(orig)
        });
    }
    $('#rgsTablesForm').on('submit', function (e) { e.preventDefault(); send(this, { action: 'tables', count: $(this).find('[name=count]').val() }, 'rgsTablesModal'); });
    $('#rgsMenuForm').on('submit', function (e) {
        e.preventDefault();
        const items = [];
        $(this).find('.rgs-row').each(function () {
            if (!$(this).find('.rgs-pick').is(':checked')) return;
            items.push({ name: $(this).find('.rgs-name').val(), price: $(this).find('.rgs-price').val(), type: $(this).data('type'), stock: $(this).find('.rgs-stock').val() || 0 });
        });
        if (!items.length) { Swal.fire({ icon: 'info', title: S.tickOne }); return; }
        send(this, { action: 'menu', items: JSON.stringify(items) }, 'rgsMenuModal');
    });
    // Ticking a row as you type a price feels natural on a phone.
    $('#rgsMenuForm').on('input', '.rgs-price, .rgs-stock, .rgs-name', function () { $(this).closest('.rgs-row').find('.rgs-pick').prop('checked', true); });
})();
</script>
<?php endif; ?>
<style>.rgs-card { border-left: 4px solid #0d6efd !important; }</style>
