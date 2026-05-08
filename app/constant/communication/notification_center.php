<?php
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('notification_center');
includeHeader();

// Get current user ID
$user_id = $_SESSION['user_id'];

// Get notification types for filtering
$types_stmt = $pdo->query("SELECT DISTINCT type FROM notifications ORDER BY type");
$notification_types = $types_stmt->fetchAll(PDO::FETCH_COLUMN);

// Get user notification preferences
$preferences = [];
$stmt = $pdo->prepare("SELECT notification_preferences FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user_data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!empty($user_data['notification_preferences'])) {
    $preferences = json_decode($user_data['notification_preferences'], true);
} else {
    // Default preferences
    $preferences = [
        'email_notifications' => true,
        'push_notifications' => true,
        'sms_notifications' => false,
        'loan_alerts' => true,
        'payment_alerts' => true,
        'system_alerts' => true,
        'report_alerts' => false,
        'quiet_hours_enabled' => false,
        'quiet_hours_start' => '22:00',
        'quiet_hours_end' => '07:00'
    ];
}
?>

<div class="container-fluid mt-4">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-bell"></i> Notification Center</h2>
                    <p class="text-muted mb-0">Manage your alerts and notification preferences</p>
                </div>
                <div>
                    <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#preferencesModal">
                        <i class="bi bi-gear"></i> Settings
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card custom-stat-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0" id="total-notifications">0</h4>
                            <p class="mb-0 small uppercase font-weight-bold">Total Notifications</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-bell" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card custom-stat-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0" id="unread-count">0</h4>
                            <p class="mb-0 small uppercase font-weight-bold">Unread Alerts</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-envelope-exclamation" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card custom-stat-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0" id="high-priority-unread">0</h4>
                            <p class="mb-0 small uppercase font-weight-bold">High Priority</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-exclamation-triangle" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card custom-stat-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0" id="today-count">0</h4>
                            <p class="mb-0 small uppercase font-weight-bold">Today's Alerts</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-calendar-check" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Notifications List -->
        <div class="col-lg-9">
            <div class="card shadow-sm mb-4">
                <div class="card-header custom-table-header bg-light d-flex justify-content-between align-items-center py-3">
                    <h5 class="mb-0"><i class="bi bi-list-ul"></i> Activity Log</h5>
                    <div class="btn-group">
                        <button type="button" class="btn btn-success btn-sm" onclick="bulkAction('mark_all_read')">
                            <i class="bi bi-check-all"></i> Mark All Read
                        </button>
                        <button type="button" class="btn btn-outline-danger btn-sm" onclick="bulkAction('clear_all_read')">
                            <i class="bi bi-trash"></i> Clear Read
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="notificationsTable" class="table table-hover align-middle" style="width:100%">
                            <thead class="bg-light text-muted small uppercase">
                                <tr>
                                    <th>S/NO</th>
                                    <th>Content</th>
                                    <th>Type</th>
                                    <th>Priority</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="small">
                                <!-- Loaded via AJAX -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar - Filters -->
        <div class="col-lg-3">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-light py-3">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-funnel"></i> Filter Alerts</h6>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label small text-muted">Notification Type</label>
                        <select class="form-select form-select-sm filter-input" id="filter_type">
                            <option value="">All Types</option>
                            <?php foreach ($notification_types as $type): ?>
                                <option value="<?= htmlspecialchars($type) ?>"><?= ucfirst($type) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label small text-muted">Priority</label>
                        <select class="form-select form-select-sm filter-input" id="filter_priority">
                            <option value="">All Priorities</option>
                            <option value="low">Low</option>
                            <option value="medium">Medium</option>
                            <option value="high">High</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label small text-muted">Status</label>
                        <select class="form-select form-select-sm filter-input" id="filter_status">
                            <option value="">All Status</option>
                            <option value="0">Unread Only</option>
                            <option value="1">Read Only</option>
                        </select>
                    </div>
                    
                    <div class="d-grid gap-2">
                        <button type="button" class="btn btn-primary btn-sm" onclick="refreshTable()">
                            Apply Filters
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="resetFilters()">
                            Reset
                        </button>
                    </div>
                </div>
            </div>

            <!-- Stats Chart -->
            <div class="card shadow-sm">
                <div class="card-header bg-light py-3">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-graph-up"></i> Overview</h6>
                </div>
                <div class="card-body">
                    <div style="height: 200px;">
                        <canvas id="notificationChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Preferences Modal -->
<div class="modal fade" id="preferencesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Notification Settings</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="preferencesForm">
                <div class="modal-body">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <h6 class="border-bottom pb-2">Channels</h6>
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="email_notifications" id="pref_email" <?= ($preferences['email_notifications'] ?? false) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="pref_email">Email Alerts</label>
                            </div>
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="push_notifications" id="pref_push" <?= ($preferences['push_notifications'] ?? false) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="pref_push">Browser Push</label>
                            </div>
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="sms_notifications" id="pref_sms" <?= ($preferences['sms_notifications'] ?? false) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="pref_sms">SMS Notifications</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <h6 class="border-bottom pb-2">Alert Types</h6>
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="loan_alerts" id="pref_loans" <?= ($preferences['loan_alerts'] ?? false) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="pref_loans">Loan Updates</label>
                            </div>
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="payment_alerts" id="pref_payments" <?= ($preferences['payment_alerts'] ?? false) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="pref_payments">Payment Confirmations</label>
                            </div>
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="system_alerts" id="pref_system" <?= ($preferences['system_alerts'] ?? false) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="pref_system">System Maintenance</label>
                            </div>
                        </div>
                        <div class="col-12 mt-4">
                            <h6 class="border-bottom pb-2">Quiet Hours</h6>
                            <div class="row align-items-center">
                                <div class="col-md-4">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="quiet_hours_enabled" id="pref_quiet" <?= ($preferences['quiet_hours_enabled'] ?? false) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="pref_quiet">Enable Quiet Period</label>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <input type="time" name="quiet_hours_start" class="form-control form-control-sm" value="<?= $preferences['quiet_hours_start'] ?? '22:00' ?>">
                                </div>
                                <div class="col-md-4">
                                    <input type="time" name="quiet_hours_end" class="form-control form-control-sm" value="<?= $preferences['quiet_hours_end'] ?? '07:00' ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.custom-stat-card {
    background-color: #d1e7dd !important;
    border-color: #badbcc !important;
    border-radius: 12px !important;
    box-shadow: 0 4px 6px rgba(0,0,0,0.05);
    transition: all 0.2s ease-in-out;
}
.custom-stat-card:hover { transform: translateY(-5px); box-shadow: 0 8px 15px rgba(0,0,0,0.1); }
.custom-stat-card h4, .custom-stat-card p, .custom-stat-card i {
    color: black !important;
    text-shadow: 1px 1px 3px rgba(255, 255, 255, 0.8);
}
.custom-table-header { border-bottom: 2px solid #e9ecef; }
.notification-unread { border-left: 3px solid #0d6efd !important; background-color: rgba(13, 110, 253, 0.02); }
.badge-subtle { border: 1px solid currentColor; }
</style>

<!-- Chart.js -->
<script src="/assets/js/chart.js"></script>

<script>
let notificationChart = null;

$(document).ready(function() {
    logReportAction('Viewed Notification Center', 'User viewed their system notifications and activity log');
    const table = $('#notificationsTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: '/api/get_notifications.php',
            data: function(d) {
                d.type = $('#filter_type').val();
                d.priority = $('#filter_priority').val();
                d.is_read = $('#filter_status').val();
            },
            dataSrc: function(json) {
                if (json.stats) {
                    $('#total-notifications').text(json.stats.total_notifications);
                    $('#unread-count').text(json.stats.unread_count);
                    $('#high-priority-unread').text(json.stats.high_priority_unread);
                    $('#today-count').text(json.stats.today_count);
                    updateChart(json.stats);
                }
                return json.data;
            }
        },
        columns: [
            { 
                data: null, 
                render: (data, type, row, meta) => meta.row + meta.settings._iDisplayStart + 1 
            },
            { 
                data: 'title',
                render: function(data, type, row) {
                    let related = '';
                    if (row.related_loan_id) {
                        related = `<br><small class="text-primary"><i class="bi bi-link-45deg"></i> Loan #${row.related_loan_id} ${row.customer_name ? ' - ' + row.customer_name : ''}</small>`;
                    }
                    return `<div class="${row.is_read == 0 ? 'fw-bold' : 'text-muted'}">
                                ${escapeHtml(data)}
                                <div class="small text-muted text-truncate" style="max-width: 400px;">${row.message}</div>
                                ${related}
                            </div>`;
                }
            },
            { 
                data: 'type',
                render: data => `<span class="badge bg-light text-dark border">${data}</span>`
            },
            { 
                data: 'priority',
                render: data => {
                    const colors = { high: 'danger', medium: 'warning', low: 'info' };
                    const color = colors[data] || 'secondary';
                    return `<span class="badge bg-${color}-subtle text-${color} border border-${color}-subtle px-3">${data}</span>`;
                }
            },
            { 
                data: 'is_read',
                render: data => data == 1 
                    ? '<span class="badge bg-light text-muted border px-3">Read</span>' 
                    : '<span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3">New</span>'
            },
            { 
                data: 'created_at',
                render: data => `<span class="small text-muted">${new Date(data).toLocaleString()}</span>`
            },
            {
                data: null,
                orderable: false,
                className: 'text-end',
                render: function(data, type, row) {
                    let markReadBtn = row.is_read == 0 
                        ? `<li><a class="dropdown-item text-success" href="javascript:void(0)" onclick="markRead(${row.notification_id})"><i class="bi bi-check-circle me-2"></i> Mark as Read</a></li>`
                        : '';
                    
                    let linkBtn = row.action_url 
                        ? `<li><a class="dropdown-item" href="${row.action_url}"><i class="bi bi-box-arrow-up-right me-2"></i> View Details</a></li>`
                        : '';

                    return `
                    <div class="dropdown">
                        <button class="btn btn-sm btn-light border dropdown-toggle d-flex align-items-center" type="button" data-bs-toggle="dropdown">
                            <i class="bi bi-gear"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0">
                            ${markReadBtn}
                            ${linkBtn}
                            ${(markReadBtn || linkBtn) ? '<li><hr class="dropdown-divider"></li>' : ''}
                            <li><a class="dropdown-item text-danger" href="javascript:void(0)" onclick="deleteNotification(${row.notification_id})"><i class="bi bi-trash me-2"></i> Delete</a></li>
                        </ul>
                    </div>`;
                }
            }
        ],
        order: [[4, 'desc']],
        rowCallback: function(row, data) {
            if (data.is_read == 0) {
                $(row).addClass('notification-unread');
            }
        }
    });

    // Handle Preferences Form
    $('#preferencesForm').on('submit', function(e) {
        e.preventDefault();
        $.post('/api/save_notification_preferences.php', $(this).serialize(), function(res) {
            if (res.success) {
                logReportAction('Updated Notification Preferences', 'User successfully updated their alert and channel settings');
                $('#preferencesModal').modal('hide');
                showToast('success', res.message);
            } else {
                alert(res.message);
            }
        });
    });

    initChart();
});

function refreshTable() {
    $('#notificationsTable').DataTable().ajax.reload();
}

function resetFilters() {
    logReportAction('Reset Notification Filters', 'User cleared all activity log search filters');
    $('.filter-input').val('');
    refreshTable();
}

function markRead(id) {
    $.post('/api/mark_notification_read.php', { notification_id: id }, function(res) {
        if (res.success) {
            logReportAction('Marked Notification Read', 'User marked notification ID: ' + id + ' as read');
            refreshTable();
        }
    });
}

function deleteNotification(id) {
    Swal.fire({
        title: 'Confirm Delete',
        text: "Are you sure you want to delete this notification?",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, Delete!',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('/api/delete_notification.php', { notification_id: id }, function(res) {
                if (res.success) {
                    logReportAction('Deleted Notification', 'User deleted notification ID: ' + id);
                    refreshTable();
                    Swal.fire({
                        icon: 'success',
                        title: 'Deleted!',
                        text: 'Notification has been successfully removed from your list.',
                        timer: 3000,
                        showConfirmButton: true,
                        confirmButtonText: 'OK',
                        confirmButtonColor: '#28a745'
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: res.message || 'Could not be deleted',
                        confirmButtonColor: '#6c757d'
                    });
                }
            });
        }
    });
}

function bulkAction(action) {
    const isMarkRead = action === 'mark_all_read';
    const title = isMarkRead ? 'Mark All as Read?' : 'Clear All Read?';
    const text = isMarkRead ? 'Do you want to mark all notifications as read?' : 'Do you want to delete all read notifications?';
    const confirmBtnText = isMarkRead ? 'Yes, Mark All' : 'Yes, Delete All';
    const icon = isMarkRead ? 'question' : 'warning';
    
    Swal.fire({
        title: title,
        text: text,
        icon: icon,
        showCancelButton: true,
        confirmButtonColor: isMarkRead ? '#28a745' : '#d33',
        cancelButtonColor: '#6c757d',
        confirmButtonText: confirmBtnText,
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('/api/notification_bulk_actions.php', { action: action }, function(res) {
                if (res.success) {
                    logReportAction('Bulk Notification Action', 'User performed ' + action.replace(/_/g, ' ') + ' on notifications');
                    refreshTable();
                    
                    const successTitle = isMarkRead ? 'All Marked Read!' : 'All Read Deleted!';
                    const successMsg = isMarkRead ? 'All notifications have been successfully marked as read.' : 'All read notifications have been permanently removed.';
                    
                    Swal.fire({
                        icon: 'success',
                        title: successTitle,
                        text: successMsg,
                        showConfirmButton: true,
                        confirmButtonText: 'OK',
                        confirmButtonColor: '#28a745'
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: res.message || 'An error occurred',
                        confirmButtonColor: '#6c757d'
                    });
                }
            });
        }
    });
}

function initChart() {
    const ctx = document.getElementById('notificationChart').getContext('2d');
    notificationChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Read', 'Unread'],
            datasets: [{
                data: [0, 0],
                backgroundColor: ['#1cc88a', '#4e73df'],
                hoverBackgroundColor: ['#17a673', '#2e59d9'],
                hoverBorderColor: "rgba(234, 236, 244, 1)",
            }],
        },
        options: {
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 12, padding: 15 } }
            },
            cutout: '70%',
        }
    });
}

function updateChart(stats) {
    if (!notificationChart) return;
    const read = stats.total_notifications - stats.unread_count;
    notificationChart.data.datasets[0].data = [read, stats.unread_count];
    notificationChart.update();
}

function escapeHtml(text) {
    return text ? $('<div>').text(text).html() : '';
}

function showToast(type, message) {
    Swal.fire({
        title: type === 'success' ? 'Success!' : (type === 'error' ? 'Error!' : 'Information'),
        text: message,
        icon: type,
        timer: 3000,
        showConfirmButton: true,
        confirmButtonText: 'OK',
        confirmButtonColor: type === 'success' ? '#28a745' : '#6c757d'
    });
}
</script>

<?php include 'footer.php'; ?>
