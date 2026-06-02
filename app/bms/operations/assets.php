<?php
// app/bms/operations/assets.php
// Start the buffer
ob_start();

// Ensure database connection is available
global $pdo;

// Include roots configuration
require_once __DIR__ . '/../../../roots.php';

// Enforce permission BEFORE any output
autoEnforcePermission('assets');

// Phase 3b — option lists for the registration form (Select2-backed).
// scope-audit: skip — assets have no project_id; suppliers is read only to
// populate a display/filter list, not to expose project-scoped data.
$asset_suppliers = $pdo->query("SELECT supplier_id, supplier_name FROM suppliers WHERE status != 'deleted' ORDER BY supplier_name")->fetchAll(PDO::FETCH_ASSOC);
$asset_users = $pdo->query("SELECT user_id, TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) AS full_name, username FROM users WHERE is_active = 1 ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
// Existing assets for the optional Parent Asset picker (sub-assets/components).
// A free-text ID let users type a non-existent id and hit an FK "conflict" on
// save; a dropdown of real assets makes an invalid value impossible.
$asset_parents = $pdo->query("SELECT asset_id, asset_code, asset_name FROM assets WHERE status != 'deleted' ORDER BY asset_name")->fetchAll(PDO::FETCH_ASSOC);

// Custodian is auto-detected from the logged-in user (no manual selection).
// Build a quick id→label map for the JS so Edit can still show the asset's
// own (original) custodian, and resolve the current user's own label.
$current_user_id    = (int)($_SESSION['user_id'] ?? 0);
$asset_user_labels  = [];
$current_user_label = $_SESSION['username'] ?? 'Current User';
foreach ($asset_users as $u) {
    $label = trim($u['full_name']) !== '' ? $u['full_name'] . ' (' . $u['username'] . ')' : $u['username'];
    $asset_user_labels[(int)$u['user_id']] = $label;
    if ((int)$u['user_id'] === $current_user_id) $current_user_label = $label;
}

// Default financial dates for the form.
require_once __DIR__ . '/../../../core/asset_settings.php';
$asset_settings = getAssetSettings($pdo);

// Include the header
includeHeader();

?>

<div class="container-fluid mt-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow-sm border-0 bg-white">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h2 class="mb-1 fw-bold text-dark"><i class="bi bi-box-seam text-primary"></i> Assets Management</h2>
                            <p class="mb-0 text-muted">Track and manage business physical assets, maintenance, and disposal</p>
                        </div>
                        <div>
                            <a href="<?= getUrl('asset_dashboard') ?>" class="btn btn-outline-primary shadow-sm me-2">
                                <i class="bi bi-speedometer2 me-1"></i> Dashboard
                            </a>
                            <a href="<?= getUrl('asset_schedule') ?>" class="btn btn-outline-primary shadow-sm me-2">
                                <i class="bi bi-table me-1"></i> PPE Schedule
                            </a>
                            <?php if (canCreate('assets')): ?>
                            <button type="button" class="btn btn-primary shadow-sm px-4" data-bs-toggle="modal" data-bs-target="#assetModal">
                                <i class="bi bi-plus-circle me-1"></i> Add / Record Asset
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0" id="stat-total-assets">0</h4>
                            <p class="mb-0">Total Assets</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-box-seam" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0" id="stat-total-cost">0.00</h4>
                            <p class="mb-0">Total Cost Value</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-currency-dollar" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0" id="stat-maintenance-count">0</h4>
                            <p class="mb-0">In Maintenance</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-tools" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0" id="stat-categories-count">0</h4>
                            <p class="mb-0">Categories</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-tags" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters Card -->
    <div class="card mb-4">
        <div class="card-header bg-light">
            <h6 class="mb-0"><i class="bi bi-funnel"></i> Filters</h6>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Category</label>
                    <select class="form-select" id="categoryFilter">
                        <option value="">All Categories</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select class="form-select" id="statusFilter">
                        <option value="">All Status</option>
                        <option value="active">Active</option>
                        <option value="maintenance">Maintenance</option>
                        <option value="disposed">Disposed</option>
                        <option value="written_off">Written Off</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Location</label>
                    <input type="text" class="form-control" id="locationFilter" placeholder="Any location">
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="button" class="btn btn-primary w-100" onclick="refreshTable()">
                        <i class="bi bi-filter"></i> Apply
                    </button>
                    <button type="button" class="btn btn-outline-secondary ms-2" onclick="clearFilters()">
                        <i class="bi bi-arrow-clockwise"></i> Clear
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Actions Bar -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div class="d-flex align-items-center gap-3">
            <div class="btn-group shadow-sm" style="border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden;">
                <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="printAssets()" style="background: #fff; color: #444;">
                    <i class="bi bi-printer text-primary me-1"></i> Print
                </button>
                <div style="width: 1px; background: #eee; height: 24px; margin-top: 6px;"></div>
                <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="exportAssets()" style="background: #fff; color: #444;">
                    <i class="bi bi-file-earmark-excel text-success me-1"></i> Excel
                </button>
            </div>

            <?php if (canEdit('assets')): ?>
            <button type="button" class="btn btn-outline-primary btn-sm shadow-sm" onclick="runDepreciation()">
                <i class="bi bi-calculator me-1"></i> Run Depreciation
            </button>
            <?php endif; ?>

            <div class="d-flex align-items-center bg-white shadow-sm px-3 py-1" style="border: 1px solid #dee2e6; border-radius: 8px;">
                <span class="small text-muted me-2"><i class="bi bi-list-ol"></i> Show:</span>
                <select class="form-select form-select-sm border-0 fw-bold p-0" style="width: 60px; box-shadow: none; background: transparent;" onchange="$('#assetsTable').DataTable().page.len(this.value).draw();">
                    <option value="10">10</option>
                    <option value="25" selected>25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                    <option value="-1">All</option>
                </select>
            </div>

            <div class="input-group input-group-sm shadow-sm" style="width: 250px; border-radius: 8px; overflow: hidden; border: 1px solid #dee2e6;">
                <span class="input-group-text bg-white border-0"><i class="bi bi-search text-muted"></i></span>
                <input type="text" class="form-control border-0 p-2" id="searchFilter" placeholder="Search assets..." onkeyup="$('#assetsTable').DataTable().ajax.reload()">
            </div>
        </div>
    </div>
    <!-- Assets Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3 border-bottom">
            <h5 class="mb-0 fw-bold">Asset Records</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table id="assetsTable" class="table table-hover align-middle mb-0" style="width:100%">
                    <thead class="bg-light text-muted small text-uppercase">
                        <tr>
                            <th class="ps-4" style="width: 50px;">S/NO</th>
                            <th>Asset Details</th>
                            <th>Code</th>
                            <th>Make</th>
                            <th>Category</th>
                            <th>Purchase Date</th>
                            <th>Capitalization</th>
                            <th class="text-end">Cost</th>
                            <th class="text-end">Accum. Dep. (Book)</th>
                            <th class="text-end">NBV (Book)</th>
                            <th class="text-end">Useful Life</th>
                            <th>Dep. Method</th>
                            <th>Condition</th>
                            <th>Location</th>
                            <th>Custodian</th>
                            <th>Status</th>
                            <th>Disposal Date</th>
                            <th class="text-end pe-4">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Loaded via AJAX -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Add/Edit Asset Modal -->
<div class="modal fade" id="assetModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white p-4">
                <h5 class="modal-title" id="assetModalLabel">
                    <i class="bi bi-plus-circle me-2"></i>Add / Record Asset
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="assetForm">
                <input type="hidden" name="asset_id" id="asset_id">
                <input type="hidden" name="acquisition_type" id="acquisition_type" value="new">
                <input type="hidden" name="condition" id="conditionHidden">
                <div class="modal-body p-4">

                    <!-- §3.1 Identification -->
                    <h6 class="text-uppercase small fw-bold text-muted mb-2"><i class="bi bi-tag me-1"></i> Identification</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Asset Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="asset_name" required placeholder="e.g. MacBook Pro M3">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Make / Manufacturer</label>
                            <input type="text" class="form-control" name="make" id="make" placeholder="e.g. Dell Inc., Toyota Motors">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Asset Code</label>
                            <input type="text" class="form-control" name="asset_code" id="asset_code" placeholder="auto">
                            <small class="text-muted">Auto from category — editable</small>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Warranty Expiry</label>
                            <input type="date" class="form-control" name="warranty_expiry" id="warranty_expiry">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Category <span class="text-danger">*</span></label>
                            <select class="form-select shadow-sm" id="categorySelect" style="border-radius: 8px;" onchange="onCategoryChange()">
                                <option value="">Select Category</option>
                            </select>
                            <input type="hidden" name="category" id="categoryHidden" required>
                            <input type="hidden" name="category_id" id="categoryIdHidden">
                            <small class="text-muted">Manage in <a href="<?= getUrl('asset_categories') ?>" target="_blank">Settings → Asset Categories</a></small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Parent Asset (optional)</label>
                            <select class="form-select select2-asset" name="parent_asset_id" id="parent_asset_id">
                                <option value="">— None —</option>
                                <?php foreach ($asset_parents as $p): ?>
                                <option value="<?= (int)$p['asset_id'] ?>"><?= safe_output(($p['asset_code'] ? $p['asset_code'] . ' — ' : '') . $p['asset_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Only for sub-assets / components</small>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Description</label>
                            <textarea class="form-control" name="description" rows="2" placeholder="Model, accessories, notes…"></textarea>
                        </div>
                    </div>

                    <!-- §3.4/§3.5 Acquisition -->
                    <h6 class="text-uppercase small fw-bold text-muted mb-2"><i class="bi bi-cart-check me-1"></i> Acquisition</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Cost (TZS) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text font-monospace">TZS</span>
                                <input type="number" class="form-control" name="cost" id="cost" step="0.01" required placeholder="0.00" oninput="updatePreview()">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Purchase Date</label>
                            <input type="date" class="form-control" name="purchase_date" id="purchase_date" value="<?= date('Y-m-d') ?>" onchange="onPurchaseDateChange()">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Capitalization Date</label>
                            <input type="date" class="form-control" name="capitalization_date" id="capitalization_date" value="<?= date('Y-m-d') ?>" onchange="updatePreview()">
                            <small class="text-muted">Depreciation starts here</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Take-on Date</label>
                            <input type="date" class="form-control" name="take_on_date" id="take_on_date" onchange="updatePreview()">
                            <small class="text-muted">Optional — only for an already-existing asset (go-live cut-off for b/f balances)</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Supplier</label>
                            <select class="form-select select2-asset" name="supplier_id" id="supplier_id">
                                <option value="">— None —</option>
                                <?php foreach ($asset_suppliers as $s): ?>
                                <option value="<?= (int)$s['supplier_id'] ?>"><?= safe_output($s['supplier_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Invoice / PO / GRN Ref</label>
                            <input type="text" class="form-control" name="invoice_ref" placeholder="e.g. GRN-2026-0001">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Status</label>
                            <select class="form-select" name="status" required>
                                <option value="active">Active</option>
                                <option value="maintenance">Maintenance</option>
                                <option value="disposed">Disposed</option>
                                <option value="written_off">Written Off</option>
                            </select>
                        </div>
                    </div>

                    <!-- §3.4 Assignment -->
                    <h6 class="text-uppercase small fw-bold text-muted mb-2"><i class="bi bi-geo-alt me-1"></i> Assignment</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Location</label>
                            <input type="text" class="form-control" name="location" placeholder="e.g. Headquarters - Room 204">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Custodian</label>
                            <input type="hidden" name="custodian_id" id="custodian_id" value="<?= $current_user_id ?>">
                            <input type="text" class="form-control bg-light" id="custodian_display" value="<?= safe_output($current_user_label) ?>" readonly>
                            <small class="text-muted">Auto-set to the logged-in user when adding; unchanged when editing.</small>
                        </div>
                    </div>

                    <!-- §3.6 Depreciation areas (hidden for non-depreciable categories) -->
                    <div id="depreciationAreas">
                        <h6 class="text-uppercase small fw-bold text-muted mb-2"><i class="bi bi-calculator me-1"></i> Depreciation Areas</h6>
                        <div class="row g-3 mb-2">
                            <!-- Book area -->
                            <div class="col-lg-6">
                                <div class="border rounded p-3 h-100 bg-light">
                                    <div class="fw-bold mb-2"><i class="bi bi-journal-text me-1 text-primary"></i> Book Area <span class="text-muted small">(financial statements)</span></div>
                                    <div class="row g-2">
                                        <div class="col-6">
                                            <label class="form-label small fw-semibold mb-0">Method</label>
                                            <select class="form-select form-select-sm" name="book_method" id="book_method" onchange="updatePreview()">
                                                <option value="straight_line">Straight Line</option>
                                                <option value="reducing_balance">Reducing Balance</option>
                                            </select>
                                        </div>
                                        <div class="col-6" id="book_life_group">
                                            <label class="form-label small fw-semibold mb-0">Useful Life (yrs)</label>
                                            <input type="number" class="form-control form-control-sm" name="book_useful_life" id="book_useful_life" min="1" oninput="updatePreview()">
                                        </div>
                                        <div class="col-6 d-none" id="book_rate_group">
                                            <label class="form-label small fw-semibold mb-0">RB Rate (%)</label>
                                            <input type="number" class="form-control form-control-sm" name="book_rate" id="book_rate" step="0.01" min="0" max="100" oninput="updatePreview()">
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label small fw-semibold mb-0">Salvage (TZS)</label>
                                            <input type="number" class="form-control form-control-sm" name="book_salvage" id="book_salvage" step="0.01" min="0" value="0" oninput="updatePreview()">
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label small fw-semibold mb-0">Opening Accum. b/f</label>
                                            <input type="number" class="form-control form-control-sm" name="book_opening_accum_bf" id="book_opening_accum_bf" step="0.01" min="0" value="0" oninput="updatePreview()">
                                            <small class="text-muted">Existing assets only</small>
                                        </div>
                                    </div>
                                    <hr class="my-2">
                                    <div class="small d-flex justify-content-between"><span class="text-muted">Accumulated:</span> <span id="prev_book_accum" class="fw-semibold">0.00</span></div>
                                    <div class="small d-flex justify-content-between"><span class="text-muted">Net Book Value:</span> <span id="prev_book_nbv" class="fw-bold text-primary">0.00</span></div>
                                    <div class="small d-flex justify-content-between"><span class="text-muted">Suggested condition:</span> <span id="prev_condition" class="fw-semibold">—</span></div>
                                </div>
                            </div>
                            <!-- Tax area -->
                            <div class="col-lg-6">
                                <div class="border rounded p-3 h-100 bg-light">
                                    <div class="fw-bold mb-2"><i class="bi bi-bank me-1 text-success"></i> Tax Area <span class="text-muted small">(capital allowances)</span></div>
                                    <div class="row g-2">
                                        <div class="col-6">
                                            <label class="form-label small fw-semibold mb-0">Method</label>
                                            <input type="text" class="form-control form-control-sm" value="Reducing Balance" disabled>
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label small fw-semibold mb-0">Tax Rate (%)</label>
                                            <input type="number" class="form-control form-control-sm" name="tax_rate" id="tax_rate" step="0.01" min="0" max="100" oninput="updatePreview()">
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label small fw-semibold mb-0">Opening Accum. b/f</label>
                                            <input type="number" class="form-control form-control-sm" name="tax_opening_accum_bf" id="tax_opening_accum_bf" step="0.01" min="0" value="0" oninput="updatePreview()">
                                            <small class="text-muted">Existing assets only</small>
                                        </div>
                                    </div>
                                    <hr class="my-2">
                                    <div class="small d-flex justify-content-between"><span class="text-muted">Accumulated (WDV calc):</span> <span id="prev_tax_accum" class="fw-semibold">0.00</span></div>
                                    <div class="small d-flex justify-content-between"><span class="text-muted">Written-Down Value:</span> <span id="prev_tax_nbv" class="fw-bold text-success">0.00</span></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- §3.3 GL determination (read-only display from category) -->
                    <div id="glDetermination" class="d-none">
                        <h6 class="text-uppercase small fw-bold text-muted mb-2 mt-3"><i class="bi bi-diagram-3 me-1"></i> GL Determination <span class="text-muted">(from category)</span></h6>
                        <div class="row g-3 mb-2">
                            <div class="col-md-4"><span class="text-muted small d-block">Asset Account</span><span id="gl_asset" class="fw-semibold">—</span></div>
                            <div class="col-md-4"><span class="text-muted small d-block">Accum. Dep. Account</span><span id="gl_accum" class="fw-semibold">—</span></div>
                            <div class="col-md-4"><span class="text-muted small d-block">Dep. Expense Account</span><span id="gl_expense" class="fw-semibold">—</span></div>
                        </div>
                    </div>

                    <!-- Non-depreciable notice -->
                    <div id="nonDepreciableNotice" class="alert alert-info d-none mb-0">
                        <i class="bi bi-info-circle me-1"></i> This category is <strong>non-depreciable</strong> (e.g. Land). It will appear on the PPE schedule at cost only.
                    </div>
                </div>
                <div class="modal-footer bg-light p-4">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="bi bi-check-lg me-1"></i> <span id="btnSaveText">Save Asset</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Depreciation Proposal Modal (Preview -> Post) -->
<div class="modal fade" id="depProposalModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-calculator me-2"></i> Run Depreciation — Proposal</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="row g-3 align-items-end mb-3">
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Scope</label>
                        <select id="dep_scope" class="form-select" onchange="onDepScopeChange()">
                            <option value="all">All assets</option>
                            <option value="category">One category</option>
                            <option value="asset">One asset</option>
                        </select>
                    </div>
                    <div class="col-md-4 d-none" id="dep_category_wrap">
                        <label class="form-label fw-semibold">Category</label>
                        <select id="dep_category" class="form-select"></select>
                    </div>
                    <div class="col-md-4 d-none" id="dep_asset_wrap">
                        <label class="form-label fw-semibold">Asset</label>
                        <select id="dep_asset" class="form-select" style="width:100%"></select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Financial Year</label>
                        <input type="number" id="dep_fy" class="form-control" min="2000" max="2100" value="<?= date('Y') ?>">
                    </div>
                    <div class="col-md-2">
                        <button type="button" class="btn btn-outline-primary w-100" onclick="loadDepPreview()">
                            <i class="bi bi-eye me-1"></i> Preview
                        </button>
                    </div>
                </div>

                <div class="alert alert-info py-2 small d-print-none">
                    <i class="bi bi-info-circle me-1"></i> This preview is read-only — figures are computed from each asset's
                    method and do not touch the books until you press <strong>Post Depreciation</strong>.
                </div>

                <div id="dep_preview_area">
                    <div class="text-center text-muted py-4">Choose a scope and year, then <strong>Preview</strong>.</div>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success px-4" id="dep_post_btn" onclick="postDepreciation()" disabled>
                    <i class="bi bi-check2-circle me-1"></i> Post Depreciation
                </button>
            </div>
        </div>
    </div>
</div>

<style>
    /* Asset modal: the <form> wraps both the body and footer, which breaks
       Bootstrap's .modal-dialog-scrollable flex chain (the body can't scroll
       and the top of a tall form gets clipped off-screen). Re-establish the
       flex column on the form so the body scrolls and header/footer stay put. */
    #assetModal .modal-content { max-height: calc(100vh - 3.5rem); overflow: hidden; }
    #assetModal .modal-content > form {
        display: flex;
        flex-direction: column;
        min-height: 0;
        flex: 1 1 auto;
        overflow: hidden;
    }
    #assetModal .modal-body { overflow-y: auto; min-height: 0; }

    .custom-stat-card {
        background-color: #d1e7dd !important;
        border-color: #badbcc !important;
        transition: transform 0.2s;
        border-radius: 12px;
    }
    .custom-stat-card:hover { transform: translateY(-3px); }
    .custom-stat-card h4, 
    .custom-stat-card p, 
    .custom-stat-card i {
        color: #0f5132 !important;
        font-weight: 600;
    }
    
    .table thead th { font-weight: 600; letter-spacing: 0.5px; border-top: 0; }
    .table tbody td { padding: 1rem 0.75rem; }
    
    .status-badge {
        padding: 0.35em 0.65em;
        font-size: 0.75em;
        font-weight: 600;
        border-radius: 50rem;
    }

    /* 📱 MOBILE RESPONSIVE CARD VIEW */
    @media (max-width: 767px) {
        .container-fluid { padding-left: 10px; padding-right: 10px; }
        
        /* Sticky Actions Bar for Mobile */
        .d-flex.justify-content-between.align-items-center.mb-4 {
            position: sticky;
            top: 60px;
            z-index: 100;
            background: #f8f9fa;
            padding: 10px 0;
            flex-direction: column !important;
            gap: 10px;
            align-items: stretch !important;
        }

        #assetsTable, #assetsTable thead, #assetsTable tbody, #assetsTable th, #assetsTable td, #assetsTable tr { 
            display: block; 
        }
        
        #assetsTable thead { display: none; } /* Hide headers on mobile */
        
        #assetsTable tr {
            margin-bottom: 15px;
            border: 1px solid #dee2e6;
            border-radius: 12px;
            background: #fff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            padding: 10px;
            position: relative;
        }

        #assetsTable td {
            border: none;
            position: relative;
            padding-left: 50% !important;
            text-align: right !important;
            min-height: 40px;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            border-bottom: 1px solid #f8f9fa;
        }

        #assetsTable td:last-child { border-bottom: none; }

        #assetsTable td:before {
            content: attr(data-label);
            position: absolute;
            left: 15px;
            width: 45%;
            padding-right: 10px;
            white-space: nowrap;
            text-align: left;
            font-weight: 700;
            color: #6c757d;
            font-size: 0.8rem;
            text-transform: uppercase;
        }

        /* Specialized styling for key fields in card */
        #assetsTable td[data-label="Asset Details"] {
            padding-left: 15px !important;
            text-align: left !important;
            display: block;
            background: #f8f9fa;
            border-radius: 8px;
            margin-bottom: 10px;
        }
        #assetsTable td[data-label="Asset Details"]:before { display: none; }
        
        #assetsTable td[data-label="Actions"] {
            justify-content: center;
            padding-left: 15px !important;
            background: #fff;
        }
        #assetsTable td[data-label="Actions"]:before { display: none; }

        .custom-stat-card { margin-bottom: 10px; }
    }
</style>

<!-- Scripts -->
<script>
// Phase 1 depreciation feature — categories loaded from asset_categories table.
// Cached on first load so onCategoryChange() can auto-fill the form.
let assetCategoriesCache = [];
const ASSET_VIEW_URL = '<?= getUrl('asset_view') ?>';

// Custodian auto-detection: current user for new assets; id→label map so Edit
// can display the asset's own original custodian without a dropdown.
const CURRENT_USER_ID    = <?= (int)$current_user_id ?>;
const CURRENT_USER_LABEL = <?= json_encode($current_user_label) ?>;
const ASSET_USER_LABELS  = <?= json_encode($asset_user_labels, JSON_FORCE_OBJECT) ?>;

// Set the custodian hidden id + read-only display together.
function setCustodian(id, label) {
    $('#custodian_id').val(id || '');
    $('#custodian_display').val(label || (id && ASSET_USER_LABELS[id]) || '—');
}

// "Existing asset" is implied (no mode toggle) when the user fills the take-on
// date or an opening accumulated-depreciation b/f. Otherwise it's a new asset.
function syncAcqType() {
    const bf = parseFloat($('#book_opening_accum_bf').val() || 0) + parseFloat($('#tax_opening_accum_bf').val() || 0);
    const existing = !!$('#take_on_date').val() || bf > 0;
    $('#acquisition_type').val(existing ? 'existing' : 'new');
    return existing;
}

function loadAssetCategoriesIntoSelect() {
    return $.getJSON('<?= buildUrl('api/assets/get_asset_categories.php') ?>', function(resp) {
        if (!resp.success) return;
        assetCategoriesCache = resp.categories;
        const $sel = $('#categorySelect');
        $sel.find('option:not(:first)').remove();
        resp.categories.forEach(c => {
            $sel.append(`<option value="${c.category_name.replace(/"/g, '&quot;')}" data-cat-id="${c.category_id}">${c.category_name}${c.tra_class ? ' ('+c.tra_class+')' : ''}</option>`);
        });
    });
}

// Category-first cascade (§3.3): sets hidden name/id, auto-fills both areas'
// defaults, shows/hides the depreciation section by is_depreciable, displays
// the category's GL accounts, and fetches the next asset code.
function onCategoryChange() {
    const $sel  = $('#categorySelect');
    const name  = $sel.val();
    const catId = $sel.find(':selected').data('cat-id') || '';
    $('#categoryHidden').val(name);
    $('#categoryIdHidden').val(catId);

    const cat = assetCategoriesCache.find(c => c.category_id == catId);
    applyCategoryToForm(cat);

    // Live asset code from the server (only meaningful on create / blank code).
    if (!$('#asset_id').val() && catId) fetchNextCode(catId);

    updatePreview();
}

// Apply a category's controller settings to the form. autoFill=true overwrites
// the depreciation defaults (used on category change); false leaves user/edit
// values intact (used when loading an existing asset).
function applyCategoryToForm(cat, autoFill = true) {
    const depreciable = cat ? Number(cat.is_depreciable) === 1 : true;

    $('#depreciationAreas').toggle(depreciable);
    $('#nonDepreciableNotice').toggleClass('d-none', depreciable);

    if (cat) {
        // GL determination — read-only display.
        const hasGl = cat.gl_asset_account || cat.gl_accum_account || cat.gl_expense_account;
        $('#glDetermination').toggleClass('d-none', !hasGl);
        $('#gl_asset').text(cat.gl_asset_account || '—');
        $('#gl_accum').text(cat.gl_accum_account || '—');
        $('#gl_expense').text(cat.gl_expense_account || '—');

        if (autoFill && depreciable) {
            $('#book_method').val(cat.default_method || 'straight_line');
            if (cat.default_useful_life_years) $('#book_useful_life').val(cat.default_useful_life_years);
            if (cat.default_annual_rate_percent !== null) $('#book_rate').val(cat.default_annual_rate_percent);
            if (cat.tax_rate !== null && cat.tax_rate !== undefined) $('#tax_rate').val(cat.tax_rate);
            if (cat.default_salvage_percent > 0) {
                const cost = parseFloat($('#cost').val() || 0);
                if (cost > 0) $('#book_salvage').val((cost * cat.default_salvage_percent / 100).toFixed(2));
            }
        }
    } else {
        $('#glDetermination').addClass('d-none');
    }
    toggleBookMethodFields();
}

// Show useful-life for straight line, RB rate for reducing balance.
function toggleBookMethodFields() {
    const isSL = $('#book_method').val() === 'straight_line';
    $('#book_life_group').toggleClass('d-none', !isSL);
    $('#book_rate_group').toggleClass('d-none', isSL);
}

function fetchNextCode(catId) {
    $.getJSON('<?= buildUrl('api/assets/get_next_asset_code.php') ?>', { category_id: catId }, function(resp) {
        if (resp.success && !$('#asset_code').val()) $('#asset_code').val(resp.code);
    });
}

// Capitalization date defaults to purchase date until the user overrides it.
let capDateTouched = false;
function onPurchaseDateChange() {
    if (!capDateTouched) $('#capitalization_date').val($('#purchase_date').val());
    updatePreview();
}

// Whole-years elapsed between two yyyy-mm-dd dates (>= 0).
function yearsBetween(startStr, endStr) {
    if (!startStr) return 0;
    const start = new Date(startStr);
    const end   = endStr ? new Date(endStr) : new Date();
    if (isNaN(start) || isNaN(end) || end < start) return 0;
    let y = end.getFullYear() - start.getFullYear();
    const m = end.getMonth() - start.getMonth();
    if (m < 0 || (m === 0 && end.getDate() < start.getDate())) y--;
    return Math.max(0, y);
}

// §4 formulas — client-side preview of accumulated / NBV / condition.
function updatePreview() {
    // Non-depreciable category (e.g. Land): no schedule, let the server set 'good'.
    if (!$('#depreciationAreas').is(':visible')) {
        $('#conditionHidden').val('');
        return;
    }
    toggleBookMethodFields();
    const cost      = parseFloat($('#cost').val() || 0);
    const existing  = syncAcqType();
    const asOf      = '<?= date('Y-m-d') ?>';
    const start     = existing ? ($('#take_on_date').val() || $('#capitalization_date').val())
                               : $('#capitalization_date').val();
    const years     = yearsBetween(start, asOf);

    // ── Book area ──
    const bMethod  = $('#book_method').val();
    const bSalvage = parseFloat($('#book_salvage').val() || 0);
    const bBf      = existing ? parseFloat($('#book_opening_accum_bf').val() || 0) : 0;
    let bAccum, bNbv;
    if (bMethod === 'straight_line') {
        const life = parseInt($('#book_useful_life').val() || 0, 10);
        const annual = life > 0 ? (cost - bSalvage) / life : 0;
        if (existing) {
            const openNbv = cost - bBf;
            bNbv = Math.max(bSalvage, openNbv - annual * years);
        } else {
            bNbv = Math.max(bSalvage, cost - annual * years);
        }
        bAccum = cost - bNbv;
    } else {
        const rate = parseFloat($('#book_rate').val() || 0) / 100;
        let nbv = existing ? (cost - bBf) : cost;
        for (let i = 0; i < years; i++) nbv = nbv * (1 - rate);
        bNbv = nbv; bAccum = cost - nbv;
    }
    $('#prev_book_accum').text(formatCurrency(bAccum));
    $('#prev_book_nbv').text(formatCurrency(bNbv));

    // Suggested condition from book NBV % (§4.4).
    const cond = suggestConditionJS(cost, bNbv);
    $('#prev_condition').text(cond.charAt(0).toUpperCase() + cond.slice(1));
    $('#conditionHidden').val(cond);

    // ── Tax area (reducing balance) ──
    const tRate = parseFloat($('#tax_rate').val() || 0) / 100;
    const tBf   = existing ? parseFloat($('#tax_opening_accum_bf').val() || 0) : 0;
    let tNbv = existing ? (cost - tBf) : cost;
    for (let i = 0; i < years; i++) tNbv = tNbv * (1 - tRate);
    $('#prev_tax_accum').text(formatCurrency(cost - tNbv));
    $('#prev_tax_nbv').text(formatCurrency(tNbv));
}

function suggestConditionJS(cost, nbv) {
    if (cost <= 0) return 'good';
    const pct = (nbv / cost) * 100;
    if (pct <= 0)  return 'eol';
    if (pct <= 25) return 'poor';
    if (pct <= 50) return 'fair';
    if (pct <= 75) return 'good';
    return 'excellent';
}

$(document).ready(function() {
    logReportAction('Viewed Assets List', 'User viewed the asset management page');
    // Categories load async; open a pre-scoped depreciation proposal once ready
    // when arriving from an asset detail page (?dep_asset=N).
    loadAssetCategoriesIntoSelect().always(function() {
        const params = new URLSearchParams(window.location.search);
        const depAsset = params.get('dep_asset');
        if (depAsset) runDepreciation(parseInt(depAsset, 10));
    });
    const userPermissions = {
        canEdit: <?= canEdit('assets') ? 'true' : 'false' ?>,
        canDelete: <?= canDelete('assets') ? 'true' : 'false' ?>
    };

    const table = $('#assetsTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: '/api/operations/get_assets.php',
            data: function(d) {
                d.category = $('#categoryFilter').val();
                d.status = $('#statusFilter').val();
                d.location = $('#locationFilter').val();
                d.search_term = $('#searchFilter').val();
            },
            dataSrc: function(json) {
                $('#stat-total-assets').text(json.stats.total_count);
                $('#stat-total-cost').text(formatCurrency(json.stats.total_cost));
                $('#stat-maintenance-count').text(json.stats.maintenance_count);
                $('#stat-categories-count').text(json.stats.categories_count);
                
                // Populate category filter if empty
                if ($('#categoryFilter option').length === 1 && json.categories) {
                    json.categories.forEach(cat => {
                        $('#categoryFilter').append(`<option value="${cat}">${cat}</option>`);
                    });
                }
                
                return json.data;
            }
        },
        columns: [
            { 
                data: null, 
                title: 'S/NO', 
                orderable: false, 
                searchable: false,
                render: function (data, type, row, meta) {
                    return meta.row + meta.settings._iDisplayStart + 1;
                },
                className: 'ps-4',
                createdCell: (td) => $(td).attr('data-label', 'S/NO')
            },
            { 
                data: 'asset_name',
                render: function(data, type, row) {
                    return `<div>
                        <div class="fw-bold text-dark">${data}</div>
                        <div class="small text-muted">${row.description ? row.description.substring(0, 50) + '...' : ''}</div>
                    </div>`;
                },
                createdCell: (td) => $(td).attr('data-label', 'Asset Details')
            },
            {
                data: 'asset_code',
                render: data => `<span class="badge bg-light text-dark border">${data || 'N/A'}</span>`,
                createdCell: (td) => $(td).attr('data-label', 'Code')
            },
            {
                data: 'make',
                render: data => data || '<span class="text-muted small">—</span>',
                createdCell: (td) => $(td).attr('data-label', 'Make')
            },
            {
                data: 'category',
                createdCell: (td) => $(td).attr('data-label', 'Category')
            },
            {
                data: 'purchase_date',
                render: data => data ? new Date(data).toLocaleDateString() : '<span class="text-muted small">—</span>',
                createdCell: (td) => $(td).attr('data-label', 'Purchase Date')
            },
            {
                data: 'capitalization_date',
                render: data => data ? new Date(data).toLocaleDateString() : 'N/A',
                createdCell: (td) => $(td).attr('data-label', 'Capitalization')
            },
            {
                data: 'cost',
                className: 'text-end',
                render: data => `<strong>${formatCurrency(data)}</strong>`,
                createdCell: (td) => $(td).attr('data-label', 'Cost')
            },
            {
                data: 'accum_dep_book',
                className: 'text-end',
                render: data => `<span class="text-muted">${formatCurrency(data || 0)}</span>`,
                createdCell: (td) => $(td).attr('data-label', 'Accum. Dep. (Book)')
            },
            {
                data: 'nbv_book',
                className: 'text-end',
                render: data => `<strong class="text-primary">${formatCurrency(data || 0)}</strong>`,
                createdCell: (td) => $(td).attr('data-label', 'NBV (Book)')
            },
            {
                data: 'useful_life_years',
                className: 'text-end',
                render: data => (data === null || data === undefined || data === '') ? '<span class="text-muted small">—</span>' : data,
                createdCell: (td) => $(td).attr('data-label', 'Useful Life')
            },
            {
                data: 'depreciation_method',
                render: data => data ? (data === 'straight_line' ? 'Straight Line' : 'Reducing Balance') : '<span class="text-muted small">—</span>',
                createdCell: (td) => $(td).attr('data-label', 'Dep. Method')
            },
            {
                data: 'condition',
                render: function(data) {
                    if (!data) return '<span class="text-muted small">—</span>';
                    const map = { excellent:'success', good:'info', fair:'warning', poor:'danger', eol:'dark' };
                    return `<span class="badge bg-${map[data] || 'secondary'}-subtle text-${map[data] || 'secondary'}-emphasis border">${data.charAt(0).toUpperCase() + data.slice(1)}</span>`;
                },
                createdCell: (td) => $(td).attr('data-label', 'Condition')
            },
            {
                data: 'location',
                render: data => data || '<span class="text-muted small">Not specified</span>',
                createdCell: (td) => $(td).attr('data-label', 'Location')
            },
            {
                data: 'custodian_name',
                render: (data, type, row) => (data && data.trim()) ? data : (row.custodian_username || '<span class="text-muted small">—</span>'),
                createdCell: (td) => $(td).attr('data-label', 'Custodian')
            },
            {
                data: 'status',
                render: function(data) {
                    let cls = 'secondary';
                    if (data === 'active') cls = 'success';
                    if (data === 'maintenance') cls = 'warning';
                    if (data === 'disposed' || data === 'written_off') cls = 'danger';
                    return `<span class="status-badge bg-${cls}">${data.charAt(0).toUpperCase() + data.slice(1)}</span>`;
                },
                createdCell: (td) => $(td).attr('data-label', 'Status')
            },
            {
                data: 'disposal_date',
                render: data => data ? new Date(data).toLocaleDateString() : '<span class="text-muted small">—</span>',
                createdCell: (td) => $(td).attr('data-label', 'Disposal Date')
            },
            {
                data: null,
                orderable: false,
                className: 'text-end pe-4',
                createdCell: (td) => $(td).attr('data-label', 'Actions'),
                render: function(data, type, row) {
                    let html = `
                        <div class="dropdown action-dropdown">
                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-gear"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow border-0">`;

                    html += `<li><a class="dropdown-item" href="${ASSET_VIEW_URL}?id=${row.asset_id}"><i class="bi bi-eye me-2 text-info"></i> View Details</a></li>`;

                    if (userPermissions.canEdit) {
                        html += `<li><a class="dropdown-item" href="javascript:void(0)" onclick="editAsset(${row.asset_id})"><i class="bi bi-pencil me-2 text-primary"></i> Edit Details</a></li>`;
                        
                        if (row.status === 'active') {
                            html += `<li><a class="dropdown-item" href="javascript:void(0)" onclick="changeStatus(${row.asset_id}, 'maintenance')"><i class="bi bi-tools me-2 text-warning"></i> Send to Maintenance</a></li>`;
                        } else if (row.status === 'maintenance') {
                            html += `<li><a class="dropdown-item" href="javascript:void(0)" onclick="changeStatus(${row.asset_id}, 'active')"><i class="bi bi-check-circle me-2 text-success"></i> Return to Service</a></li>`;
                        }
                    }
                    
                    if (userPermissions.canDelete) {
                        html += `<li><hr class="dropdown-divider"></li>
                                 <li><a class="dropdown-item text-danger" href="javascript:void(0)" onclick="deleteAsset(${row.asset_id})"><i class="bi bi-trash me-2"></i> Delete Asset</a></li>`;
                    }
                    
                    html += `</ul></div>`;
                    return html;
                }
            }
        ],
        lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
        pageLength: 25,
        dom: 'rtip',
        drawCallback: function() {
            console.log('Table redrawn');
        }
    });

    // Log View Action
    $.post(APP_URL + '/api/log_audit', {
        action: 'view_list',
        activity_type: 'view',
        entity_type: 'asset',
        description: 'Viewed assets management page'
    });

    // Log when Add Asset modal is opened
    $('#assetModal').on('show.bs.modal', function() {
        if (!$('#asset_id').val()) {
            $.post(APP_URL + '/api/log_audit', {
                action: 'open_add_modal',
                activity_type: 'view',
                entity_type: 'asset',
                description: 'Opened Add New Asset modal'
            });
        }
    });

    let searchTimeout;
    $('#searchFilter').on('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            table.ajax.reload();
            $.post(APP_URL + '/api/log_audit', {
                action: 'search_assets',
                activity_type: 'view',
                entity_type: 'asset',
                description: `Searched assets with term: ${$(this).val()}`
            });
        }, 500);
    });

    // Form submission
    $('#assetForm').on('submit', function(e) {
        e.preventDefault();
        const $btn = $('#assetForm button[type="submit"]');
        const isEdit = !!$('#asset_id').val();
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Processing...');

        syncAcqType();
        const formData = $(this).serialize();

        $.ajax({
            url: APP_URL + '/api/operations/save_asset',
            type: 'POST',
            dataType: 'json',
            data: formData,
            success: function(res) {
                if (res.success) {
                    $('#assetModal').modal('hide');
                    table.ajax.reload();
                    Swal.fire({
                        icon: 'success',
                        title: res.message,
                        confirmButtonText: 'OK',
                        confirmButtonColor: '#0d6efd'
                    });
                } else {
                    showToast('error', res.message);
                }
            },
            error: () => showToast('error', 'Server error. Please try again.'),
            complete: () => {
                $btn.prop('disabled', false).html('<i class="bi bi-check-lg me-1"></i> <span id="btnSaveText">' + (isEdit ? 'Update Asset' : 'Save Asset') + '</span>');
            }
        });
    });

    // Mark capitalization date as user-edited so purchase-date sync backs off.
    $('#capitalization_date').on('input', function() { capDateTouched = true; });
    $('#book_method').on('change', toggleBookMethodFields);

    // Init Select2 on the assignment dropdowns when the modal opens.
    $('#assetModal').on('shown.bs.modal', function() {
        $('.select2-asset').each(function() {
            if (!$(this).hasClass('select2-hidden-accessible')) {
                $(this).select2({ theme: 'bootstrap-5', dropdownParent: $('#assetModal'), width: '100%', placeholder: '— None —', allowClear: true });
            }
        });
    });

    // Modal reset — back to a clean New-acquisition form.
    $('#assetModal').on('hidden.bs.modal', function() {
        $('#assetForm')[0].reset();
        $('#asset_id').val('');
        $('#acquisition_type').val('new');
        capDateTouched = false;
        setCustodian(CURRENT_USER_ID, CURRENT_USER_LABEL);
        $('#categorySelect').val('');
        $('#categoryHidden').val('');
        $('#categoryIdHidden').val('');
        $('#parent_asset_id option').prop('disabled', false);
        $('.select2-asset').val('').trigger('change');
        $('#depreciationAreas').show();
        $('#glDetermination').addClass('d-none');
        $('#nonDepreciableNotice').addClass('d-none');
        $('#assetModalLabel').html('<i class="bi bi-plus-circle me-2"></i>Add / Record Asset');
        $('#btnSaveText').text('Save Asset');
    });
});

function formatCurrency(v) {
    return parseFloat(v).toLocaleString('en-US', { minimumFractionDigits: 2 }) + ' TZS';
}

function refreshTable() {
    $('#assetsTable').DataTable().ajax.reload();
}

function clearFilters() {
    $('#categoryFilter').val('');
    $('#statusFilter').val('');
    $('#locationFilter').val('');
    $('#searchFilter').val('');
    refreshTable();
}

// Trigger the depreciation engine for a chosen financial year (§4 run).
// ── Depreciation Proposal (Preview -> Post) ─────────────────────────────────
const DEP_RUN_URL = '<?= buildUrl('api/assets/run_depreciation.php') ?>';

// Open the proposal modal. Optional presetAssetId pre-scopes to one asset.
function runDepreciation(presetAssetId) {
    $('#dep_fy').val(new Date().getFullYear());
    $('#dep_post_btn').prop('disabled', true);
    $('#dep_preview_area').html('<div class="text-center text-muted py-4">Choose a scope and year, then <strong>Preview</strong>.</div>');

    // Populate the category dropdown from the cache.
    const $cat = $('#dep_category').empty();
    assetCategoriesCache.forEach(c => $cat.append(`<option value="${escapeAttr(c.category_name)}">${escapeHtmlDep(c.category_name)}</option>`));

    if (presetAssetId) {
        $('#dep_scope').val('asset');
    } else {
        $('#dep_scope').val('all');
    }
    onDepScopeChange();
    new bootstrap.Modal(document.getElementById('depProposalModal')).show();

    if (presetAssetId) {
        // Preselect the asset option, then auto-preview.
        const opt = new Option('Asset #' + presetAssetId, presetAssetId, true, true);
        $('#dep_asset').append(opt).trigger('change');
        setTimeout(loadDepPreview, 250);
    }
}

function onDepScopeChange() {
    const s = $('#dep_scope').val();
    $('#dep_category_wrap').toggleClass('d-none', s !== 'category');
    $('#dep_asset_wrap').toggleClass('d-none', s !== 'asset');
    // Init the asset Select2 (AJAX search on the existing register feed) once.
    if (s === 'asset' && !$('#dep_asset').hasClass('select2-hidden-accessible')) {
        $('#dep_asset').select2({
            theme: 'bootstrap-5',
            dropdownParent: $('#depProposalModal'),
            placeholder: 'Search asset…',
            width: '100%',
            ajax: {
                url: '/api/operations/get_assets.php',
                dataType: 'json',
                delay: 250,
                data: params => ({ draw: 1, start: 0, length: 20, search_term: params.term || '' }),
                processResults: data => ({
                    results: (data.data || []).map(a => ({ id: a.asset_id, text: (a.asset_code ? a.asset_code + ' — ' : '') + a.asset_name }))
                })
            }
        });
    }
    $('#dep_post_btn').prop('disabled', true);
}

function depScopeParams() {
    const s = $('#dep_scope').val();
    let value = null;
    if (s === 'category') value = $('#dep_category').val();
    if (s === 'asset')    value = $('#dep_asset').val();
    return { scope_type: s, scope_value: value || '' };
}

function loadDepPreview() {
    const fy = parseInt($('#dep_fy').val(), 10);
    if (!fy || fy < 2000 || fy > 2100) { Swal.fire('Invalid year', 'Enter a financial year between 2000 and 2100.', 'warning'); return; }
    const sp = depScopeParams();
    if (sp.scope_type !== 'all' && !sp.scope_value) { Swal.fire('Pick a ' + sp.scope_type, 'Choose a ' + sp.scope_type + ' to preview.', 'warning'); return; }

    $('#dep_preview_area').html('<div class="text-center py-4"><span class="spinner-border spinner-border-sm me-2"></span> Computing proposal…</div>');
    $('#dep_post_btn').prop('disabled', true);

    $.ajax({
        url: DEP_RUN_URL, type: 'POST', dataType: 'json',
        data: Object.assign({ mode: 'preview', fy_year: fy, _csrf: CSRF_TOKEN }, sp),
        success: function(res) {
            if (!res.success) { $('#dep_preview_area').html(`<div class="alert alert-danger">${res.message || 'Failed'}</div>`); return; }
            renderDepProposal(res.proposal);
        },
        error: () => $('#dep_preview_area').html('<div class="alert alert-danger">Preview request failed.</div>')
    });
}

function renderDepProposal(p) {
    if (!p.rows.length) {
        $('#dep_preview_area').html('<div class="alert alert-warning mb-0">No depreciable assets match this scope/year.</div>');
        $('#dep_post_btn').prop('disabled', true);
        return;
    }
    let html = `<div class="small text-muted mb-2">Financial year <strong>${p.fy_year}</strong> (${p.period_start} to ${p.period_end}) — book area, ${p.rows.length} asset(s)</div>
    <div class="table-responsive"><table class="table table-sm table-bordered align-middle mb-0">
      <thead class="table-light"><tr>
        <th>Code</th><th>Asset</th><th>Category</th><th>Method</th>
        <th class="text-end">Cost</th><th class="text-end">Opening Accum.</th>
        <th class="text-end">Charge for Year</th><th class="text-end">Closing Accum.</th>
        <th class="text-end">NBV</th><th></th>
      </tr></thead><tbody>`;
    p.rows.forEach(r => {
        const m = r.method === 'straight_line' ? 'Straight Line' : 'Reducing Balance';
        html += `<tr>
            <td>${escapeHtmlDep(r.asset_code || '—')}</td>
            <td>${escapeHtmlDep(r.asset_name || '')}</td>
            <td>${escapeHtmlDep(r.category || '')}</td>
            <td>${m}</td>
            <td class="text-end">${fmtDep(r.cost)}</td>
            <td class="text-end">${fmtDep(r.opening_accum)}</td>
            <td class="text-end fw-bold text-primary">${fmtDep(r.charge)}</td>
            <td class="text-end">${fmtDep(r.closing_accum)}</td>
            <td class="text-end">${fmtDep(r.nbv)}</td>
            <td>${r.already_posted ? '<span class="badge bg-secondary-subtle text-secondary-emphasis border">Posted</span>' : ''}</td>
        </tr>`;
    });
    html += `<tr class="table-primary fw-bold">
        <td colspan="4">TOTAL</td>
        <td class="text-end">${fmtDep(p.totals.cost)}</td>
        <td class="text-end">${fmtDep(p.totals.opening_accum)}</td>
        <td class="text-end">${fmtDep(p.totals.charge)}</td>
        <td class="text-end">${fmtDep(p.totals.closing_accum)}</td>
        <td class="text-end">${fmtDep(p.totals.nbv)}</td><td></td>
    </tr></tbody></table></div>`;
    $('#dep_preview_area').html(html);
    $('#dep_post_btn').prop('disabled', false);
}

function postDepreciation() {
    const fy = parseInt($('#dep_fy').val(), 10);
    const sp = depScopeParams();
    Swal.fire({
        title: 'Post depreciation?',
        html: `This will post depreciation for FY <strong>${fy}</strong> (scope: ${sp.scope_type}) to the books. Already-posted periods are skipped.`,
        icon: 'question', showCancelButton: true, confirmButtonText: 'Yes, post', confirmButtonColor: '#198754'
    }).then(r => {
        if (!r.isConfirmed) return;
        const $btn = $('#dep_post_btn'); const orig = $btn.html();
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Posting…');
        $.ajax({
            url: DEP_RUN_URL, type: 'POST', dataType: 'json',
            data: Object.assign({ mode: 'post', fy_year: fy, _csrf: CSRF_TOKEN }, sp),
            success: function(res) {
                if (res.success) {
                    bootstrap.Modal.getInstance(document.getElementById('depProposalModal')).hide();
                    refreshTable();
                    Swal.fire({ icon: 'success', title: 'Posted', text: res.message, confirmButtonColor: '#0d6efd' });
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: res.message });
                }
            },
            error: () => Swal.fire({ icon: 'error', title: 'Error', text: 'Post request failed.' }),
            complete: () => $btn.html(orig)
        });
    });
}

function fmtDep(v) { return Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 }); }
function escapeHtmlDep(s) { return s == null ? '' : String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function escapeAttr(s) { return escapeHtmlDep(s); }

function changeStatus(id, status) {
    // Log intent
    $.post(APP_URL + '/api/log_audit', {
        action: 'update_status_intent',
        activity_type: 'update',
        entity_type: 'asset',
        entity_id: id,
        description: `User initiated status update for asset (ID: ${id}, New Status: ${status})`
    });

    const action = status === 'maintenance' ? 'send to maintenance' : 'return to service';
    
    Swal.fire({
        title: 'Are you sure?',
        text: `Do you want to ${action} this asset?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#0d6efd',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, proceed!',
        cancelButtonText: 'No, cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post(APP_URL + '/api/operations/save_asset', { asset_id: id, status: status, action: 'update_status' }, function(res) {
                if (res.success) {
                    logReportAction('Updated Asset Status', `User updated asset ID: ${id} status to ${status}`);
                    refreshTable();
                    showToast('success', res.message);
                } else {
                    showToast('error', res.message);
                }
            });
        }
    });
}

function editAsset(id) {
    // Log intent
    logReportAction('Initiated Asset Edit', `User clicked to edit asset (ID: ${id})`);

    $.getJSON(APP_URL + '/api/operations/get_asset', { id: id }, function(res) {
        if (!res.success) { showToast('error', res.message); return; }
        const data = res.data;
        const areas = data.areas || {};
        const mode = data.acquisition_type === 'existing' ? 'existing' : 'new';

        // Identification
        $('#asset_id').val(data.asset_id);
        $('input[name="asset_name"]').val(data.asset_name);
        $('#make').val(data.make || '');
        $('#asset_code').val(data.asset_code);
        $('#warranty_expiry').val(data.warranty_expiry || '');
        // Parent dropdown: prevent an asset from being its own parent.
        $('#parent_asset_id option').prop('disabled', false);
        $('#parent_asset_id option[value="' + data.asset_id + '"]').prop('disabled', true);
        $('#parent_asset_id').val(data.parent_asset_id || '').trigger('change');
        $('textarea[name="description"]').val(data.description || '');
        $('#categorySelect').val(data.category);
        $('#categoryHidden').val(data.category);
        $('#categoryIdHidden').val(data.category_id || '');

        // Acquisition type is now inferred from the existing-asset fields below.
        $('#acquisition_type').val(mode);
        $('#purchase_date').val(data.purchase_date || '');
        $('#capitalization_date').val(data.capitalization_date || data.purchase_date || '');
        $('#take_on_date').val(data.take_on_date || '');
        capDateTouched = true;

        $('#cost').val(data.cost);
        $('select[name="status"]').val(data.status);
        $('input[name="invoice_ref"]').val(data.invoice_ref || '');
        $('input[name="location"]').val(data.location || '');
        $('#supplier_id').val(data.supplier_id || '').trigger('change');
        // Keep the asset's original custodian on edit (show it read-only).
        setCustodian(data.custodian_id || '', null);

        // Category controls (show/hide depreciation + GL) WITHOUT overwriting saved values.
        const cat = assetCategoriesCache.find(c => c.category_id == data.category_id);
        applyCategoryToForm(cat, false);

        // Depreciation areas from the saved rows.
        if (areas.book) {
            $('#book_method').val(areas.book.method || 'straight_line');
            $('#book_useful_life').val(areas.book.useful_life || '');
            $('#book_rate').val(areas.book.rate || '');
            $('#book_salvage').val(areas.book.salvage_value || 0);
            $('#book_opening_accum_bf').val(areas.book.opening_accum_bf || 0);
        }
        if (areas.tax) {
            $('#tax_rate').val(areas.tax.rate || '');
            $('#tax_opening_accum_bf').val(areas.tax.opening_accum_bf || 0);
        }

        $('#assetModalLabel').html('<i class="bi bi-pencil me-2"></i>Edit Asset');
        $('#btnSaveText').text('Update Asset');
        $('#assetModal').modal('show');
        updatePreview();
    });
}

function deleteAsset(id) {
    // Log intent
    logReportAction('Initiated Asset Deletion', `User initiated deletion for asset (ID: ${id})`);

    Swal.fire({
        title: 'Delete Asset?',
        text: 'Are you sure you want to delete this asset? This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, delete it!',
        cancelButtonText: 'No, keep it'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post(APP_URL + '/api/operations/delete_asset', { asset_id: id }, function(res) {
                if (res.success) {
                    logReportAction('Deleted Asset Record', `User successfully deleted asset ID: ${id}`);
                    refreshTable();
                    showToast('success', res.message);
                } else {
                    showToast('error', res.message);
                }
            });
        }
    });
}

function printAssets() {
    logReportAction('Printed Assets List', 'User generated a printed list of assets');
    const category = $('#categoryFilter').val();
    const status = $('#statusFilter').val();
    const search = $('#searchFilter').val();
    
    // Open in a new tab/window using the dynamic system URL
    const url = '<?= getUrl("print-assets") ?>?c=' + category + '&s=' + status + '&q=' + encodeURIComponent(search) + '&a=1';
    window.open(url, '_blank');
}

function exportAssets() {
    logReportAction('Exported Assets List', 'User exported the assets list to Excel/CSV');
    const category = $('#categoryFilter').val();
    const status = $('#statusFilter').val();
    window.location.href = `/api/operations/export_assets.php?category=${category}&status=${status}`;
}

function showToast(type, msg) {
    if (type === 'success') {
        Swal.fire({
            icon: 'success',
            title: msg,
            confirmButtonText: 'OK',
            confirmButtonColor: '#0d6efd'
        });
    } else {
        Swal.fire({
            icon: type,
            title: msg,
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: true
        });
    }
}
</script>

<?php
includeFooter();
?>

<?php
ob_end_flush();
?>
