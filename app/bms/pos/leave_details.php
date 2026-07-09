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
        e.project_id AS employee_project_id,
        d.department_name,
        u1.username as applied_by_name,
        u2.username as approved_by_name,
        lt.type_name AS official_type,
        lt.requires_document,
        lt.max_days_per_year,
        lt.is_paid AS type_is_paid,
        lt.color as type_color,
        CONCAT_WS(' ', h.first_name, h.last_name) AS handover_name,
        h.employee_number AS handover_number
    FROM leaves l
    LEFT JOIN employees e ON l.employee_id = e.employee_id
    LEFT JOIN departments d ON e.department_id = d.department_id
    LEFT JOIN users u1 ON l.applied_by = u1.user_id
    LEFT JOIN users u2 ON l.approved_by = u2.user_id
    -- Joined on the FK. The old condition was `l.leave_type = lt.type_name`,
    -- comparing the ENUM 'annual' to 'Annual Leave' — it never matched, so the
    -- type's rules were never available on this page.
    LEFT JOIN leave_types lt ON lt.type_id = l.leave_type_id
    LEFT JOIN employees h ON h.employee_id = l.handover_to
    WHERE l.leave_id = ?
");
$stmt->execute([$leave_id]);
$leave = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$leave) {
    header("Location: leaves.php?error=Leave Application Not Found");
    exit();
}

// Phase D — project-scope gate
$leave_project_id = $leave['employee_project_id'] ?? null;
if (!empty($leave_project_id) && function_exists('userCan') && !userCan('project', (int)$leave_project_id)) {
    header("Location: leaves.php?error=Access+denied:+this+leave+is+not+in+your+project+scope");
    exit();
}

// Company identity for the print header (same source as leaves.php).
$c_name = getSetting('company_name', 'BMS');

$page_title = "Leave Details - LEV-" . $leave['leave_id'];
require_once 'header.php';
?>

<style>
    :root {
        /* Blue scale per .claude/ui-constants.md §UI-1 — primary is #0d6efd. */
        --primary-gradient: linear-gradient(45deg, #0d6efd, #0a58ca);
        --glass-bg: rgba(255, 255, 255, 0.95);
        --card-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
        --border-radius: 24px;
    }

    .leave-dashboard {
        background: #fff;   /* §UI-1: page background is white */
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
        background: #e7f0ff;   /* §UI-1: stat card background */
        padding: 1.5rem;
        border-radius: 20px;
        box-shadow: 0 8px 20px rgba(0,0,0,0.05);
        border: 1px solid #b6ccfe;
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
        background: #e7f0ff !important;
        color: #0a58ca !important;
        border: 1px solid #b6ccfe;
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
        border-left: 6px solid #0d6efd;
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

    /* ── Print: same shape as the other print views ── */
    @page { size: auto; margin: 15mm; }
    @media print {
        * {
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        body { background: #fff !important; padding: 0 !important; margin: 0 !important; }

        .navbar, .sidebar, .btn, .btn-group, .dropdown, .modal, footer { display: none !important; }

        .leave-dashboard {
            background: #fff !important;
            padding: 0 !important;
            /* Screen-only hero sizing — min-height:100vh reserved a full blank
               page before any content, pushing the whole card to page 2. */
            min-height: 0 !important;
            margin: 0 !important;
        }
        .premium-card {
            box-shadow: none !important;
            border: 1px solid #dee2e6 !important;
            border-radius: 0 !important;
            page-break-inside: avoid;
        }
        .detail-header { background: #fff !important; color: #000 !important; }
        .stat-mini-card { border: 1px solid #dee2e6 !important; box-shadow: none !important; }
        /* The -3rem overlap onto the header is a screen-only hero effect; with
           .leave-dashboard's own offset gone above, keeping it would pull the
           stat cards up over the header text instead. */
        .stats-container { margin-top: 1rem !important; }
    }
</style>
<?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>

<div class="leave-dashboard">
    <div class="container-fluid">

        <!-- Print-only header (mirrors leaves.php) -->
        <div class="d-none d-print-block text-center mb-1" id="printHeader" style="margin-top: 15px !important;">
            <h4 style="color:#333;font-weight:700;margin:2px 0;font-size:12pt;letter-spacing:1px;"><?= safe_output($c_name) ?></h4>
            <h2 style="color:#333;font-weight:700;text-transform:uppercase;margin:2px 0;font-size:15pt;letter-spacing:2px;">LEAVE APPLICATION</h2>
            <p class="text-muted mb-1" style="font-size:9pt;">Reference: <span class="fw-bold text-dark">LEV-<?= (int)$leave['leave_id'] ?></span></p>
            <div style="border-bottom:3px solid #0d6efd;width:100px;margin:10px auto;"></div>
        </div>

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
                        <i class="bi bi-calendar-range me-2 text-primary"></i>
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
                        <h4 class="mb-0 fw-bold"><?= safe_output($leave['official_type'] ?? '', '—') ?></h4>
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

                <!-- Leave terms — every field captured on the application form -->
                <div class="row g-4 mb-5">
                    <div class="col-md-6 col-lg-3">
                        <div class="info-label">Payment Treatment</div>
                        <div class="info-value">
                            <?php
                            // The leave's OWN snapshot, not the type's current setting: a
                            // type re-classified later must not rewrite this record.
                            $paid = $leave['is_paid'];
                            if ($paid === null) {
                                echo '<span class="text-muted">—</span>';
                            } else {
                                $isPaid = (int)$paid === 1;
                                echo '<span class="badge" style="background:' . ($isPaid ? '#0d6efd' : '#6c757d') . ';color:#fff;">'
                                   . ($isPaid ? 'Paid Leave' : 'Unpaid Leave') . '</span>';
                            }
                            ?>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <div class="info-label">Half Day</div>
                        <div class="info-value">
                            <?php
                            switch ($leave['half_day'] ?? 'none') {
                                case 'first_half':  echo 'First Half'; break;
                                case 'second_half': echo 'Second Half'; break;
                                case 'other':
                                    echo safe_output(rtrim(rtrim(number_format((float)$leave['leave_hours'], 2), '0'), '.')) . ' hour(s)';
                                    break;
                                default: echo '<span class="text-muted">No</span>';
                            }
                            ?>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <div class="info-label">Entitlement (this type)</div>
                        <div class="info-value">
                            <?= $leave['max_days_per_year'] !== null
                                ? (int)$leave['max_days_per_year'] . ' days/year'
                                : '<span class="text-muted">—</span>' ?>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <div class="info-label">Supporting Document</div>
                        <div class="info-value">
                            <?php if (!empty($leave['document_path'])): ?>
                                <a href="<?= getUrl($leave['document_path']) ?>" target="_blank" rel="noopener" class="d-print-none">
                                    <i class="bi bi-paperclip me-1"></i>View document
                                </a>
                                <span class="d-none d-print-inline">Attached</span>
                            <?php elseif ((int)($leave['requires_document'] ?? 0) === 1): ?>
                                <span class="text-danger">Required — not attached</span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <div class="info-label">Contact During Leave</div>
                        <div class="info-value"><?= safe_output($leave['contact_during_leave'] ?? '', '—') ?></div>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <div class="info-label">Handover To</div>
                        <div class="info-value">
                            <?php if (!empty($leave['handover_name'])): ?>
                                <?= safe_output($leave['handover_name']) ?>
                                <div class="text-muted small"><?= safe_output($leave['handover_number'] ?? '') ?></div>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Reason Section -->
                <div class="mb-5">
                    <h5 class="section-title">Reason &amp; Justification</h5>
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
                                <i class="bi bi-shield-check text-primary fs-4 me-3"></i>
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
                <div class="d-flex flex-wrap gap-3 justify-content-between align-items-center border-top pt-4 d-print-none">
                    <div>
                        <a href="leaves.php" class="btn btn-outline-secondary btn-premium">
                            <i class="bi bi-arrow-left"></i> Back to List
                        </a>
                    </div>
                    <div class="d-flex gap-2">
                        <button onclick="printExport(<?= $leave['leave_id'] ?>)" class="btn btn-outline-primary btn-premium">
                            <i class="bi bi-printer"></i> PRINT
                        </button>
                        
                        <?php if($leave['status'] == 'pending'): ?>
                            <button onclick="handleAction('approve', <?= $leave['leave_id'] ?>)" class="btn btn-primary btn-premium">
                                <i class="bi bi-check2-circle"></i> Approve Leave
                            </button>
                            <button onclick="handleAction('reject', <?= $leave['leave_id'] ?>)" class="btn btn-danger btn-premium">
                                <i class="bi bi-x-circle"></i> Reject Application
                            </button>
                        <?php elseif($leave['status'] == 'approved'): ?>
                            <!-- Only show cancel if the leave hasn't started yet or is ongoing -->
                            <button onclick="handleAction('cancel', <?= $leave['leave_id'] ?>)" class="btn btn-secondary btn-premium">
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
    // Print THIS page, so the printout matches the record on screen and uses the
    // same shared print header/footer as every other print view.
    window.print();
}

function handleAction(action, id) {
    let title = 'Are you sure?';
    let text = `Do you want to ${action} this leave application?`;
    let icon = 'question';
    let confirmBtnColor = '#0d6efd';

    if(action === 'reject') {
        icon = 'warning';
        confirmBtnColor = '#dc3545';   // reject/void stays red per §UI-1
    } else if(action === 'approve') {
        confirmBtnColor = '#0d6efd';
    } else if(action === 'cancel') {
        confirmBtnColor = '#6c757d';
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

<?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>
<?php includeFooter(); ?>
