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
    'cash' => 'Cash',
    'card' => 'Credit/Debit Card',
    'mobile_money' => 'Mobile Money',
    'bank_transfer' => 'Bank Transfer',
    'credit' => 'Customer Credit'
];

// Get currency
$currency = 'TZS';
?>

<div class="container-fluid px-0" id="pos-container" style="height: auto; min-height: 100vh;">
    <!-- Hidden input that captures barcode scanner keystrokes (scanner acts as keyboard) -->
    <input id="hiddenScanInput" type="text" autocomplete="off" aria-hidden="true"
           style="position:fixed;left:-9999px;width:1px;height:1px;opacity:0;pointer-events:none;"
           tabindex="-1">

    <!-- POS Header -->
    <div id="posHeaderBar" class="bg-primary text-white py-2 px-3 d-flex justify-content-between align-items-center">
        <div>
            <h4 class="mb-0">
                <i class="bi bi-cash-register"></i> Point of Sale
                <span id="scannerReadyBadge" class="badge bg-light text-success ms-2 small fw-normal"
                      style="font-size:0.65rem;vertical-align:middle;display:none;"
                      title="Barcode scanner active — scan a product to add it to the cart">
                    <i class="bi bi-upc-scan"></i> SCANNER READY
                </span>
            </h4>
            <small class="opacity-75">
                <?php if ($shift_active): ?>
                Shift: <?= $shift_active['shift_code'] ?> |
                Started: <?= date('H:i', strtotime($shift_active['start_time'])) ?> |
                Cashier: <?= htmlspecialchars($_SESSION['username'] ?? 'User') ?>
                <?php else: ?>
                No active shift
                <?php endif; ?>
            </small>
        </div>
        <div class="d-flex align-items-center gap-3">
            <div class="text-center">
                <div class="fs-6">Cash Balance</div>
                <div class="fs-4 fw-bold cash-balance-display"><?= format_currency($cash_balance) ?></div>
                <small>Starting: <?= format_currency($starting_cash) ?></small>
            </div>
            <div class="vr text-white opacity-50"></div>
            <div>
                <?php if ($shift_active): ?>
                <button class="btn btn-light btn-sm me-2" onclick="openCashDrawer()">
                    <i class="bi bi-cash"></i> Open Drawer
                </button>
                <button class="btn btn-outline-light btn-sm" onclick="endShift()">
                    <i class="bi bi-power"></i> End Shift
                </button>
                <?php else: ?>
                <button class="btn btn-warning btn-sm" onclick="startShift()">
                    <i class="bi bi-play-circle"></i> Start Shift
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
                    <div class="col-md-6">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-white"><i class="bi bi-house-door text-primary"></i></span>
                            <select class="form-select" id="posWarehouseId" onchange="loadProducts()" required>
                                <option value="" selected disabled>— Select Warehouse —</option>
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
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-white"><i class="bi bi-briefcase text-info"></i></span>
                            <select class="form-select" id="posProjectId">
                                <option value="">General (No Project)</option>
                                <?php
                                $_pos_assigned = isAdmin() ? [] : array_values(array_filter(array_map('intval', $_SESSION['scope']['projects'] ?? [])));
                                if (isAdmin()) {
                                    $projects = $pdo->query("SELECT project_id, project_name FROM projects WHERE status = 'active' ORDER BY project_name")->fetchAll(PDO::FETCH_ASSOC);
                                } elseif (!empty($_pos_assigned)) {
                                    $_pos_pph = implode(',', array_fill(0, count($_pos_assigned), '?'));
                                    $_pos_pstmt = $pdo->prepare("SELECT project_id, project_name FROM projects WHERE status = 'active' AND project_id IN ($_pos_pph) ORDER BY project_name");
                                    $_pos_pstmt->execute($_pos_assigned);
                                    $projects = $_pos_pstmt->fetchAll(PDO::FETCH_ASSOC);
                                } else {
                                    $projects = [];
                                }
                                foreach ($projects as $p) {
                                    echo "<option value='{$p['project_id']}'>{$p['project_name']}</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="row g-2">
                    <div class="col-md-5">
                        <div class="input-group">
                            <input type="text" class="form-control" id="productSearch" 
                                   placeholder="Search product by name, SKU or barcode" autofocus>
                            <button class="btn btn-outline-secondary" type="button" onclick="searchProducts()">
                                <i class="bi bi-search"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-7">
                        <div class="d-flex gap-2 flex-wrap" id="categoryButtons">
                            <button type="button" class="btn btn-sm btn-outline-primary active" onclick="loadProductsByCategory('all')">
                                All Products
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
                        <span class="visually-hidden">Loading products...</span>
                    </div>
                    <p class="mt-2 text-muted">Loading products...</p>
                </div>
            </div>
        </div>

        <!-- Right Column: Cart & Checkout -->
        <div class="col-md-5 bg-light">
            <!-- Current Sale Header -->
            <div class="p-3 border-bottom bg-white">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0"><i class="bi bi-cart3"></i> Current Sale</h5>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-warning" onclick="openDiscountModal()" title="Apply Discount">
                            <i class="bi bi-percent"></i>
                        </button>
                        <button class="btn btn-outline-danger" onclick="clearCart()" title="Clear Cart">
                            <i class="bi bi-trash"></i>
                        </button>
                        <button class="btn btn-outline-secondary" onclick="holdSale()" title="Hold Sale">
                            <i class="bi bi-pause"></i>
                        </button>
                        <button class="btn btn-outline-info" onclick="showHeldSales()" title="View Held Sales">
                            <i class="bi bi-list"></i>
                        </button>
                    </div>
                </div>
                <div class="row g-2 small">
                    <div class="col-6">
                        <div class="text-muted">Receipt #</div>
                        <strong id="receiptNumber" class="small"><?= generate_receipt_number() ?></strong>
                    </div>
                    <div class="col-6 text-end">
                        <div class="text-muted">Items</div>
                        <strong id="cartItemCount" class="badge bg-primary">0</strong>
                    </div>
                </div>
            </div>

            <!-- Cart Items -->
            <div class="flex-grow-1 p-2" style="overflow-y: auto; background: #f8f9fa;">
                <table class="table table-sm table-hover bg-white" id="cartTable" style="display: none;">
                    <thead class="table-light">
                        <tr>
                            <th width="35%">Product</th>
                            <th width="15%" class="text-end">Price (TZS)</th>
                            <th width="20%" class="text-center">Qty</th>
                            <th width="20%" class="text-end">Total (TZS)</th>
                            <th width="10%" class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody id="cartBody">
                        <!-- Cart items will be added here -->
                    </tbody>
                </table>
                <div id="emptyCart" class="text-center py-5 bg-white rounded">
                    <i class="bi bi-cart-x" style="font-size: 3rem; color: #6c757d;"></i>
                    <p class="text-muted mt-2 mb-1">Cart is empty</p>
                    <p class="text-muted small">Search or browse products to add items</p>
                </div>
            </div>

            <!-- Cart Summary -->
            <div class="p-3 border-top bg-white">
                <div class="mb-2">
                    <div class="d-flex justify-content-between mb-1">
                        <span class="text-muted">Subtotal:</span>
                        <strong id="cartSubtotal">TZS 0.00</strong>
                    </div>
                    <div class="d-flex justify-content-between mb-1" id="discountRow" style="display: none !important;">
                        <span class="text-muted">Discount (<span id="discountPercentageDisplay">0</span>%):</span>
                        <strong id="cartDiscount" class="text-danger">-TZS 0.00</strong>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-muted">VAT:</span>
                        <select id="saleVatSelect" class="form-select form-select-sm" style="width:auto;min-width:140px;">
                            <option value="0" selected>No Tax (0%)</option>
                            <option value="18">VAT 18%</option>
                        </select>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Total Tax:</span>
                        <strong id="cartTax">TZS 0.00</strong>
                    </div>
                    <div class="d-flex justify-content-between border-top pt-2">
                        <h6 class="mb-0">TOTAL:</h6>
                        <h5 class="mb-0 text-success" id="cartTotal">TZS 0.00</h5>
                    </div>
                </div>
            </div>

            <!-- Payment Section -->
            <div class="p-3 border-top bg-white">
                <div class="mb-2">
                    <label class="form-label small fw-bold">Customer</label>
                    <select class="form-select form-select-sm" id="customerSelect">
                        <option value="">Walk-in Customer</option>
                        <?php
                        $customers = $pdo->query("SELECT customer_id, customer_name FROM customers WHERE status = 'active' ORDER BY customer_name LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($customers as $customer) {
                            echo "<option value='{$customer['customer_id']}'>{$customer['customer_name']}</option>";
                        }
                        ?>
                    </select>
                </div>

                <div class="mb-2">
                    <label class="form-label small fw-bold">Payment Method</label>
                    <div class="btn-group w-100" role="group" id="paymentMethodGroup">
                        <?php foreach ($payment_methods as $value => $label): ?>
                            <input type="radio" class="btn-check" name="paymentMethod" 
                                   id="payment<?= ucfirst($value) ?>" value="<?= $value ?>" 
                                   <?= $value == 'cash' ? 'checked' : '' ?>>
                            <label class="btn btn-outline-primary btn-sm" for="payment<?= ucfirst($value) ?>">
                                <?= $value == 'mobile_money' ? 'Mobile' : ($value == 'bank_transfer' ? 'Bank' : ucfirst($value)) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Cash Payment Specific -->
                <div id="cashPaymentSection">
                    <div class="mb-2">
                        <label class="form-label small fw-bold">Amount Tendered</label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">TZS</span>
                            <input type="number" class="form-control" id="amountTendered" 
                                   min="0" step="0.01" value="0" oninput="calculateChange()">
                        </div>
                    </div>
                    <div class="alert alert-success py-2" id="changeAlert" style="display: none;">
                        <div class="d-flex justify-content-between small">
                            <span>Change Due:</span>
                            <strong id="changeAmount">TZS 0.00</strong>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="d-grid gap-2">
                    <button class="btn btn-success btn-lg" onclick="processPayment()" id="processPaymentBtn">
                        <i class="bi bi-check-circle"></i> PROCESS PAYMENT
                    </button>
                    <button class="btn btn-outline-primary" onclick="openSplitPaymentModal()">
                        <i class="bi bi-columns-gap"></i> SPLIT PAYMENT
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
</style>

<?php
// Include the footer
include("footer.php");

// Flush the buffer
ob_end_flush();
?>
