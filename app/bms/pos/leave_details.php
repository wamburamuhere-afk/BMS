<?php
// File: app/bms/pos/leave_details.php
require_once __DIR__ . '/../../../roots.php';

// Check permissions
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

// Phase 5b — enforce view permission on leave detail
autoEnforcePermission('leaves');

// Get Leave ID
$leave_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($leave_id <= 0) {
    header("Location: leaves.php?error=Invalid Leave ID");
    exit();
}

// Fetch Leave Details
global $pdo;
$stmt = $pdo->prepare("
    SELECT 
        l.*,
        e.first_name,
        e.last_name,
        e.employee_number,
        e.department_id,
        d.department_name,
        u1.username as applied_by_name,
        u2.username as approved_by_name,
        lt.requires_document,
        lt.color as type_color
    FROM leaves l
    LEFT JOIN employees e ON l.employee_id = e.employee_id
    LEFT JOIN departments d ON e.department_id = d.department_id
    LEFT JOIN users u1 ON l.applied_by = u1.user_id
    LEFT JOIN users u2 ON l.approved_by = u2.user_id
    LEFT JOIN leave_types lt ON l.leave_type = lt.type_name
    WHERE l.leave_id = ?
");
$stmt->execute([$leave_id]);
$leave = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$leave) {
    header("Location: leaves.php?error=Leave Application Not Found");
    exit();
}

$page_title = "Leave Details - LEV-" . $leave['leave_id'];
require_once 'header.php';
?>

<style>
    :root {
        --primary-gradient: linear-gradient(45deg, #198754, #157347);
        --glass-bg: rgba(255, 255, 255, 0.95);
        --card-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
        --border-radius: 24px;
    }

    .leave-dashboard {
        background: #d1e7dd;
        min-height: 100vh;
        margin: -1.5rem -1.5rem 0; /* Negate container padding */
        padding: 2rem;
    }

    .premium-card {
        background: white;
        border: none;
        border-radius: var(--border-radius);
        box-shadow: var(--card-shadow);
        overflow: hidden;
    }

    .detail-header {
        background: #ffffff;
        color: #1e293b;
        padding: 4rem 3rem;
        position: relative;
        overflow: hidden;
        border-bottom: 1px solid #e2e8f0;
    }

    .detail-header::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -10%;
        width: 400px;
        height: 400px;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 50%;
        filter: blur(40px);
    }

    .status-badge-lg {
        padding: 10px 24px;
        border-radius: 50px;
        font-weight: 700;
        text-transform: uppercase;
        font-size: 0.85rem;
        letter-spacing: 1px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }

    .stats-container {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1.5rem;
        margin-top: -3rem;
        padding: 0 3rem;
        position: relative;
        z-index: 2;
    }

    .stat-mini-card {
        background: #d1e7dd;
        padding: 1.5rem;
        border-radius: 20px;
        box-shadow: 0 8px 20px rgba(0,0,0,0.05);
        border: 1px solid rgba(0,0,0,0.02);
        display: flex;
        align-items: center;
        transition: transform 0.3s ease;
    }

    .stat-mini-card:hover { transform: translateY(-5px); }

    .stat-icon {
        width: 48px;
        height: 48px;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        margin-right: 1.25rem;
        background: #d1e7dd !important;
        color: #157347 !important;
        border: 1px solid #157347;
    }

    .info-section {
        padding: 3rem;
    }

    .section-title {
        font-size: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 1.5px;
        color: #64748b;
        font-weight: 800;
        margin-bottom: 1.5rem;
        display: flex;
        align-items: center;
    }

    .section-title::after {
        content: '';
        flex-grow: 1;
        height: 1px;
        background: #e2e8f0;
        margin-left: 1.5rem;
    }

    .info-label {
        font-size: 0.75rem;
        color: #94a3b8;
        text-transform: uppercase;
        font-weight: 700;
        margin-bottom: 0.25rem;
    }

    .info-value {
        color: #1e293b;
        font-weight: 600;
        font-size: 1.1rem;
    }

    .reason-box {
        background: #ffffff;
        padding: 2rem;
        border-radius: 18px;
        border-left: 6px solid #198754;
        font-size: 1.05rem;
        color: #334155;
        line-height: 1.6;
    }

    .audit-trail {
        background: #ffffff;
        border-radius: 18px;
        padding: 1.5rem;
        border: 1px dashed #e2e8f0;
    }

    .btn-premium {
        padding: 12px 28px;
        border-radius: 14px;
        font-weight: 700;
        transition: all 0.3s;
        border: none;
        display: inline-flex;
        align-items: center;
        gap: 0.75rem;
    }

    .btn-premium:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 20px rgba(0,0,0,0.1);
    }
</style>

<div class="leave-dashboard">
    <div class="container-fluid">
        <!-- Main Card -->
        <div class="premium-card">
            <!-- Header -->
            <div class="detail-header text-center">
                <div class="mb-3">
                    <?php
                    $status_cls = 'bg-secondary';
                    if($leave['status'] == 'approved') $status_cls = 'bg-success';
                    if($leave['status'] == 'pending') $status_cls = 'bg-warning text-dark';
                    if($leave['status'] == 'rejected') $status_cls = 'bg-danger';
                    ?>
                    <span class="status-badge-lg <?= $status_cls ?>">
                        <?= $leave['status'] ?>
                    </span>
                </div>
                <h1 class="display-5 fw-bold mb-1">LEV-<?= $leave['leave_id'] ?></h1>
                <p class="lead opacity-75 mb-0">Leave Application Details</p>
                <div class="mt-4">
                    <span class="badge bg-light text-dark py-2 px-3 rounded-pill fw-bold border">
                        <i class="bi bi-calendar-range me-2 text-success"></i>
                        <?= date('d M Y', strtotime($leave['start_date'])) ?> — <?= date('d M Y', strtotime($leave['end_date'])) ?>
                    </span>
                </div>
            </div>

            <!-- Stats Overlay -->
            <div class="stats-container">
                <div class="stat-mini-card">
                    <div class="stat-icon">
                        <i class="bi bi-clock-history"></i>
                    </div>
                    <div>
                        <div class="info-label">Total Days</div>
                        <h4 class="mb-0 fw-bold"><?= $leave['total_days'] ?> Days</h4>
                    </div>
                </div>
                <div class="stat-mini-card">
                    <div class="stat-icon">
                        <i class="bi bi-tag"></i>
                    </div>
                    <div>
                        <div class="info-label">Leave Type</div>
                        <h4 class="mb-0 fw-bold text-capitalize"><?= $leave['leave_type'] ?></h4>
                    </div>
                </div>
                <div class="stat-mini-card">
                    <div class="stat-icon">
                        <i class="bi bi-person-badge"></i>
                    </div>
                    <div>
                        <div class="info-label">Employee ID</div>
                        <h4 class="mb-0 fw-bold"><?= $leave['employee_number'] ?></h4>
                    </div>
                </div>
            </div>

            <!-- Content Body -->
            <div class="info-section">
                <!-- Employee Info row -->
                <div class="row g-4 mb-5">
                    <div class="col-md-6 col-lg-3">
                        <div class="info-label">Employee Name</div>
                        <div class="info-value"><?= $leave['first_name'] ?> <?= $leave['last_name'] ?></div>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <div class="info-label">Department</div>
                        <div class="info-value"><?= $leave['department_name'] ?></div>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <div class="info-label">Applied Date</div>
                        <div class="info-value"><?= date('d M Y, H:i', strtotime($leave['created_at'])) ?></div>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <div class="info-label">Applied By</div>
                        <div class="info-value"><?= $leave['applied_by_name'] ?></div>
                    </div>
                </div>

                <!-- Reason Section -->
                <div class="mb-5">
                    <h5 class="section-title">Reason & Justification</h5>
                    <div class="reason-box">
                        <?= nl2br(htmlspecialchars($leave['reason'])) ?>
                    </div>
                </div>

                <!-- Audit/Notes -->
                <div class="row g-4 mb-5">
                    <?php if($leave['approved_by']): ?>
                    <div class="col-md-6">
                        <h5 class="section-title">Approval Details</h5>
                        <div class="audit-trail">
                            <div class="d-flex align-items-center mb-2">
                                <i class="bi bi-shield-check text-success fs-4 me-3"></i>
                                <div>
                                    <div class="fw-bold"><?= $leave['approved_by_name'] ?></div>
                                    <div class="text-muted small"><?= date('d M Y, H:i', strtotime($leave['updated_at'])) ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if(!empty($leave['notes'])): ?>
                    <div class="col-md-6">
                        <h5 class="section-title">Approval/Rejection Notes</h5>
                        <div class="audit-trail">
                            <i class="bi bi-chat-left-dots text-muted me-2"></i>
                            <span class="text-muted small fst-italic"><?= htmlspecialchars($leave['notes']) ?></span>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Actions -->
                <div class="d-flex flex-wrap gap-3 justify-content-between align-items-center border-top pt-4">
                    <div>
                        <a href="leaves.php" class="btn btn-outline-secondary btn-premium">
                            <i class="bi bi-arrow-left"></i> Back to List
                        </a>
                    </div>
                    <div class="d-flex gap-2">
                        <button onclick="printExport(<?= $leave['leave_id'] ?>)" class="btn btn-outline-success btn-premium">
                            <i class="bi bi-printer"></i> PRINT
                        </button>
                        
                        <?php if($leave['status'] == 'pending'): ?>
                            <button onclick="handleAction('approve', <?= $leave['leave_id'] ?>)" class="btn btn-success btn-premium">
                                <i class="bi bi-check2-circle"></i> Approve Leave
                            </button>
                            <button onclick="handleAction('reject', <?= $leave['leave_id'] ?>)" class="btn btn-danger btn-premium">
                                <i class="bi bi-x-circle"></i> Reject Application
                            </button>
                        <?php elseif($leave['status'] == 'approved'): ?>
                            <!-- Only show cancel if the leave hasn't started yet or is ongoing -->
                            <button onclick="handleAction('cancel', <?= $leave['leave_id'] ?>)" class="btn btn-warning btn-premium">
                                <i class="bi bi-slash-circle"></i> Cancel Leave
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    logReportAction('Viewed Leave Details', 'User viewed full details for leave application ID: <?= $leave_id ?>');
});

function printExport(id) {
    logReportAction('Printed Leave Application', 'User generated a printed/PDF export for leave application ID: ' + id);
    window.open(`leave_application.php?id=${id}`, '_blank');
}

function handleAction(action, id) {
    let title = 'Are you sure?';
    let text = `Do you want to ${action} this leave application?`;
    let icon = 'question';
    let confirmBtnColor = '#4f46e5';

    if(action === 'reject') {
        icon = 'warning';
        confirmBtnColor = '#dc3545';
    } else if(action === 'approve') {
        confirmBtnColor = '#198754';
    } else if(action === 'cancel') {
        confirmBtnColor = '#ffc107';
    }

    Swal.fire({
        title: title,
        text: text,
        icon: icon,
        showCancelButton: true,
        confirmButtonColor: confirmBtnColor,
        cancelButtonColor: '#64748b',
        confirmButtonText: `Yes, ${action} it!`,
        input: (action === 'reject' || action === 'cancel') ? 'textarea' : null,
        inputPlaceholder: 'Enter reason/notes here...',
        inputValidator: (value) => {
            if ((action === 'reject' || action === 'cancel') && !value) {
                return 'You must provide a reason!';
            }
        }
    }).then((result) => {
        if (result.isConfirmed) {
            const notes = result.value || '';
            processAction(action, id, notes);
        }
    });
}

function processAction(action, id, notes) {
    let apiUrl = '';
    if(action === 'approve') apiUrl = APP_URL + '/api/approve_leave';
    else if(action === 'reject') apiUrl = APP_URL + '/api/reject_leave';
    else if(action === 'cancel') apiUrl = APP_URL + '/api/cancel_leave';

    Swal.fire({
        title: 'Processing...',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });

    $.ajax({
        url: apiUrl,
        type: 'POST',
        data: { 
            leave_id: id,
            notes: notes,
            reason: notes // Support both naming conventions
        },
        dataType: 'json',
        success: function(res) {
            if(res.success) {
                logReportAction('Processed Leave Action', `User performed action "${action}" on leave application ID: ${id}`);
                Swal.fire('Success!', res.message, 'success').then(() => {
                    location.reload();
                });
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        },
        error: function() {
            Swal.fire('Error', 'Critical system error occurred during processing.', 'error');
        }
    });
}
</script>

<?php includeFooter(); ?>
