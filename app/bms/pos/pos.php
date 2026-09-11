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

// Phase 14 (pos_upgrade_plan.md §8) — selling price tiers. Gated behind the
// 'pos_advanced' entitlement, same as Registers/Loyalty; base-tier POS
// behaves exactly as before this phase (no selector, plain selling_price).
$price_groups_enabled = canView('pos_advanced');
$price_groups = $price_groups_enabled
    ? $pdo->query("SELECT price_group_id, name, is_default FROM price_groups WHERE status = 'active' ORDER BY is_default DESC, name ASC")->fetchAll(PDO::FETCH_ASSOC)
    : [];

// Phase 20 (pos_upgrade_plan.md §8) — cash denomination counting.
require_once ROOT_DIR . '/core/pos_denominations.php';
$pos_denomination_list = posDenominationList();

// Phase 30 (pos_upgrade_plan.md §9) — restaurant module entitlement + the
// per-warehouse pos_mode map built above.
$restaurant_pos_enabled = canView('restaurant_pos');
?>
<script>
// Phase 30 (pos_upgrade_plan.md §9) — populated once from PHP, never fetched
// per warehouse-change; the JS layer just looks up the selected id.
const POS_WAREHOUSE_MODES = <?= json_encode($_pos_warehouse_modes ?? []) ?>;
const POS_RESTAURANT_ENABLED = <?= json_encode($restaurant_pos_enabled) ?>;
</script>

<div class="container-fluid px-0" id="pos-container" style="height: auto; min-height: 100vh;">
    <!-- Hidden input that captures barcode scanner keystrokes (scanner acts as keyboard) -->
    <input id="hiddenScanInput" type="text" autocomplete="off" aria-hidden="true"
           style="position:fixed;left:-9999px;width:1px;height:1px;opacity:0;pointer-events:none;"
           tabindex="-1">

    <!-- POS Header -->
    <div id="posHeaderBar" class="bg-primary text-white py-2 px-3 d-flex justify-content-between align-items-center">
        <div>
            <h4 class="mb-0">
                <i class="bi bi-cash-register"></i> <?= t('Point of Sale') ?>
                <span id="scannerReadyBadge" class="badge bg-light text-success ms-2 small fw-normal"
                      style="font-size:0.65rem;vertical-align:middle;display:none;"
                      title="<?= t('Barcode scanner active — scan a product to add it to the cart') ?>">
                    <i class="bi bi-upc-scan"></i> <?= t('SCANNER READY') ?>
                </span>
            </h4>
            <small class="opacity-75" id="posShiftInfoLine">
                <?php if ($shift_active): ?>
                <span class="pos-shift-info-item"><?= t('Shift:') ?> <?= $shift_active['shift_code'] ?></span><span class="pos-shift-info-sep"> | </span><span class="pos-shift-info-item"><?= t('Started:') ?> <?= date('H:i', strtotime($shift_active['start_time'])) ?></span><span class="pos-shift-info-sep"> | </span><span class="pos-shift-info-item"><?= t('Cashier:') ?> <?= htmlspecialchars($_SESSION['username'] ?? t('User')) ?></span>
                <?php else: ?>
                <?= t('No active shift') ?>
                <?php endif; ?>
            </small>
        </div>
        <div class="d-flex align-items-center gap-3">
            <div class="text-center">
                <div class="fs-6"><?= t('Cash Balance') ?></div>
                <div class="fs-4 fw-bold cash-balance-display"><?= format_currency($cash_balance, $currency) ?></div>
                <small><?= t('Starting:') ?> <?= format_currency($starting_cash, $currency) ?></small>
            </div>
            <div class="vr text-white opacity-50"></div>
            <div id="posShiftButtons">
                <?php if ($shift_active): ?>
                <button class="btn btn-light btn-sm me-2" onclick="openCashDrawer()">
                    <i class="bi bi-cash"></i> <?= t('Open Drawer') ?>
                </button>
                <button class="btn btn-outline-light btn-sm" onclick="endShift()">
                    <i class="bi bi-power"></i> <?= t('End Shift') ?>
                </button>
                <?php else: ?>
                <button class="btn btn-warning btn-sm" onclick="startShift()">
                    <i class="bi bi-play-circle"></i> <?= t('Start Shift') ?>
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Main POS Layout -->
    <div class="row g-0">
        <!-- Left Column: Product Selection -->
        <div class="col-md-7" style="border-right: 1px solid #dee2e6;">
            <!-- Product Search & Categories -->
            <div class="bg-light p-3 border-bottom sticky-top" style="z-index: 1020; top: 0;">
                <!-- Warehouse & Project Selection -->
                <div class="row g-2 mb-3">
                    <div class="col-md-<?= projectsModuleActive() ? 6 : 12 ?>">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-white"><i class="bi bi-house-door text-primary"></i></span>
                            <select class="form-select" id="posWarehouseId" onchange="loadProducts()" required>
                                <option value="" selected disabled><?= t('— Select Warehouse —') ?></option>
                                <?php
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
                                echo renderWarehouseOptions($_pos_warehouse_scoped);

                                // Phase 30 (pos_upgrade_plan.md §9) — a warehouse-scoped
                                // pos_mode map, so the JS layer can show/hide restaurant
                                // affordances (table picker, send-to-kitchen) purely
                                // client-side on warehouse change, no extra round trip.
                                // A plain 'retail' warehouse (the default, every warehouse
                                // until an admin explicitly changes it) never triggers any
                                // of this — byte-for-byte unchanged behaviour.
                                // Defensive: a tenant whose database hasn't yet had the
                                // Phase 30 migration applied (warehouses.pos_mode missing)
                                // must NEVER crash the whole POS terminal over this —
                                // degrade to treating every warehouse as 'retail' instead,
                                // identical to how the JS side already reads a warehouse
                                // absent from this map (POS_WAREHOUSE_MODES[wid] || 'retail').
                                $_pos_warehouse_modes = [];
                                if (!empty($_pos_warehouse_scoped)) {
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
                                ?>
                            </select>
                        </div>
                    </div>
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
                    <div class="col-md-5">
                        <div class="input-group">
                            <input type="text" class="form-control" id="productSearch"
                                   placeholder="<?= t('Search product by name, SKU or barcode') ?>" autofocus>
                            <button class="btn btn-outline-secondary" type="button" onclick="searchProducts()">
                                <i class="bi bi-search"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-7">
                        <div class="d-flex gap-2 flex-wrap" id="categoryButtons">
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
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0"><i class="bi bi-cart3"></i> <?= t('Current Sale') ?></h5>
                    <div class="btn-group btn-group-sm">
                        <?php if (canEdit('pos_discount_override')): ?>
                        <button class="btn btn-outline-warning" onclick="openDiscountModal()" title="<?= t('Apply Discount') ?>">
                            <i class="bi bi-percent"></i>
                        </button>
                        <?php endif; ?>
                        <button class="btn btn-outline-danger" onclick="clearCart()" title="<?= t('Clear Cart') ?>">
                            <i class="bi bi-trash"></i>
                        </button>
                        <button class="btn btn-outline-secondary" onclick="holdSale()" title="<?= t('Hold Sale') ?>">
                            <i class="bi bi-pause"></i>
                        </button>
                        <button class="btn btn-outline-info" onclick="showHeldSales()" title="<?= t('View Held Sales') ?>">
                            <i class="bi bi-list"></i>
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
            <div class="flex-grow-1 p-2" style="overflow-y: auto; background: #f8f9fa;">
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
            <div class="p-3 border-top bg-white">
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
            <div class="p-3 border-top bg-white">
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
                            <?= safe_output($pg['name']) ?>
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
                                <?= $value == 'mobile_money' ? t('Mobile') : ($value == 'bank_transfer' ? t('Bank') : $label) ?>
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

<?php include 'pos_modals_new.php'; ?>
<?php include 'pos_scripts_new.php'; ?>
<script>
    $(document).ready(function() {
        logReportAction('Viewed POS Page', 'User opened the Point of Sale interface');
    });
</script>

<style>
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

/* Category Buttons */
#categoryButtons .btn {
    border-radius: 20px;
    padding: 4px 15px;
    font-weight: 500;
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
    /* Scanner badge, when the scanner is active, drops to its own line
       under the title instead of squeezing the title text. */
    #posHeaderBar h4 #scannerReadyBadge {
        flex-basis: 100%;
        margin-left: 0 !important;
        width: fit-content;
    }
    #posHeaderBar #scannerReadyBadge {
        font-size: 0.62rem !important;
    }
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
}
</style>

<?php
// Include the footer
include("footer.php");

// Flush the buffer
ob_end_flush();
?>
