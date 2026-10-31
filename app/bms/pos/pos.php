<?php
// File: pos.php
// scope-audit: skip — POS terminal page; POS project scope (pos_sales.project_id) deferred to Phase G-2
// Start the buffer
ob_start();

// Enforce permission BEFORE any output
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('pos');

// Include the header
require_once 'header.php';

// Get current user info
$user_id = $_SESSION['user_id'];
$shift_id = isset($_SESSION['shift_id']) ? $_SESSION['shift_id'] : null;

// Check if shift is active
$shift_active = false;
if ($shift_id) {
    $stmt = $pdo->prepare("SELECT * FROM cash_register_shifts WHERE shift_id = ? AND user_id = ? AND status = 'active'");
    $stmt->execute([$shift_id, $user_id]);
    $shift_active = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get active shift data or check for existing active shift
if (!$shift_active) {
    $stmt = $pdo->prepare("SELECT * FROM cash_register_shifts WHERE user_id = ? AND status = 'active' ORDER BY start_time DESC LIMIT 1");
    $stmt->execute([$user_id]);
    $shift_active = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($shift_active) {
        $_SESSION['shift_id'] = $shift_active['shift_id'];
    }
}

// Get cash register balance if shift active
$cash_balance = 0;
$starting_cash = 0;
if ($shift_active) {
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN transaction_type = 'cash_in' THEN amount ELSE 0 END), 0) as cash_in,
            COALESCE(SUM(CASE WHEN transaction_type = 'cash_out' THEN amount ELSE 0 END), 0) as cash_out,
            COALESCE(SUM(CASE WHEN payment_method = 'cash' AND transaction_type = 'sale' THEN amount ELSE 0 END), 0) as cash_sales,
            COALESCE(SUM(CASE WHEN payment_method = 'cash' AND transaction_type = 'refund' THEN amount ELSE 0 END), 0) as cash_refunds
        FROM cash_register_transactions 
        WHERE shift_id = ?
    ");
    $stmt->execute([$shift_active['shift_id']]);
    $cash_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $starting_cash = $shift_active['starting_cash'];
    $cash_balance = $starting_cash + 
                   $cash_data['cash_in'] - 
                   $cash_data['cash_out'] + 
                   $cash_data['cash_sales'] - 
                   $cash_data['cash_refunds'];
}

// Get tax rates
$tax_rates = $pdo->query("SELECT * FROM tax_rates WHERE status = 'active' ORDER BY rate_name")->fetchAll(PDO::FETCH_ASSOC);

// Get payment methods
$payment_methods = [
    'cash' => t('Cash'),
    'card' => t('Credit/Debit Card'),
    'mobile_money' => t('Mobile Money'),
    'bank_transfer' => t('Bank Transfer'),
    'credit' => t('Customer Credit')
];

// Phase 11 (pos_upgrade_plan.md §7) — the tenant's own configured operating
// currency, not a hardcoded TZS. This is the professional baseline for a
// single-currency business (the vast majority of SMEs): every price, receipt,
// and report reads the one company-wide currency from Settings. Genuine
// multi-currency (accepting foreign-currency tender at checkout with a live
// FX rate per sale) is a materially larger feature — pos_sales.currency/
// exchange_rate columns already exist for it — and is intentionally NOT built
// here; see pos_upgrade_plan.md §Phase 11 for the recommendation.
$currency = getSetting('currency', 'TZS');

// Phase 11 (pos_upgrade_plan.md §7) — loyalty program on/off.
$loyalty_enabled = getSetting('pos_loyalty_enabled', '0') === '1';

// Phase 14 (pos_upgrade_plan.md §8) — selling price tiers. Available to all
// POS users so every cashier can switch between Retail/Wholesale price groups.
$price_groups_enabled = true;
$price_groups = $pdo->query("SELECT price_group_id, name, is_default FROM price_groups WHERE status = 'active' ORDER BY is_default DESC, name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Phase 20 (pos_upgrade_plan.md §8) — cash denomination counting.
require_once ROOT_DIR . '/core/pos_denominations.php';
$pos_denomination_list = posDenominationList();

// Phase 30 (pos_upgrade_plan.md §9) — restaurant module entitlement + the
// per-warehouse pos_mode map.
$restaurant_pos_enabled = canView('restaurant_pos');

// Shared Project ↔ Warehouse mechanism (core/warehouse_scope.php) narrows
// by project scope first; then narrowed again to this specific user's own
// warehouse assignment (Phase 6, pos_upgrade_plan.md) — a cashier only ever
// sees the warehouse(s) they're personally assigned to here, never every
// warehouse their project touches. Still calls warehousesForSelect() /
// renderWarehouseOptions() per tests/test_warehouse_project_filter_cli.php.
require_once ROOT_DIR . '/core/warehouse_scope.php';
$_pos_project_scoped = warehousesForSelect($pdo);
$_pos_warehouse_scoped = array_values(array_filter(
    $_pos_project_scoped,
    fn($w) => userCan('warehouse', (int)$w['warehouse_id'])
));

// Phase 30 (pos_upgrade_plan.md §9) — a warehouse-scoped pos_mode map, so the
// JS layer can show/hide restaurant affordances (table picker, send-to-kitchen)
// purely client-side on warehouse change, no extra round trip. Built here, BEFORE
// the <script> that prints POS_WAREHOUSE_MODES — it used to be built further
// down inside the warehouse <select>, after it had already been printed, so the
// map was always empty and every shop behaved as retail (2026-10-31).
// Only looked up when Restaurant POS is enabled: with it off every shop stays
// 'retail' (the JS reads a missing id as 'retail') — today's behaviour exactly.
// Defensive: a tenant whose database hasn't yet had the Phase 30 migration
// applied (warehouses.pos_mode missing) must NEVER crash the whole POS terminal
// over this — degrade to treating every warehouse as 'retail' instead.
$_pos_warehouse_modes = [];
if ($restaurant_pos_enabled && !empty($_pos_warehouse_scoped)) {
    try {
        $_ids = array_column($_pos_warehouse_scoped, 'warehouse_id');
        $_ph = implode(',', array_fill(0, count($_ids), '?'));
        $_modeStmt = $pdo->prepare("SELECT warehouse_id, pos_mode FROM warehouses WHERE warehouse_id IN ($_ph)");
        $_modeStmt->execute($_ids);
        foreach ($_modeStmt->fetchAll(PDO::FETCH_ASSOC) as $_mr) {
            $_pos_warehouse_modes[(int)$_mr['warehouse_id']] = $_mr['pos_mode'];
        }
    } catch (PDOException $_e) {
        error_log('pos.php: pos_mode lookup failed (tenant DB likely missing the Phase 30 migration) — degrading to retail-only: ' . $_e->getMessage());
        $_pos_warehouse_modes = [];
    }
}

// "Add Product" shortcut (pos_upgrade_plan.md-style) — two options gated
// independently: New Product needs the same permission product_create.php
// itself enforces; Restock Product needs adjust_stock, the same permission
// the Products page's own stock-adjustment action already requires (it's a
// stock/pricing action reachable from POS, not a sales action).
$can_add_new_product = canCreate('products');
$can_restock_product  = canView('pos_restock');

// Record Old Sale (Rekodi Mauzo ya Zamani) — a sale made on an earlier day,
// typed in now. Role-granted permission; rules live in core/pos_old_sales.php.
require_once ROOT_DIR . '/core/pos_old_sales.php';
$can_record_old_sale = canView('pos_past_sales');
$old_sale_max_days   = posOldSaleMaxDays();

$pos_simple = posSimpleModeEnabled();
// A shift still open from an earlier day: cash totals keep accumulating into it.
$shift_from_earlier_day = $shift_active && date('Y-m-d', strtotime($shift_active['start_time'])) < date('Y-m-d');
$cashier_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''))
    ?: ($_SESSION['username'] ?? t('User'));
?>
<script>
// Phase 30 (pos_upgrade_plan.md §9) — populated once from PHP, never fetched
// per warehouse-change; the JS layer just looks up the selected id.
const POS_WAREHOUSE_MODES = <?= json_encode($_pos_warehouse_modes ?? []) ?>;
const POS_RESTAURANT_ENABLED = <?= json_encode($restaurant_pos_enabled) ?>;
// Shop scope (2026-09-16) — non-null when the active shift was opened on a
// shop-assigned register; the Shop dropdown gets locked to this value so a
// cashier can never sell against a different shop mid-shift (see the lock
// logic near the bottom of pos_scripts_new.php).
const POS_SHIFT_WAREHOUSE_ID = <?= json_encode(($shift_active && !empty($shift_active['warehouse_id'])) ? (int)$shift_active['warehouse_id'] : null) ?>;
const POS_USER_ID = <?= (int)$user_id ?>;
</script>

<div class="container-fluid px-0<?= $pos_simple ? ' pos-simple' : '' ?>" id="pos-container" style="height: auto; min-height: 100vh;">
    <!-- Hidden input that captures barcode scanner keystrokes (scanner acts as keyboard) -->
    <input id="hiddenScanInput" type="text" autocomplete="off" aria-hidden="true"
           style="position:fixed;left:-9999px;width:1px;height:1px;opacity:0;pointer-events:none;"
           tabindex="-1">

    <!-- Critical mobile CSS hoisted above posHeaderBar to prevent FOUC:
         without this, the browser renders posHeaderBar in the desktop horizontal
         layout first, then reflows when it reaches the full <style> block at the
         bottom of this file. Duplicating just the layout-changing rules here so
         the first paint is already correct on phones. -->
    <style>
    @media (max-width: 767.98px) {
        #posHeaderBar {
            flex-direction: column !important;
            align-items: stretch !important;
            gap: 10px;
            padding: 14px 14px !important;
            border-radius: 12px;   /* rounded like the Actions button inside it */
        }
        #posHeaderBar > .d-flex.align-items-center.gap-3 {
            width: 100%;
            flex-direction: column;
            align-items: stretch !important;
            gap: 10px !important;
            padding-top: 10px;
            border-top: 1px solid rgba(255,255,255,.2);
        }
        #posShiftButtons {
            display: flex;
            gap: 8px;
            width: 100%;
        }
        #posShiftButtons .btn {
            flex: 1 1 0;
            margin-right: 0 !important;
        }
        #posHeaderBar .pos-hdr-top { display: flex; align-items: center; gap: 8px; }
        /* Phones: the bar is one line; shift info, balance and the buttons move
           into the Actions sheet. The button block is kept in the page (height 0,
           hidden) because the Record Old Sale pop-up lives inside it and is
           shown from there as a fixed bottom sheet. */
        #posHeaderBar.pos-hdr-collapsed #posShiftInfoLine { display: none !important; }
        #posHeaderBar.pos-hdr-collapsed > .d-flex.align-items-center.gap-3 {
            display: block !important; height: 0; overflow: hidden; visibility: hidden;
            padding: 0 !important; border: 0 !important; margin-top: -10px;
        }
        #posHeaderBar.pos-hdr-collapsed #oldSalePopover { visibility: visible; }
    }
    </style>
    <!-- POS Header -->
    <!-- Phones: the bar is one line (title · scanner dot · balance · Actions);
         "Actions" opens #posActionsSheet with the shift details and every action. -->
    <div id="posHeaderBar" class="bg-primary text-white py-2 px-3 d-flex justify-content-between align-items-center pos-hdr-collapsed">
        <div>
            <div class="pos-hdr-top">
            <h4 class="mb-0">
                <i class="bi bi-cash-register"></i> <span class="pos-hdr-title"><?= htmlspecialchars(wLabel('Point of Sale', 'Sell')) ?></span>
                <span id="scannerReadyBadge" class="badge bg-light text-success ms-2 small fw-normal"
                      style="font-size:0.65rem;vertical-align:middle;display:none;"
                      title="<?= t('Barcode scanner active — scan a product to add it to the cart') ?>"
                      aria-label="<?= htmlspecialchars(t('Scanner ready')) ?>">
                    <i class="bi bi-upc-scan"></i> <span class="pos-scan-txt"><?= t('SCANNER READY') ?></span>
                </span>
            </h4>
            <?php if ($shift_active): ?>
            <span class="pos-hdr-mini-bal d-md-none"><small><?= t('Cash in drawer') ?></small><span class="cash-balance-display"><?= htmlspecialchars($currency) ?> <?= number_format((float)$cash_balance, 2) ?></span></span>
            <?php else: ?>
            <span class="pos-hdr-mini-bal d-md-none"><?= t('No shift') ?></span>
            <?php endif; ?>
            <button type="button" class="pos-hdr-actions d-md-none" id="posActionsBtn"
                    data-bs-toggle="offcanvas" data-bs-target="#posActionsSheet" aria-controls="posActionsSheet" aria-haspopup="dialog">
                <i class="bi bi-grid-fill"></i> <?= t('Actions') ?> <i class="bi bi-chevron-down"></i>
            </button>
            </div>
            <small class="opacity-75" id="posShiftInfoLine">
                <?php if ($shift_active): ?>
                <span class="pos-shift-info-item"><?= t('Shift:') ?> <?= $shift_active['shift_code'] ?></span><span class="pos-shift-info-sep"> | </span><span class="pos-shift-info-item"><?= t('Started:') ?> <?= date('H:i', strtotime($shift_active['start_time'])) ?></span><span class="pos-shift-info-sep"> | </span><span class="pos-shift-info-item"><?= t('Cashier:') ?> <?= htmlspecialchars($cashier_name) ?></span>
                <?php else: ?>
                <?= t('No active shift') ?>
                <?php endif; ?>
            </small>
        </div>
        <div class="d-flex align-items-center gap-3">
            <div class="text-center">
                <div class="fs-6"><?= t('Cash Balance') ?></div>
                <div class="fs-4 fw-bold cash-balance-display"><?= htmlspecialchars($currency) ?> <?= number_format((float)$cash_balance, 2) ?></div>
                <small><?= t('Starting:') ?> <?= htmlspecialchars($currency) ?> <?= number_format((float)$starting_cash, 2) ?></small>
            </div>
            <div class="vr text-white opacity-50"></div>
            <div id="posShiftButtons">
                <!-- The terminal is the default landing page for POS (header.php's
                     "POS" link goes straight here) — this is the one way back to
                     the stats/shift-history/catalog-setup hub for whoever wants it,
                     without forcing every cashier through it first every day. -->
                <a href="<?= getUrl('pos/dashboard') ?>" class="btn btn-outline-light btn-sm me-2" title="<?= wLabel('POS Workspace — stats, shift history, catalog setup', 'Sales Overview — stats, shift history, catalog setup') ?>">
                    <i class="bi bi-speedometer2"></i> <span><?= htmlspecialchars(wLabel('Workspace', 'Overview')) ?></span>
                </a>
                <?php if ($can_record_old_sale): ?>
                <div class="pos-old-sale-wrap me-2" id="oldSaleWrap">
                    <button type="button" class="btn btn-outline-light btn-sm" id="oldSaleBtn" onclick="toggleOldSalePopover()"
                            aria-haspopup="dialog" aria-expanded="false" aria-controls="oldSalePopover"
                            title="<?= htmlspecialchars(t('Record a sale made on an earlier day')) ?>">
                        <i class="bi bi-clock-history"></i>
                        <span class="d-none d-md-inline"><?= t('Record Old Sale') ?></span><span class="d-md-none"><?= t('Old Sale') ?></span>
                    </button>
                    <span class="pos-old-sale-chip d-none" id="oldSaleChip">
                        <button type="button" class="pos-old-sale-chip-open" onclick="toggleOldSalePopover()" title="<?= htmlspecialchars(t('Change the date or time')) ?>">
                            <i class="bi bi-clock-history"></i> <span id="oldSaleChipText"></span>
                        </button>
                        <button type="button" class="pos-old-sale-chip-x" onclick="exitOldSaleMode()" aria-label="<?= htmlspecialchars(t('Exit old-sale mode')) ?>" title="<?= htmlspecialchars(t('Exit old-sale mode')) ?>">&times;</button>
                    </span>
                    <div class="pos-old-sale-pop d-none" id="oldSalePopover" role="dialog" aria-modal="false" aria-labelledby="oldSalePopTitle">
                        <div class="fw-semibold mb-2" id="oldSalePopTitle"><?= t('When was this sale made?') ?></div>
                        <!-- One "When" field; tapping it opens a day list (last N days) and a time list. -->
                        <input type="hidden" id="oldSaleDate">
                        <input type="hidden" id="oldSaleTime" value="12:00">
                        <button type="button" class="pos-old-sale-when mb-2" id="oldSaleWhenBtn" onclick="toggleOldSalePicker()"
                                aria-expanded="false" aria-controls="oldSalePicker">
                            <i class="bi bi-calendar-event"></i><span id="oldSaleWhenText"></span><i class="bi bi-chevron-down"></i>
                        </button>
                        <div class="pos-old-sale-picker d-none mb-2" id="oldSalePicker">
                            <ul id="oldSaleDayList" aria-label="<?= htmlspecialchars(t('Day')) ?>"></ul>
                            <ul id="oldSaleTimeList" aria-label="<?= htmlspecialchars(t('Time')) ?>"></ul>
                        </div>
                        <div class="small mb-2" id="oldSalePreview" aria-live="polite"></div>
                        <div class="d-flex justify-content-end gap-2">
                            <button type="button" class="btn btn-sm btn-light border" onclick="closeOldSalePopover()"><?= t('Cancel') ?></button>
                            <button type="button" class="btn btn-sm btn-primary" id="oldSaleStartBtn" onclick="startOldSaleMode()" disabled><?= t('Start recording') ?></button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($shift_active): ?>
                <button class="btn btn-light btn-sm me-2" onclick="openCashDrawer()">
                    <i class="bi bi-cash"></i> <span><?= t('Open Drawer') ?></span>
                </button>
                <button class="btn btn-outline-light btn-sm pos-end-shift-btn" onclick="endShift()">
                    <i class="bi bi-power"></i> <span><?= t('End Shift') ?></span>
                </button>
                <?php else: ?>
                <button class="btn btn-warning btn-sm" onclick="startShift()">
                    <i class="bi bi-play-circle"></i> <?= t('Start Shift') ?>
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($can_record_old_sale): ?>
    <!-- Phones: while an old sale is being recorded, say so right under the bar
         (the desktop shows the date chip in the header buttons instead). -->
    <div class="pos-old-sale-strip d-none d-md-none" id="posOldSaleStrip" role="status">
        <i class="bi bi-clock-history"></i>
        <span id="posOldSaleStripText"></span>
        <button type="button" onclick="toggleOldSalePopover()"><?= t('Change') ?></button>
        <button type="button" onclick="exitOldSaleMode()"><?= t('Stop') ?></button>
    </div>
    <?php endif; ?>

    <!-- Phones: every header action in one sheet, each explained in a line, so a
         new cashier knows what each one does. Rows call the same functions as the
         desktop buttons, after the sheet has closed (posAction()). -->
    <div class="offcanvas offcanvas-bottom pos-actions-sheet d-md-none" tabindex="-1" id="posActionsSheet" aria-labelledby="posActionsTitle">
        <div class="pos-actions-grab" aria-hidden="true"></div>
        <div class="pos-actions-head">
            <?php if ($shift_active): ?>
            <div style="min-width:0">
                <div class="fw-bold" id="posActionsTitle"><?= sprintf(t('Shift open since %s'), date('H:i', strtotime($shift_active['start_time']))) ?></div>
                <small class="text-muted"><?= htmlspecialchars($shift_active['shift_code']) ?> · <?= htmlspecialchars($cashier_name) ?></small>
            </div>
            <div class="text-end">
                <div class="fw-bold text-nowrap cash-balance-display"><?= htmlspecialchars($currency) ?> <?= number_format((float)$cash_balance, 2) ?></div>
                <small class="text-muted text-nowrap"><?= t('Starting:') ?> <?= htmlspecialchars($currency) ?> <?= number_format((float)$starting_cash, 2) ?></small>
            </div>
            <?php else: ?>
            <div>
                <div class="fw-bold" id="posActionsTitle"><?= t('No shift open') ?></div>
                <small class="text-muted"><?= t('Start a shift before you sell today') ?></small>
            </div>
            <?php endif; ?>
            <button type="button" class="btn-close ms-2" data-bs-dismiss="offcanvas" aria-label="<?= t('Close') ?>"></button>
        </div>
        <div class="pos-actions-list">
            <?php if (!$shift_active): ?>
            <button type="button" class="pos-act pos-act-warn" onclick="posAction(startShift)">
                <span class="pos-act-i"><i class="bi bi-play-circle"></i></span>
                <span><b><?= t('Start Shift') ?></b><small><?= t('Count the cash in the drawer and begin selling') ?></small></span>
            </button>
            <?php endif; ?>
            <button type="button" class="pos-act" onclick="posAction(openCashDrawer)" <?= $shift_active ? '' : 'disabled' ?>>
                <span class="pos-act-i"><i class="bi bi-cash"></i></span>
                <span><b><?= t('Open Drawer') ?></b><small><?= $shift_active ? t('Open the cash drawer without a sale') : t('Start a shift first') ?></small></span>
            </button>
            <?php if ($can_record_old_sale): ?>
            <button type="button" class="pos-act" id="posActOldSale" onclick="posAction(toggleOldSalePopover)">
                <span class="pos-act-i"><i class="bi bi-clock-history"></i></span>
                <span><b id="posActOldSaleTitle"><?= t('Record Old Sale') ?></b><small id="posActOldSaleHelp"><?= t('A sale from an earlier day you forgot to enter') ?></small></span>
            </button>
            <button type="button" class="pos-act d-none" id="posActOldSaleExit" onclick="posAction(exitOldSaleMode)">
                <span class="pos-act-i"><i class="bi bi-x-circle"></i></span>
                <span><b><?= t('Stop recording old sale') ?></b><small><?= t("Go back to today's sales") ?></small></span>
            </button>
            <?php endif; ?>
            <a class="pos-act" href="<?= getUrl('pos/dashboard') ?>">
                <span class="pos-act-i"><i class="bi bi-speedometer2"></i></span>
                <span><b><?= htmlspecialchars(wLabel('Workspace', 'Sales Overview')) ?></b><small><?= t("Today's totals, past shifts and receipts") ?></small></span>
            </a>
            <?php if ($shift_active): ?>
            <button type="button" class="pos-act pos-act-danger" onclick="posAction(endShift)">
                <span class="pos-act-i"><i class="bi bi-power"></i></span>
                <span><b><?= t('End Shift') ?></b><small><?= t('Count your cash and close the day') ?></small></span>
            </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($pos_simple && $shift_from_earlier_day): ?>
    <!-- Dismissible; comes back every 24 hours counted from the shift's opening time.
         data-period = completed 24h periods since opening (server clock). -->
    <div class="alert alert-warning d-flex flex-wrap align-items-center justify-content-between gap-2 rounded-0 mb-0 py-2 pe-5 position-relative" id="posOldShiftNotice"
         data-shift-id="<?= (int)$shift_active['shift_id'] ?>"
         data-period="<?= (int)floor(max(0, time() - strtotime($shift_active['start_time'])) / 86400) ?>">
        <span><i class="bi bi-exclamation-triangle-fill me-1"></i>
            <?= sprintf(htmlspecialchars(t('This shift was opened on %s. Close the day so your cash count stays correct.')), '<strong>' . date('d/m/Y', strtotime($shift_active['start_time'])) . '</strong>') ?>
        </span>
        <button type="button" class="btn btn-sm btn-dark" onclick="endShift()"><i class="bi bi-power me-1"></i><?= t('Close the Day') ?></button>
        <button type="button" class="btn-close position-absolute top-0 end-0 m-2" id="posOldShiftDismiss" aria-label="<?= htmlspecialchars(t('Close')) ?>"></button>
    </div>
    <script>
    (function () {
        var n = document.getElementById('posOldShiftNotice');
        var key = 'pos_oldshift_dismissed_' + n.dataset.shiftId, period = parseInt(n.dataset.period, 10);
        var seen = null;
        try { seen = localStorage.getItem(key); } catch (e) {}
        // remove(), not style.display: the notice's .d-flex is display:flex !important.
        if (seen !== null && parseInt(seen, 10) >= period) { n.remove(); return; }
        document.getElementById('posOldShiftDismiss').addEventListener('click', function () {
            try { localStorage.setItem(key, String(period)); } catch (e) {}
            n.remove();
        });
    })();
    </script>
    <?php endif; ?>

    <!-- Main POS Layout -->
    <div class="row g-0<?= $pos_simple ? ' pos-simple-layout' : '' ?>">
        <!-- Left Column: Product Selection -->
        <div class="col-md-7" style="border-right: 1px solid #dee2e6;">
            <!-- Product Search & Categories -->
            <div class="bg-light p-3 border-bottom sticky-top" style="z-index: 1020; top: 0;">
                <!-- Warehouse & Project Selection -->
                <div class="row g-2 mb-3">
                    <div class="<?= ($pos_simple && $can_restock_product) ? 'col ' : '' ?>col-md-<?= projectsModuleActive() ? 6 : 12 ?>">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-white"><i class="bi bi-house-door text-primary"></i></span>
                            <select class="form-select" id="posWarehouseId" onchange="loadProducts()" required>
                                <option value="" selected disabled><?= wLabel('— Select Warehouse —', '— Select Shop —', true) ?></option>
                                <?php
                                // Shared Project ↔ Warehouse mechanism (core/warehouse_scope.php) narrows
                                // by project scope first; then narrowed again to this specific user's own
                                // warehouse assignment (Phase 6, pos_upgrade_plan.md) — a cashier only ever
                                // sees the warehouse(s) they're personally assigned to here, never every
                                // warehouse their project touches. Still calls warehousesForSelect() /
                                // renderWarehouseOptions() per tests/test_warehouse_project_filter_cli.php.
                                // $_pos_warehouse_scoped is built near the top of this file,
                                // before POS_WAREHOUSE_MODES is printed.
                                // Shop scope (2026-09-16): a shift opened on a shop-assigned
                                // register locks every sale in that shift to that one shop —
                                // pre-select it here so the dropdown already reflects it on
                                // page load; the JS lock below disables the control so it can
                                // never be changed mid-shift. A shift on a still-unassigned
                                // ("legacy") register leaves this null — normal free choice.
                                $_pos_shift_locked_warehouse_id = ($shift_active && !empty($shift_active['warehouse_id']))
                                    ? (int)$shift_active['warehouse_id'] : null;
                                echo renderWarehouseOptions($_pos_warehouse_scoped, $_pos_shift_locked_warehouse_id);
                                ?>
                            </select>
                        </div>
                    </div>
                    <?php if ($pos_simple && $can_restock_product): ?>
                    <!-- Phone: Receive Stock sits on the shop row (desktop copy is beside the search). -->
                    <div class="col-auto d-md-none">
                        <button type="button" class="btn btn-primary btn-sm pos-restock-btn" onclick="openRestockProductModal()">
                            <i class="bi bi-box-arrow-in-down me-1"></i><?= t('Receive Stock') ?>
                        </button>
                    </div>
                    <?php endif; ?>
                    <?php if (projectsModuleActive()): ?>
                    <div class="col-md-6">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-white"><i class="bi bi-briefcase text-info"></i></span>
                            <select class="form-select" id="posProjectId">
                                <option value=""><?= t('General (No Project)') ?></option>
                                <?php
                                $projects = projectsForSelect($pdo);
                                foreach ($projects as $p) {
                                    echo "<option value='{$p['project_id']}'>{$p['project_name']}</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="row g-2">
                    <div class="<?= $pos_simple ? 'col' : 'col-md-5' ?>">
                        <div class="input-group<?= $pos_simple ? ' flex-nowrap pos-search-group' : '' ?>">
                            <input type="text" class="form-control" id="productSearch"
                                   placeholder="<?= t('Search product by name, SKU or barcode') ?>" autofocus>
                            <button class="btn btn-outline-secondary" type="button" onclick="searchProducts()">
                                <i class="bi bi-search"></i>
                            </button>
                            <?php if ($can_add_new_product || $can_restock_product): ?>
                            <!-- data-bs-display="static": the global .dropdown-menu fadeIn
                                 animation overrides Popper's transform, which flung this
                                 menu to the screen's top-right corner. -->
                            <button class="btn btn-outline-success dropdown-toggle" type="button"
                                    id="addProductDropdown" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false"
                                    title="<?= t('Add Product') ?>">
                                <i class="bi bi-plus-circle"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="addProductDropdown">
                                <?php if ($can_add_new_product): ?>
                                <li><a class="dropdown-item" href="#" onclick="openNewProductPage(); return false;">
                                    <i class="bi bi-box-seam me-2"></i><?= t('New Product') ?>
                                </a></li>
                                <?php endif; ?>
                                <?php if ($can_restock_product): ?>
                                <li><a class="dropdown-item" href="#" onclick="openRestockProductModal(); return false;">
                                    <i class="bi bi-arrow-repeat me-2"></i><?= t('Restock Product') ?>
                                </a></li>
                                <?php endif; ?>
                                <?php if (canView('warehouses') && isShopLabel(true)): ?>
                                <li><a class="dropdown-item" href="<?= getUrl('warehouses') ?>">
                                    <i class="bi bi-shop me-2"></i><?= wLabel('Warehouses', 'Shop', true) ?>
                                </a></li>
                                <?php endif; ?>
                            </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($pos_simple && $can_restock_product): ?>
                    <div class="col-auto d-none d-md-block">
                        <button type="button" class="btn btn-primary pos-restock-btn" id="posRestockBtn" onclick="openRestockProductModal()">
                            <i class="bi bi-box-arrow-in-down me-1"></i><?= t('Receive Stock') ?>
                        </button>
                    </div>
                    <?php endif; ?>
                    <div class="col-12 mt-2">
                        <div class="category-scroll-strip" id="categoryButtons">
                            <button type="button" class="btn btn-sm btn-outline-primary active" onclick="loadProductsByCategory('all')">
                                <?= t('All Products') ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Product Grid -->
            <div class="p-3">
                <div class="row g-3" id="productGrid" style="min-height: 400px;">
                    <!-- Products will be loaded here -->
                </div>
                <div id="loadingProducts" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden"><?= t('Loading products...') ?></span>
                    </div>
                    <p class="mt-2 text-muted"><?= t('Loading products...') ?></p>
                </div>
            </div>
        </div>

        <!-- Right Column: Cart & Checkout -->
        <div class="col-md-5 bg-light">
            <!-- Current Sale Header -->
            <div class="p-3 border-bottom bg-white">
                <div id="cartHeaderTop" class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0"><i class="bi bi-cart3" id="cartTitleIcon"></i> <span id="cartTitleText"><?= t('Current Sale') ?></span></h5>
                    <div id="cartHeaderActions" class="btn-group btn-group-sm">
                        <?php if (canEdit('pos_discount_override')): ?>
                        <button class="btn btn-outline-warning" onclick="openDiscountModal()" title="<?= t('Apply Discount') ?>">
                            <i class="bi bi-percent"></i><?php if ($pos_simple): ?><span class="pos-btn-label"> <?= t('Discount') ?></span><?php endif; ?>
                        </button>
                        <?php endif; ?>
                        <button class="btn btn-outline-danger" onclick="clearCart()" title="<?= t('Clear Cart') ?>">
                            <i class="bi bi-trash"></i><?php if ($pos_simple): ?><span class="pos-btn-label"> <?= t('Clear') ?></span><?php endif; ?>
                        </button>
                        <button class="btn btn-outline-secondary" onclick="holdSale()" title="<?= t('Hold Sale') ?>">
                            <i class="bi bi-pause"></i><?php if ($pos_simple): ?><span class="pos-btn-label"> <?= t('Hold') ?></span><?php endif; ?>
                        </button>
                        <button class="btn btn-outline-info" onclick="showHeldSales()" title="<?= t('View Held Sales') ?>">
                            <i class="bi bi-list"></i><?php if ($pos_simple): ?><span class="pos-btn-label"> <?= t('Held') ?></span><?php endif; ?>
                        </button>
                        <!-- Phase 30 (pos_upgrade_plan.md §9) — restaurant-only affordances,
                             hidden unless the selected warehouse's pos_mode !== 'retail' AND
                             the tenant holds restaurant_pos. A plain retail warehouse never
                             shows these. -->
                        <button class="btn btn-outline-success d-none" id="restaurantTableBtn" onclick="openTablePicker()" title="<?= t('Select Table') ?>">
                            <i class="bi bi-grid-3x3-gap"></i>
                        </button>
                        <button class="btn btn-outline-dark d-none" id="sendToKitchenBtn" onclick="sendCurrentOrderToKitchen()" title="<?= t('Send to Kitchen') ?>">
                            <i class="bi bi-fire"></i>
                        </button>
                    </div>
                </div>
                <div class="alert alert-primary py-2 px-2 small mb-2 d-none" id="oldSaleCartNote" role="status"></div>
                <div class="row g-2 small">
                    <div class="col-6">
                        <div class="text-muted"><?= t('Receipt #') ?></div>
                        <strong id="receiptNumber" class="small"><?= generate_receipt_number() ?></strong>
                    </div>
                    <div class="col-6 text-end">
                        <div class="text-muted"><?= t('Items') ?></div>
                        <strong id="cartItemCount" class="badge bg-primary">0</strong>
                    </div>
                </div>
                <div class="mt-2 d-none" id="restaurantTableIndicator">
                    <span class="badge bg-success"><i class="bi bi-table"></i> <span id="restaurantTableIndicatorText"></span></span>
                    <button type="button" class="btn btn-link btn-sm p-0 ms-1 text-decoration-none" onclick="clearTableSelection()"><?= t('change') ?></button>
                </div>
            </div>

            <!-- Cart Items -->
            <div class="flex-grow-1 p-2" id="cartItemsScrollArea" style="overflow-y: auto; background: #f8f9fa;">
                <table class="table table-sm table-hover bg-white" id="cartTable" style="display: none;">
                    <thead class="table-light">
                        <tr>
                            <th width="35%"><?= t('Product') ?></th>
                            <th width="15%" class="text-end"><?= sprintf(t('Price (%s)'), htmlspecialchars($currency)) ?></th>
                            <th width="20%" class="text-center"><?= t('Qty') ?></th>
                            <th width="20%" class="text-end"><?= sprintf(t('Total (%s)'), htmlspecialchars($currency)) ?></th>
                            <th width="10%" class="text-center"><?= t('Action') ?></th>
                        </tr>
                    </thead>
                    <tbody id="cartBody">
                        <!-- Cart items will be added here -->
                    </tbody>
                </table>
                <div id="emptyCart" class="text-center py-5 bg-white rounded">
                    <i class="bi bi-cart-x" style="font-size: 3rem; color: #6c757d;"></i>
                    <p class="text-muted mt-2 mb-1"><?= t('Cart is empty') ?></p>
                    <p class="text-muted small"><?= t('Search or browse products to add items') ?></p>
                </div>
            </div>

            <!-- Cart Summary -->
            <div class="p-3 border-top bg-white pos-cart-summary">
                <div class="mb-2">
                    <div class="d-flex justify-content-between mb-1">
                        <span class="text-muted"><?= t('Subtotal:') ?></span>
                        <strong id="cartSubtotal"><?= htmlspecialchars($currency) ?> 0.00</strong>
                    </div>
                    <div class="d-flex justify-content-between mb-1" id="discountRow" style="display: none !important;">
                        <span class="text-muted"><?= t('Discount') ?> (<span id="discountPercentageDisplay">0</span>%):</span>
                        <strong id="cartDiscount" class="text-danger">-<?= htmlspecialchars($currency) ?> 0.00</strong>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-muted"><?= t('VAT:') ?></span>
                        <select id="saleVatSelect" class="form-select form-select-sm" style="width:auto;min-width:140px;">
                            <option value="0" selected><?= t('No Tax (0%)') ?></option>
                            <option value="18"><?= t('VAT 18%') ?></option>
                        </select>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted"><?= t('Total Tax:') ?></span>
                        <strong id="cartTax"><?= htmlspecialchars($currency) ?> 0.00</strong>
                    </div>
                    <div class="d-flex justify-content-between border-top pt-2">
                        <h6 class="mb-0"><?= t('TOTAL:') ?></h6>
                        <h5 class="mb-0 text-success" id="cartTotal"><?= htmlspecialchars($currency) ?> 0.00</h5>
                    </div>
                </div>
            </div>

            <!-- Payment Section -->
            <div class="p-3 border-top bg-white pos-pay-fields">
                <div class="mb-2">
                    <label class="form-label small fw-bold"><?= t('Customer') ?></label>
                    <div class="input-group input-group-sm">
                        <select class="form-select form-select-sm" id="customerSelect" style="width:1%;flex:1 1 auto;">
                            <option value=""><?= t('Walk-in Customer') ?></option>
                        </select>
                        <button type="button" class="btn btn-outline-secondary" id="btnQuickAddCustomer" title="<?= t('Add new customer') ?>">
                            <i class="bi bi-person-plus"></i>
                        </button>
                    </div>
                    <!-- Phase 19 (pos_upgrade_plan.md §8) — customer credit limit -->
                    <div class="d-none small mt-1" id="customerCreditInfo"></div>
                </div>

                <?php if ($price_groups_enabled && count($price_groups) > 1): ?>
                <!-- Price Group — Phase 14 (pos_upgrade_plan.md §8) -->
                <div class="mb-2">
                    <label class="form-label small fw-bold"><?= t('Price Group') ?></label>
                    <select class="form-select form-select-sm" id="posPriceGroupId">
                        <?php foreach ($price_groups as $pg): ?>
                        <option value="<?= (int)$pg['price_group_id'] ?>" <?= $pg['is_default'] ? 'selected' : '' ?>>
                            <?= caseFormat($pg['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <?php if ($loyalty_enabled): ?>
                <!-- Loyalty Points — Phase 11 (pos_upgrade_plan.md §7) -->
                <div class="mb-2 d-none" id="loyaltyPointsSection">
                    <div class="d-flex justify-content-between align-items-center small mb-1">
                        <span class="text-muted"><?= t('Loyalty Points Available:') ?></span>
                        <strong id="loyaltyAvailablePoints" class="text-success">0</strong>
                    </div>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><?= t('Redeem') ?></span>
                        <input type="number" class="form-control" id="redeemPointsInput" min="0" step="1" value="0" oninput="calculateCartTotal()">
                        <span class="input-group-text"><?= t('pts') ?></span>
                    </div>
                    <div class="text-end small text-danger mt-1 d-none" id="loyaltyDiscountPreview"></div>
                </div>
                <?php endif; ?>

                <div class="mb-2">
                    <label class="form-label small fw-bold"><?= t('Payment Method') ?></label>
                    <div class="btn-group w-100" role="group" id="paymentMethodGroup">
                        <?php foreach ($payment_methods as $value => $label): ?>
                            <input type="radio" class="btn-check" name="paymentMethod"
                                   id="payment<?= ucfirst($value) ?>" value="<?= $value ?>"
                                   <?= $value == 'cash' ? 'checked' : '' ?>>
                            <label class="btn btn-outline-primary btn-sm" for="payment<?= ucfirst($value) ?>">
                                <?php if ($value == 'mobile_money'): ?>
                                    <?= t('Mobile') ?>
                                <?php elseif ($value == 'bank_transfer'): ?>
                                    <?= t('Bank') ?>
                                <?php elseif ($value == 'card'): ?>
                                    <span class="d-none d-md-inline"><?= $label ?></span>
                                    <span class="d-inline d-md-none"><?= t('Card') ?></span>
                                <?php elseif ($value == 'credit'): ?>
                                    <span class="d-none d-md-inline"><?= $label ?></span>
                                    <span class="d-inline d-md-none"><?= t('Credit') ?></span>
                                <?php else: ?>
                                    <?= $label ?>
                                <?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Cash Payment Specific -->
                <div id="cashPaymentSection">
                    <div class="mb-2">
                        <label class="form-label small fw-bold"><?= t('Amount Tendered') ?></label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text"><?= htmlspecialchars($currency) ?></span>
                            <input type="number" class="form-control" id="amountTendered"
                                   min="0" step="0.01" value="0" oninput="calculateChange()">
                        </div>
                    </div>
                    <div class="alert alert-success py-2" id="changeAlert" style="display: none;">
                        <div class="d-flex justify-content-between small">
                            <span><?= t('Change Due:') ?></span>
                            <strong id="changeAmount"><?= htmlspecialchars($currency) ?> 0.00</strong>
                        </div>
                    </div>
                </div>

                <?php if ($pos_simple): ?>
            </div>
            <!-- Simple Mode: Pay/Split in their own block, pinned to the bottom of the cart panel. -->
            <div class="px-3 pb-3 pt-2 bg-white pos-pay-actions">
                <div class="d-flex justify-content-between align-items-center mb-2 pos-pay-total">
                    <span class="fw-bold"><?= t('TOTAL:') ?></span>
                    <span class="fs-5 fw-bold text-success" id="posPayTotal"><?= htmlspecialchars($currency) ?> 0.00</span>
                </div>
                <?php endif; ?>
                <!-- Action Buttons -->
                <div class="d-grid gap-2">
                    <button class="btn btn-success btn-lg" onclick="processPayment()" id="processPaymentBtn">
                        <i class="bi bi-check-circle"></i> <?= t('PROCESS PAYMENT') ?>
                    </button>
                    <button class="btn btn-outline-primary" onclick="openSplitPaymentModal()">
                        <i class="bi bi-columns-gap"></i> <?= t('SPLIT PAYMENT') ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── Mobile Cart FAB ───────────────────────────────────────────────────
     Fixed bottom-right button (phone-only: d-md-none). Shown only when the
     cart has ≥1 item (JS controls display). Blue to match the POS primary
     colour; count badge is white-on-blue for clean contrast without the
     alarm-red that belongs only on errors/warnings.
     ──────────────────────────────────────────────────────────────────────── -->
<button id="mobileCartFab"
        type="button"
        class="d-md-none btn btn-primary shadow-lg"
        data-bs-toggle="offcanvas"
        data-bs-target="#mobileCartOffcanvas"
        aria-label="<?= t('View Cart') ?>"
        style="position:fixed;bottom:24px;right:20px;width:58px;height:58px;border-radius:50%;font-size:1.25rem;z-index:1055;display:none;padding:0;">
    <i class="bi bi-cart3"></i>
    <span id="mobileCartBadge"
          class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-white text-primary fw-bold"
          style="font-size:0.65rem;min-width:20px;padding:2px 5px;border:2px solid #0d6efd;">0</span>
</button>

<!-- ── Mobile Cart Offcanvas (bottom sheet) ──────────────────────────────
     Slides up 72 % of the screen. Fully functional: item list + total +
     Process Payment. The trigger (FAB) is d-md-none so desktop users never
     see this; the offcanvas element itself has no display restriction so
     Bootstrap can control its visibility normally.
     ──────────────────────────────────────────────────────────────────────── -->
<div class="offcanvas offcanvas-bottom"
     id="mobileCartOffcanvas"
     tabindex="-1"
     style="height:72vh;max-height:72vh;border-radius:16px 16px 0 0;">
    <div class="offcanvas-header border-bottom py-2 px-3">
        <h6 class="offcanvas-title mb-0 fw-bold">
            <i class="bi bi-cart3 me-1 text-primary"></i>
            <span id="mobileCartTitleText"><?= t('Current Sale') ?></span>
            <span id="mobileCartOffcanvasCount" class="badge bg-primary ms-1 fw-normal">0</span>
        </h6>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="<?= t('Close') ?>"></button>
    </div>
    <div class="offcanvas-body px-3 py-0" style="overflow-y:auto;">
        <div id="mobileCartOffcanvasItems">
            <!-- Populated by updateMobileCartFab() whenever the cart changes -->
        </div>
        <!-- Simple Mode: the real VAT/total + customer/payment controls are moved here while the sheet is open. -->
        <div id="mobileSheetExtra"></div>
    </div>
    <!-- Simple Mode: the real Pay/Split block is moved here while the sheet is open. -->
    <div id="mobileSheetFooterSlot" style="flex-shrink:0;"></div>
    <div class="p-3 border-top bg-white" style="flex-shrink:0;" id="mobileSheetDefaultFooter">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="fw-semibold text-muted small"><?= t('Total:') ?></span>
            <span class="fw-bold fs-6 text-success" id="mobileCartOffcanvasTotal"><?= htmlspecialchars($currency) ?> 0.00</span>
        </div>
        <button class="btn btn-success w-100 fw-bold"
                data-bs-dismiss="offcanvas"
                onclick="processPayment()" id="mobilePayBtn">
            <i class="bi bi-check-circle me-1"></i><?= t('PROCESS PAYMENT') ?>
        </button>
    </div>
</div>

<?php include 'pos_modals_new.php'; ?>
<?php include 'pos_scripts_new.php'; ?>
<script>
    $(document).ready(function() {
        logReportAction('Viewed POS Page', 'User opened the Point of Sale interface');
    });
</script>

<style>
/* Record Old Sale — header button, pop-up (a bottom sheet on phones), chip
   while the mode is on, and the cart header tint. White and blue, like the POS. */
.pos-old-sale-wrap { display: inline-block; position: relative; vertical-align: middle; }
.pos-old-sale-pop {
    position: absolute; top: calc(100% + 8px); right: 0; width: 320px; z-index: 1085;
    background: #fff; color: #212529; text-align: left; font-size: .875rem;
    border: 1px solid #dee2e6; border-radius: 10px; padding: 12px 14px;
    box-shadow: 0 10px 30px rgba(13, 32, 64, .22);
}
.pos-old-sale-pop::before {
    content: ""; position: absolute; top: -7px; right: 28px; width: 12px; height: 12px;
    background: #fff; border-left: 1px solid #dee2e6; border-top: 1px solid #dee2e6; transform: rotate(45deg);
}
.pos-old-sale-pop .btn-outline-primary.active { background: #0d6efd; color: #fff; }
.pos-old-sale-when {
    display: flex; align-items: center; gap: 8px; width: 100%; min-height: 46px; padding: 6px 12px;
    border: 1px solid #0d6efd; border-radius: 10px; background: #fff; color: #212529; text-align: left; font-weight: 600;
}
.pos-old-sale-when .bi-calendar-event { color: #0d6efd; font-size: 1.05rem; }
.pos-old-sale-when span { flex: 1; min-width: 0; }
.pos-old-sale-when .bi-chevron-down { transition: transform .2s; }
.pos-old-sale-when[aria-expanded="true"] .bi-chevron-down { transform: rotate(180deg); }
.pos-old-sale-when:focus-visible { outline: 2px solid #0d6efd; outline-offset: 2px; }
.pos-old-sale-picker {
    display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr); height: 210px;
    border: 1px solid #dee2e6; border-radius: 10px; overflow: hidden;
}
.pos-old-sale-picker ul { list-style: none; margin: 0; padding: 4px; overflow-y: auto; }
.pos-old-sale-picker ul + ul { border-left: 1px solid #dee2e6; }
.pos-old-sale-picker button {
    display: block; width: 100%; text-align: left; border: 0; background: none; padding: 7px 8px;
    border-radius: 6px; font-size: .84rem; color: #212529; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.pos-old-sale-picker button small { display: block; font-size: .72rem; opacity: .75; }
.pos-old-sale-picker button:hover { background: #f1f4f8; }
.pos-old-sale-picker button.active { background: #0d6efd; color: #fff; }
.pos-old-sale-backdrop { position: fixed; inset: 0; background: rgba(13, 32, 64, .42); z-index: 1084; }
.pos-old-sale-chip {
    display: inline-flex; align-items: center; gap: 4px; background: #fff; color: #0a58ca;
    border-radius: 20px; padding: 2px 4px 2px 10px; font-size: .82rem; font-weight: 600; white-space: nowrap;
}
.pos-old-sale-chip button { border: 0; background: transparent; color: inherit; font-weight: 600; padding: 2px 0; }
.pos-old-sale-chip .pos-old-sale-chip-x {
    width: 24px; height: 24px; border-radius: 50%; background: #e7f1ff; line-height: 1; font-size: 1rem; padding: 0;
}
.pos-old-sale-chip button:focus-visible, .pos-old-sale-wrap .btn:focus-visible { outline: 2px solid #fff; outline-offset: 2px; }
.pos-old-sale-head { background: #e7f1ff !important; border-bottom: 2px solid #0d6efd !important; }
.pos-old-sale-head #cartTitleText { color: #0b3a82; }
@media (max-width: 767.98px) {
    #posShiftButtons .pos-old-sale-wrap { flex: 1 1 0; display: flex; margin-right: 0 !important; }
    #posShiftButtons .pos-old-sale-wrap > .btn { width: 100%; }
    #posShiftButtons .pos-old-sale-chip { width: 100%; justify-content: space-between; }
    .pos-old-sale-pop {
        position: fixed; top: auto; left: 0; right: 0; bottom: 0; width: auto;
        border-radius: 16px 16px 0 0; padding-bottom: calc(16px + env(safe-area-inset-bottom, 0px));
    }
    .pos-old-sale-pop::before { display: none; }
}
#pos-container {
    height: 100vh;
    overflow: hidden;
    background: #f4f6f9;
}

.product-card {
    cursor: pointer;
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    border: 1px solid rgba(0,0,0,0.08);
    border-radius: 12px;
    background: #fff;
}

.product-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 20px rgba(13, 110, 253, 0.1);
    border-color: #0d6efd;
}

.product-card:active {
    transform: translateY(0);
}

.product-card .card-body {
    padding: 1rem !important;
}

.product-card .card-title {
    color: #334155;
    font-size: 0.85rem;
}

#productGrid {
    min-height: 400px;
    padding-bottom: 2rem;
}

#cartTable {
    font-size: 0.85rem;
    border-collapse: separate;
    border-spacing: 0 4px;
}

#cartTable thead th {
    background: #f8fafc;
    border: none;
    text-transform: uppercase;
    font-size: 0.75rem;
    letter-spacing: 0.5px;
    color: #64748b;
    padding: 10px;
}

#cartTable tbody tr {
    background: #fff;
    box-shadow: 0 1px 3px rgba(0,0,0,0.02);
    transition: all 0.2s;
}

#cartTable tbody tr:hover {
    background-color: #f1f5f9;
}

#emptyCart {
    color: #94a3b8;
}

.btn-outline-primary.active {
    background-color: #0d6efd;
    color: white;
}

#paymentMethodGroup .btn {
    font-size: 0.75rem;
    padding: 0.35rem 0.6rem;
    border-radius: 6px;
}

/* Custom Scrollbar */
::-webkit-scrollbar {
    width: 6px;
}
::-webkit-scrollbar-track {
    background: transparent;
}
::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 10px;
}
::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}

/* Category scroll strip — single horizontal row, scroll sideways on overflow */
.category-scroll-strip {
    display: flex;
    flex-wrap: nowrap;
    gap: 8px;
    overflow-x: auto;
    overflow-y: hidden;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;          /* Firefox */
    padding-bottom: 4px;
    padding-top: 2px;
}
.category-scroll-strip::-webkit-scrollbar { display: none; } /* Chrome/Safari */
.category-scroll-strip .btn {
    flex-shrink: 0;
    border-radius: 20px;
    padding: 4px 16px;
    font-weight: 500;
    white-space: nowrap;
}

/* ═══════════════════════════════════════════════════════════════════════
   Mobile view — desktop layout above is completely untouched by this block.
   Fixes: (1) the header bar's Cash Balance + Open Drawer/End Shift/Start
   Shift controls overflowing off the right edge (needed a horizontal
   scroll to reach them), (2) everything on this page running noticeably
   larger than it needs to on a phone, (3) product tiles rendering one per
   row when two fit comfortably.
   ═══════════════════════════════════════════════════════════════════════ */
@media (max-width: 767.98px) {
    /* Header bar — redesigned as three clearly-separated, stacked sections
       (Title → Shift info → Cash Balance → Actions) instead of squeezing
       the original two-column desktop layout down. Nothing is removed —
       every piece of text/data still renders, just arranged so it reads
       top-to-bottom instead of wrapping mid-word. */
    #posHeaderBar {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 10px;
        padding: 14px 14px !important;
    }
    #posHeaderBar h4 {
        font-size: 1.15rem;
        white-space: nowrap;
        display: flex;
        align-items: center;
        gap: 6px;
        margin-bottom: 2px;
    }
    /* Scanner badge: a small green dot beside the title instead of a
       full-width "SCANNER READY" bar (the text stays as its tooltip/label). */
    #posHeaderBar h4 { min-width: 0; }
    #posHeaderBar .pos-hdr-title { min-width: 0; overflow: hidden; text-overflow: ellipsis; }
    #posHeaderBar #scannerReadyBadge {
        flex: none; width: 9px; height: 9px; padding: 0; margin-left: 2px !important;
        border-radius: 50%; background: #3ddc84 !important; box-shadow: 0 0 0 3px rgba(61, 220, 132, .3);
        font-size: 0 !important;
    }
    #posHeaderBar #scannerReadyBadge > * { display: none; }

    /* One-line bar: balance (or "No shift") + the Actions button share the title line. */
    .pos-hdr-mini-bal {
        margin-left: auto; font-weight: 800; font-size: .95rem; white-space: nowrap;
        font-variant-numeric: tabular-nums;
        display: flex; flex-direction: column; align-items: flex-end; line-height: 1.15;
    }
    #posHeaderBar .pos-hdr-mini-bal small { font-size: .62rem; font-weight: 600; opacity: .85; }
    /* Old-sale strip under the bar (phones). */
    .pos-old-sale-strip {
        display: flex; align-items: center; gap: 8px; margin: 8px 0 0; padding: 8px 8px 8px 12px;
        background: #e7f1ff; color: #0b3a82; border: 1px solid #b6d4fe; border-radius: 10px;
        font-size: .82rem; font-weight: 600;
    }
    .pos-old-sale-strip.d-none { display: none !important; }
    .pos-old-sale-strip span { flex: 1; min-width: 0; }
    .pos-old-sale-strip button {
        border: 0; border-radius: 6px; background: #fff; color: #0a58ca; font-weight: 600; font-size: .78rem; padding: 4px 10px;
    }
    /* A solid white button on the blue bar, so it plainly reads as tappable. */
    .pos-hdr-actions {
        flex: none; height: 36px; border: 0; border-radius: 10px; padding: 0 10px;
        background: #fff; color: #0d6efd; font-size: .82rem; font-weight: 700;
        display: inline-flex; align-items: center; gap: 6px;
        box-shadow: 0 2px 6px rgba(0, 0, 0, .18);
    }
    .pos-hdr-actions .bi-chevron-down { font-size: .75rem; }
    .pos-hdr-actions:active { transform: translateY(1px); box-shadow: 0 1px 2px rgba(0, 0, 0, .2); }
    .pos-hdr-actions:focus-visible { outline: 2px solid #fff; outline-offset: 2px; }
    /* Shift info: each piece (Shift/Started/Cashier) is its own nowrap
       chip, wrapping cleanly between items — never mid-value. The "|"
       separators (meaningful only as an inline run-on) are hidden in
       favour of a visible gap between chips. */
    #posHeaderBar small.opacity-75 {
        font-size: 0.7rem;
        line-height: 1.5;
        display: flex;
        flex-wrap: wrap;
        gap: 4px 10px;
        opacity: 0.85 !important;
    }
    .pos-shift-info-item {
        white-space: nowrap;
    }
    .pos-shift-info-sep {
        display: none;
    }

    /* Cash Balance — its own full-width, centered section with a subtle
       divider above/below so it reads as a distinct block, not squeezed
       next to the action buttons. */
    #posHeaderBar > .d-flex.align-items-center.gap-3 {
        width: 100%;
        flex-direction: column;
        align-items: stretch !important;
        gap: 10px !important;
        padding-top: 10px;
        border-top: 1px solid rgba(255,255,255,.2);
    }
    #posHeaderBar .vr {
        display: none;
    }
    #posHeaderBar .fs-6 {
        font-size: 0.72rem !important;
        letter-spacing: .03em;
        text-transform: uppercase;
        opacity: .8;
    }
    #posHeaderBar .fs-4 {
        font-size: 1.5rem !important;
    }
    #posHeaderBar small {
        font-size: 0.68rem;
    }
    /* Action buttons — a clean, evenly-balanced row (not stacked/squeezed),
       each button taking equal width so the pair looks deliberate. */
    #posShiftButtons {
        display: flex;
        gap: 8px;
        width: 100%;
    }
    #posShiftButtons .btn {
        flex: 1 1 0;
        margin-right: 0 !important;
    }
    #posHeaderBar .btn-sm {
        font-size: 0.8rem;
        padding: 8px 10px;
        font-weight: 600;
    }

    /* Actions sheet (phones): shift details on top, one row per action. */
    .pos-actions-sheet.offcanvas-bottom { height: auto; max-height: 85vh; border-radius: 16px 16px 0 0; }
    .pos-actions-grab { width: 40px; height: 4px; border-radius: 2px; background: #ced4da; margin: 8px auto 4px; }
    .pos-actions-head {
        display: flex; justify-content: space-between; align-items: flex-start; gap: 10px;
        padding: 8px 16px 12px; border-bottom: 1px solid #dee2e6;
    }
    .pos-actions-head small { display: block; font-size: .75rem; }
    .pos-actions-list { overflow-y: auto; padding: 4px 12px calc(12px + env(safe-area-inset-bottom, 0px)); }
    .pos-act {
        display: flex; align-items: center; gap: 12px; width: 100%; text-align: left; border: 0; background: none;
        padding: 10px 4px; border-bottom: 1px solid #f0f2f5; color: #212529; text-decoration: none;
    }
    .pos-act:last-child { border-bottom: 0; }
    .pos-act b { display: block; font-size: .92rem; }
    .pos-act small { display: block; color: #6c757d; font-size: .76rem; line-height: 1.35; }
    .pos-act-i {
        width: 40px; height: 40px; border-radius: 10px; background: #e7f1ff; color: #0d6efd;
        display: grid; place-items: center; flex: none; font-size: 1.15rem;
    }
    .pos-act:disabled { opacity: .45; }
    .pos-act:focus-visible { outline: 2px solid #0d6efd; outline-offset: -2px; border-radius: 8px; }
    .pos-act-warn .pos-act-i { background: #ffc107; color: #3d2c00; }
    .pos-act-danger .pos-act-i { background: #fde8ea; color: #dc3545; }
    .pos-act-danger b { color: #b02a37; }
    .pos-act-on .pos-act-i { background: #0d6efd; color: #fff; }

    /* Warehouse/project selectors + search + category row — smaller text,
       tighter spacing, still full width so nothing needs pinch-zoom. */
    #posWarehouseId, #posProjectId, #productSearch {
        font-size: 0.85rem;
    }
    #categoryButtons .btn {
        font-size: 0.72rem;
        padding: 3px 10px;
    }

    /* Product tiles: two per row instead of one, with everything inside
       scaled down to match — image/icon area, title, price, stock line. */
    .product-card .card-body {
        padding: 0.5rem !important;
    }
    .product-card .card-body > div:first-child {
        height: 55px !important;
    }
    .product-card .card-body i.bi {
        font-size: 1.8rem !important;
    }
    .product-card .card-title {
        font-size: 0.72rem;
        white-space: normal;
        line-height: 1.2;
        min-height: 1.9em;
    }
    .product-card .card-text {
        font-size: 0.68rem;
        margin-bottom: 0.15rem !important;
    }
    .product-card .card-text.fw-bold {
        font-size: 0.75rem;
    }
    .product-card .badge {
        font-size: 6px !important;
        padding: 2px 4px;
    }

    /* Current Sale header actions (%, Clear, Hold, Held Sales) — a
       btn-group that never wrapped, so the rightmost button(s) ran off the
       edge of the screen with no way to reach them. Stacking the title
       above gives the button row the FULL width to itself, so every button
       (icon-only) fits on one single row, evenly spaced — never wrapping
       to a second line and never hidden off-screen. */
    #cartHeaderTop {
        flex-direction: column;
        align-items: stretch !important;
        gap: 8px;
    }
    #cartHeaderActions {
        display: flex;
        flex-wrap: nowrap;
        width: 100%;
    }
    #cartHeaderActions .btn {
        flex: 1 1 0;
        min-width: 0;
        padding: 5px 2px;
    }

    /* Payment Method row (Cash / Card / Mobile / Bank / Customer Credit) —
       5 buttons in one un-wrapped btn-group meant the last one(s) ran off
       the edge, unreachable. Each button now flexes to an equal share of
       the full-width row instead, so all 5 stay on one row, fully visible,
       never needing a horizontal scroll. Longer labels (Card/Credit) swap
       to their short mobile-only form so the text still fits. */
    #paymentMethodGroup {
        flex-wrap: nowrap;
    }
    #paymentMethodGroup .btn {
        flex: 1 1 0;
        min-width: 0;
        padding: 5px 1px !important;
        font-size: 0.62rem !important;
        white-space: normal;
        line-height: 1.15;
    }

    /* Cart line items table — kept as one row per item (not a page-level
       scroll) by shrinking padding/fonts and the qty stepper so the
       Product/Price/Qty/Total/Action columns all fit the phone's width;
       overflow-x is a safety net for unusually long product names, and
       scrolls only this panel, never the whole page. */
    #cartItemsScrollArea {
        overflow-x: auto;
    }
    #cartTable {
        font-size: 0.72rem;
    }
    #cartTable th, #cartTable td {
        padding: 0.25rem 0.2rem !important;
    }
    #cartTable .btn-outline-secondary {
        min-width: 15px !important;
        padding: 1px 3px !important;
        font-size: 9px !important;
    }
    #cartTable input[type="number"] {
        width: 26px !important;
        padding: 1px !important;
        font-size: 10px !important;
    }
    #cartTable .btn-link {
        font-size: 9px !important;
    }
}

/* Simple Mode, tablet/desktop: Total + Pay/Split stick to the bottom of the
   screen from the first moment, however long the cart or page gets.
   overflow:clip (not hidden) — hidden makes the container a scroll box, which
   silently disables position:sticky for everything inside it. */
@media (min-width: 768px) {
    #pos-container.pos-simple { overflow: clip; }
    .pos-simple-layout .pos-pay-actions {
        position: sticky;
        bottom: 0;
        z-index: 5;
        box-shadow: 0 -4px 10px rgba(0,0,0,.08);
    }
    .pos-simple-layout > .col-md-7 > .sticky-top { top: var(--pos-top, 80px) !important; }
}
.pos-btn-label { font-size: .75rem; }
/* style.css gives every .btn min-width:85px — it turned the round cart button
   into an oval and pushed the search row's buttons onto a second line. */
#mobileCartFab { min-width: 0; }
body.pos-actions-open #mobileCartFab { visibility: hidden; }
/* Shop not chosen yet: red outline only (Bootstrap's is-invalid icon hides the text in the narrow phone picker). */
#posWarehouseId.pos-shop-needed { border-color: #dc3545; box-shadow: 0 0 0 .2rem rgba(220, 53, 69, .25); }
.pos-search-group .btn, .pos-restock-btn, .pos-sheet-btn { min-width: 0; }
.pos-sheet-btn { width: 40px; height: 40px; padding: 0; display: inline-flex; align-items: center; justify-content: center; font-size: 1.1rem; }
.pos-sheet-qty { width: 64px; height: 40px; padding: 0 4px; font-size: 1.05rem; -moz-appearance: textfield; }
.pos-sheet-qty::-webkit-outer-spin-button, .pos-sheet-qty::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
#mobileCartOffcanvas #paymentMethodGroup .btn { font-size: .8rem !important; padding: 10px 2px !important; }
#mobileCartOffcanvas .pos-pay-actions { padding-top: .75rem; }
/* The sheet already shows the total in the VAT/total block above. */
#mobileCartOffcanvas .pos-pay-total { display: none !important; }
#posProductPager { gap: 4px; }
#posProductPager .page-link { min-height: 40px; min-width: 38px; padding: .35rem .55rem; display: flex; align-items: center; justify-content: center; border-radius: 8px; white-space: nowrap; font-size: .9rem; }
@media (max-width: 767.98px) {
    #cartHeaderActions .btn { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .pos-btn-label { font-size: .65rem; }
}
</style>

<?php
// Include the footer
include("footer.php");

// Flush the buffer
ob_end_flush();
?>
