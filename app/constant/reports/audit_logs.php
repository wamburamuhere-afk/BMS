<?php
// Centralized Audit Logs Report
require_once __DIR__ . '/../../../roots.php';

// Enforce permission
autoEnforcePermission('audit_logs');

$page_title = 'System Audit Trail';
includeHeader();

// Get filter parameters
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$user_id_filter = $_GET['user_id'] ?? '';
$action_query = $_GET['action'] ?? '';
$log_type_filter = $_GET['log_type'] ?? '';

// Pre-calculate total logs for use in headers/summaries
$count_sql = "SELECT 
    (SELECT COUNT(*) FROM activity_logs WHERE created_at BETWEEN ? AND ?) +
    (SELECT COUNT(*) FROM audit_logs WHERE created_at BETWEEN ? AND ?) as total";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute([$start_date.' 00:00:00', $end_date.' 23:59:59', $start_date.' 00:00:00', $end_date.' 23:59:59']);
$total_logs = $count_stmt->fetchColumn() ?: 0;

// Fetch users for filter
$stmt_users = $pdo->query("SELECT user_id, username FROM users ORDER BY username ASC");
$users = $stmt_users->fetchAll(PDO::FETCH_ASSOC);

?>

<div class="container-fluid py-4">
    <!-- Professional Print Header -->
    <div class="print-header d-none d-print-block text-center mb-2">
        <div class="mt-2 text-center">
            <h2 style="color: #495057; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px;">SYSTEM AUDIT TRAIL REPORT</h2>
            <p style="color: #6c757d; margin: 0; font-size: 10pt;">Complete history of system activities, data changes, and security events for compliance purposes.</p>
            <p style="color: #444; margin: 5px 0 0; font-size: 9pt; font-weight: 600; text-transform: uppercase;">Period: <?= date('d M Y', strtotime($start_date)) ?> - <?= date('d M Y', strtotime($end_date)) ?></p>
            <p style="color: #444; margin: 5px 0 0; font-size: 9pt; font-weight: 600; text-transform: uppercase;">Generated At: <?= date('d M Y, h:i A') ?></p>
        </div>
        <div style="border-bottom: 3px solid #0d6efd; margin-top: 15px; margin-bottom: 25px;"></div>
    </div>

    <!-- Print Summary Cards -->
    <div class="d-none d-print-block mb-4">
        <div style="display: flex !important; flex-direction: row !important; gap: 10px !important; align-items: stretch !important;">
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 10px; text-align: center;">
                <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Total Log Entries</p>
                <h4 style="color: #333; font-weight: 800; margin: 0; font-size: 14pt;"><?= number_format($total_logs) ?></h4>
            </div>
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 10px; text-align: center;">
                <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Reporting Period</p>
                <h4 style="color: #0d6efd; font-weight: 800; margin: 0; font-size: 12pt;"><?= date('M d', strtotime($start_date)) ?> - <?= date('M d, Y', strtotime($end_date)) ?></h4>
            </div>
        </div>
    </div>

    <!-- Header -->
    <div class="row mb-4 align-items-center d-print-none">
        <div class="col-md-6">
            <h2 class="h3 mb-1 fw-bold text-dark"><i class="bi bi-shield-lock-fill me-2 text-primary"></i>System Audit Trail</h2>
            <p class="text-muted mb-0">Complete history of system activities and data changes</p>
        </div>
        <div class="col-md-6 text-end d-print-none">
            <button onclick="window.print()" class="btn btn-outline-primary shadow-sm px-4 fw-bold">
                <i class="bi bi-printer me-2"></i>Print Trail
            </button>
            <button onclick="exportToExcel()" class="btn btn-success shadow-sm px-4 fw-bold ms-2">
                <i class="bi bi-file-earmark-excel me-2"></i>Export Excel
            </button>
        </div>
    </div>

    <!-- Stats Summary -->
    <div class="row g-3 mb-4 d-print-none">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm" style="border-radius: 12px; border-left: 4px solid #0d6efd; background-color: #d1e7dd;">
                <div class="card-body p-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Date Range</p>
                    <h5 class="fw-bold mb-0"><?= date('M d', strtotime($start_date)) ?> - <?= date('M d, Y', strtotime($end_date)) ?></h5>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm" style="border-radius: 12px; border-left: 4px solid #198754; background-color: #d1e7dd;">
                <div class="card-body p-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Total Entries</p>
                    <h5 class="fw-bold mb-0 text-success"><?= number_format($total_logs) ?> Actions</h5>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background-color: #d1e7dd;">
                <div class="card-body p-0 d-flex align-items-center">
                    <div class="px-4 py-2 border-end text-center flex-grow-1">
                        <span class="d-block text-muted small fw-bold">ACTIVITY LOGS</span>
                        <span class="h5 mb-0 fw-bold">General</span>
                    </div>
                    <div class="px-4 py-2 text-center flex-grow-1">
                        <span class="d-block text-muted small fw-bold">AUDIT LOGS</span>
                        <span class="h5 mb-0 fw-bold text-primary">Data Changes</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card shadow-sm border-0 mb-4 d-print-none" style="border-radius: 12px;">
        <div class="card-body p-4">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted">START DATE</label>
                    <input type="date" name="start_date" class="form-control" value="<?= $start_date ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted">END DATE</label>
                    <input type="date" name="end_date" class="form-control" value="<?= $end_date ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted">USER</label>
                    <select name="user_id" class="form-select">
                        <option value="">All Staff</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= $u['user_id'] ?>" <?= $user_id_filter == $u['user_id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)($u['username'] ?? '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted">LOG TYPE</label>
                    <select name="log_type" class="form-select">
                        <option value="">Both Types</option>
                        <option value="activity" <?= $log_type_filter == 'activity' ? 'selected' : '' ?>>Activity Logs</option>
                        <option value="audit" <?= $log_type_filter == 'audit' ? 'selected' : '' ?>>Audit Logs</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted">SEARCH ACTION</label>
                    <input type="text" name="action" class="form-control" placeholder="Login, Delete..." value="<?= htmlspecialchars((string)($action_query ?? '')) ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100 py-2 fw-bold"><i class="bi bi-filter me-2"></i>Filter</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Data Table -->
    <div class="card shadow-lg border-0" style="border-radius: 15px; overflow: hidden;">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="auditTable" class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4 py-3 text-muted small text-uppercase" width="60">S/NO</th>
                            <th class="py-3 text-muted small text-uppercase">Time & Date</th>
                            <th class="py-3 text-muted small text-uppercase">Member</th>
                            <th class="py-3 text-muted small text-uppercase">Action & Source</th>
                            <th class="py-3 text-muted small text-uppercase">Event Details</th>
                            <th class="text-end pe-4 py-3 text-muted small text-uppercase" width="120">Terminal IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $params = [
                            ':start1' => $start_date . ' 00:00:00',
                            ':end1'   => $end_date . ' 23:59:59',
                            ':start2' => $start_date . ' 00:00:00',
                            ':end2'   => $end_date . ' 23:59:59'
                        ];
                        
                        $where1 = "";
                        $where2 = "";
                        
                        if($user_id_filter) {
                            $where1 .= " AND user_id = :user_id1";
                            $where2 .= " AND user_id = :user_id2";
                            $params[':user_id1'] = $user_id_filter;
                            $params[':user_id2'] = $user_id_filter;
                        }
                        
                        if($action_query) {
                            $where1 .= " AND (action LIKE :action1 OR description LIKE :desc1)";
                            $where2 .= " AND (action LIKE :action2 OR description LIKE :desc2 OR activity_type LIKE :type2)";
                            $params[':action1'] = "%$action_query%";
                            $params[':action2'] = "%$action_query%";
                            $params[':desc1'] = "%$action_query%";
                            $params[':desc2'] = "%$action_query%";
                            $params[':type2'] = "%$action_query%";
                        }

                        $sql = "";
                        if(!$log_type_filter || $log_type_filter == 'activity') {
                            $sql .= "(SELECT created_at, user_id, action, '' as activity_type, description, ip_address, 'activity' as log_type FROM activity_logs WHERE created_at BETWEEN :start1 AND :end1 $where1)";
                        }
                        
                        if(!$log_type_filter) {
                            $sql .= " UNION ALL ";
                        }

                        if(!$log_type_filter || $log_type_filter == 'audit') {
                            $sql .= "(SELECT created_at, user_id, action, activity_type, description, ip_address, 'audit' as log_type FROM audit_logs WHERE created_at BETWEEN :start2 AND :end2 $where2)";
                        }
                        
                        $sql .= " ORDER BY created_at DESC LIMIT 1000";
                        
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute($params);
                        
                        $sn = 1;
                        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)):
                            $log_user = 'System/Unknown';
                            foreach($users as $u) {
                                if($u['user_id'] == $row['user_id']) {
                                    $log_user = $u['username'];
                                    break;
                                }
                            }
                        ?>
                            <tr>
                                <td class="ps-4 text-center fw-bold text-muted"><?= $sn++ ?></td>
                                <td>
                                    <div class="fw-bold text-dark"><?= date('M d, Y', strtotime($row['created_at'])) ?></div>
                                    <div class="small text-muted font-monospace"><?= date('H:i:s', strtotime($row['created_at'])) ?></div>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="avatar-sm me-2 bg-primary bg-opacity-10 rounded-circle text-center d-flex align-items-center justify-content-center" style="width: 35px; height:35px;">
                                            <i class="bi bi-person-fill text-primary"></i>
                                        </div>
                                        <span class="fw-bold text-dark"><?= htmlspecialchars((string)($log_user ?? '')) ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="badge bg-<?= $row['log_type'] == 'audit' ? 'info' : 'secondary' ?> bg-opacity-10 text-<?= $row['log_type'] == 'audit' ? 'info' : 'secondary' ?> border border-<?= $row['log_type'] == 'audit' ? 'info' : 'secondary' ?> shadow-sm px-3 py-2 text-uppercase mb-1" style="font-size: 0.7rem; letter-spacing: 0.5px;">
                                        <?= htmlspecialchars((string)($row['activity_type'] ?: $row['action'])) ?>
                                    </div>
                                    <div class="small text-muted italic"><?= $row['log_type'] == 'audit' ? 'Data Change' : 'User Activity' ?></div>
                                </td>
                                <td style="max-width: 450px;">
                                    <p class="mb-0 text-muted small lh-sm" title="<?= htmlspecialchars((string)($row['description'] ?? '')) ?>">
                                        <?= htmlspecialchars((string)($row['description'] ?? '')) ?>
                                    </p>
                                </td>
                                <td class="text-end pe-4">
                                    <code class="px-2 py-1 bg-light border rounded text-muted small"><?= htmlspecialchars((string)($row['ip_address'] ?? '')) ?></code>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('#auditTable').DataTable({
        "order": [[ 1, "desc" ]],
        "pageLength": 25,
        "dom": '<"d-flex justify-content-between align-items-center p-3"lf>rt<"p-3"ip>',
        "language": {
            "search": "_INPUT_",
            "searchPlaceholder": "Quick Search Detail..."
        }
    });
});

function exportToExcel() {
    const urlParams = new URLSearchParams(window.location.search);
    urlParams.set('export', 'excel');
    window.location.href = '?' + urlParams.toString();
}

function logReportAction(action, desc) {
    $.post('<?= buildUrl('api/log_audit.php') ?>', {
        action: action,
        description: desc,
        activity_type: 'report'
    });
}
</script>

<style>
    .bg-info-subtle { background-color: #cfe2ff !important; }
    .text-info { color: #084298 !important; }
    .border-info { border-color: #084298 !important; }
    
    .bg-secondary-subtle { background-color: #e2e3e5 !important; }
    .text-secondary { color: #41464b !important; }
    .border-secondary { border-color: #41464b !important; }

    .italic { font-style: italic; }
    .avatar-sm { font-size: 1.1rem; }
    
    #auditTable_wrapper .dataTables_filter input {
        border-radius: 20px;
        padding: 5px 15px;
        border: 1px solid #ddd;
    }

    @media print {
        .navbar, .sidebar, .btn, form, .dataTables_length, .dataTables_filter, .dataTables_info, .dataTables_paginate, .d-print-none { display: none !important; }
        .card { border: none !important; box-shadow: none !important; border-radius: 0 !important; }
        .table { width: 100% !important; border-collapse: collapse !important; border: 1px solid #000 !important; }
        .table th { background-color: #f8f9fa !important; border: 1px solid #000 !important; -webkit-print-color-adjust: exact; color: #000 !important; padding: 8px !important; }
        .table td { border: 1px solid #dee2e6 !important; padding: 8px !important; }
        .container-fluid { padding: 0 !important; }
        .badge { color: #000 !important; border: 1px solid #ddd !important; background: transparent !important; }
    }
    /* Canonical I/E Print margin — see i_e_print.md §1 */
    @page { margin: 10mm 8mm 16mm 8mm; }
</style>

<?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
<div class="d-none d-print-block">
    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>
</div>

<?php includeFooter(); ?>
