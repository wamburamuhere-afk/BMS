<?php
/**
 * Cash Register Management
 */
ob_start();

global $pdo;
require_once __DIR__ . '/../../../roots.php';

// Check permissions - using 'cash_register' specifically as intended
autoEnforcePermission('cash_register');

includeHeader();

// Fetch company settings for print
$c_logo = getSetting('company_logo', '');
$c_name = getSetting('company_name', 'BMS');

$shifts = [];
$current_shift = null;
$total_cash = 0;
$total_transactions = 0;
$error = null;

// Get user role safely using the standard system function
$is_admin = isAdmin();
// Managers or those with edit permission should see all shifts
$can_view_all = $is_admin || canEdit('cash_register');

// Filters
$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';
$filter_user_id = $_GET['user_id'] ?? '';
$filter_status = $_GET['status'] ?? '';

try {
    $user_id = $_SESSION['user_id'] ?? 0;
    
    // Get current user's active shift
    $shiftStmt = $pdo->prepare("
        SELECT * FROM cash_register_shifts 
        WHERE user_id = ? AND status = 'active' 
        ORDER BY start_time DESC LIMIT 1
    ");
    $shiftStmt->execute([$user_id]);
    $current_shift = $shiftStmt->fetch(PDO::FETCH_ASSOC);
    
    // Build shifts query
    $where = ["1=1"];
    $params = [];

    if (!$can_view_all) {
        $where[] = "crs.user_id = ?";
        $params[] = $user_id;
    } elseif ($filter_user_id) {
        $where[] = "crs.user_id = ?";
        $params[] = $filter_user_id;
    }

    if ($from_date) {
        $where[] = "crs.start_time >= ?";
        $params[] = $from_date . ' 00:00:00';
    }
    if ($to_date) {
        $where[] = "crs.start_time <= ?";
        $params[] = $to_date . ' 23:59:59';
    }
    if ($filter_status) {
        $where[] = "crs.status = ?";
        $params[] = $filter_status;
    }

    $whereStr = implode(' AND ', $where);
    
    $shiftsStmt = $pdo->prepare("
        SELECT crs.*, u.username 
        FROM cash_register_shifts crs
        LEFT JOIN users u ON crs.user_id = u.user_id
        WHERE $whereStr
        ORDER BY crs.start_time DESC
    ");
    $shiftsStmt->execute($params);
    $shifts = $shiftsStmt->fetchAll(PDO::FETCH_ASSOC);

    $filter_users = [];
    if ($can_view_all) {
        // Load all users for admin/manager filter dropdown
        $usersStmt = $pdo->query("SELECT user_id, username FROM users ORDER BY username ASC");
        $filter_users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    if ($current_shift) {
        $transStmt = $pdo->prepare("
            SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as total
            FROM cash_register_transactions
            WHERE shift_id = ?
        ");
        $transStmt->execute([$current_shift['shift_id']]);
        $stats = $transStmt->fetch(PDO::FETCH_ASSOC);
        $total_transactions = $stats['count'];
        $total_cash = $current_shift['starting_cash'] + $stats['total'];
    }
    
} catch (Exception $e) {
    $error = $e->getMessage();
}

// DEBUG: Temporary - remove after testing
if (!$is_admin && isset($_GET['debug'])) {
    echo '<div class="alert alert-info m-3"><strong>Debug Info (remove after fix):</strong><br>';
    echo 'User ID: ' . ($_SESSION['user_id'] ?? 'N/A') . '<br>';
    echo 'Is Admin: ' . ($is_admin ? 'YES' : 'NO') . '<br>';
    echo 'Can View All: ' . ($can_view_all ? 'YES' : 'NO') . '<br>';
    echo 'Shifts Found: ' . count($shifts) . '<br>';
    echo 'canEdit(cash_register): ' . (canEdit('cash_register') ? 'YES' : 'NO') . '<br>';
    echo 'Permissions: <pre>' . print_r($_SESSION['permissions']['cash_register'] ?? 'NOT SET', true) . '</pre>';
    echo '</div>';
    exit;
}

?>

<!-- DataTables Export Dependencies -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>

<div class="container-fluid py-4 px-4">
    <!-- Print Header -->
    <div class="d-none d-print-block text-center mb-4" id="printHeader">
        
        <h2 style="color: #495057; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px;">Cash Register - Shift Records</h2>
        <p style="color: #6c757d; margin: 0; font-size: 10pt;">Generated on: <?= date('F j, Y, g:i a') ?></p>
        <div style="border-bottom: 3px solid #0d6efd; margin-top: 10px; margin-bottom: 20px;"></div>
    </div>

    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 d-print-none">
        <div>
            <h2 class="fw-bold mb-1 text-primary"><i class="bi bi-cash-register me-2"></i>Cash Register</h2>
            <p class="text-muted mb-0">Monitor shift activity and manage cash flow</p>
        </div>
        <div class="d-flex gap-2">
            <?php if ($current_shift): ?>
                <button class="btn btn-danger px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#closeShiftModal">
                    <i class="bi bi-x-circle me-1"></i> Close Shift
                </button>
            <?php else: ?>
                <button class="btn btn-primary px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#openShiftModal">
                    <i class="bi bi-play-circle me-1"></i> Open Shift
                </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger border-0 shadow-sm rounded-3 d-print-none">
        <i class="bi bi-exclamation-triangle me-2"></i> <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <!-- Active Shift Summary -->
    <?php if ($current_shift): ?>
    <div class="row mb-4 g-3 d-print-none">
        <div class="col-md-3">
            <div class="card custom-stat-card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-uppercase small fw-bold mb-1 opacity-75">Shift Status</div>
                    <div class="h4 mb-0 fw-bold">OPEN</div>
                    <div class="mt-2 small opacity-75">Currently Active</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card custom-stat-card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-uppercase small fw-bold mb-1 opacity-75">Opening Balance</div>
                    <div class="h4 mb-0 fw-bold"><?= format_currency($current_shift['starting_cash']) ?></div>
                    <div class="mt-2 small opacity-75">Cash at Shift Start</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card custom-stat-card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-uppercase small fw-bold mb-1 opacity-75">Cash in Hand</div>
                    <div class="h4 mb-0 fw-bold"><?= format_currency($total_cash) ?></div>
                    <div class="mt-2 small opacity-75">Current Register Total</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card custom-stat-card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-uppercase small fw-bold mb-1 opacity-75">Transactions</div>
                    <div class="h4 mb-0 fw-bold"><?= number_format($total_transactions) ?></div>
                    <div class="mt-2 small opacity-75">Processed this shift</div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- History & Records -->
    <div class="card border-0 shadow-sm rounded-3">
        <div class="card-header bg-white border-0 py-3 ps-4">
            <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-clock-history me-2 text-primary"></i> Shift Records</h5>
        </div>
        <div class="card-body pt-0 px-4">
            <!-- Filter Bar -->
            <form action="" method="GET" class="row g-3 align-items-end mb-4 bg-light p-3 rounded-3 border d-print-none">
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">From</label>
                    <input type="date" name="from_date" class="form-control form-control-sm" value="<?= htmlspecialchars($from_date) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">To</label>
                    <input type="date" name="to_date" class="form-control form-control-sm" value="<?= htmlspecialchars($to_date) ?>">
                </div>
                <?php if ($can_view_all): ?>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">Cashier</label>
                    <select name="user_id" class="form-select form-select-sm">
                        <option value="">All Staff</option>
                        <?php foreach ($filter_users as $u): ?>
                        <option value="<?= $u['user_id'] ?>" <?= $filter_user_id == $u['user_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($u['username']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All Status</option>
                        <option value="active" <?= $filter_status == 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="closed" <?= $filter_status == 'closed' ? 'selected' : '' ?>>Closed</option>
                    </select>
                </div>
                <div class="col-md-<?php echo ($can_view_all ? '4' : '6'); ?>">
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm px-4 w-100 fw-bold shadow-sm">
                            <i class="bi bi-filter me-1"></i> Apply
                        </button>
                        <a href="<?= strtok($_SERVER["REQUEST_URI"], '?') ?>" class="btn btn-outline-secondary btn-sm px-4 w-100 text-dark">
                            <i class="bi bi-arrow-counterclockwise me-1"></i> Clear
                        </a>
                    </div>
                </div>
            </form>

            <!-- Actions Bar -->
            <div class="d-flex justify-content-between align-items-center mb-4 d-print-none">
                <div class="d-flex align-items-center gap-3">
                    <div class="btn-group shadow-sm" style="border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden;">
                        <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="copyShiftsTable()" style="background: #fff; color: #444;">
                            <i class="bi bi-clipboard text-info me-1"></i> Copy
                        </button>
                        <div style="width: 1px; background: #eee; height: 24px; margin-top: 6px;"></div>
                        <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="exportShiftsExcel()" style="background: #fff; color: #444;">
                            <i class="bi bi-file-earmark-excel text-success me-1"></i> Excel
                        </button>
                        <div style="width: 1px; background: #eee; height: 24px; margin-top: 6px;"></div>
                        <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="printShiftsTable()" style="background: #fff; color: #444;">
                            <i class="bi bi-printer text-primary me-1"></i> Print
                        </button>
                    </div>
                    
                    <div class="d-flex align-items-center bg-white shadow-sm px-3 py-1" style="border: 1px solid #dee2e6; border-radius: 8px;">
                        <span class="small text-muted me-2"><i class="bi bi-list-ol"></i> Show:</span>
                        <select class="form-select form-select-sm border-0 fw-bold p-0" id="pageLength" style="width: 60px; box-shadow: none; background: transparent;" onchange="$('#shiftsTable').DataTable().page.len(this.value).draw();">
                            <option value="10">10</option>
                            <option value="25" selected>25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                            <option value="-1">All</option>
                        </select>
                    </div>

                    <div class="input-group input-group-sm shadow-sm" style="width: 250px; border-radius: 8px; overflow: hidden; border: 1px solid #dee2e6;">
                        <span class="input-group-text bg-white border-0"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" class="form-control border-0 p-2" id="customShiftSearch" placeholder="Search shifts..." onkeyup="$('#shiftsTable').DataTable().search(this.value).draw();">
                    </div>
                </div>
                <div>
                    <span class="badge bg-success-soft text-success border border-success px-3 py-2 fs-6 rounded-pill">
                        <i class="bi bi-check-circle-fill me-1"></i> <?= count($shifts) ?> Shifts
                    </span>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="shiftsTable">
                    <thead>
                        <tr class="text-uppercase small fw-bold text-muted border-bottom">
                            <th style="width:50px;" class="ps-3 py-3">S/NO</th>
                            <th class="ps-3 py-3">ID</th>
                            <th>Staff</th>
                            <th>Started</th>
                            <th>Ended</th>
                            <th class="text-end">Opening Amount</th>
                            <th class="text-end">Closing Amount</th>
                            <th class="text-center">Status</th>
                            <th class="text-end pe-3 d-print-none">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $sn = 1; foreach ($shifts as $shift): ?>
                        <tr>
                            <td class="ps-3 text-center text-muted small fw-bold"><?= $sn++ ?></td>
                            <td class="ps-3"><code>#<?= $shift['shift_id'] ?></code></td>
                            <td class="fw-bold text-dark"><?= htmlspecialchars($shift['username'] ?? 'Unknown') ?></td>
                            <td class="small text-muted"><?= date('M d, y | h:i A', strtotime($shift['start_time'])) ?></td>
                            <td class="small text-muted"><?= $shift['end_time'] ? date('M d, y | h:i A', strtotime($shift['end_time'])) : '-' ?></td>
                            <td class="text-end"><?= format_currency($shift['starting_cash']) ?></td>
                            <td class="text-end fw-bold"><?= $shift['ending_cash'] ? format_currency($shift['ending_cash']) : '-' ?></td>
                            <td class="text-center">
                                <span class="badge rounded-pill bg-<?= $shift['status'] == 'active' ? 'primary' : 'secondary' ?> bg-opacity-10 text-<?= $shift['status'] == 'active' ? 'primary' : 'secondary' ?> py-2 px-3">
                                    <?= ucfirst($shift['status']) ?>
                                </span>
                            </td>
                            <td class="text-end pe-3 d-print-none">
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                        <i class="bi bi-gear"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                                        <li>
                                            <a class="dropdown-item py-2" href="<?= getUrl('cash_register_details') ?>?shift=<?= $shift['shift_id'] ?>">
                                                <i class="bi bi-eye me-2 text-primary"></i> View Details
                                            </a>
                                        </li>
                                        <?php if ($can_view_all): ?>
                                        <li>
                                            <a class="dropdown-item py-2 edit-shift-btn" href="#" 
                                               data-id="<?= $shift['shift_id'] ?>"
                                               data-user="<?= htmlspecialchars($shift['username'] ?? 'Unknown') ?>"
                                               data-opening="<?= $shift['starting_cash'] ?>"
                                               data-closing="<?= $shift['ending_cash'] ?>"
                                               data-status="<?= $shift['status'] ?>">
                                                <i class="bi bi-pencil me-2 text-warning"></i> Edit Shift
                                            </a>
                                        </li>
                                        <?php endif; ?>
                                        <li>
                                            <a class="dropdown-item py-2" href="<?= getUrl('cash_register_details') ?>?shift=<?= $shift['shift_id'] ?>&print=true" target="_blank">
                                                <i class="bi bi-printer me-2 text-secondary"></i> Print Receipt
                                            </a>
                                        </li>
                                        <?php if ($can_view_all): ?>
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <a class="dropdown-item py-2 text-danger" href="#" onclick="deleteShift(<?= $shift['shift_id'] ?>)">
                                                <i class="bi bi-trash me-2"></i> Delete Shift
                                            </a>
                                        </li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <!-- Spacer to prevent data hidden behind browser footer in print -->
                        <tr class="d-none d-print-table-row" style="height: 60px; border: none !important;">
                            <td colspan="9" style="border: none !important;"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Open Shift -->
<div class="modal fade" id="openShiftModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 rounded-3 shadow-lg">
            <div class="modal-header border-0 pb-0 pe-4 pt-4">
                <h5 class="fw-bold mb-0">Shift Entry</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="openShiftForm" method="POST">
                <div class="modal-body p-4 text-center">
                    <div class="bg-primary bg-opacity-10 text-primary p-3 rounded-circle d-inline-block mb-3">
                        <i class="bi bi-play-circle-fill fs-1"></i>
                    </div>
                    <p class="text-muted mb-4 px-3">Enter the starting balance to open your cash register session.</p>
                    
                    <div class="text-start mb-4">
                        <label class="form-label fw-bold small text-muted text-uppercase">Opening Cash (TSh)</label>
                        <div class="input-group input-group-lg">
                            <span class="input-group-text bg-light border-end-0 text-muted">TSh</span>
                            <input type="number" class="form-control border-start-0 ps-0 fw-bold" name="opening_balance" step="1" required value="0">
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-lg w-100 rounded-3 fw-bold py-3">
                        Start Shift
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Close Shift -->
<div class="modal fade" id="closeShiftModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 rounded-3 shadow-lg">
            <div class="modal-header border-0 pb-0 pe-4 pt-4">
                <h5 class="fw-bold mb-0">Shift Settlement</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="closeShiftForm" method="POST">
                <div class="modal-body p-4 text-center">
                    <div class="bg-danger bg-opacity-10 text-danger p-3 rounded-circle d-inline-block mb-3">
                        <i class="bi bi-stop-circle-fill fs-1"></i>
                    </div>
                    <p class="text-muted mb-4">Finalize your shift activity and reconcile cash balances.</p>
                    
                    <div class="p-3 bg-light rounded-3 mb-4 border border-dashed text-center">
                        <span class="text-muted d-block small text-uppercase fw-bold mb-1">Expected System Balance</span>
                        <h4 class="fw-bold text-dark mb-0"><?= format_currency($total_cash) ?></h4>
                    </div>

                    <div class="text-start mb-4">
                        <label class="form-label fw-bold small text-muted text-uppercase">Actual Cash Counted (TSh)</label>
                        <div class="input-group input-group-lg">
                            <span class="input-group-text bg-light border-end-0 text-muted">TSh</span>
                            <input type="number" class="form-control border-start-0 ps-0 fw-bold" name="closing_balance" step="1" required placeholder="0">
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-danger btn-lg w-100 rounded-3 fw-bold py-3">
                        Reconcile & Close
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Edit Shift -->
<div class="modal fade" id="editShiftModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 rounded-3 shadow-lg">
            <div class="modal-header border-0 pb-0 pe-4 pt-4">
                <h5 class="fw-bold mb-0">Edit Shift Record</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="editShiftForm" method="POST">
                <input type="hidden" name="shift_id" id="edit_shift_id">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted text-uppercase">Staff Member</label>
                        <input type="text" class="form-control bg-light" id="edit_user_name" readonly>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted text-uppercase">Opening Balance</label>
                            <input type="number" class="form-control" name="starting_cash" id="edit_starting_cash" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted text-uppercase">Closing Balance</label>
                            <input type="number" class="form-control" name="ending_cash" id="edit_ending_cash">
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label small fw-bold text-muted text-uppercase">Shift Status</label>
                        <select name="status" id="edit_status" class="form-select" required>
                            <option value="active">Active (Open)</option>
                            <option value="closed">Closed</option>
                        </select>
                    </div>
                    
                    <button type="submit" class="btn btn-warning btn-lg w-100 rounded-3 fw-bold py-3 shadow-sm">
                        Update Shift Information
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    logReportAction('Viewed Cash Register', 'User viewed cash register shifts');

    // Populate Edit Modal
    $('.edit-shift-btn').on('click', function(e) {
        e.preventDefault();
        const data = $(this).data();
        $('#edit_shift_id').val(data.id);
        $('#edit_user_name').val(data.user);
        $('#edit_starting_cash').val(data.opening);
        $('#edit_ending_cash').val(data.closing);
        $('#edit_status').val(data.status);
        $('#editShiftModal').modal('show');
    });

    // Handle Edit Form Submission
    $('#editShiftForm').on('submit', function(e) {
        e.preventDefault();
        const btn = $(this).find('button[type="submit"]');
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Updating...');
        
        fetch('<?= getUrl("api/cash_register/update_shift.php") ?>', { method: 'POST', body: new FormData(this) })
            .then(r => r.json())
            .then(data => {
                if(data.success) {
                    Swal.fire('Success', data.message, 'success').then(() => location.reload());
                } else {
                    Swal.fire('Error', data.message, 'error').then(() => {
                        btn.prop('disabled', false).text('Update Shift Information');
                    });
                }
            })
            .catch(err => {
                Swal.fire('Error', 'Server connection failed', 'error');
                btn.prop('disabled', false).text('Update Shift Information');
            });
    });

    if (!$.fn.DataTable.isDataTable('#shiftsTable')) {
        const table = $('#shiftsTable').DataTable({
            dom: 'rtip',
            order: [[0, 'desc']],
            pageLength: 25,
            responsive: true
        });
    }

    $('#openShiftForm').on('submit', function(e) {
        e.preventDefault();
        const btn = $(this).find('button[type="submit"]');
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Starting...');
        fetch('<?= getUrl("api/cash_register/open_shift.php") ?>', { method: 'POST', body: new FormData(this) })
            .then(r => r.json())
            .then(data => { 
                if(data.success) {
                    location.reload(); 
                } else {
                    Swal.fire('Error', data.message, 'error').then(() => { btn.prop('disabled', false).text('Start Shift'); }); 
                }
            });
    });

    $('#closeShiftForm').on('submit', function(e) {
        e.preventDefault();
        const btn = $(this).find('button[type="submit"]');
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Closing...');
        fetch('<?= getUrl("api/cash_register/close_shift.php") ?>', { method: 'POST', body: new FormData(this) })
            .then(r => r.json())
            .then(data => { 
                if(data.success) {
                    location.reload(); 
                } else {
                    Swal.fire('Error', data.message, 'error').then(() => { btn.prop('disabled', false).text('Reconcile & Close'); }); 
                }
            });
    });
});

// Actions Bar Functions
function copyShiftsTable() {
    const table = document.getElementById('shiftsTable');
    if (!table) return;
    const range = document.createRange();
    range.selectNode(table);
    window.getSelection().removeAllRanges();
    window.getSelection().addRange(range);
    try {
        document.execCommand('copy');
        Swal.fire({ icon: 'success', title: 'Copied!', text: 'Table copied to clipboard', timer: 1500, showConfirmButton: false });
    } catch(err) {
        Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to copy table' });
    }
    window.getSelection().removeAllRanges();
}

function exportShiftsExcel() {
    let csv = [];
    const table = document.getElementById('shiftsTable');
    const rows = table.querySelectorAll('tr');
    
    for (let i = 0; i < rows.length; i++) {
        let row = [], cols = rows[i].querySelectorAll("td, th");
        for (let j = 0; j < cols.length - 1; j++) {
            let data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, "").replace(/"/g, '""');
            row.push('"' + data + '"');
        }
        csv.push(row.join(","));
    }

    const csvFile = new Blob([csv.join("\n")], {type: "text/csv"});
    const downloadLink = document.createElement("a");
    downloadLink.download = "Cash_Register_Shifts.csv";
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = "none";
    document.body.appendChild(downloadLink);
    downloadLink.click();
}

function printShiftsTable() {
    window.print();
}

function deleteShift(id) {
    Swal.fire({
        title: 'Are you sure?',
        text: "You won't be able to revert this! This will also delete all transactions in this shift.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            fetch('<?= getUrl("api/cash_register/delete_shift.php") ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'shift_id=' + id
            })
            .then(r => r.json())
            .then(data => {
                if(data.success) {
                    Swal.fire('Deleted!', data.message, 'success').then(() => location.reload());
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            })
            .catch(err => Swal.fire('Error', 'Server connection failed', 'error'));
        }
    });
}
</script>

<style>
.custom-stat-card {
    background-color: #d1e7dd !important;
    border-color: #badbcc !important;
    border-radius: 12px !important;
    transition: all 0.2s ease-in-out;
}
.custom-stat-card:hover { transform: translateY(-3px); box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
.custom-stat-card div, .custom-stat-card h4 {
    color: #0f5132 !important;
    font-weight: 600;
}

.bg-success-soft {
    background-color: rgba(25, 135, 84, 0.1) !important;
}

.rounded-3 { border-radius: 0.5rem !important; }
.card { border: none; border-radius: 12px; }
.table thead th { border-bottom: 2px solid #f0f0f0; background: #fafafa; }
.form-control, .form-select { border-radius: 0.4rem; border: 1px solid #ced4da; }

@media print {
    .d-print-none, .btn, .card-header, .form-control, .form-select, .input-group, .pagination, footer, nav, .modal, .dropdown-menu, .alert {
        display: none !important;
    }
    .d-print-block { display: block !important; }
    @page {
        margin: 10mm 10mm 15mm 10mm;
    }
    body { margin: 0; padding: 20px; padding-top: 0 !important; }
    .container-fluid { width: 100% !important; max-width: 100% !important; padding: 0 !important; }
    .card, .card-body, .table-responsive { 
        border: none !important; 
        box-shadow: none !important; 
        margin: 0 !important; 
        padding: 0 !important;
        break-inside: auto !important;
        page-break-inside: auto !important;
        display: block !important;
    }
    #shiftsTable { 
        width: 100% !important; 
        border-collapse: collapse !important; 
        border: 1px solid #333;
        break-inside: auto !important;
        page-break-inside: auto !important;
    }
    #shiftsTable tr {
        break-inside: avoid !important;
        page-break-inside: avoid !important;
    }
    th, td { border: 1px solid #333 !important; padding: 8px !important; }

    /* Print header styling */
    #printHeader h1 {
        color: #0d6efd !important;
        text-transform: uppercase;
        font-weight: 800;
        margin: 0;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    #printHeader h2 {
        color: #495057 !important;
        text-transform: uppercase;
        font-weight: 600;
        margin: 5px 0;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}
</style>

<?php
includeFooter();
ob_end_flush();
?>
