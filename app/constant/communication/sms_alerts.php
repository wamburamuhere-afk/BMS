<?php
// scope-audit: skip — SMS alerts configuration references customers for notification targeting; not project-scoped data
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('sms_alerts');
includeHeader();

// Get filter parameters
$status_filter = $_GET['status'] ?? '';
$alert_type = $_GET['alert_type'] ?? '';
$search_query = $_GET['search'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Fetch SMS alerts
try {
    $query = "
        SELECT sa.*, 
               l.reference_number,
               CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
               c.phone_number AS customer_phone,
               u.username AS created_by_name,
               cs.strategy_name,
               l.amount,
               l.due_date,
               DATEDIFF(CURDATE(), l.due_date) AS overdue_days,
               '' AS country_code
        FROM sms_alerts sa
        LEFT JOIN loans l ON sa.loan_id = l.loan_id
        LEFT JOIN customers c ON l.customer_id = c.customer_id
        LEFT JOIN users u ON sa.created_by = u.user_id
        LEFT JOIN collection_strategies cs ON sa.strategy_id = cs.strategy_id
    ";
    
    $conditions = [];
    $params = [];
    
    if (!empty($alert_type)) {
        $conditions[] = "sa.alert_type = ?";
        $params[] = $alert_type;
    }
    
    if (!empty($status_filter)) {
        $conditions[] = "sa.status = ?";
        $params[] = $status_filter;
    }
    
    if (!empty($search_query)) {
        $conditions[] = "(l.reference_number LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR c.phone_number LIKE ?)";
        $params[] = "%$search_query%";
        $params[] = "%$search_query%";
        $params[] = "%$search_query%";
        $params[] = "%$search_query%";
    }
    
    if (!empty($date_from)) {
        $conditions[] = "sa.scheduled_date >= ?";
        $params[] = $date_from;
    }
    
    if (!empty($date_to)) {
        $conditions[] = "sa.scheduled_date <= ?";
        $params[] = $date_to;
    }
    
    if (!empty($conditions)) {
        $query .= " WHERE " . implode(" AND ", $conditions);
    }
    
    $query .= " ORDER BY sa.scheduled_date ASC, sa.created_at DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get alert types for filter
    $alert_types_stmt = $pdo->query("SELECT DISTINCT alert_type FROM sms_alerts");
    $alert_types = $alert_types_stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Get SMS statistics
    $stats_stmt = $pdo->query("
        SELECT 
            status,
            COUNT(*) as count,
            alert_type,
            SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent_count,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count
        FROM sms_alerts 
        WHERE scheduled_date >= CURDATE() - INTERVAL 30 DAY
        GROUP BY status, alert_type
    ");
    $sms_stats = $stats_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get SMS credit balance (simulated)
    $credit_balance = 485; // This would come from your SMS gateway API
    
} catch (Exception $e) {
    $error = "Error fetching SMS alerts: " . $e->getMessage();
}
?>

<div class="container-fluid py-4">
    <!-- Success/Error Notification Container -->
    <div id="notificationContainer" style="position: fixed; top: 80px; right: 20px; z-index: 9999; min-width: 350px;"></div>

    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-chat-text"></i> SMS Alerts</h2>
                    <p class="text-muted">Manage and track SMS alerts for loan payments and collections</p>
                </div>
                <div>
                    <button class="btn btn-outline-primary me-2" data-bs-toggle="modal" data-bs-target="#bulkSMSModal">
                        <i class="bi bi-send-check me-2"></i>Bulk SMS
                    </button>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSMSModal">
                        <i class="bi bi-plus-circle me-2"></i>New SMS
                    </button>
                </div>
            </div>
        </div>
    </div>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- SMS Overview Cards -->
    <div class="row mb-4">
        <div class="col-xl-2 col-md-4 mb-4">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                Total SMS
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?= count($alerts) ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-chat-text fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-md-4 mb-4">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                Sent
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?= count(array_filter($alerts, fn($a) => $a['status'] === 'sent')) ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-check-circle fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-md-4 mb-4">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                Scheduled
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?= count(array_filter($alerts, fn($a) => $a['status'] === 'scheduled')) ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-clock fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-md-4 mb-4">
            <div class="card border-left-info shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                Payment Reminders
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?= count(array_filter($alerts, fn($a) => $a['alert_type'] === 'payment_reminder')) ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-cash-coin fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-md-4 mb-4">
            <div class="card border-left-secondary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-secondary text-uppercase mb-1">
                                Collection Alerts
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?= count(array_filter($alerts, fn($a) => $a['alert_type'] === 'collection_alert')) ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-diagram-3 fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-md-4 mb-4">
            <div class="card border-left-danger shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">
                                Failed
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?= count(array_filter($alerts, fn($a) => $a['status'] === 'failed')) ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-exclamation-triangle fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- SMS Credit and Quick Actions -->
    <div class="row mb-4">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Quick Actions</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <button class="btn btn-outline-primary w-100" id="sendScheduledSMS">
                                <i class="bi bi-send-check me-2"></i>
                                Send Scheduled
                            </button>
                        </div>
                        <div class="col-md-3 mb-3">
                            <button class="btn btn-outline-success w-100" id="generateOverdueSMS">
                                <i class="bi bi-lightning me-2"></i>
                                Generate Overdue
                            </button>
                        </div>
                        <div class="col-md-3 mb-3">
                            <button class="btn btn-outline-warning w-100" id="testSMSGateway">
                                <i class="bi bi-gear me-2"></i>
                                Test Gateway
                            </button>
                        </div>
                        <div class="col-md-3 mb-3">
                            <button class="btn btn-outline-info w-100" id="viewSMSTemplates">
                                <i class="bi bi-file-text me-2"></i>
                                View Templates
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-gradient-primary text-white">
                <div class="card-body">
                    <div class="text-center">
                        <i class="bi bi-phone fa-3x mb-3"></i>
                        <h4><?= number_format($credit_balance) ?> Credits</h4>
                        <p class="mb-0">SMS Balance</p>
                        <small>Approx. <?= floor($credit_balance / 0.05) ?> messages remaining</small>
                    </div>
                </div>
                <div class="card-footer bg-transparent">
                    <button class="btn btn-light btn-sm w-100" id="topUpCredits">
                        <i class="bi bi-plus-circle me-1"></i>Top Up Credits
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- SMS Performance -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">SMS Performance (Last 30 Days)</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-8">
                            <canvas id="smsPerformanceChart" height="100"></canvas>
                        </div>
                        <div class="col-md-4">
                            <div class="row text-center">
                                <div class="col-6 mb-3">
                                    <div class="border rounded p-3">
                                        <h4 class="text-success"><?= count(array_filter($alerts, fn($a) => $a['status'] === 'sent' && strtotime($a['created_at']) >= strtotime('-30 days'))) ?></h4>
                                        <small>Sent (30 days)</small>
                                    </div>
                                </div>
                                <div class="col-6 mb-3">
                                    <div class="border rounded p-3">
                                        <h4 class="text-danger"><?= count(array_filter($alerts, fn($a) => $a['status'] === 'failed' && strtotime($a['created_at']) >= strtotime('-30 days'))) ?></h4>
                                        <small>Failed (30 days)</small>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="border rounded p-3">
                                        <h4 class="text-info"><?= round(count(array_filter($alerts, fn($a) => $a['status'] === 'sent' && strtotime($a['created_at']) >= strtotime('-30 days'))) / max(1, count(array_filter($alerts, fn($a) => strtotime($a['created_at']) >= strtotime('-30 days')))) * 100, 1) ?>%</h4>
                                        <small>Success Rate</small>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="border rounded p-3">
                                        <h4 class="text-warning"><?= count(array_filter($alerts, fn($a) => $a['status'] === 'scheduled')) ?></h4>
                                        <small>Pending</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters and Search -->
    <div class="card mb-4">
        <div class="card-body">
            <div class="row">
                <div class="col-md-3">
                    <div class="mb-3">
                        <label class="form-label">Alert Type</label>
                        <select class="form-select" id="alertTypeFilter">
                            <option value="">All Types</option>
                            <?php foreach ($alert_types as $type): ?>
                                <option value="<?= htmlspecialchars($type) ?>" <?= $alert_type === $type ? 'selected' : '' ?>>
                                    <?= ucfirst(str_replace('_', ' ', htmlspecialchars($type))) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" id="statusFilter">
                            <option value="">All Statuses</option>
                            <option value="scheduled" <?= $status_filter === 'scheduled' ? 'selected' : '' ?>>Scheduled</option>
                            <option value="sent" <?= $status_filter === 'sent' ? 'selected' : '' ?>>Sent</option>
                            <option value="failed" <?= $status_filter === 'failed' ? 'selected' : '' ?>>Failed</option>
                            <option value="cancelled" <?= $status_filter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="mb-3">
                        <label class="form-label">Date From</label>
                        <input type="date" class="form-control" id="dateFromFilter" value="<?= htmlspecialchars($date_from) ?>">
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="mb-3">
                        <label class="form-label">Date To</label>
                        <input type="date" class="form-control" id="dateToFilter" value="<?= htmlspecialchars($date_to) ?>">
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="mb-3">
                        <label class="form-label">&nbsp;</label>
                        <button class="btn btn-outline-secondary w-100" type="button" id="resetFilters">
                            <i class="bi bi-arrow-clockwise"></i> Reset
                        </button>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-md-8">
                    <div class="mb-3">
                        <label class="form-label">Search</label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="searchInput" placeholder="Search loans, customers, or phone numbers..." value="<?= htmlspecialchars($search_query) ?>">
                            <button class="btn btn-outline-secondary" type="button" id="searchButton">
                                <i class="bi bi-search"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="mb-3">
                        <label class="form-label">&nbsp;</label>
                        <div class="btn-group w-100">
                            <button class="btn btn-outline-secondary" id="exportSMS">
                                <i class="bi bi-download me-1"></i>Export
                            </button>
                            <button class="btn btn-outline-secondary" id="refreshData">
                                <i class="bi bi-arrow-repeat me-1"></i>Refresh
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- SMS Alerts Table -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">SMS Alerts</h5>
            <div class="text-muted small">
                <span class="badge bg-light text-dark">Last updated: <?= date('H:i:s') ?></span>
            </div>
        </div>
        <div class="card-body">
            <?php if (empty($alerts)): ?>
                <div class="text-center py-4">
                    <i class="bi bi-chat-text display-1 text-muted"></i>
                    <h4 class="mt-3 text-muted">No SMS Alerts Found</h4>
                    <p class="text-muted">Get started by creating your first SMS alert.</p>
                    <button class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#addSMSModal">
                        <i class="bi bi-plus-circle me-2"></i>Create SMS Alert
                    </button>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover" id="smsAlertsTable">
                        <thead>
                            <tr>
                                <th>Loan Reference</th>
                                <th>Customer & Phone</th>
                                <th>Type</th>
                                <th>Message</th>
                                <th>Scheduled Date</th>
                                <th>Status</th>
                                <th>Chars/Cost</th>
                                <th class="action-column">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($alerts as $alert): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($alert['reference_number']) ?></strong>
                                        <br><small class="text-muted">Amount: $<?= number_format($alert['amount'], 2) ?></small>
                                        <?php if ($alert['overdue_days'] > 0): ?>
                                            <br><small class="text-danger"><?= $alert['overdue_days'] ?> days overdue</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($alert['customer_name']) ?></strong>
                                        <br><small class="text-muted">
                                            <?= htmlspecialchars($alert['country_code'] ?? '+1') ?> <?= htmlspecialchars($alert['customer_phone']) ?>
                                        </small>
                                    </td>
                                    <td>
                                        <?php
                                        $type_class = [
                                            'payment_reminder' => 'bg-primary',
                                            'collection_alert' => 'bg-info',
                                            'payment_confirmation' => 'bg-success',
                                            'loan_approval' => 'bg-warning',
                                            'general_alert' => 'bg-secondary'
                                        ][$alert['alert_type']] ?? 'bg-secondary';
                                        ?>
                                        <span class="badge <?= $type_class ?>">
                                            <?= ucfirst(str_replace('_', ' ', htmlspecialchars($alert['alert_type']))) ?>
                                        </span>
                                        <?php if (!empty($alert['strategy_name'])): ?>
                                            <br><small class="text-muted">Strategy: <?= htmlspecialchars($alert['strategy_name']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="message-preview">
                                            <i class="bi bi-chat-left-text me-1"></i>
                                            <?= htmlspecialchars(substr($alert['message_content'], 0, 60)) ?>...
                                        </div>
                                        <small class="text-muted">
                                            <?= strlen($alert['message_content']) ?> chars
                                        </small>
                                    </td>
                                    <td>
                                        <strong><?= date('M j, Y', strtotime($alert['scheduled_date'])) ?></strong>
                                        <br><small class="text-muted"><?= date('H:i', strtotime($alert['scheduled_date'])) ?></small>
                                        <?php if ($alert['sent_at']): ?>
                                            <br><small class="text-success">Sent: <?= date('M j, H:i', strtotime($alert['sent_at'])) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $status_class = [
                                            'scheduled' => 'bg-warning',
                                            'sent' => 'bg-success',
                                            'failed' => 'bg-danger',
                                            'cancelled' => 'bg-secondary'
                                        ][$alert['status']] ?? 'bg-secondary';
                                        ?>
                                        <span class="badge <?= $status_class ?>">
                                            <?= ucfirst(htmlspecialchars($alert['status'])) ?>
                                        </span>
                                        <?php if (!empty($alert['delivery_status'])): ?>
                                            <br><small class="text-muted"><?= htmlspecialchars($alert['delivery_status']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small>
                                            <?= strlen($alert['message_content']) ?> / 160
                                            <br>
                                            <span class="text-muted">$0.05</span>
                                        </small>
                                    </td>
                                    <td class="action-column">
                                        <div class="dropdown action-dropdown">
                                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                <i class="bi bi-gear"></i>
                                            </button>
                                            <ul class="dropdown-menu">
                                                <li>
                                                    <a class="dropdown-item view-sms" href="#" data-sms-id="<?= $alert['sms_id'] ?>">
                                                        <i class="bi bi-eye text-primary"></i> View Details
                                                    </a>
                                                </li>
                                                <?php if ($alert['status'] === 'scheduled'): ?>
                                                    <li>
                                                        <a class="dropdown-item send-now-sms" href="#" data-sms-id="<?= $alert['sms_id'] ?>">
                                                            <i class="bi bi-send text-success"></i> Send Now
                                                        </a>
                                                    </li>
                                                    <li>
                                                        <a class="dropdown-item edit-sms" href="#" data-sms-id="<?= $alert['sms_id'] ?>">
                                                            <i class="bi bi-pencil text-warning"></i> Edit
                                                        </a>
                                                    </li>
                                                    <li>
                                                        <a class="dropdown-item cancel-sms" href="#" data-sms-id="<?= $alert['sms_id'] ?>">
                                                            <i class="bi bi-x-circle text-danger"></i> Cancel
                                                        </a>
                                                    </li>
                                                <?php endif; ?>
                                                <?php if ($alert['status'] === 'sent'): ?>
                                                    <li>
                                                        <a class="dropdown-item resend-sms" href="#" data-sms-id="<?= $alert['sms_id'] ?>">
                                                            <i class="bi bi-arrow-repeat text-info"></i> Resend
                                                        </a>
                                                    </li>
                                                <?php endif; ?>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <a class="dropdown-item view-loan-details" href="loan_details.php?id=<?= $alert['loan_id'] ?>">
                                                        <i class="bi bi-cash-stack text-secondary"></i> View Loan
                                                    </a>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add SMS Modal -->
<div class="modal fade" id="addSMSModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Create SMS Alert</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="addSMSForm">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="loan_id" class="form-label">Select Loan *</label>
                                <select class="form-control" id="loan_id" name="loan_id" required>
                                    <option value="">Select a loan...</option>
                                    <?php
                                    $loans_stmt = $pdo->query("
                                        SELECT l.loan_id, l.reference_number, 
                                               CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
                                               c.phone_number, c.country_code,
                                               l.amount, l.due_date,
                                               DATEDIFF(CURDATE(), l.due_date) AS overdue_days
                                        FROM loans l
                                        JOIN customers c ON l.customer_id = c.customer_id
                                        WHERE c.phone_number IS NOT NULL AND c.phone_number != ''
                                        ORDER BY l.due_date ASC
                                    ");
                                    $loans = $loans_stmt->fetchAll(PDO::FETCH_ASSOC);
                                    foreach ($loans as $loan): 
                                    ?>
                                        <option value="<?= $loan['loan_id'] ?>" 
                                                data-customer-name="<?= htmlspecialchars($loan['customer_name']) ?>"
                                                data-phone-number="<?= htmlspecialchars($loan['phone_number']) ?>"
                                                data-country-code="<?= htmlspecialchars($loan['country_code'] ?? '+1') ?>"
                                                data-overdue-days="<?= $loan['overdue_days'] ?>">
                                            <?= htmlspecialchars($loan['reference_number']) ?> - <?= htmlspecialchars($loan['customer_name']) ?> (<?= $loan['country_code'] ?? '+1' ?> <?= htmlspecialchars($loan['phone_number']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="alert_type" class="form-label">Alert Type *</label>
                                <select class="form-control" id="alert_type" name="alert_type" required>
                                    <option value="">Select type...</option>
                                    <option value="payment_reminder">Payment Reminder</option>
                                    <option value="collection_alert">Collection Alert</option>
                                    <option value="payment_confirmation">Payment Confirmation</option>
                                    <option value="loan_approval">Loan Approval</option>
                                    <option value="general_alert">General Alert</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="strategy_id" class="form-label">Collection Strategy (Optional)</label>
                                <select class="form-control" id="strategy_id" name="strategy_id">
                                    <option value="">No strategy</option>
                                    <?php
                                    $strategies_stmt = $pdo->query("
                                        SELECT strategy_id, strategy_name 
                                        FROM collection_strategies 
                                        WHERE status = 'active'
                                        ORDER BY strategy_name
                                    ");
                                    $strategies = $strategies_stmt->fetchAll(PDO::FETCH_ASSOC);
                                    foreach ($strategies as $strategy): 
                                    ?>
                                        <option value="<?= $strategy['strategy_id'] ?>"><?= htmlspecialchars($strategy['strategy_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="scheduled_date" class="form-label">Scheduled Date & Time *</label>
                                <input type="datetime-local" class="form-control" id="scheduled_date" name="scheduled_date" required value="<?= date('Y-m-d\TH:i') ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Recipient</label>
                        <div class="border rounded p-3 bg-light">
                            <div id="recipientInfo">Select a loan to see recipient information...</div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="message_content" class="form-label">Message Content *</label>
                        <textarea class="form-control" id="message_content" name="message_content" rows="4" required 
                                  placeholder="Enter your SMS message (max 160 characters)..."
                                  maxlength="160"></textarea>
                        <div class="form-text">
                            <span id="charCount">0</span>/160 characters • 
                            <span id="messageCost">$0.00</span> per message •
                            Available variables: {customer_name}, {loan_amount}, {due_date}, {overdue_days}, {reference_number}
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Quick Templates</label>
                        <div class="btn-group w-100">
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-template="payment_reminder">
                                Payment Reminder
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-template="collection_alert">
                                Collection Alert
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-template="friendly_reminder">
                                Friendly Reminder
                            </button>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Message Preview</label>
                        <div class="border rounded p-3 bg-light" id="messagePreview">
                            <div class="d-flex align-items-start">
                                <i class="bi bi-phone me-3 text-muted"></i>
                                <div>
                                    <div class="bg-primary text-white rounded p-2 mb-2" style="max-width: 250px;">
                                        Preview will appear here...
                                    </div>
                                    <small class="text-muted">SMS Preview</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Schedule SMS</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bulk SMS Modal -->
<div class="modal fade" id="bulkSMSModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Bulk SMS Alerts</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="bulkSMSForm">
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        Send SMS alerts to multiple customers based on selected criteria.
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="bulk_alert_type" class="form-label">Alert Type *</label>
                                <select class="form-control" id="bulk_alert_type" name="alert_type" required>
                                    <option value="payment_reminder">Payment Reminder</option>
                                    <option value="collection_alert">Collection Alert</option>
                                    <option value="general_alert">General Alert</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="bulk_strategy_id" class="form-label">Collection Strategy</label>
                                <select class="form-control" id="bulk_strategy_id" name="strategy_id">
                                    <option value="">No strategy</option>
                                    <?php foreach ($strategies as $strategy): ?>
                                        <option value="<?= $strategy['strategy_id'] ?>"><?= htmlspecialchars($strategy['strategy_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="bulk_overdue_days_min" class="form-label">Overdue Days (Min)</label>
                                <input type="number" class="form-control" id="bulk_overdue_days_min" name="overdue_days_min" min="1" placeholder="Minimum days overdue">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="bulk_overdue_days_max" class="form-label">Overdue Days (Max)</label>
                                <input type="number" class="form-control" id="bulk_overdue_days_max" name="overdue_days_max" min="1" placeholder="Maximum days overdue">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="bulk_loan_status" class="form-label">Loan Status</label>
                                <select class="form-control" id="bulk_loan_status" name="loan_status">
                                    <option value="">All Statuses</option>
                                    <option value="overdue">Overdue</option>
                                    <option value="defaulted">Defaulted</option>
                                    <option value="active">Active</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="bulk_scheduled_date" class="form-label">Scheduled Date & Time *</label>
                        <input type="datetime-local" class="form-control" id="bulk_scheduled_date" name="scheduled_date" required value="<?= date('Y-m-d\TH:i') ?>">
                    </div>
                    
                    <div class="mb-3">
                        <label for="bulk_message_content" class="form-label">Message Template *</label>
                        <textarea class="form-control" id="bulk_message_content" name="message_content" rows="4" required 
                                  placeholder="Enter your SMS message template (max 160 characters)..."
                                  maxlength="160"></textarea>
                        <div class="form-text">
                            <span id="bulkCharCount">0</span>/160 characters • 
                            Available variables: {customer_name}, {loan_amount}, {due_date}, {overdue_days}, {reference_number}
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Estimated Impact & Cost</label>
                        <div class="border rounded p-3 bg-light">
                            <div id="bulkImpactSummary">Select criteria to see estimated impact...</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Bulk SMS</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- SMS Details Modal -->
<div class="modal fade" id="smsDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">SMS Alert Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="smsDetailsContent">
                <!-- Content will be loaded via AJAX -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<style>
.card-hover:hover {
    transform: translateY(-2px);
    transition: transform 0.2s ease-in-out;
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

.border-left-primary { border-left: 4px solid #4e73df !important; }
.border-left-success { border-left: 4px solid #1cc88a !important; }
.border-left-warning { border-left: 4px solid #f6c23e !important; }
.border-left-info { border-left: 4px solid #36b9cc !important; }
.border-left-secondary { border-left: 4px solid #858796 !important; }
.border-left-danger { border-left: 4px solid #e74a3b !important; }

.bg-gradient-primary {
    background: linear-gradient(45deg, #4e73df, #224abe);
}

/* Compact dropdown styles */
.action-dropdown .btn-sm {
    padding: 0.25rem 0.5rem;
    font-size: 0.75rem;
}

.action-dropdown .dropdown-menu {
    font-size: 0.875rem;
    min-width: 180px;
}

.action-dropdown .dropdown-item {
    padding: 0.25rem 1rem;
}

.action-dropdown .dropdown-item i {
    width: 18px;
    margin-right: 0.5rem;
}

.action-column {
    width: 80px;
    min-width: 80px;
    max-width: 80px;
}

/* Reduce table padding for more compact rows */
.table td, .table th {
    padding: 0.5rem;
}

/* Message preview styling */
.message-preview {
    max-width: 200px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.notification {
    padding: 15px 20px;
    margin-bottom: 10px;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    animation: slideInRight 0.3s ease-out;
    border-left: 4px solid;
}

.notification.success {
    background-color: #d4edda;
    border-color: #28a745;
    color: #155724;
}

.notification.error {
    background-color: #f8d7da;
    border-color: #dc3545;
    color: #721c24;
}

.notification.info {
    background-color: #d1ecf1;
    border-color: #17a2b8;
    color: #0c5460;
}

@keyframes slideInRight {
    from {
        transform: translateX(100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

.char-count-warning {
    color: #dc3545;
    font-weight: bold;
}
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
$(document).ready(function() {
    // Audit Log for Page View
    logReportAction('Viewed SMS Alerts', 'User viewed the SMS alerts dashboard and history');

    $('#alertTypeFilter, #statusFilter, #dateFromFilter, #dateToFilter').on('change', function() {
        logReportAction('Filtered SMS Alerts', 'User changed filters: Type=' + ($('#alertTypeFilter').val() || 'All') + ', Status=' + ($('#statusFilter').val() || 'All'));
        updateFilters();
    });

    $('#searchInput').on('keypress', function(e) {
        if (e.which == 13) {
            logReportAction('Searched SMS Alerts', 'User searched for: ' + $(this).val());
            updateFilters();
        }
    });

    $('#searchButton').on('click', function() {
        logReportAction('Searched SMS Alerts', 'User searched for: ' + $('#searchInput').val());
        updateFilters();
    });

    $('#resetFilters').on('click', function() {
        logReportAction('Reset SMS Filters', 'User cleared all SMS search filters');
        window.location.href = 'sms_alerts.php';
    });

    $('#refreshData').on('click', function() {
        logReportAction('Refreshed SMS Data', 'User clicked refresh SMS alerts list');
        location.reload();
    });

    $('#exportSMS').on('click', function() {
        logReportAction('Exported SMS Alerts', 'User exported the SMS alerts list to Excel/CSV');
        // Existing export logic if any...
    });

    // Quick Actions
    $('#sendScheduledSMS').click(function() {
        logReportAction('Triggered Scheduled SMS', 'User manually triggered sending of all scheduled SMS alerts');
        // ...
    });

    $('#generateOverdueSMS').click(function() {
        logReportAction('Generated Overdue SMS', 'User manually triggered generation of overdue loan SMS alerts');
        // ...
    });

    $('#testSMSGateway').click(function() {
        logReportAction('Tested SMS Gateway', 'User initiated an SMS gateway connection test');
        // ...
    });

    $('#viewSMSTemplates').click(function() {
        logReportAction('Viewed SMS Templates', 'User navigated to SMS templates from alerts dashboard');
        window.location.href = 'sms_templates.php';
    });

    $('#topUpCredits').click(function() {
        logReportAction('Initiated SMS Top-up', 'User clicked to top up SMS credits');
        // ...
    });

    let currentSMSId = null;
    const SMS_COST_PER_MESSAGE = 0.05;

    // Notification function
    function showNotification(message, type = 'success', duration = 3000) {
        Swal.fire({
            title: type === 'success' ? 'Mafanikio!' : (type === 'error' ? 'Hitilafu!' : 'Taarifa'),
            text: message,
            icon: type,
            timer: duration,
            showConfirmButton: true,
            confirmButtonText: 'OK',
            confirmButtonColor: '#3085d6'
        });
    }

    // Initialize SMS performance chart
    function initializeSMSChart() {
        const ctx = document.getElementById('smsPerformanceChart').getContext('2d');
        
        // Sample data - in real implementation, fetch from server
        const data = {
            labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4'],
            datasets: [
                {
                    label: 'Sent',
                    data: [45, 52, 48, 61],
                    backgroundColor: '#1cc88a',
                    borderColor: '#1cc88a',
                    borderWidth: 1
                },
                {
                    label: 'Failed',
                    data: [3, 2, 5, 4],
                    backgroundColor: '#e74a3b',
                    borderColor: '#e74a3b',
                    borderWidth: 1
                }
            ]
        };
        
        new Chart(ctx, {
            type: 'bar',
            data: data,
            options: {
                responsive: true,
                plugins: {
                    title: {
                        display: true,
                        text: 'SMS Delivery Performance'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Number of SMS'
                        }
                    }
                }
            }
        });
    }

    // Filter functionality
    $('#alertTypeFilter, #statusFilter').change(function() {
        applyFilters();
    });

    $('#searchButton').click(function() {
        applyFilters();
    });

    $('#dateFromFilter, #dateToFilter').change(function() {
        applyFilters();
    });

    $('#searchInput').keypress(function(e) {
        if (e.which === 13) {
            applyFilters();
        }
    });

    $('#resetFilters').click(function() {
        window.location.href = 'sms_alerts.php';
    });

    $('#refreshData').click(function() {
        location.reload();
    });

    function applyFilters() {
        const alertType = $('#alertTypeFilter').val();
        const status = $('#statusFilter').val();
        const search = $('#searchInput').val();
        const dateFrom = $('#dateFromFilter').val();
        const dateTo = $('#dateToFilter').val();
        
        let urlParams = new URLSearchParams();
        if (alertType) urlParams.set('alert_type', alertType);
        if (status) urlParams.set('status', status);
        if (search) urlParams.set('search', search);
        if (dateFrom) urlParams.set('date_from', dateFrom);
        if (dateTo) urlParams.set('date_to', dateTo);
        
        window.location.href = 'sms_alerts.php?' + urlParams.toString();
    }

    // Character count and cost calculation
    function updateCharCountAndCost() {
        const message = $('#message_content').val();
        const charCount = message.length;
        const cost = charCount > 0 ? SMS_COST_PER_MESSAGE : 0;
        
        $('#charCount').text(charCount);
        $('#messageCost').text('$' + cost.toFixed(2));
        
        if (charCount > 160) {
            $('#charCount').addClass('char-count-warning');
        } else {
            $('#charCount').removeClass('char-count-warning');
        }
        
        updateMessagePreview();
    }

    // Bulk character count
    function updateBulkCharCount() {
        const message = $('#bulk_message_content').val();
        const charCount = message.length;
        
        $('#bulkCharCount').text(charCount);
        
        if (charCount > 160) {
            $('#bulkCharCount').addClass('char-count-warning');
        } else {
            $('#bulkCharCount').removeClass('char-count-warning');
        }
    }

    // Update recipient info
    function updateRecipientInfo() {
        const selectedOption = $('#loan_id option:selected');
        if (selectedOption.val()) {
            const customerName = selectedOption.data('customer-name');
            const phoneNumber = selectedOption.data('phone-number');
            const countryCode = selectedOption.data('country-code');
            const overdueDays = selectedOption.data('overdue-days');
            
            $('#recipientInfo').html(`
                <strong>${customerName}</strong><br>
                <span class="text-muted">${countryCode} ${phoneNumber}</span>
                ${overdueDays > 0 ? `<br><span class="text-danger">${overdueDays} days overdue</span>` : ''}
            `);
        } else {
            $('#recipientInfo').text('Select a loan to see recipient information...');
        }
    }

    // Message preview
    function updateMessagePreview() {
        const message = $('#message_content').val();
        const customerName = $('#loan_id option:selected').data('customer-name') || '{customer_name}';
        const overdueDays = $('#loan_id option:selected').data('overdue-days') || '{overdue_days}';
        
        let preview = message
            .replace(/{customer_name}/g, customerName)
            .replace(/{overdue_days}/g, overdueDays)
            .replace(/{loan_amount}/g, '$1,000.00')
            .replace(/{due_date}/g, '<?= date('M j, Y') ?>')
            .replace(/{reference_number}/g, 'LN-001');
            
        $('#messagePreview').html(`
            <div class="d-flex align-items-start">
                <i class="bi bi-phone me-3 text-muted"></i>
                <div>
                    <div class="bg-primary text-white rounded p-2 mb-2" style="max-width: 250px;">
                        ${preview || 'Preview will appear here...'}
                    </div>
                    <small class="text-muted">SMS Preview • ${preview.length}/160 chars</small>
                </div>
            </div>
        `);
    }

    // Quick templates
    $('[data-template]').click(function() {
        const templateType = $(this).data('template');
        let template = '';
        
        switch(templateType) {
            case 'payment_reminder':
                template = 'Hi {customer_name}, your loan payment of {loan_amount} is due. Please make payment to avoid late fees. Ref: {reference_number}';
                break;
            case 'collection_alert':
                template = 'URGENT: Loan {reference_number} is {overdue_days} days overdue. Amount: {loan_amount}. Immediate payment required to avoid legal action.';
                break;
            case 'friendly_reminder':
                template = 'Friendly reminder {customer_name}, your payment of {loan_amount} is overdue by {overdue_days} days. Please contact us if you need assistance.';
                break;
        }
        
        $('#message_content').val(template);
        updateCharCountAndCost();
    });

    // Event listeners
    $('#message_content').on('input', updateCharCountAndCost);
    $('#loan_id').change(updateRecipientInfo);
    $('#loan_id').change(updateMessagePreview);
    $('#bulk_message_content').on('input', updateBulkCharCount);

    // Add SMS Form
    $('#addSMSForm').submit(function(e) {
        e.preventDefault();
        
        const message = $('#message_content').val();
        if (message.length > 160) {
            showNotification('SMS message cannot exceed 160 characters.', 'error');
            return;
        }
        
        const formData = $(this).serialize();
        
        $.post('ajax/add_sms_alert.php', formData, function(response) {
            if (response.success) {
                logReportAction('Created SMS Alert', 'User scheduled a new SMS for loan ID: ' + $('#loan_id').val() + ' (Alert Type: ' + $('#alert_type').val() + ')');
                $('#addSMSModal').modal('hide');
                showNotification('SMS alert scheduled successfully!');
                setTimeout(() => location.reload(), 1500);
            } else {
                showNotification('Error: ' + response.message, 'error');
            }
        }, 'json').fail(function() {
            showNotification('Error: Failed to schedule SMS. Please try again.', 'error');
        });
    });

    // Bulk SMS Form
    $('#bulkSMSForm').submit(function(e) {
        e.preventDefault();
        
        const message = $('#bulk_message_content').val();
        if (message.length > 160) {
            showNotification('SMS message cannot exceed 160 characters.', 'error');
            return;
        }
        
        const formData = $(this).serialize();
        
        $.post('ajax/add_bulk_sms.php', formData, function(response) {
            if (response.success) {
                logReportAction('Created Bulk SMS', 'User scheduled ' + response.created_count + ' bulk SMS alerts (Type: ' + $('#bulk_alert_type').val() + ')');
                $('#bulkSMSModal').modal('hide');
                showNotification(`Successfully created ${response.created_count} bulk SMS alerts!`);
                setTimeout(() => location.reload(), 1500);
            } else {
                showNotification('Error: ' + response.message, 'error');
            }
        }, 'json').fail(function() {
            showNotification('Error: Failed to create bulk SMS. Please try again.', 'error');
        });
    });

    // View SMS Details
    $(document).on('click', '.view-sms', function(e) {
        e.preventDefault();
        const smsId = $(this).data('sms-id');
        
        $('#smsDetailsContent').html(`
            <div class="text-center py-4">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="mt-2">Loading SMS details...</p>
            </div>
        `);
        
        $('#smsDetailsModal').modal('show');
        
        $.get('ajax/get_sms_details.php?id=' + smsId, function(response) {
            if (response.success) {
                logReportAction('Viewed SMS Details', 'User viewed full details for SMS ID: ' + smsId);
                $('#smsDetailsContent').html(response.html);
            } else {
                $('#smsDetailsContent').html(`
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        Error loading SMS details: ${response.message}
                    </div>
                `);
            }
        }, 'json').fail(function() {
            $('#smsDetailsContent').html(`
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    Failed to load SMS details. Please try again.
                </div>
            `);
        });
    });

    // Send Now
    $(document).on('click', '.send-now-sms', function(e) {
        e.preventDefault();
        const smsId = $(this).data('sms-id');
        
        if (confirm('Are you sure you want to send this SMS now?')) {
            $.post('ajax/send_sms_now.php', { sms_id: smsId }, function(response) {
                if (response.success) {
                    logReportAction('Sent SMS Now', 'User triggered immediate send for SMS ID: ' + smsId);
                    showNotification('SMS sent successfully!');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showNotification('Error: ' + response.message, 'error');
                }
            }, 'json').fail(function() {
                showNotification('Error: Failed to send SMS. Please try again.', 'error');
            });
        }
    });

    // Cancel SMS
    $(document).on('click', '.cancel-sms', function(e) {
        e.preventDefault();
        const smsId = $(this).data('sms-id');
        
        if (confirm('Are you sure you want to cancel this SMS?')) {
            $.post('ajax/cancel_sms.php', { sms_id: smsId }, function(response) {
                if (response.success) {
                    logReportAction('Cancelled SMS', 'User cancelled scheduled SMS ID: ' + smsId);
                    showNotification('SMS cancelled successfully!');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showNotification('Error: ' + response.message, 'error');
                }
            }, 'json').fail(function() {
                showNotification('Error: Failed to cancel SMS. Please try again.', 'error');
            });
        }
    });

    // Resend SMS
    $(document).on('click', '.resend-sms', function(e) {
        e.preventDefault();
        const smsId = $(this).data('sms-id');
        
        if (confirm('Are you sure you want to resend this SMS?')) {
            $.post('ajax/resend_sms.php', { sms_id: smsId }, function(response) {
                if (response.success) {
                    logReportAction('Resent SMS', 'User triggered resend for sent SMS ID: ' + smsId);
                    showNotification('SMS resent successfully!');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showNotification('Error: ' + response.message, 'error');
                }
            }, 'json').fail(function() {
                showNotification('Error: Failed to resend SMS. Please try again.', 'error');
            });
        }
    });

    // Quick Actions
    $('#sendScheduledSMS').click(function() {
        $.post('ajax/send_scheduled_sms.php', function(response) {
            if (response.success) {
                logReportAction('Triggered Scheduled SMS', 'User manually triggered processing of ' + response.sent_count + ' scheduled SMS alerts');
                showNotification(`Sent ${response.sent_count} scheduled SMS alerts!`);
                setTimeout(() => location.reload(), 2000);
            } else {
                showNotification('Error: ' + response.message, 'error');
            }
        }, 'json');
    });

    $('#generateOverdueSMS').click(function() {
        $.post('ajax/generate_overdue_sms.php', function(response) {
            if (response.success) {
                logReportAction('Generated Overdue SMS', 'User manually triggered generation of ' + response.generated_count + ' overdue SMS alerts');
                showNotification(`Generated ${response.generated_count} overdue SMS alerts!`);
                setTimeout(() => location.reload(), 2000);
            } else {
                showNotification('Error: ' + response.message, 'error');
            }
        }, 'json');
    });

    $('#testSMSGateway').click(function() {
        $.post('ajax/test_sms_gateway.php', function(response) {
            if (response.success) {
                logReportAction('Tested SMS Gateway', 'User successfully tested the SMS gateway connection');
                showNotification('SMS gateway test completed successfully!', 'info');
            } else {
                showNotification('Test failed: ' + response.message, 'error');
            }
        }, 'json');
    });

    $('#viewSMSTemplates').click(function() {
        window.open('sms_templates.php', '_blank');
    });

    $('#topUpCredits').click(function() {
        showNotification('Credit top-up functionality will be implemented in the next phase.', 'info');
    });

    // Export functionality
    $('#exportSMS').click(function() {
        const alertType = $('#alertTypeFilter').val();
        const status = $('#statusFilter').val();
        const search = $('#searchInput').val();
        const dateFrom = $('#dateFromFilter').val();
        const dateTo = $('#dateToFilter').val();
        
        let urlParams = new URLSearchParams();
        if (alertType) urlParams.set('alert_type', alertType);
        if (status) urlParams.set('status', status);
        if (search) urlParams.set('search', search);
        if (dateFrom) urlParams.set('date_from', dateFrom);
        if (dateTo) urlParams.set('date_to', dateTo);
        urlParams.set('export', '1');
        
        window.open('ajax/export_sms.php?' + urlParams.toString(), '_blank');
    });

    // Bulk SMS impact calculation
    function calculateBulkImpact() {
        const minDays = $('#bulk_overdue_days_min').val();
        const maxDays = $('#bulk_overdue_days_max').val();
        const loanStatus = $('#bulk_loan_status').val();
        const message = $('#bulk_message_content').val();
        
        if (minDays || maxDays || loanStatus) {
            $.get('ajax/get_bulk_sms_impact.php', {
                overdue_days_min: minDays,
                overdue_days_max: maxDays,
                loan_status: loanStatus
            }, function(response) {
                if (response.success) {
                    const totalCost = response.affected_loans * SMS_COST_PER_MESSAGE;
                    $('#bulkImpactSummary').html(`
                        <strong>${response.affected_loans}</strong> loans will receive SMS<br>
                        <strong>${response.eligible_customers}</strong> customers eligible<br>
                        <strong>$${totalCost.toFixed(2)}</strong> estimated cost<br>
                        <small class="text-muted">Based on current criteria</small>
                    `);
                }
            }, 'json');
        } else {
            $('#bulkImpactSummary').text('Select criteria to see estimated impact...');
        }
    }

    $('#bulk_overdue_days_min, #bulk_overdue_days_max, #bulk_loan_status').change(calculateBulkImpact);

    // Edit SMS (placeholder)
    $(document).on('click', '.edit-sms', function(e) {
        e.preventDefault();
        const smsId = $(this).data('sms-id');
        showNotification('Edit functionality will be implemented in the next phase.', 'info');
    });

    // Initialize when page loads
    initializeSMSChart();
    updateCharCountAndCost();
    updateBulkCharCount();
});
</script>

<?php require_once 'footer.php'; ?>