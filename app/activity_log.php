<?php
// File: app/activity_log.php
require_once __DIR__ . '/../roots.php';

// Phase 2 of security_implementation_plan.md — anyone logged in could open
// this page before; now only admins or roles explicitly granted 'audit_logs'
// can see the system-wide activity log.
autoEnforcePermission('audit_logs');
$is_admin = isAdmin();

// Pagination setup
$limit = isset($_GET['limit']) ? ($_GET['limit'] === 'all' ? -1 : (int)$_GET['limit']) : 10;
if (!in_array($limit, [10, 25, 50, 100, -1])) $limit = 10; // Validate limit
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($limit === -1) ? 0 : ($page - 1) * $limit;

try {
// Get filter parameters
$type_filter = $_GET['type'] ?? '';
$user_id_filter = $_GET['user_id'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Get users for filter dropdown
$users = $pdo->query("SELECT user_id, username FROM users ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

// Get company info for print header
$company_name = get_setting('company_name', 'BMS Business Management');
$company_logo = get_setting('company_logo');
$company_address = get_setting('company_address', '');
$company_phone = get_setting('company_phone', '');
$company_email = get_setting('company_email', '');
$company_tin = get_setting('company_tin', '');
$company_vrn = get_setting('company_vrn', '');

    // Build filter conditions
    $conditions = [];
    $params = [];

    // Get today's stats for cards
    $stats_stmt = $pdo->query("
        SELECT 
            COUNT(CASE WHEN (action LIKE 'Created%' OR action LIKE 'Added%' OR description LIKE 'Created%' OR description LIKE 'Added%') THEN 1 END) as created,
            COUNT(CASE WHEN (action LIKE 'Viewed%' OR description LIKE 'Viewed%' OR action LIKE 'View %' OR description LIKE 'View %') THEN 1 END) as viewed,
            COUNT(CASE WHEN (action LIKE 'Updated%' OR description LIKE 'Updated%' OR action LIKE 'Edited%' OR description LIKE 'Edited%') THEN 1 END) as updated,
            COUNT(CASE WHEN (action LIKE 'Deleted%' OR description LIKE 'Deleted%' OR action LIKE 'Removed%' OR description LIKE 'Removed%') THEN 1 END) as deleted
        FROM activity_logs 
        WHERE DATE(created_at) = CURDATE()
    ");
    $today_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

    if ($type_filter) {
        $conditions[] = "action LIKE :type";
        $params[':type'] = "%$type_filter%";
    }
    if ($user_id_filter) {
        $conditions[] = "activity_logs.user_id = :user_id";
        $params[':user_id'] = $user_id_filter;
    }
    if ($date_from) {
        $conditions[] = "activity_logs.created_at >= :date_from";
        $params[':date_from'] = $date_from . ' 00:00:00';
    }
    if ($date_to) {
        $conditions[] = "activity_logs.created_at <= :date_to";
        $params[':date_to'] = $date_to . ' 23:59:59';
    }

    $where_clause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

    // Use activity_logs table for comprehensive tracking
    $sql_base = "FROM activity_logs 
                 LEFT JOIN users u ON activity_logs.user_id = u.user_id";

    // Get total count with filters
    $count_stmt = $pdo->prepare("SELECT COUNT(*) $sql_base $where_clause");
    foreach ($params as $key => $val) { $count_stmt->bindValue($key, $val); }
    $count_stmt->execute();
    $total_items = $count_stmt->fetchColumn();
    $total_pages = ($limit === -1) ? 1 : ceil($total_items / $limit);

    // Get activities with filters
    $limit_sql = ($limit === -1) ? "" : " LIMIT :limit OFFSET :offset";
    $sql = "SELECT 
                activity_logs.id,
                activity_logs.action as raw_action,
                activity_logs.description as raw_description,
                activity_logs.created_at as timestamp,
                activity_logs.ip_address as reference,
                u.username as user_name,
                u.user_id as user_id,
                0 as amount
            $sql_base 
            $where_clause 
            ORDER BY activity_logs.created_at DESC" . $limit_sql;
    
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $val) { $stmt->bindValue($key, $val); }
    if ($limit !== -1) {
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
    }
    $stmt->execute();
    $raw_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Process activities to separate type and description properly
    $activities = [];
    foreach ($raw_activities as $activity) {
        $type = '';
        $description = '';
        
        // Get raw data - prefer description field, fallback to action if description is empty or null
        $raw_desc = !empty($activity['raw_description']) ? trim($activity['raw_description']) : trim($activity['raw_action'] ?? '');
        
        // Parse to extract action and entity, put details in description
        // Enhanced pattern to catch "Added new customer", "Created new expense", "Printed Customers list", etc.
        if (preg_match('/^(Created|Added|Updated|Deleted|Suspended|Activated|Deactivated|Approved|Rejected|Sent|Received|Paid|Cancelled|Imported|Voided|Restored|Marked|Applied|Active|Inactive|Blacklisted|Printed|Copied|Exported|Downloaded)\s+(?:new\s+|a\s+)?(customer|supplier|product|expense|invoice|payment|employee|loan|sale|order|stock|document|user|report|category|tax|asset|budget|project|task|leave|shift|attendance|payroll|transaction|items|list|template)(.*)$/i', $raw_desc, $matches)) {
            // Pattern: "Action [new/a] Entity: Details"
            $action = ucfirst(strtolower($matches[1]));
            $entity = ucfirst(strtolower($matches[2]));
            $details = trim($matches[3], ': ');
            
            $type = "$action $entity";
            $description = $details ?: '-';
        }
        elseif (preg_match('/^(customer|supplier|product|expense|invoice|payment|employee|loan|sale|order|stock|document|user|report|category|tax|asset|budget|project|task|leave|shift|attendance|payroll|transaction|items|list|template)\s+(created|added|updated|deleted|suspended|activated|deactivated|approved|rejected|sent|received|paid|cancelled|imported|voided|restored|marked|applied|active|inactive|blacklisted|printed|copied|exported|downloaded)(.*)$/i', $raw_desc, $matches)) {
            // Pattern: "Entity Action: Details"
            $entity = ucfirst(strtolower($matches[1]));
            $action = ucfirst(strtolower($matches[2]));
            $details = trim($matches[3], ': ');
            
            $type = "$action $entity";
            $description = $details ?: '-';
        }
        elseif (preg_match('/^(.+?):\s*(.+)$/i', $raw_desc, $matches)) {
            // Pattern: "Type: Description" - keep as is
            $type = ucfirst(trim($matches[1]));
            $description = trim($matches[2]);
        }
        else {
            // Default: Use raw_action as type, full desc as description
            $type = ucfirst($activity['raw_action'] ?? 'Activity');
            $description = $raw_desc ?: '-';
        }

        // --- STRICT REFERENCE LOGIC ---
        $reference = '-';
        // 1. Look for patterns that MUST contain at least one digit (e.g., INV-001, CUST-23)
        // This prevents capturing words like 'CREATED' or 'UPDATED'
        if (preg_match('/([A-Z]{2,}-?[A-Z0-9-]*\d[A-Z0-9-]*)/i', $raw_desc, $refMatches)) {
            $candidate = strtoupper($refMatches[1]);
            // Double check it's not just a word
            if (preg_match('/\d/', $candidate)) {
                $reference = $candidate;
            }
        } 
        
        // 2. If no code found, look for hashtag IDs or purely numeric IDs associated with the action
        if ($reference == '-' && preg_match('/(?:#|ID:?\s*)(\d+)/i', $raw_desc, $refMatches)) {
            $reference = '#' . $refMatches[1];
        }
        
        // 3. Fallback: Use a unique activity reference based on the log ID to ensure formality
        if ($reference == '-') {
            $reference = 'REF-' . str_pad($activity['id'], 5, '0', STR_PAD_LEFT);
        }
        
        $activities[] = [
            'id' => $activity['id'],
            'type' => $type,
            'description' => $description,
            'timestamp' => $activity['timestamp'],
            'reference' => $reference,
            'user_name' => $activity['user_name'],
            'user_id' => $activity['user_id'],
            'amount' => $activity['amount']
        ];
    }

    // Get unique activity types for filter
    $types_stmt = $pdo->query("SELECT DISTINCT action FROM activity_logs ORDER BY action");
    $activity_types = $types_stmt->fetchAll(PDO::FETCH_COLUMN);



} catch (PDOException $e) {
    if (isset($_GET['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
    $error = "Failed to load activities: " . $e->getMessage();
}

// Smart Pagination Helper
function renderSmartPagination($current, $total, $mode = 'html') {
    $html = '';
    $window = 2; // Pages around current to show

    // Prev button
    $prevDisabled = $current <= 1 ? ' disabled' : '';
    $prevClick    = $current <= 1 ? '' : "onclick=\"loadPage(" . ($current - 1) . ")\"";
    $html .= "<li class=\"page-item{$prevDisabled}\"><a class=\"page-link\" href=\"javascript:void(0)\" {$prevClick}><i class=\"bi bi-chevron-left\"></i></a></li>";

    $pagesToShow = [1];
    $rangeStart = max(2, $current - $window);
    $rangeEnd   = min($total - 1, $current + $window);
    for ($i = $rangeStart; $i <= $rangeEnd; $i++) {
        $pagesToShow[] = $i;
    }
    if ($total > 1) $pagesToShow[] = $total;
    $pagesToShow = array_unique($pagesToShow);
    sort($pagesToShow);

    $prev = null;
    foreach ($pagesToShow as $p) {
        if ($prev !== null && $p - $prev > 1) {
            $html .= '<li class="page-item disabled"><span class="page-link px-2">…</span></li>';
        }
        $activeClass = ($p === $current) ? ' active' : '';
        $html .= "<li class=\"page-item{$activeClass}\"><a class=\"page-link\" href=\"javascript:void(0)\" onclick=\"loadPage({$p})\">{$p}</a></li>";
        $prev = $p;
    }

    // Next button
    $nextDisabled = $current >= $total ? ' disabled' : '';
    $nextClick    = $current >= $total ? '' : "onclick=\"loadPage(" . ($current + 1) . ")\"";
    $html .= "<li class=\"page-item{$nextDisabled}\"><a class=\"page-link\" href=\"javascript:void(0)\" {$nextClick}><i class=\"bi bi-chevron-right\"></i></a></li>";

    return $html;
}

// Handle AJAX Request - MUST be before header.php
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    ob_start();
    ?>
    <?php $si = $offset + 1; foreach ($activities as $activity): ?>
    <tr>
        <td class="text-center" data-label="S/NO"><?= $si++ ?></td>
        <td class="ps-3 text-muted" data-label="Time">
            <small><?= date('d/m/y, H:i', strtotime($activity['timestamp'])) ?></small>
        </td>
        <td class="col-type" data-label="Type">
            <?php 
            $badge_class = 'primary';
            $t = strtolower($activity['type']);
            if (strpos($t, 'payment') !== false) { $badge_class = 'success'; }
            elseif (strpos($t, 'sale') !== false || strpos($t, 'pos') !== false) { $badge_class = 'info'; }
            elseif (strpos($t, 'delete') !== false) { $badge_class = 'danger'; }
            elseif (strpos($t, 'customer') !== false) { $badge_class = 'warning'; }
            ?>
            <span class="badge bg-<?= $badge_class ?> rounded-pill">
                <?= ucfirst(str_replace('_', ' ', $activity['type'] ?? 'Unknown')) ?>
            </span>
        </td>
        <td data-label="Description"><?= htmlspecialchars($activity['description'] ?? '-') ?></td>
        <td data-label="Reference">
            <?php 
            $ref = (string)$activity['reference'];
            if (!empty($ref) && strpos($ref, '.') === false && strpos($ref, ':') === false) {
                echo '<code class="text-dark bg-light px-2 py-1 rounded">' . htmlspecialchars($ref) . '</code>';
            } else {
                echo '<span class="text-muted">-</span>';
            }
            ?>
        </td>
        <td class="pe-3" data-label="User"><?= htmlspecialchars($activity['user_name'] ?? 'System') ?></td>
    </tr>
    <?php endforeach; ?>
    <?php
    $rows = ob_get_clean();
    
    ob_start();
    ?>
    <?php if ($total_pages > 1): ?>
    <ul class="pagination justify-content-center mb-0">
        <?php echo renderSmartPagination($page, $total_pages, 'ajax'); ?>
    </ul>
    <?php endif; ?>
    <?php
    $pagination = ob_get_clean();
    
    echo json_encode([
        'success' => true,
        'rows' => $rows,
        'pagination' => $pagination,
        'info' => "Showing " . ($total_items > 0 ? $offset + 1 : 0) . " to " . ($limit === -1 ? $total_items : min($offset + $limit, $total_items)) . " of $total_items entries",
        'page' => $page,
        'total_pages' => $total_pages
    ]);
    exit;
}

// Now include header after AJAX check
require_once ROOT_DIR . '/header.php';

$page_title = "Activity Log";
?>

<style>
/* Base Styles and Print Overrides */
.print-header { display: none; }

@media print {
    @page {
        margin: 1cm;
    }
    
    html, body {
        margin: 0 !important;
        padding: 0 !important;
        padding-top: 0 !important;
        margin-top: 0 !important;
        background: #fff !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    
    .main-content {
        padding: 1.5cm !important;
        margin: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
    }

    .navbar, footer, .no-print, .pagination, .btn, .navbar-toggler, .dropdown-menu, .breadcrumb {
        display: none !important;
    }

    .print-header {
        display: block !important;
        margin-bottom: 25px;
        text-align: center;
        border-bottom: 4px double #0d6efd;
        padding-bottom: 15px;
    }

    .card {
        border: none !important;
        box-shadow: none !important;
        background: transparent !important;
    }

    .table {
        font-size: 9pt !important;
        width: 100% !important;
        table-layout: fixed !important;
        border-collapse: collapse !important;
        page-break-inside: auto;
    }

    .table thead {
        display: table-header-group !important;
    }

    .table tr {
        page-break-inside: avoid !important;
        page-break-after: auto !important;
    }

    .table th {
        background-color: #f8f9fa !important;
        color: #000 !important;
        border: 1px solid #dee2e6 !important;
        padding: 6px !important;
        text-align: center !important;
        -webkit-print-color-adjust: exact;
    }

    .table td {
        border: 1px solid #eee !important;
        padding: 6px !important;
        word-wrap: break-word !important;
        word-break: break-all !important;
        white-space: normal !important;
        overflow: visible !important; /* Ensure all text is seen */
        vertical-align: top !important;
    }

    .print-only-column {
        display: table-cell !important;
        width: 60px !important;
        text-align: center !important;
        white-space: nowrap !important; /* Keep S/NO in one row */
    }

    .col-type {
        white-space: normal !important;
        width: 120px !important;
        word-break: break-word !important; /* Wrap properly within column */
        overflow: hidden !important;
        text-align: center !important;
    }
}

/* UI Premium Styling */
.activity-card {
    border: none;
    border-radius: 20px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.05);
    background: #fff;
    overflow: hidden;
}

.table thead th {
    background: #f8fafc;
    color: #475569;
    font-weight: 700;
    text-transform: uppercase;
    font-size: 0.75rem;
    letter-spacing: 0.05em;
    padding: 1rem;
    border: none;
    text-align: center; /* Center aligned headings */
}

.table tbody td {
    padding: 1rem;
    color: #1e293b;
    border-bottom: 1px solid #f1f5f9;
}

.pagination {
    gap: 5px;
}

.pagination .page-link {
    border: none;
    background: #f1f5f9;
    color: #64748b;
    border-radius: 10px;
    padding: 8px 16px;
    font-weight: 600;
    transition: all 0.2s;
}

.pagination .page-item.active .page-link {
    background: #0d6efd;
    color: #fff;
    box-shadow: 0 4px 10px rgba(13, 110, 253, 0.25);
}

.pagination .page-link:hover:not(.active) {
    background: #e2e8f0;
    color: #1e293b;
}

#paginationInfo {
    background: #f8fafc;
    padding: 8px 16px;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
}

/* Mobile Responsive Card View */
@media screen and (max-width: 768px) {
    .main-content {
        padding-left: 10px !important;
        padding-right: 10px !important;
        overflow-x: hidden;
    }
    
    #activityTable thead {
        display: none;
    }
    
    #activityTable, #activityTable tbody, #activityTable tr, #activityTable td {
        display: block;
        width: 100% !important;
    }
    
    #activityTable tr {
        margin-bottom: 1.5rem;
        background: #fff;
        border-radius: 15px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        border: 1px solid #f1f5f9;
        padding: 10px;
    }
    
    #activityTable td {
        text-align: right !important;
        padding: 12px 15px !important;
        border-bottom: 1px solid #f1f5f9;
        position: relative;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    #activityTable td:last-child {
        border-bottom: none;
    }
    
    #activityTable td::before {
        content: attr(data-label);
        font-weight: 700;
        text-transform: uppercase;
        font-size: 0.7rem;
        color: #64748b;
        margin-right: 15px;
        text-align: left;
    }

    .card-body {
        padding: 1rem !important;
    }

    .btn-group {
        width: auto;
        margin-top: 10px;
    }
}
</style>

<div class="container-fluid mt-4 main-content">
    <!-- Print-only Header -->
    <div class="print-header">
        <div class="py-2 mb-3" style="border-top: 2px solid #000; border-bottom: 2px solid #000; text-align: center;">
            <h3 class="mb-0 fw-bold" style="color: #000; text-transform: uppercase; letter-spacing: 2px;">System Activity Report</h3>
        </div>
        <div class="d-flex justify-content-between small text-muted mb-4">
            <span>Generated on: <?= date('d M Y, H:i') ?></span>
            <span>Ref: LOG-<?= date('Ymd-His') ?></span>
        </div>
    </div>

    <div class="mb-4 no-print">
        <h2 class="fw-bold text-primary" style="text-transform: uppercase;"><i class="bi bi-clock-history"></i> ACTIVITY LOG</h2>
        <p class="text-muted mb-0">Track and analyze all system transactions</p>
    </div>

    <!-- Activity Stats Cards -->
    <div class="row mb-4 no-print g-3">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm" style="background-color: #d1e7dd; border-radius: 15px;">
                <div class="card-body p-3 text-center">
                    <h6 class="text-success text-uppercase small fw-bold mb-1" style="font-size: 0.65rem;">Created Today</h6>
                    <h3 class="fw-bold mb-0 text-success"><?= number_format($today_stats['created'] ?? 0) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm" style="background-color: #d1e7dd; border-radius: 15px;">
                <div class="card-body p-3 text-center">
                    <h6 class="text-success text-uppercase small fw-bold mb-1" style="font-size: 0.65rem;">Viewed Today</h6>
                    <h3 class="fw-bold mb-0 text-success"><?= number_format($today_stats['viewed'] ?? 0) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm" style="background-color: #d1e7dd; border-radius: 15px;">
                <div class="card-body p-3 text-center">
                    <h6 class="text-success text-uppercase small fw-bold mb-1" style="font-size: 0.65rem;">Updated Today</h6>
                    <h3 class="fw-bold mb-0 text-success"><?= number_format($today_stats['updated'] ?? 0) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm" style="background-color: #d1e7dd; border-radius: 15px;">
                <div class="card-body p-3 text-center">
                    <h6 class="text-success text-uppercase small fw-bold mb-1" style="font-size: 0.65rem;">Deleted Today</h6>
                    <h3 class="fw-bold mb-0 text-success"><?= number_format($today_stats['deleted'] ?? 0) ?></h3>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Card -->
    <div class="card mb-4 shadow-sm border-0 no-print" style="border-radius: 15px;">
        <div class="card-body p-4">
            <form id="filterForm" class="row g-3 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">Activity Type</label>
                    <select class="form-select border-0 bg-light" name="type" id="filterType">
                        <option value="">All Types</option>
                        <?php if (!empty($activity_types)): ?>
                            <?php foreach ($activity_types as $activity_type): ?>
                                <option value="<?= htmlspecialchars($activity_type) ?>" <?= $type_filter == $activity_type ? 'selected' : '' ?>>
                                    <?= ucfirst(str_replace('_', ' ', $activity_type)) ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">User</label>
                    <select class="form-select border-0 bg-light" name="user_id" id="filterUser">
                        <option value="">All Users</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= $u['user_id'] ?>" <?= $user_id_filter == $u['user_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($u['username']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">Period</label>
                    <select class="form-select border-0 bg-light" id="filterPeriod">
                        <option value="">All Time</option>
                        <option value="today">Today</option>
                        <option value="week">This Week</option>
                        <option value="month">This Month</option>
                        <option value="year">This Year</option>
                        <option value="custom">Custom Range</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">From</label>
                    <input type="date" class="form-control border-0 bg-light" name="date_from" id="filterDateFrom" value="<?= $date_from ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">To</label>
                    <input type="date" class="form-control border-0 bg-light" name="date_to" id="filterDateTo" value="<?= $date_to ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">Limit</label>
                    <select class="form-select border-0 bg-light" name="limit" id="filterLimit">
                        <option value="10" <?= $limit == 10 ? 'selected' : '' ?>>10 entries</option>
                        <option value="25" <?= $limit == 25 ? 'selected' : '' ?>>25 entries</option>
                        <option value="50" <?= $limit == 50 ? 'selected' : '' ?>>50 entries</option>
                        <option value="100" <?= $limit == 100 ? 'selected' : '' ?>>100 entries</option>
                        <option value="all" <?= $limit == -1 ? 'selected' : '' ?>>All entries</option>
                    </select>
                </div>
                <div class="col-12 d-flex flex-wrap gap-2 pt-1">
                    <button type="submit" class="btn btn-primary fw-bold" style="border-radius: 10px; padding: 10px 24px;">
                        <i class="bi bi-filter"></i> Apply Filters
                    </button>
                    <?php if ($is_admin): ?>
                    <button type="button" class="btn btn-outline-danger fw-bold" style="border-radius: 10px; padding: 10px 24px;" onclick="initiatePurge()">
                        <i class="bi bi-trash3"></i> Purge Matching Logs
                    </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Export Buttons -->
    <div class="mb-4 no-print d-flex justify-content-start">
        <div class="btn-group shadow-sm" style="border: 1px solid #dee2e6; border-radius: 10px; overflow: hidden; background: #fff;">
            <button type="button" class="btn btn-white fw-medium px-3 border-0 bg-white" onclick="copyLog()" style="color: #444;">
                <i class="bi bi-clipboard text-info me-1"></i> Copy
            </button>
            <div style="width: 1px; background: #eee; height: 24px; margin-top: 8px;"></div>
            <button type="button" class="btn btn-white fw-medium px-3 border-0 bg-white" onclick="exportCSV()" style="color: #444;">
                <i class="bi bi-file-earmark-spreadsheet text-success me-1"></i> CSV
            </button>
            <div style="width: 1px; background: #eee; height: 24px; margin-top: 8px;"></div>
            <button type="button" class="btn btn-white fw-medium px-3 border-0 bg-white" onclick="printLog()" style="color: #444;">
                <i class="bi bi-printer text-primary me-1"></i> Print
            </button>
        </div>
    </div>

    <div class="card mb-4 shadow-sm border-0 report-card">
        <div class="card-body p-0">
            <div class="table-responsive" style="overflow: visible !important;">
                <table class="table table-hover align-middle mb-0" id="activityTable">
                    <thead class="bg-light">
                        <tr>
                            <th class="text-center" style="width: 5%;">S/NO</th>
                            <th class="ps-3" style="width: 15%;">Time</th>
                            <th class="col-type" style="width: 15%;">Type</th>
                            <th style="width: 35%;">Description</th>
                            <th style="width: 15%;">Reference</th>
                            <th class="pe-3" style="width: 15%;">User</th>
                        </tr>
                    </thead>
                    <tbody id="activityRows">
                        <?php if (!empty($activities)): ?>
                            <?php $si = $offset + 1; foreach ($activities as $activity): ?>
                            <tr>
                                <td class="text-center" data-label="S/NO"><?= $si++ ?></td>
                                <td class="ps-3 text-muted" data-label="Time">
                                    <small><?= date('d/m/y, H:i', strtotime($activity['timestamp'])) ?></small>
                                </td>
                                <td class="col-type" data-label="Type">
                                    <?php 
                                    $badge_class = 'primary';
                                    $t = strtolower($activity['type']);
                                    if (strpos($t, 'payment') !== false) { $badge_class = 'success'; }
                                    elseif (strpos($t, 'sale') !== false || strpos($t, 'pos') !== false) { $badge_class = 'info'; }
                                    elseif (strpos($t, 'delete') !== false) { $badge_class = 'danger'; }
                                    elseif (strpos($t, 'customer') !== false) { $badge_class = 'warning'; }
                                    ?>
                                    <span class="badge bg-<?= $badge_class ?> rounded-pill">
                                        <?= ucfirst(str_replace('_', ' ', $activity['type'] ?? 'Unknown')) ?>
                                    </span>
                                </td>
                                <td data-label="Description"><?= htmlspecialchars($activity['description'] ?? '-') ?></td>
                                <td data-label="Reference">
                                    <?php 
                                    $ref = (string)$activity['reference'];
                                    // Show reference if it's not an IP address
                                    if (!empty($ref) && strpos($ref, '.') === false && strpos($ref, ':') === false) {
                                        echo '<code class="text-dark bg-light px-2 py-1 rounded">' . htmlspecialchars($ref) . '</code>';
                                    } else {
                                        echo '<span class="text-muted">-</span>';
                                    }
                                    ?>
                                </td>
                                <td class="pe-3" data-label="User"><?= htmlspecialchars($activity['user_name'] ?? 'System') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center py-5 text-muted">
                                    No activities found for this period.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="paginationContainer" class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3 no-print">
        <div id="paginationInfo" class="small text-muted fw-bold">
            Showing <span class="text-dark"><?= ($total_items > 0 ? $offset + 1 : 0) ?></span> to
            <span class="text-dark"><?= ($limit === -1 ? $total_items : min($offset + $limit, $total_items)) ?></span> of
            <span class="text-dark"><?= number_format($total_items) ?></span> entries
        </div>
        <nav id="paginationNav">
            <?php if ($total_pages > 1): ?>
            <ul class="pagination mb-0">
                <?php echo renderSmartPagination($page, $total_pages); ?>
            </ul>
            <?php endif; ?>
        </nav>
    </div>
</div>

<script>
function loadPage(page) {
    if (page < 1) return;
    
    $('#activityRows').css('opacity', '0.5');
    
    const formData = $('#filterForm').serializeArray();
    let params = { ajax: 1, page: page };
    formData.forEach(item => {
        params[item.name] = item.value;
    });
    
    $.ajax({
        url: '<?= getUrl('activity_log') ?>',
        type: 'GET',
        data: params,
        dataType: 'json',
        cache: false,
        success: function(response) {
            if (response.success) {
                $('#activityRows').html(response.rows).css('opacity', '1');
                $('#paginationNav').html(response.pagination);
                $('#paginationInfo').text(response.info);
                $('html, body').animate({
                    scrollTop: $("#activityTable").offset().top - 100
                }, 100);
            } else {
                Swal.fire('Error', response.error || 'Failed to load data', 'error');
                $('#activityRows').css('opacity', '1');
            }
        },
        error: function(xhr) {
            console.error('AJAX Error:', xhr.status, xhr.statusText);
            Swal.fire('Error', 'Connection failed (Code: ' + xhr.status + '). Please try again.', 'error');
            $('#activityRows').css('opacity', '1');
        }
    });
}

// Handle real-time filtering if preferred, or just the submit button
$('#filterForm').on('submit', function(e) {
    e.preventDefault();
    loadPage(1);
});

// Optional: Auto-load on change
$('#filterType, #filterUser, #filterDateFrom, #filterDateTo, #filterLimit').on('change', function() {
    loadPage(1);
});
// Helper to log actions

function printLog() {
    logReportAction('Printed Activity Log', 'Generated a printed report of the system activity logs');
    window.print();
}

function copyLog() {
    // Initialize a temporary DataTable if not exists to handle the copy
    if (!$.fn.DataTable.isDataTable('#activityTable')) {
        $('#activityTable').DataTable({
            paging: false,
            searching: false,
            info: false,
            dom: 'B',
            buttons: ['copyHtml5']
        }).button('.buttons-copy').trigger();
        $('#activityTable').DataTable().destroy();
    } else {
        $('#activityTable').DataTable().button('.buttons-copy').trigger();
    }
    logReportAction('Copied Activity Log', 'Copied activity log records to clipboard');
    Swal.fire({
        icon: 'success',
        title: 'Success!',
        text: 'Activity records copied to clipboard.',
        confirmButtonText: 'OK',
        confirmButtonColor: '#28a745'
    });
}

function exportCSV() {
    if (!$.fn.DataTable.isDataTable('#activityTable')) {
        $('#activityTable').DataTable({
            paging: false,
            searching: false,
            info: false,
            dom: 'B',
            buttons: [{
                extend: 'csvHtml5',
                filename: 'Activity_Log_' + new Date().toISOString().slice(0,10)
            }]
        }).button('.buttons-csv').trigger();
        $('#activityTable').DataTable().destroy();
    } else {
        $('#activityTable').DataTable().button('.buttons-csv').trigger();
    }
    logReportAction('Exported Activity Log', 'Exported activity log records to CSV file');
}

// ── Period preset ────────────────────────────────────────────────────────────
function applyPeriodPreset(period) {
    const today = new Date();
    const fmt   = d => d.toISOString().slice(0, 10);

    if (period === '') {
        $('#filterDateFrom').val('');
        $('#filterDateTo').val('');
        loadPage(1);
    } else if (period === 'today') {
        $('#filterDateFrom').val(fmt(today));
        $('#filterDateTo').val(fmt(today));
        loadPage(1);
    } else if (period === 'week') {
        const mon = new Date(today);
        const day = mon.getDay() || 7; // treat Sunday as 7 so Monday = start
        mon.setDate(mon.getDate() - day + 1);
        $('#filterDateFrom').val(fmt(mon));
        $('#filterDateTo').val(fmt(today));
        loadPage(1);
    } else if (period === 'month') {
        $('#filterDateFrom').val(fmt(new Date(today.getFullYear(), today.getMonth(), 1)));
        $('#filterDateTo').val(fmt(today));
        loadPage(1);
    } else if (period === 'year') {
        $('#filterDateFrom').val(fmt(new Date(today.getFullYear(), 0, 1)));
        $('#filterDateTo').val(fmt(today));
        loadPage(1);
    }
    // 'custom': leave From/To for manual input — existing change handler fires on user edit
}

// Detect period preset on page load when URL already has dates
(function initPeriodDropdown() {
    const from = $('#filterDateFrom').val();
    const to   = $('#filterDateTo').val();
    if (!from && !to) return;

    const today = new Date();
    const fmt   = d => d.toISOString().slice(0, 10);
    const mon   = new Date(today);
    const day   = mon.getDay() || 7;
    mon.setDate(mon.getDate() - day + 1);

    if (from === fmt(today) && to === fmt(today)) {
        $('#filterPeriod').val('today');
    } else if (from === fmt(mon) && to === fmt(today)) {
        $('#filterPeriod').val('week');
    } else if (from === fmt(new Date(today.getFullYear(), today.getMonth(), 1)) && to === fmt(today)) {
        $('#filterPeriod').val('month');
    } else if (from === fmt(new Date(today.getFullYear(), 0, 1)) && to === fmt(today)) {
        $('#filterPeriod').val('year');
    } else {
        $('#filterPeriod').val('custom');
    }
})();

$('#filterPeriod').on('change', function () {
    applyPeriodPreset($(this).val());
});

// ── Purge matching logs ──────────────────────────────────────────────────────
async function initiatePurge() {
    const payload = {
        action  : 'count',
        _csrf   : CSRF_TOKEN,
        type    : $('#filterType').val(),
        user_id : $('#filterUser').val(),
        date_from: $('#filterDateFrom').val(),
        date_to  : $('#filterDateTo').val()
    };

    Swal.fire({
        title: 'Counting…',
        text: 'Checking matching log entries',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    let countData;
    try {
        countData = await $.ajax({
            url     : '<?= getUrl("api/activity_log_delete.php") ?>',
            type    : 'POST',
            data    : payload,
            dataType: 'json'
        });
    } catch (e) {
        Swal.fire('Error', 'Connection failed. Please try again.', 'error');
        return;
    }

    if (!countData.success) {
        Swal.fire('Error', countData.error || 'Could not get count', 'error');
        return;
    }

    const count = countData.count;
    if (count === 0) {
        Swal.fire({ icon: 'info', title: 'Nothing to Delete', text: 'No log entries match the current filters.' });
        return;
    }

    const allWarn = countData.all_records
        ? `<div class="alert alert-danger small mb-3 text-start">
               <i class="bi bi-exclamation-triangle-fill me-1"></i>
               <strong>No filters applied.</strong> This will erase the <em>entire</em> activity log history.
           </div>`
        : '';

    const confirm = await Swal.fire({
        icon : 'warning',
        title: 'Purge Log Entries?',
        html : `${allWarn}
                <p class="mb-1">You are about to permanently delete</p>
                <h2 class="fw-bold text-danger my-2">${count.toLocaleString()} entries</h2>
                <p class="text-muted small mb-0">This action <strong>cannot be undone</strong>.</p>`,
        showCancelButton   : true,
        confirmButtonColor : '#dc3545',
        cancelButtonColor  : '#6c757d',
        confirmButtonText  : '<i class="bi bi-trash3 me-1"></i> Yes, Delete Permanently',
        cancelButtonText   : 'Cancel',
        reverseButtons     : true
    });

    if (!confirm.isConfirmed) return;

    Swal.fire({
        title: 'Purging…',
        html : `Deleting <strong>${count.toLocaleString()}</strong> log entries`,
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    let purgeData;
    try {
        purgeData = await $.ajax({
            url     : '<?= getUrl("api/activity_log_delete.php") ?>',
            type    : 'POST',
            data    : { ...payload, action: 'purge' },
            dataType: 'json'
        });
    } catch (e) {
        Swal.fire('Error', 'Connection failed during purge. Check server logs.', 'error');
        return;
    }

    if (purgeData.success) {
        await Swal.fire({
            icon : 'success',
            title: 'Purged Successfully',
            html : `<strong>${purgeData.count.toLocaleString()}</strong> log entries have been permanently deleted.`,
            confirmButtonColor: '#28a745'
        });
        loadPage(1);
    } else {
        Swal.fire('Error', purgeData.error || 'Purge failed', 'error');
    }
}
</script>

<style>
/* Utilities */
.tracking-wider { letter-spacing: 0.1em; }
</style>

<?php require_once ROOT_DIR . '/footer.php'; ?>
