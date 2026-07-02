<?php
// File: lpo_create.php
// scope-audit: skip — create/edit form; new LPO creation has no prior record to scope; edit is gated by assertScopeForRecord in api/customer/save_lpo.php
require_once __DIR__ . '/../../../../roots.php';

// Enforce permission BEFORE any output
autoEnforcePermission('lpo');

includeHeader();

// Optional deep link from the Customer detail page
$customer_id  = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;
$project_id   = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;
$warehouse_id = 0;

global $pdo;

// Scope: assigned project IDs for current user
$_lc_assigned = isAdmin() ? [] : array_values(array_filter(array_map('intval', $_SESSION['scope']['projects'] ?? [])));

// Customers for dropdown — scoped by project for non-admins
if (isAdmin()) {
    $customers = $pdo->query("SELECT customer_id, customer_name, company_name, customer_type, currency FROM customers WHERE status = 'active' ORDER BY customer_name")->fetchAll(PDO::FETCH_ASSOC);
} elseif (!empty($_lc_assigned)) {
    $_lc_cph = implode(',', array_fill(0, count($_lc_assigned), '?'));
    $_lc_cstmt = $pdo->prepare("SELECT customer_id, customer_name, company_name, customer_type, currency FROM customers WHERE status = 'active' AND (project_id IS NULL OR project_id IN ($_lc_cph)) ORDER BY customer_name");
    $_lc_cstmt->execute($_lc_assigned);
    $customers = $_lc_cstmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $customers = $pdo->query("SELECT customer_id, customer_name, company_name, customer_type, currency FROM customers WHERE status = 'active' AND project_id IS NULL ORDER BY customer_name")->fetchAll(PDO::FETCH_ASSOC);
}

// Warehouses for dropdown — scoped by project for non-admins; JS filters further
// by the selected project (project's warehouses only, or unassigned-only if none).
require_once ROOT_DIR . '/core/warehouse_scope.php';
$warehouses = warehousesForSelect($pdo);

// Tax rates — same VAT-only restriction as the Purchase Order module.
$tax_rates = $pdo->query("SELECT * FROM tax_rates WHERE status = 'active' AND rate_percentage IN (0, 18) ORDER BY rate_percentage")->fetchAll(PDO::FETCH_ASSOC);

$currencies = [
    'TZS' => 'Tanzanian Shilling',
    'USD' => 'US Dollar',
    'EUR' => 'Euro',
    'GBP' => 'British Pound',
    'KES' => 'Kenyan Shilling'
];

// Projects if enabled
$enable_projects = 0;
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'enable_projects'");
    $stmt->execute();
    $enable_projects = $stmt->fetchColumn() ?: 0;
} catch (Exception $e) {}

$projects = [];
if ($enable_projects) {
    try {
        if (isAdmin()) {
            $projects = $pdo->query("SELECT project_id, project_name FROM projects WHERE status = 'active' ORDER BY project_name")->fetchAll(PDO::FETCH_ASSOC);
        } elseif (!empty($_lc_assigned)) {
            $_lc_pph = implode(',', array_fill(0, count($_lc_assigned), '?'));
            $_lc_pstmt = $pdo->prepare("SELECT project_id, project_name FROM projects WHERE status = 'active' AND project_id IN ($_lc_pph) ORDER BY project_name");
            $_lc_pstmt->execute($_lc_assigned);
            $projects = $_lc_pstmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {}
}

// Check for edit mode
$edit_id = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
$is_edit = $edit_id > 0;

$lpo_data = null;
$lpo_items = [];
$lpo_attachments = [];

if ($is_edit) {
    $stmt = $pdo->prepare("SELECT * FROM customer_lpos WHERE lpo_id = ? AND status != 'deleted'");
    $stmt->execute([$edit_id]);
    $lpo_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($lpo_data) {
        $customer_id  = $lpo_data['customer_id'];
        $project_id   = $lpo_data['project_id'];
        $warehouse_id = (int)($lpo_data['warehouse_id'] ?? 0);

        $stmt = $pdo->prepare("
            SELECT loi.*, tr.rate_id AS tax_rate_id
            FROM customer_lpo_items loi
            LEFT JOIN tax_rates tr ON loi.tax_rate = tr.rate_percentage AND tr.status = 'active'
            WHERE loi.lpo_id = ?
            ORDER BY loi.sort_order, loi.item_id
        ");
        $stmt->execute([$edit_id]);
        $lpo_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("SELECT * FROM customer_lpo_attachments WHERE lpo_id = ? ORDER BY attachment_id");
        $stmt->execute([$edit_id]);
        $lpo_attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $is_edit = false;
        $edit_id = 0;
    }
}

$back_url = getUrl('lpos');
?>

<div class="container-fluid mt-4">
    <nav aria-label="breadcrumb" class="mb-3 lpo-create-sticky-nav">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= getUrl('lpos') ?>">Customer LPOs</a></li>
            <li class="breadcrumb-item active"><?= $is_edit ? 'Edit LPO' : 'New LPO' ?></li>
        </ol>
    </nav>

    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex flex-column flex-md-row justify-content-md-between align-items-md-center gap-2">
                <div>
                    <h2 class="fw-bold">
                        <i class="bi <?= $is_edit ? 'bi-pencil-square' : 'bi-file-earmark-plus' ?> text-primary"></i>
                        <?= $is_edit ? 'Edit Customer LPO' : 'Create Customer LPO' ?>
                    </h2>
                    <p class="text-muted mb-0"><?= $is_edit ? 'Update an existing customer LPO' : 'Record a Local Purchase Order received from a customer' ?></p>
                </div>
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <a href="<?= htmlspecialchars($back_url) ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Back to List
                    </a>
                </div>
            </div>
        </div>
    </div>

    <form id="lpoForm">
        <input type="hidden" name="lpo_id" value="<?= $edit_id ?>">
        <div class="col-lg-12">
            <div class="card mb-4 shadow-sm">
                <div class="card-header bg-primary text-white py-3">
                    <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i> Basic Information</h5>
                </div>
                <div class="card-body p-4">
                    <div class="row g-4">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Customer <span class="text-danger">*</span></label>
                            <select class="form-select select2-static" id="customer_id" name="customer_id" required <?= ($is_edit) ? 'disabled' : '' ?>>
                                <option value="">Select a customer</option>
                                <?php foreach ($customers as $c):
                                    $cname = ($c['customer_type'] === 'business' && !empty($c['company_name'])) ? $c['company_name'] : $c['customer_name'];
                                ?>
                                    <option value="<?= $c['customer_id'] ?>"
                                        <?= $customer_id == $c['customer_id'] ? 'selected' : '' ?>
                                        data-currency="<?= htmlspecialchars($c['currency'] ?? 'TZS') ?>">
                                        <?= htmlspecialchars($cname) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($is_edit): ?>
                            <input type="hidden" name="customer_id" value="<?= (int)$customer_id ?>">
                            <div class="form-text"><i class="bi bi-lock-fill me-1"></i>Customer cannot be changed after creation.</div>
                            <?php endif; ?>
                        </div>

                        <?php if ($enable_projects): ?>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Project <span class="text-muted small fw-normal">(Optional)</span></label>
                            <select class="form-select select2-static" id="project_id" name="project_id">
                                <option value="">No Project</option>
                                <?php foreach ($projects as $proj): ?>
                                    <option value="<?= $proj['project_id'] ?>" <?= $project_id == $proj['project_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($proj['project_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Warehouse <span class="text-danger">*</span></label>
                            <select class="form-select" id="warehouse_id" name="warehouse_id" required>
                                <option value="">Select Warehouse</option>
                                <?= renderWarehouseOptions($warehouses, $warehouse_id) ?>
                            </select>
                            <div class="form-text">Stock for the items below will be checked against this warehouse.</div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Issue Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="issue_date" value="<?= $is_edit ? $lpo_data['issue_date'] : date('Y-m-d') ?>" required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Expiry Date <span class="text-muted small fw-normal">(Optional)</span></label>
                            <input type="date" class="form-control" name="expiry_date" value="<?= ($is_edit && !empty($lpo_data['expiry_date'])) ? $lpo_data['expiry_date'] : '' ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Currency <span class="text-danger">*</span></label>
                            <select class="form-select select2-static" id="currency" name="currency" required>
                                <?php foreach ($currencies as $code => $name): ?>
                                    <option value="<?= $code ?>" <?= ($is_edit && $lpo_data['currency'] == $code) ? 'selected' : '' ?>>
                                        <?= $code ?> - <?= $name ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Description <span class="text-muted small fw-normal">(Optional)</span></label>
                            <input type="text" class="form-control" name="description"
                                   value="<?= $is_edit ? htmlspecialchars($lpo_data['description'] ?? '') : '' ?>"
                                   placeholder="What goods/services does this LPO cover?">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Items Section -->
            <div class="card mb-4 shadow-sm border-0">
                <div class="card-header bg-light border-bottom py-3">
                    <h5 class="mb-0 fw-bold"><i class="bi bi-list-task me-2 text-primary"></i> Items Ordered</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="itemsTable">
                            <thead class="bg-light text-uppercase small fw-bold">
                                <tr>
                                    <th style="width: 50px;">S/NO</th>
                                    <th style="min-width: 300px;">Product / Service</th>
                                    <th style="width: 150px;">Quantity</th>
                                    <th style="width: 200px;">Unit Price</th>
                                    <th style="width: 200px;">Tax Rate</th>
                                    <th class="text-end" style="width: 150px;">Total</th>
                                    <th style="width: 50px;"></th>
                                </tr>
                            </thead>
                            <tbody id="itemsBody">
                                <?php if ($is_edit && count($lpo_items) > 0): ?>
                                    <?php foreach ($lpo_items as $index => $item):
                                        $rowId = 'row_edit_' . $index;
                                    ?>
                                        <tr id="<?= $rowId ?>">
                                            <td class="serial-number text-center fw-bold text-muted"><?= $index + 1 ?></td>
                                            <td>
                                                <div class="input-group">
                                                    <input type="text" class="form-control product-selector" required
                                                           oninput="openProductSearch('<?= $rowId ?>', this.value)"
                                                           onclick="openProductSearch('<?= $rowId ?>', this.value)"
                                                           style="cursor:text;background:#fff;" autocomplete="off"
                                                           value="<?= htmlspecialchars($item['product_name']) ?>">
                                                    <button type="button" class="btn btn-outline-secondary" onclick="openProductSearch('<?= $rowId ?>')">
                                                        <i class="bi bi-search"></i>
                                                    </button>
                                                </div>
                                                <input type="hidden" class="item-product-id" name="productId" value="<?= $item['product_id'] ?>">
                                            </td>
                                            <td>
                                                <input type="number" class="form-control qty-input" name="qty"
                                                       value="<?= $item['quantity'] ?>" min="0.001" step="0.001"
                                                       oninput="calculateRowTotal('<?= $rowId ?>')" required>
                                            </td>
                                            <td>
                                                <div class="input-group">
                                                    <span class="input-group-text bg-light cur-symbol">TSh </span>
                                                    <input type="number" class="form-control price-input" name="price" value="<?= $item['unit_price'] ?>" min="0" step="0.01" oninput="calculateRowTotal('<?= $rowId ?>')" required>
                                                </div>
                                            </td>
                                            <td>
                                                <select class="form-select tax-selector" name="taxId" onchange="calculateRowTotal('<?= $rowId ?>')">
                                                    <option value="0" data-rate="0">No Tax (0%)</option>
                                                    <?php foreach ($tax_rates as $tr): ?>
                                                        <option value="<?= $tr['rate_id'] ?>" data-rate="<?= $tr['rate_percentage'] ?>"
                                                            <?= ($item['tax_rate_id'] == $tr['rate_id']) ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($tr['rate_name']) ?> (<?= $tr['rate_percentage'] ?>%)
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td class="text-end fw-bold"><span class="row-total"><?= number_format($item['total'], 2, '.', '') ?></span></td>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-outline-danger btn-sm border-0"
                                                    onclick="$('#<?= $rowId ?>').remove(); updateSerialNumbers(); calculateGrandTotal();">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <tfoot>
                                <tr class="bg-light">
                                    <td colspan="5" class="text-end fw-bold">Subtotal:</td>
                                    <td class="text-end fw-bold pt-3" id="subtotal_display">TSh 0.00</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
            <button type="button" class="btn btn-primary btn-sm" onclick="addItemRow()">
                <i class="bi bi-plus-circle"></i> Add Item
            </button>

            <div class="row mt-4">
                <div class="col-md-7">
                    <div class="card mb-4 shadow-sm">
                        <div class="card-header bg-light py-3">
                            <h6 class="mb-0 fw-bold"><i class="bi bi-journal-text me-2"></i> Notes</h6>
                        </div>
                        <div class="card-body">
                            <div class="mb-0">
                                <label class="form-label fw-semibold">Internal Notes</label>
                                <textarea class="form-control" name="notes" rows="3" placeholder="Internal notes..."><?= $is_edit ? htmlspecialchars($lpo_data['notes'] ?? '') : '' ?></textarea>
                            </div>
                            <div class="mb-0 mt-3">
                                <label class="form-label fw-semibold mb-2">Attachments <span class="text-muted small fw-normal">(Optional)</span></label>
                                <div id="attachments-container" class="border rounded p-3 bg-light">
                                    <div id="attachment-fields">
                                        <?php if ($is_edit && count($lpo_attachments) > 0): ?>
                                            <?php foreach ($lpo_attachments as $att): ?>
                                                <div class="row g-2 attachment-row mb-2 align-items-center">
                                                    <div class="col-md-5">
                                                        <input type="text" class="form-control form-control-sm" name="attach_names[]" value="<?= htmlspecialchars($att['original_name']) ?>" placeholder="Document Name">
                                                    </div>
                                                    <div class="col-md-6">
                                                        <a href="<?= htmlspecialchars(buildUrl($att['file_path'])) ?>" target="_blank" class="btn btn-sm btn-outline-secondary py-1">
                                                            <i class="bi bi-file-earmark-arrow-down me-1"></i> Current file
                                                        </a>
                                                        <input type="hidden" name="existing_attach_ids[]" value="<?= $att['attachment_id'] ?>">
                                                    </div>
                                                    <div class="col-md-1 text-end">
                                                        <button type="button" class="btn btn-link text-danger p-0 border-0" onclick="removeExistingAttachment(this, <?= $att['attachment_id'] ?>)" title="Remove">
                                                            <i class="bi bi-trash fs-5"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                        <div class="row g-2 attachment-row mb-2">
                                            <div class="col-md-5">
                                                <input type="text" class="form-control form-control-sm" name="attach_names[]" placeholder="Document Name (e.g. Contract, Specs)">
                                            </div>
                                            <div class="col-md-6">
                                                <input type="file" class="form-control form-control-sm" name="attach_files[]" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                                            </div>
                                            <div class="col-md-1 text-end">
                                                <button type="button" class="btn btn-link text-danger p-0 border-0" onclick="removeAttachmentRow(this)" title="Remove">
                                                    <i class="bi bi-trash fs-5"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-outline-primary shadow-sm" onclick="addAttachmentRow()">
                                        <i class="bi bi-plus-circle me-1"></i> Add Attachment
                                    </button>
                                </div>
                                <div class="form-text text-muted mt-2">Accepted: PDF, DOC, DOCX, JPG, PNG (max 10MB each).</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-5">
                    <div class="card shadow-sm border-primary">
                        <div class="card-header bg-primary text-white py-3">
                            <h6 class="mb-0 fw-bold"><i class="bi bi-calculator me-2"></i> LPO Summary</h6>
                        </div>
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between mb-3 text-muted">
                                <span>Items Subtotal</span>
                                <span id="summary-subtotal">0.00</span>
                            </div>
                            <div class="d-flex justify-content-between mb-3 text-muted">
                                <span>Total Tax</span>
                                <span id="summary-tax">0.00</span>
                            </div>
                            <hr class="my-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <h4 class="mb-0 fw-bold text-dark">Total Value</h4>
                                <h4 class="mb-0 fw-bold text-primary" id="summary-grand-total">TSh 0.00</h4>
                            </div>
                        </div>
                    </div>

                    <div class="d-grid gap-2 mt-4">
                        <button type="submit" class="btn btn-success btn-lg shadow-sm">
                            <i class="bi bi-check2-all me-2"></i> <?= $is_edit ? 'Update LPO' : 'Create LPO' ?>
                        </button>
                        <a href="<?= htmlspecialchars($back_url) ?>" class="btn btn-link text-decoration-none text-muted">
                            Cancel and return
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<!-- Floating Product Search Results -->
<div id="productSearchResults" class="product-search-results shadow-lg border">
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="bg-light sticky-top">
                <tr><th>Product</th><th>SKU</th><th>Stock</th><th>Selling Price</th></tr>
            </thead>
            <tbody id="productsSearchBody"></tbody>
        </table>
    </div>
</div>

<link href="/assets/css/select2.min.css" rel="stylesheet" />
<link href="/assets/css/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
<script src="/assets/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="<?= getUrl('assets/js/warehouse-project-filter.js') ?>"></script>
<script>
let productsList = [];
const editId = <?= json_encode($edit_id) ?>;
const isEdit = <?= json_encode($is_edit) ?>;

function initSelect2Fields() {
    $('.select2-static').each(function() {
        const $el = $(this);
        if ($el.data('select2')) return;
        $el.select2({ theme: 'bootstrap-5', placeholder: $el.find('option:first').text() || 'Select...', allowClear: true, width: '100%' });
    });
}

function initTaxSelect2(rowId) {
    const $sel = rowId ? $(`#${rowId} .tax-selector`) : $('.tax-selector');
    $sel.each(function() {
        if ($(this).data('select2')) return;
        $(this).select2({ theme: 'bootstrap-5', dropdownParent: $('body'), allowClear: false, width: '100%', minimumResultsForSearch: Infinity });
    });
}

$(document).ready(function() {
    initSelect2Fields();
    initTaxSelect2();
    // Shared Project → Warehouse cascade (assets/js/warehouse-project-filter.js)
    bindWarehouseToProject({
        onFiltered: function () { fetchProducts(); }
    });

    if (isEdit) {
        $('h2').html('<i class="bi bi-pencil-square text-primary"></i> Edit Customer LPO');
        $('button[type="submit"]').html('<i class="bi bi-save me-2"></i> Update LPO');
    }

    fetchProducts().then(() => {
        if (!isEdit) {
            logReportAction('Viewed LPO Create Page', 'User opened the create customer LPO page');
            addItemRow();
        } else {
            calculateGrandTotal();
        }
    });

    $('#warehouse_id').on('change', function() { fetchProducts(); });

    $('#customer_id').on('change', function() {
        const option = $(this).find('option:selected');
        if (option.val()) {
            $('#currency').val(option.data('currency') || 'TZS');
            updateCurSymbols();
        }
    });

    $(document).on('click', function(e) {
        if (!$(e.target).closest('.product-selector, #productSearchResults').length) {
            $('#productSearchResults').hide();
        }
    });
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape') $('#productSearchResults').hide();
    });

    $('#lpoForm').on('submit', function(e) {
        e.preventDefault();
        saveLpo();
    });
});

function handleFileSelect(input) {
    if (input.files && input.files[0] && input.files[0].size > 10 * 1024 * 1024) {
        Swal.fire({ icon: 'warning', title: 'File Too Large', text: 'Maximum file size is 10MB.', confirmButtonColor: '#0d6efd' });
        input.value = '';
    }
}

function addAttachmentRow() {
    const rowId = 'attach_' + Date.now();
    const html = `
        <div class="row g-2 attachment-row mb-2" id="${rowId}">
            <div class="col-md-5">
                <input type="text" class="form-control form-control-sm" name="attach_names[]" placeholder="Document Name (e.g. Contract, Specs)">
            </div>
            <div class="col-md-6">
                <input type="file" class="form-control form-control-sm" name="attach_files[]" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" onchange="handleFileSelect(this)">
            </div>
            <div class="col-md-1 text-end">
                <button type="button" class="btn btn-link text-danger p-0 border-0" onclick="removeAttachmentRow(this)" title="Remove"><i class="bi bi-trash fs-5"></i></button>
            </div>
        </div>
    `;
    $('#attachment-fields').append(html);
}

function removeAttachmentRow(btn) {
    $(btn).closest('.attachment-row').remove();
}

function removeExistingAttachment(btn, attachmentId) {
    Swal.fire({
        title: 'Remove attachment?', icon: 'warning', showCancelButton: true,
        confirmButtonColor: '#dc3545', confirmButtonText: 'Yes, Remove'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post('<?= buildUrl('api/customer/delete_lpo_attachment.php') ?>', { attachment_id: attachmentId, _csrf: CSRF_TOKEN }, function(res) {
            if (res.success) {
                $(btn).closest('.attachment-row').remove();
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Could not remove.' });
            }
        }, 'json').fail(() => Swal.fire({ icon: 'error', title: 'Error', text: 'Server error.' }));
    });
}

function updateSerialNumbers() {
    $('#itemsBody tr').each(function(index) { $(this).find('.serial-number').text(index + 1); });
}

function addItemRow() {
    const rowId = 'row_' + Date.now();
    const html = `
        <tr id="${rowId}">
            <td class="serial-number text-center fw-bold text-muted"></td>
            <td>
                <div class="input-group">
                    <input type="text" class="form-control product-selector" placeholder="Type to search product..." required
                           oninput="openProductSearch('${rowId}', this.value)" onclick="openProductSearch('${rowId}', this.value)"
                           style="cursor: text; background-color: #fff;" autocomplete="off">
                    <button type="button" class="btn btn-outline-secondary" onclick="openProductSearch('${rowId}')"><i class="bi bi-search"></i></button>
                </div>
                <input type="hidden" class="item-product-id" name="productId">
            </td>
            <td><input type="number" class="form-control qty-input" name="qty" value="1" min="0.001" step="0.001" oninput="calculateRowTotal('${rowId}')" required></td>
            <td>
                <div class="input-group">
                    <span class="input-group-text bg-light cur-symbol">TSh </span>
                    <input type="number" class="form-control price-input" name="price" value="0.00" min="0" step="0.01" oninput="calculateRowTotal('${rowId}')" required>
                </div>
            </td>
            <td>
                <select class="form-select tax-selector" name="taxId" onchange="calculateRowTotal('${rowId}')">
                    <option value="0" data-rate="0">No Tax (0%)</option>
                    <?php foreach ($tax_rates as $tr): ?>
                        <option value="<?= $tr['rate_id'] ?>" data-rate="<?= $tr['rate_percentage'] ?>"><?= htmlspecialchars($tr['rate_name']) ?> (<?= $tr['rate_percentage'] ?>%)</option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td class="text-end fw-bold"><span class="row-total">0.00</span></td>
            <td class="text-center"><button type="button" class="btn btn-outline-danger btn-sm border-0" onclick="$('#${rowId}').remove(); updateSerialNumbers(); calculateGrandTotal();"><i class="bi bi-trash"></i></button></td>
        </tr>
    `;
    $('#itemsBody').append(html);
    updateSerialNumbers();
    initTaxSelect2(rowId);
}

let currentRowId = null;

function openProductSearch(rowId, term = '') {
    currentRowId = rowId;
    const input = $(`#${rowId} .product-selector`);
    const offset = input.offset();
    $('#productSearchResults').css({ top: offset.top + input.outerHeight() + 2, left: offset.left, width: Math.max(input.outerWidth() * 1.5, 600), display: 'block' });
    searchProducts(term);
}

function searchProducts(term = '') {
    const tbody = $('#productsSearchBody');
    tbody.empty();
    const searchTerm = term.toLowerCase().trim();
    let results = productsList;
    if (searchTerm.length > 0) {
        results = productsList.filter(p =>
            (p.product_name && p.product_name.toLowerCase().includes(searchTerm)) ||
            (p.sku && p.sku.toLowerCase().includes(searchTerm)) ||
            (p.barcode && p.barcode.toLowerCase().includes(searchTerm))
        );
    }
    if (results.length === 0) {
        tbody.append(`<tr><td colspan="4" class="text-center text-danger p-3">No products found</td></tr>`);
        return;
    }
    results.slice(0, 50).forEach(product => {
        const price = parseFloat(product.selling_price) || parseFloat(product.cost_price) || 0;
        tbody.append(`
            <tr onclick="selectProduct(${product.product_id})">
                <td><strong>${product.product_name}</strong><br><small class="text-muted">${product.sku || 'No SKU'}</small></td>
                <td>${product.sku || 'N/A'}</td>
                <td>${product.current_stock || 0}</td>
                <td>${price.toLocaleString()}</td>
            </tr>
        `);
    });
}

function selectProduct(productId) {
    const product = productsList.find(p => p.product_id == productId);
    if (product) {
        const row = $(`#${currentRowId}`);
        row.find('.product-selector').val(product.product_name);
        row.find('.item-product-id').val(product.product_id);
        let price = 0;
        if (parseFloat(product.selling_price) > 0) price = parseFloat(product.selling_price);
        else if (parseFloat(product.cost_price) > 0) price = parseFloat(product.cost_price);
        row.find('.price-input').val(price.toFixed(2));
        $('#productSearchResults').hide();
        calculateRowTotal(currentRowId);
        row.find('.qty-input').focus();
    }
}

function calculateRowTotal(rowId) {
    const row = $('#' + rowId);
    const qty = parseFloat(row.find('.qty-input').val()) || 0;
    const price = parseFloat(row.find('.price-input').val()) || 0;
    const taxRate = parseFloat(row.find('.tax-selector option:selected').data('rate')) || 0;
    const subtotal = qty * price;
    const total = subtotal + (subtotal * (taxRate / 100));
    row.find('.row-total').text(total.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}));
    calculateGrandTotal();
}

function calculateGrandTotal() {
    let subtotal = 0, taxTotal = 0;
    $('#itemsBody tr').each(function() {
        const qty = parseFloat($(this).find('.qty-input').val()) || 0;
        const price = parseFloat($(this).find('.price-input').val()) || 0;
        const taxRate = parseFloat($(this).find('.tax-selector option:selected').data('rate')) || 0;
        const lineSubtotal = qty * price;
        subtotal += lineSubtotal;
        taxTotal += lineSubtotal * (taxRate / 100);
    });
    const grand = subtotal + taxTotal;
    const cur = $('#currency').val();
    $('#subtotal_display').text(cur + ' ' + subtotal.toLocaleString(undefined, {minimumFractionDigits: 2}));
    $('#summary-subtotal').text(subtotal.toLocaleString(undefined, {minimumFractionDigits: 2}));
    $('#summary-tax').text(taxTotal.toLocaleString(undefined, {minimumFractionDigits: 2}));
    $('#summary-grand-total').text(cur + ' ' + grand.toLocaleString(undefined, {minimumFractionDigits: 2}));
}

function updateCurSymbols() {
    const sym = $('#currency').val();
    $('.cur-symbol').text(sym + ' ');
    calculateGrandTotal();
}
$(document).on('change', '#currency', updateCurSymbols);

async function fetchProducts() {
    try {
        const whId = $('#warehouse_id').val() || '';
        const response = await fetch('<?= buildUrl('api/account/get_products.php') ?>?limit=1000&is_service=0&warehouse_id=' + whId);
        const result = await response.json();
        if (result.success) productsList = result.data;
    } catch (error) {
        console.error('Failed to fetch products:', error);
    }
}

function saveLpo() {
    const form = $('#lpoForm');
    if (!form[0].checkValidity()) { form[0].reportValidity(); return; }

    const items = [];
    $('#itemsBody tr').each(function() {
        const row = $(this);
        const productId = row.find('.item-product-id').val();
        const productName = row.find('.product-selector').val();
        if (productName) {
            items.push({
                product_id: productId || null,
                product_name: productName,
                quantity: row.find('.qty-input').val(),
                unit_price: row.find('.price-input').val(),
                tax_rate: row.find('.tax-selector option:selected').data('rate') || 0
            });
        }
    });

    if (items.length === 0) {
        Swal.fire('Error', 'Please add at least one item', 'error');
        return;
    }

    const formData = new FormData(form[0]);
    formData.append('_csrf', CSRF_TOKEN);
    formData.append('items', JSON.stringify(items));

    Swal.fire({ title: isEdit ? 'Updating LPO...' : 'Saving LPO...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    $.ajax({
        url: '<?= buildUrl('api/customer/save_lpo.php') ?>',
        type: 'POST', data: formData, processData: false, contentType: false,
        success: function(response) {
            if (response.success) {
                const action = isEdit ? 'Updated Customer LPO' : 'Created Customer LPO';
                logReportAction(action, action + ': ' + (response.lpo_number || ''));
                Swal.fire({
                    icon: 'success',
                    title: isEdit ? 'LPO Updated!' : 'LPO Created!',
                    text: isEdit ? 'The LPO has been successfully updated.' : 'The LPO has been successfully created.',
                    confirmButtonColor: '#28a745', timer: 2500
                }).then(() => { window.location.href = '<?= htmlspecialchars($back_url) ?>'; });
            } else {
                Swal.fire({ icon: 'error', title: 'Save Failed', text: response.message || 'An unknown error occurred.' });
            }
        },
        error: function() {
            Swal.fire({ icon: 'error', title: 'System Error', text: 'A network or server error occurred. Please try again.' });
        }
    });
}
</script>

<style>
.product-search-results { position: absolute; background: white; z-index: 9999; max-height: 400px; overflow-y: auto; border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.1) !important; }
.product-search-results table thead th { position: sticky; top: 0; background: #f8f9fa; z-index: 10; }
.product-search-results tr { cursor: pointer; transition: all 0.2s; }
.product-search-results tr:hover { background-color: #e9ecef !important; }
@media (max-width: 767px) {
    .lpo-create-sticky-nav { position: sticky; top: 0; z-index: 1020; background: #fff; padding: 8px 0 4px; margin-bottom: 0.5rem !important; box-shadow: 0 2px 4px rgba(0,0,0,0.06); }
    #itemsTable { min-width: 700px; }
    .product-search-results { left: 0 !important; right: 0 !important; width: auto !important; margin: 0 8px; }
    .col-md-5 { width: 100%; }
}
</style>

<?php includeFooter(); ?>
