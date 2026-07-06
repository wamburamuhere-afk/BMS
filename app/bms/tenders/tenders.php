<?php
// File: app/bms/tenders/tenders.php
require_once __DIR__ . '/../../../roots.php';

// Enforce permission
autoEnforcePermission('tenders'); // Now using proper tender permission

includeHeader();

logActivity($pdo, $_SESSION['user_id'], 'View tenders', 'User viewed the tenders management list');

$can_create = isAdmin() || canCreate('tenders');

// Fetch Stats
$total_tenders = $pdo->query("SELECT COUNT(*) FROM tenders")->fetchColumn();
$pending_tenders = $pdo->query("SELECT COUNT(*) FROM tenders WHERE status = 'PENDING'")->fetchColumn();
$awarded_tenders = $pdo->query("SELECT COUNT(*) FROM tenders WHERE status = 'AWARDED'")->fetchColumn();
$submission_tenders = $pdo->query("SELECT COUNT(*) FROM tenders WHERE status = 'SUBMISSION'")->fetchColumn();
$other_tenders = $total_tenders - ($pending_tenders + $awarded_tenders + $submission_tenders);

// Attention mode — dashboard "Expiring Tenders" deep-link (?attention=1).
$attention = (isset($_GET['attention']) && $_GET['attention'] === '1');

// Helper for Workflow Actions
function get_tender_action($status, $id) {
    $status = strtoupper($status);
    switch ($status) {
        case 'PENDING':
            return ['label' => 'Approve', 'icon' => 'bi-check2-circle', 'status' => 'APPROVED', 'type' => 'status'];
        case 'APPROVED':
            return ['label' => 'Fees', 'icon' => 'bi-cash-stack', 'type' => 'modal', 'modal' => '#feeModal'];
        case 'INVITATION':
            return ['label' => 'Submit', 'icon' => 'bi-send', 'status' => 'SUBMISSION', 'type' => 'status'];
        case 'SUBMISSION':
            return ['label' => 'Opening', 'icon' => 'bi-unlock', 'type' => 'modal', 'modal' => '#openingModal'];
        case 'OPENING':
            return ['label' => 'Evaluation', 'icon' => 'bi-journal-check', 'type' => 'modal', 'modal' => '#evaluationModal'];
        case 'EVALUATION':
            return ['label' => 'Post-Qualify', 'icon' => 'bi-award', 'status' => 'POST-QUALIFICATION', 'type' => 'status'];
        case 'POST-QUALIFICATION':
            return ['label' => 'Negotiation', 'icon' => 'bi-chat-dots', 'status' => 'NEGOTIATION', 'type' => 'status'];
        case 'NEGOTIATION':
            return ['label' => 'Decision', 'icon' => 'bi-check2-circle', 'type' => 'modal', 'modal' => '#decisionModal'];
        case 'AWARDED':
            return ['label' => 'Add Award Letter', 'icon' => 'bi-file-earmark-text', 'type' => 'modal', 'modal' => '#awardModal'];
        case 'LOSS':
            return null;
        case 'END TENDER':
            return null;
        default:
            return null;
    }
}

// Log Registry View
logAudit($pdo, $_SESSION['user_id'], 'VIEW', [
    'activity_type' => 'Tender List View',
    'description' => "Viewed tenders registry list"
]);
?>

<div class="container-fluid mt-4" id="tenderReportContainer" style="overflow-x: hidden; max-width: 100%; padding-left: 12px; padding-right: 12px;">
    <!-- Professional Print Header -->
    <div class="print-header d-none d-print-block text-center mb-4">
        <?php 
        $c_name = getSetting('company_name', 'BMS');
        $c_logo = getSetting('company_logo', '');
       
        ?>
        
           
        
        <p class="text-dark mb-1 small text-uppercase text-center">
            <?php 
            $web_email = [];
            if (!empty($c_web)) $web_email[] = "Web: " . safe_output($c_web);
            if (!empty($c_email)) $web_email[] = "Email: " . safe_output($c_email);
            if (!empty($web_email)) echo implode(" | ", $web_email);
            ?>
        </p>

        <p class="text-dark mb-1 small text-uppercase text-center">
            <?php 
            $tin_vrn = [];
            if (!empty($c_tin)) $tin_vrn[] = "TIN: " . safe_output($c_tin);
            if (!empty($c_vrn)) $tin_vrn[] = "VRN: " . safe_output($c_vrn);
            if (!empty($tin_vrn)) echo implode(" | ", $tin_vrn);
            ?>
        </p>

        <div class="mt-3 text-center">
            <h2 style="color: #495057; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px;">TENDERS REGISTRY REPORT</h2>
           
            <p style="color: #444; margin: 5px 0 0; font-size: 9pt; font-weight: 600; text-transform: uppercase;">Generated At: <?= date('d M Y, h:i A') ?></p>
        </div>
        <div style="border-bottom: 3px solid #0d6efd; margin-top: 15px; margin-bottom: 25px;"></div>
    </div>

    <!-- Print Summary Cards -->
    <div class="d-none d-print-block mb-4">
        <div class="row g-2">
            <div class="col" style="flex: 1 0 0%;">
                <div style="border: 1px solid #dee2e6; padding: 10px; border-radius: 0; text-align: center;">
                    <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Total Tenders</p>
                    <h4 style="color: #333; font-weight: 800; margin: 0; font-size: 14pt;"><?= $total_tenders ?></h4>
                </div>
            </div>
            <div class="col" style="flex: 1 0 0%;">
                <div style="border: 1px solid #dee2e6; padding: 10px; border-radius: 0; text-align: center;">
                    <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Pending</p>
                    <h4 style="color: #333; font-weight: 800; margin: 0; font-size: 14pt;"><?= $pending_tenders ?></h4>
                </div>
            </div>
            <div class="col" style="flex: 1 0 0%;">
                <div style="border: 1px solid #dee2e6; padding: 10px; border-radius: 0; text-align: center;">
                    <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Awarded</p>
                    <h4 style="color: #333; font-weight: 800; margin: 0; font-size: 14pt;"><?= $awarded_tenders ?></h4>
                </div>
            </div>
            <div class="col" style="flex: 1 0 0%;">
                <div style="border: 1px solid #dee2e6; padding: 10px; border-radius: 0; text-align: center;">
                    <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Other Stages</p>
                    <h4 style="color: #333; font-weight: 800; margin: 0; font-size: 14pt;"><?= $other_tenders ?></h4>
                </div>
            </div>
        </div>
    </div>
    <div class="d-flex align-items-center justify-content-between mb-3 gap-2 d-print-none" style="flex-wrap: nowrap;">
        <div style="min-width: 0; overflow: hidden;">
            <h2 class="fw-bold mb-0 text-truncate" style="font-size: clamp(0.95rem, 3.5vw, 1.4rem);"><i class="bi bi-clipboard-check text-primary"></i> Tenders Registered List</h2>
            <p class="text-muted mb-0 small d-none d-md-block">Track and manage your tender submissions</p>
        </div>
        <a href="<?= getUrl('tender_create') ?>" class="btn btn-primary btn-sm px-3 shadow flex-shrink-0">
            <i class="bi bi-plus-circle me-1"></i>
            <span class="d-none d-md-inline">Add New Tender</span>
            <span class="d-inline d-md-none">New Tender</span>
        </a>
    </div>

    <?php if ($attention): ?>
    <div class="alert border-0 shadow-sm d-flex flex-wrap align-items-center gap-2 mb-3 d-print-none" style="background:#fff9e6; border-left:5px solid #ffc107 !important; border-radius:10px;">
        <i class="bi bi-funnel-fill fs-5 text-warning"></i>
        <div class="flex-grow-1">
            <strong>Showing only tenders that need attention</strong>
            <span class="text-muted small d-block">Submission deadline within 7 days &mdash; still open (pending / open / draft).</span>
        </div>
        <a href="<?= getUrl('tenders') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-circle me-1"></i> Show all tenders</a>
    </div>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div class="row g-2 mb-3 d-print-none">
        <div class="col-6 col-md-3">
            <div class="card custom-stat-card h-100 border-0 shadow-sm p-2">
                <div class="card-body p-0 d-flex align-items-center">
                    <div class="stats-icon text-success bg-opacity-10 bg-success p-2 rounded me-2"><i class="bi bi-clipboard-check"></i></div>
                    <div>
                        <h5 class="fw-bold mb-0 text-dark"><?= $total_tenders ?></h5>
                        <small class="text-muted text-uppercase fw-bold" style="font-size:0.65rem;">Total</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card custom-stat-card h-100 border-0 shadow-sm p-2">
                <div class="card-body p-0 d-flex align-items-center">
                    <div class="stats-icon text-warning bg-opacity-10 bg-warning p-2 rounded me-2"><i class="bi bi-hourglass-split"></i></div>
                    <div>
                        <h5 class="fw-bold mb-0 text-dark"><?= $pending_tenders ?></h5>
                        <small class="text-muted text-uppercase fw-bold" style="font-size:0.65rem;">Pending</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card custom-stat-card h-100 border-0 shadow-sm p-2">
                <div class="card-body p-0 d-flex align-items-center">
                    <div class="stats-icon text-success bg-opacity-10 bg-success p-2 rounded me-2"><i class="bi bi-award"></i></div>
                    <div>
                        <h5 class="fw-bold mb-0 text-dark"><?= $awarded_tenders ?></h5>
                        <small class="text-muted text-uppercase fw-bold" style="font-size:0.65rem;">Awarded</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card custom-stat-card h-100 border-0 shadow-sm p-2">
                <div class="card-body p-0 d-flex align-items-center">
                    <div class="stats-icon text-primary bg-opacity-10 bg-primary p-2 rounded me-2"><i class="bi bi-layers"></i></div>
                    <div>
                        <h5 class="fw-bold mb-0 text-dark"><?= $other_tenders ?></h5>
                        <small class="text-muted text-uppercase fw-bold" style="font-size:0.65rem;">Other</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters Section -->
    <div class="card border-0 shadow-sm mb-3 d-print-none">
        <div class="card-body p-3">
            <div class="row g-2">
                <div class="col-12 col-md-4">
                    <div class="input-group input-group-sm mb-1">
                        <span class="input-group-text bg-light border-end-0"><i class="bi bi-search"></i></span>
                        <input type="text" id="searchInput" class="form-control border-start-0" placeholder="Search NO, Entity...">
                    </div>
                </div>
                <div class="col-6 col-md-2">
                    <select id="statusFilter" class="form-select form-select-sm">
                        <option value="">Status</option>
                        <option value="PENDING">PENDING</option>
                        <option value="APPROVED">APPROVED</option>
                        <option value="INVITATION">INVITATION</option>
                        <option value="SUBMISSION">SUBMISSION</option>
                        <option value="OPENING">OPENING</option>
                        <option value="EVALUATION">EVALUATION</option>
                        <option value="POST-QUALIFICATION">POST-QUALIFY</option>
                        <option value="NEGOTIATION">NEGOTIATION</option>
                        <option value="AWARDED">AWARDED</option>
                        <option value="LOSS">LOSS</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <select id="categoryFilter" class="form-select form-select-sm">
                        <option value="">Category</option>
                        <option value="Goods">Goods</option>
                        <option value="Works">Works</option>
                        <option value="Consultancy">Consultancy</option>
                        <option value="Non-consultancy">Non-Consult</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <input type="date" id="dateFrom" class="form-control form-control-sm" title="Deadline From">
                </div>
                <div class="col-6 col-md-2">
                    <input type="date" id="dateTo" class="form-control form-control-sm" title="Deadline To">
                </div>
            </div>
            <div class="d-flex justify-content-end gap-2 mt-2 pt-2 border-top">
                <button type="button" class="btn btn-secondary btn-sm px-4 rounded-pill shadow-sm" id="clearBtn">
                    <i class="bi bi-arrow-counterclockwise me-1"></i> Clear
                </button>
                <button type="button" class="btn btn-primary btn-sm px-4 rounded-pill shadow-sm" id="applyBtn">
                    <i class="bi bi-filter me-1"></i> Apply
                </button>
            </div>
        </div>
    </div>

    <!-- Actions Bar -->
    <div class="d-print-none mb-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div class="d-flex align-items-center gap-2">
                <div class="btn-group shadow-sm bg-white" style="border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden;">
                    <button type="button" class="btn btn-sm btn-white fw-medium px-3 border-0 py-2" onclick="window.print()" style="background: #fff; color: #444;">
                        <i class="bi bi-printer text-primary me-1"></i> <span class="d-none d-sm-inline">Print</span>
                    </button>
                    <div style="width: 1px; background: #eee; height: 24px; margin-top: 6px;"></div>
                    <button type="button" class="btn btn-sm btn-white fw-medium px-3 border-0 py-2" onclick="exportPDF()" style="background: #fff; color: #444;">
                        <i class="bi bi-file-pdf text-danger me-1"></i> <span class="d-none d-sm-inline">PDF</span>
                    </button>
                </div>
                <div class="d-flex align-items-center bg-white shadow-sm px-2 py-1" style="border: 1px solid #dee2e6; border-radius: 8px; height: 36px;">
                    <span class="small text-muted me-1 d-none d-md-inline"><i class="bi bi-list-ol"></i></span>
                    <select id="pageLimit" class="form-select form-select-sm border-0 fw-bold p-0" style="width: 50px; box-shadow: none; background: transparent;">
                        <option value="10">10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </div>
            </div>
            <span class="badge bg-light text-success border border-success border-opacity-25 px-3 py-2 rounded-pill shadow-sm small">
                <i class="bi bi-clipboard-check me-1"></i> <span class="d-none d-sm-inline">Tenders Registry</span><span class="d-inline d-sm-none">Registry</span>
            </span>
        </div>
    </div>


    <!-- Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 text-nowrap" id="tenderTable" style="width: 100%; min-width: 800px;">
                    <thead class="bg-light d-print-table-header">
                        <tr class="text-nowrap">
                            <th class="ps-2 text-center" style="font-size:0.78rem;">S/NO</th>
                            <th style="font-size:0.82rem;">Tender NO</th>
                            <th style="font-size:0.82rem;">Procuring Entity</th>
                            <th class="d-none d-md-table-cell" style="font-size:0.82rem;">Acronym</th>
                            <th class="d-none d-md-table-cell" style="font-size:0.82rem;">Category</th>
                            <th style="font-size:0.82rem;">Deadline</th>
                            <th style="font-size:0.82rem;">Status</th>
                            <th class="text-center" style="font-size:0.82rem;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="tenderTableBody">
                        <tr>
                            <td colspan="8" class="text-center py-5">
                                <div class="spinner-border text-primary" role="status"></div>
                                <div class="mt-2 text-muted">Loading Tenders Registry...</div>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot class="d-none d-print-table-footer">
                        <!-- Slim 'Bodyguard' Row for safety -->
                        <tr style="height: 30px !important; border: 1px solid #dee2e6 !important; background: #fff !important;">
                            <td colspan="8" style="border: 1px solid #dee2e6 !important;">&nbsp;</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            
            <!-- Pagination Controls -->
            <div class="d-flex justify-content-between align-items-center p-3 bg-light border-top d-print-none">
                <div id="paginationInfo" class="text-muted small"></div>
                <nav>
                    <ul class="pagination pagination-sm mb-0" id="paginationControls"></ul>
                </nav>
            </div>
        </div>
    </div>
    <!-- Professional Fixed Print Footer - Logic from projects.php -->
    <div class="print-footer d-none d-print-block">
        <p class="mb-1 text-muted" style="font-size: 8pt;">
            This document was Printed by <span class="fw-bold text-dark"><?= ucwords(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?> - <?= ucwords($_SESSION['user_role'] ?? 'Staff') ?></span> on <span class="fw-bold text-dark"><?= date('d M, Y \a\t h:i A') ?></span>
        </p>
        <p class="mb-0 fw-bold text-primary" style="font-size: 10pt; letter-spacing: 0.5px;">
            Powered By BJP Technologies  © 2026, All Rights Reserved
        </p>
    </div>
</div>

 <!-- Modals for Workflow -->
 <div class="modal fade" id="openingModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-unlock me-2"></i>Mark as Opening - <span class="tender-no-display"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="openingForm">
                <input type="hidden" name="tender_id" class="tender-id-input">
                <input type="hidden" name="action" value="OPENING">
                <div class="modal-body p-4">

                    <div class="alert alert-info border-0 d-flex align-items-center gap-2 mb-4" style="border-radius:10px;">
                        <i class="bi bi-info-circle-fill fs-5"></i>
                        <span>The submission details recorded for this tender are displayed below. Confirming will move the tender to <strong>Opening</strong> status.</span>
                    </div>

                    <!-- Currency Type -->
                    <div class="mb-4">
                        <label class="form-label fw-bold text-muted text-uppercase small">Currency Type</label>
                        <div class="p-3 bg-light rounded-3 border d-flex align-items-center gap-3">
                            <i class="bi bi-currency-exchange fs-4 text-primary"></i>
                            <span id="open_display_currency" class="fs-5 fw-bold text-dark">&mdash;</span>
                        </div>
                    </div>

                    <!-- Tshs Block -->
                    <div id="open_tzs_block" class="d-none mb-4">
                        <div class="card border-0 rounded-3 overflow-hidden shadow-sm">
                            <div class="card-header bg-primary text-white py-2 px-3 fw-bold">
                                <i class="bi bi-cash me-2"></i>Tshs &mdash; Submission Details
                            </div>
                            <div class="card-body p-3">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold text-muted small text-uppercase">Amount</label>
                                        <div class="p-3 bg-light rounded-3 border">
                                            <span class="text-muted fw-bold me-1">Tshs</span>
                                            <span id="open_display_amount_tzs" class="fs-5 fw-bold text-dark">&mdash;</span>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold text-muted small text-uppercase">Submission Document</label>
                                        <div class="p-3 bg-light rounded-3 border">
                                            <span id="open_display_doc_tzs">&mdash;</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- USD Block -->
                    <div id="open_usd_block" class="d-none mb-2">
                        <div class="card border-0 rounded-3 overflow-hidden shadow-sm">
                            <div class="card-header bg-success text-white py-2 px-3 fw-bold">
                                <i class="bi bi-currency-dollar me-2"></i>USD &mdash; Submission Details
                            </div>
                            <div class="card-body p-3">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold text-muted small text-uppercase">Amount</label>
                                        <div class="p-3 bg-light rounded-3 border">
                                            <span class="text-muted fw-bold me-1">USD</span>
                                            <span id="open_display_amount_usd" class="fs-5 fw-bold text-dark">&mdash;</span>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold text-muted small text-uppercase">Submission Document</label>
                                        <div class="p-3 bg-light rounded-3 border">
                                            <span id="open_display_doc_usd">&mdash;</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-5 fw-bold"><i class="bi bi-unlock me-1"></i> Confirm Opening</button>
                </div>
            </form>
        </div>
    </div>
 </div>

 <!-- Approve Modal -->
 <div class="modal fade" id="approveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Approval Process - <span class="tender-no-display"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="approveForm">
                <input type="hidden" name="tender_id" class="tender-id-input">
                <input type="hidden" name="action" value="APPROVE_TENDER">
                <div class="modal-body text-center py-4">
                    <div class="mb-3">
                        <i class="bi bi-check2-circle text-primary display-4"></i>
                    </div>
                    <h5 class="fw-bold mb-3">Approve Tender Invitation</h5>
                    <p class="text-muted mb-0">Note: This action will officially approve the tender to proceed to the next stage.</p>
                </div>
                <div class="modal-footer justify-content-center border-0 pb-4">
                    <button type="button" class="btn btn-light px-4 border" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-5 fw-bold shadow-sm">APPROVE</button>
                </div>
            </form>
        </div>
    </div>
 </div>

 <!-- Fee Modal -->
 <div class="modal fade" id="feeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-cash-stack me-2"></i> Participation Budget/Fee - <span class="tender-no-display"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="feeForm">
                <input type="hidden" name="tender_id" class="tender-id-input">
                <input type="hidden" name="action" value="RECORD_FEE">
                <div class="modal-body p-4">
                    <div class="alert alert-info border-0 py-2 small">
                        <i class="bi bi-info-circle me-1"></i> Please enter the estimated budget or expenses for this tender participation. This amount will be tracked as the project budget if awarded.
                    </div>
                    <div class="mb-0">
                        <label class="form-label fw-bold">Participation Budget (Tshs) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" class="form-control" name="fee_amount" required placeholder="e.g. 50000">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-5 fw-bold">Confirm & Continue</button>
                </div>
            </form>
        </div>
    </div>
 </div>

 <!-- Submission Modal -->
 <div class="modal fade" id="submissionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-send-check me-2"></i> Financial & Technical Submission - <span class="tender-no-display"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="submissionForm" enctype="multipart/form-data">
                <input type="hidden" name="tender_id" class="tender-id-input">
                <input type="hidden" name="action" value="SUBMISSION_PROCESS">
                <div class="modal-body p-4">
                    <h5 class="fw-bold text-primary border-bottom pb-2 mb-4">FINANCIAL SUBMISSION</h5>
                    
                    <div class="mb-4">
                        <label class="form-label fw-bold">Select Submission Currency <span class="text-danger">*</span></label>
                        <div class="d-flex gap-4 p-3 bg-light rounded-3 border">
                            <div class="form-check">
                                <input class="form-check-input cur-selector" type="radio" name="sub_currency_choice" id="sub_cur_tzs" value="Tshs" checked>
                                <label class="form-check-label fw-bold" for="sub_cur_tzs">TZS</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input cur-selector" type="radio" name="sub_currency_choice" id="sub_cur_usd" value="USD">
                                <label class="form-check-label fw-bold" for="sub_cur_usd">USD</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input cur-selector" type="radio" name="sub_currency_choice" id="sub_cur_both" value="Both">
                                <label class="form-check-label fw-bold" for="sub_cur_both">BOTH TZS & USD</label>
                            </div>
                        </div>
                    </div>

                    <div id="sub_form_tzs" class="mb-4">
                        <div class="card border-primary border-opacity-25 shadow-sm">
                            <div class="card-header bg-primary bg-opacity-10 text-primary fw-bold py-2">TZS Bidding Details</div>
                            <div class="card-body p-3">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Amount (TZS) <span class="text-danger">*</span></label>
                                        <input type="number" step="0.01" class="form-control" name="sub_amount_tzs" id="sub_amount_tzs_input" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold text-muted">Attach Submission Document <span class="text-danger">*</span></label>
                                        <input type="file" class="form-control" name="sub_doc_tzs" id="sub_doc_tzs_input" required>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="sub_form_usd" class="d-none mb-4">
                        <div class="card border-success border-opacity-25 shadow-sm">
                            <div class="card-header bg-success bg-opacity-10 text-success fw-bold py-2">USD Bidding Details</div>
                            <div class="card-body p-3">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Amount (USD) <span class="text-danger">*</span></label>
                                        <input type="number" step="0.01" class="form-control" name="sub_amount_usd" id="sub_amount_usd_input">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold text-muted">Attach Submission Document <span class="text-danger">*</span></label>
                                        <input type="file" class="form-control" name="sub_doc_usd" id="sub_doc_usd_input">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- TECHNICAL SUBMISSION -->
                    <h5 class="fw-bold text-primary border-bottom pb-2 my-4">TECHNICAL SUBMISSION</h5>
                    <div class="card border-primary border-opacity-25 shadow-sm mb-4">
                        <div class="card-header bg-primary bg-opacity-10 text-primary fw-bold py-2 d-flex justify-content-between align-items-center">
                            <span>Assign Technical Staff</span>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-sm btn-outline-primary shadow-sm" onclick="showAddEmployeeModal()"><i class="bi bi-person-plus me-1"></i>Add New Employee</button>
                                <button type="button" class="btn btn-sm btn-primary shadow-sm" onclick="showSelectEmployeeField()"><i class="bi bi-person-check me-1"></i>Select Existing</button>
                            </div>
                        </div>
                        <div class="card-body p-3">
                            <div id="staff_selection_row" class="row g-3 d-none mb-3 bg-light p-3 rounded-3 border">
                                <div class="col-md-5">
                                    <label class="form-label fw-bold small text-muted text-uppercase">Select Employee</label>
                                    <select id="staff_select_input" class="form-select select2-basic"></select>
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label fw-bold small text-muted text-uppercase">Assign Role</label>
                                    <input type="text" id="staff_role_input" class="form-control" placeholder="e.g. Project Manager, Lead Engineer">
                                </div>
                                <div class="col-md-2 d-flex align-items-end">
                                    <button type="button" class="btn btn-success w-100 fw-bold" onclick="addStaffToList()"><i class="bi bi-plus-lg me-1"></i> Add</button>
                                </div>
                            </div>
                            
                            <div class="table-responsive">
                                <table class="table table-sm table-hover align-middle mb-0" id="tender_staff_table">
                                    <thead class="bg-light">
                                        <tr class="small text-uppercase">
                                            <th width="50" class="ps-3">S/NO</th>
                                            <th>Staff Name</th>
                                            <th>Designation</th>
                                            <th>Role in Tender</th>
                                            <th class="text-center" width="80">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tender_staff_body">
                                        <tr><td colspan="5" class="text-center py-4 text-muted small"><i class="bi bi-info-circle me-1"></i> No staff assigned yet. Select or add employees to associate them with this tender submission.</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-5 fw-bold"><i class="bi bi-check-circle me-1"></i> Finish & Submit Submission</button>
                </div>
            </form>
        </div>
    </div>
 </div>

 <!-- Add Employee Modal (FULL STYLED) -->
 <div class="modal fade" id="quickAddEmployeeModal" tabindex="-1" aria-hidden="true" style="z-index: 1060;">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white py-3">
                <h5 class="modal-title fw-bold"><i class="bi bi-person-plus-fill me-2"></i>Full Employee Registration</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="quickAddEmployeeForm">
                <input type="hidden" name="action" value="ADD_EMPLOYEE">
                <div class="modal-body p-4">
                    <div class="row g-4">
                        <div class="col-12"><h6 class="fw-bold text-primary border-bottom pb-2 mb-0"><i class="bi bi-info-circle-fill me-2"></i>Personal Information</h6></div>
                        
                        <div class="col-md-4">
                            <label class="form-label fw-bold">First Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="first_name" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Middle Name</label>
                            <input type="text" class="form-control" name="middle_name">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Last Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="last_name" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Gender <span class="text-danger">*</span></label>
                            <select class="form-select" name="gender" required>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Email <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" name="email" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Phone <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="phone" required>
                        </div>
                        <div class="col-12 mt-4"><h6 class="fw-bold text-primary border-bottom pb-2 mb-0"><i class="bi bi-briefcase-fill me-2"></i>Official Details</h6></div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Employee ID/No <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="employee_number" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Hire Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="hire_date" required value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Employment Type <span class="text-danger">*</span></label>
                            <select class="form-select tender-emp-s2" name="employment_type_id" required>
                                <option value="">-- Select --</option>
                                <?php 
                                    $ets = $pdo->query("SELECT type_id, type_name FROM employment_types WHERE status = 'active'")->fetchAll();
                                    foreach($ets as $e) echo "<option value='{$e['type_id']}'>{$e['type_name']}</option>";
                                ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Department <span class="text-danger">*</span></label>
                            <select class="form-select tender-emp-s2" name="department_id" required>
                                <?php
                                    $deps = $pdo->query("SELECT department_id, department_name FROM departments WHERE status = 'active' ORDER BY department_name")->fetchAll();
                                    foreach($deps as $d) echo "<option value='{$d['department_id']}'>{$d['department_name']}</option>";
                                ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Designation <span class="text-danger">*</span></label>
                            <select class="form-select tender-emp-s2" name="designation_id" required>
                                <?php
                                    $dess = $pdo->query("SELECT designation_id, designation_name FROM designations WHERE status = 'active' ORDER BY designation_name")->fetchAll();
                                    foreach($dess as $d) echo "<option value='{$d['designation_id']}'>{$d['designation_name']}</option>";
                                ?>
                            </select>
                        </div>
                        <div class="col-12 mt-2">
                             <div class="bg-light p-3 rounded-3 border">
                                <label class="form-label fw-bold text-success"><i class="bi bi-bookmark-plus me-1"></i> Technical Role for this Tender <span class="text-danger">*</span></label>
                                <input type="text" class="form-control border-success" name="tender_role_direct" placeholder="e.g. Senior Project Architect">
                             </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary px-4 fw-bold" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary px-5 fw-bold"><i class="bi bi-save me-1"></i> Register & Assign Staff</button>
                </div>
            </form>
        </div>
    </div>
 </div>

 <div class="modal fade" id="evaluationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-journal-check me-2"></i>Mark as Evaluation - <span class="tender-no-display"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="evaluationForm">
                <input type="hidden" name="tender_id" class="tender-id-input">
                <input type="hidden" name="action" value="EVALUATION_PROCESS">
                <div class="modal-body p-4">

                    <div class="alert alert-info border-0 d-flex align-items-center gap-2 mb-4" style="border-radius:10px;">
                        <i class="bi bi-info-circle-fill fs-5"></i>
                        <span>The submission details recorded for this tender are displayed below. Confirming will move the tender to <strong>Evaluation</strong> status.</span>
                    </div>

                    <!-- Currency Type -->
                    <div class="mb-4">
                        <label class="form-label fw-bold text-muted text-uppercase small">Currency Type</label>
                        <div class="p-3 bg-light rounded-3 border d-flex align-items-center gap-3">
                            <i class="bi bi-currency-exchange fs-4 text-primary"></i>
                            <span id="eval_display_currency" class="fs-5 fw-bold text-dark">&mdash;</span>
                        </div>
                    </div>

                    <!-- Tshs Block -->
                    <div id="eval_tzs_block" class="d-none mb-4">
                        <div class="card border-0 rounded-3 overflow-hidden shadow-sm">
                            <div class="card-header bg-primary text-white py-2 px-3 fw-bold">
                                <i class="bi bi-cash me-2"></i>Tshs &mdash; Submission Details
                            </div>
                            <div class="card-body p-3">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold text-muted small text-uppercase">Amount</label>
                                        <div class="p-3 bg-light rounded-3 border">
                                            <span class="text-muted fw-bold me-1">Tshs</span>
                                            <span id="eval_display_amount_tzs" class="fs-5 fw-bold text-dark">&mdash;</span>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold text-muted small text-uppercase">Submission Document</label>
                                        <div class="p-3 bg-light rounded-3 border">
                                            <span id="eval_display_doc_tzs">&mdash;</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- USD Block -->
                    <div id="eval_usd_block" class="d-none mb-2">
                        <div class="card border-0 rounded-3 overflow-hidden shadow-sm">
                            <div class="card-header bg-success text-white py-2 px-3 fw-bold">
                                <i class="bi bi-currency-dollar me-2"></i>USD &mdash; Submission Details
                            </div>
                            <div class="card-body p-3">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold text-muted small text-uppercase">Amount</label>
                                        <div class="p-3 bg-light rounded-3 border">
                                            <span class="text-muted fw-bold me-1">USD</span>
                                            <span id="eval_display_amount_usd" class="fs-5 fw-bold text-dark">&mdash;</span>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold text-muted small text-uppercase">Submission Document</label>
                                        <div class="p-3 bg-light rounded-3 border">
                                            <span id="eval_display_doc_usd">&mdash;</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-5 fw-bold"><i class="bi bi-journal-check me-1"></i> Confirm Evaluation</button>
                </div>
            </form>
        </div>
    </div>
 </div>


 <div class="modal fade" id="decisionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Tender Decision - <span class="tender-no-display"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="decisionForm">
                <input type="hidden" name="tender_id" class="tender-id-input">
                <input type="hidden" name="action" value="DECISION">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Decision <span class="text-danger">*</span></label>
                        <select class="form-select" name="status" id="decisionStatus" required>
                            <option value="">Select Decision</option>
                            <option value="AWARDED">AWARDED</option>
                            <option value="LOSS">LOSS (End Tender)</option>
                        </select>
                    </div>
                    <div class="mb-3 d-none" id="lossReasonBlock">
                        <label class="form-label">Reason for Loss <span class="text-danger">*</span></label>
                        <textarea class="form-control" name="loss_reason" rows="3"></textarea>
                    </div>
                    <div class="mb-3 d-none" id="tenderSumBlock">
                        <label class="form-label">Final Tender Sum <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" class="form-control" name="tender_sum">
                    </div>
                    <div class="mb-3 d-none" id="awardLetterBlock">
                        <label class="form-label">Award Letter / Contract Document</label>
                        <input type="file" class="form-control" name="award_letter_document">
                        <small class="text-muted fst-italic">Optional: Upload the award letter or final contract if available.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Submit Decision</button>
                </div>
            </form>
        </div>
    </div>
 </div>

 <!-- Negotiation Modal: Review Tender Sum -->
 <div class="modal fade" id="negotiationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="bi bi-chat-dots me-2"></i> Proceed to Negotiation - <span class="tender-no-display"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="negotiationForm">
                <input type="hidden" name="tender_id" class="tender-id-input">
                <input type="hidden" name="action" value="NEGOTIATION_CONFIRM">
                <div class="modal-body p-4">
                    <p class="text-muted mb-4">Please review the financial details below before proceeding to the negotiation stage.</p>

                    <!-- Registered Currency Type -->
                    <div class="mb-4">
                        <label class="form-label fw-bold text-muted text-uppercase small">Currency Type</label>
                        <div class="p-3 bg-light rounded-3 border d-flex align-items-center gap-3">
                            <i class="bi bi-currency-exchange fs-4 text-info"></i>
                            <span id="neg_display_currency" class="fs-5 fw-bold text-dark">&mdash;</span>
                        </div>
                    </div>

                    <!-- Tshs Block -->
                    <div id="neg_tzs_block" class="d-none mb-4">
                        <div class="card border-0 rounded-3 overflow-hidden shadow-sm">
                            <div class="card-header bg-primary text-white py-2 px-3 fw-bold">
                                <i class="bi bi-cash me-2"></i>Tshs &mdash; Submission Details
                            </div>
                            <div class="card-body p-3">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold text-muted small text-uppercase">Amount</label>
                                        <div class="p-3 bg-light rounded-3 border">
                                            <span class="text-muted fw-bold me-1">Tshs</span>
                                            <span id="neg_display_amount_tzs" class="fs-5 fw-bold text-dark">&mdash;</span>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold text-muted small text-uppercase">Document</label>
                                        <div class="p-3 bg-light rounded-3 border">
                                            <span id="neg_display_doc_tzs">&mdash;</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- USD Block -->
                    <div id="neg_usd_block" class="d-none mb-4">
                        <div class="card border-0 rounded-3 overflow-hidden shadow-sm">
                            <div class="card-header bg-success text-white py-2 px-3 fw-bold">
                                <i class="bi bi-currency-dollar me-2"></i>USD &mdash; Submission Details
                            </div>
                            <div class="card-body p-3">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold text-muted small text-uppercase">Amount</label>
                                        <div class="p-3 bg-light rounded-3 border">
                                            <span class="text-muted fw-bold me-1">USD</span>
                                            <span id="neg_display_amount_usd" class="fs-5 fw-bold text-dark">&mdash;</span>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold text-muted small text-uppercase">Document</label>
                                        <div class="p-3 bg-light rounded-3 border">
                                            <span id="neg_display_doc_usd">&mdash;</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <hr class="my-4">

                    <!-- Negotiation Input Area -->
                    <div class="bg-light p-4 rounded-3 border">
                        <h6 class="fw-bold mb-3 text-info"><i class="bi bi-pencil-square me-1"></i> Negotiation Details</h6>
                        <div class="row g-3">
                            <div class="col-md-12">
                                <label class="form-label fw-bold small">Confirmed / Negotiated Amount</label>
                                <div class="input-group">
                                    <select name="confirmed_currency" id="confirmed_currency" class="form-select border-info" style="max-width: 120px;">
                                        <option value="Tshs">Tshs</option>
                                        <option value="USD">USD</option>
                                    </select>
                                    <input type="number" step="0.01" name="confirmed_tender_sum" class="form-control border-info" placeholder="Enter final negotiated amount" required>
                                </div>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label fw-bold small">Negotiation Notes (Optional)</label>
                                <textarea class="form-control border-info" name="negotiation_notes" rows="3" placeholder="Briefly summarize the outcome of negotiations..."></textarea>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-info text-white px-5 fw-bold">Confirm & Proceed</button>
                </div>
            </form>
        </div>
    </div>
 </div>

  <div class="modal fade" id="postQualModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Post-Qualification - <span class="tender-no-display"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="postQualForm" enctype="multipart/form-data">
                <input type="hidden" name="tender_id" class="tender-id-input">
                <input type="hidden" name="action" value="POST_QUALIFICATION_PROCESS">
                <div class="modal-body">
                    <div class="mb-3 text-center">
                        <i class="bi bi-award text-primary display-4"></i>
                        <h6 class="fw-bold mt-2">Proceed to Post-Qualification Status?</h6>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Attach Post-Qualification Document (Optional)</label>
                        <input type="file" class="form-control" name="post_qual_document">
                        <small class="text-muted">You can skip this if you don't have a document to attach.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-5 fw-bold">PROCEED</button>
                </div>
            </form>
        </div>
    </div>
 </div>

 <div class="modal fade" id="awardModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">Add Award Letter - <span class="tender-no-display"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="awardForm" enctype="multipart/form-data">
                <input type="hidden" name="tender_id" class="tender-id-input">
                <input type="hidden" name="action" value="AWARDED_RECORDS">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Award Letter / Notification <span class="text-danger">*</span></label>
                        <input type="file" class="form-control" name="award_letter_document" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Award Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="award_date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="alert alert-info py-2">
                        <small><i class="bi bi-info-circle"></i> After submitting, this tender will be moved to Projects.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Save & Move to Projects</button>
                </div>
            </form>
        </div>
    </div>
 </div>

 <script>
 let currentPage = 1;
 
 function loadTenders(page = 1) {
    currentPage = page;
    const body = $('#tenderTableBody');
    const params = {
        action: 'get_tenders',
        search: $('#searchInput').val(),
        status: $('#statusFilter').val(),
        category: $('#categoryFilter').val(),
        date_from: $('#dateFrom').val(),
        date_to: $('#dateTo').val(),
        limit: $('#pageLimit').val(),
        page: page,
        attention: <?= $attention ? 1 : 0 ?>
    };
    
    if (page === 1) {
        let filterDesc = [];
        if (params.search) filterDesc.push(`search: "${params.search}"`);
        if (params.status) filterDesc.push(`status: "${params.status}"`);
        if (params.category) filterDesc.push(`category: "${params.category}"`);
        if (params.date_from || params.date_to) filterDesc.push(`date range: ${params.date_from || 'any'} to ${params.date_to || 'any'}`);
        
        let actionMsg = filterDesc.length > 0 ? 'Filtered registry by ' + filterDesc.join(', ') : 'Loaded registry';
        logActivityAction('FILTER', 'Tender Registry Filter', actionMsg);
    }
    
    $.ajax({
        url: '<?= buildUrl("api/get_tenders") ?>',
        method: 'GET',
        data: params,
        success: function(res) {
            if (!res.success) {
                body.html(`<tr><td colspan="7" class="text-center text-danger py-4">${res.message}</td></tr>`);
                return;
            }

            if (res.data.length === 0) {
                body.html('<tr><td colspan="7" class="text-center py-5 text-muted">No matching tenders found.</td></tr>');
            } else {
                let html = '';
                let sn = (page - 1) * params.limit + 1;
                res.data.forEach(t => {
                    const statusBadge = getStatusBadgeClass(t.status);
                    const deadline = t.submission_deadline ? new Date(t.submission_deadline).toLocaleDateString() : '-';
                    
                    html += `
                        <tr>
                            <td class="text-center text-muted fw-bold" style="font-size:0.78rem;">${sn++}</td>
                            <td><strong class="tender-no-text">${t.tender_no}</strong></td>
                            <td><span class="entity-text" title="${t.entity_name || t.procuring_entity_name}">${t.entity_name || t.procuring_entity_name}</span></td>
                            <td class="d-none d-md-table-cell"><span class="badge bg-light text-dark border fw-bold">${t.acronym || '-'}</span></td>
                            <td class="d-none d-md-table-cell" style="font-size:0.82rem;">${t.tender_category}</td>
                            <td class="deadline-cell" style="font-size:0.82rem; white-space:nowrap;">${deadline}</td>
                            <td><span class="badge bg-${statusBadge} status-badge">${t.status.toUpperCase()}</span></td>
                            <td class="text-center">
                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle px-1 py-1" type="button" data-bs-toggle="dropdown" style="font-size:0.78rem;">
                                    <i class="bi bi-gear"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end shadow">
                                    <li><a class="dropdown-item" href="<?= getUrl('tender_view') ?>?id=${t.tender_id}"><i class="bi bi-eye"></i> View</a></li>
                                    <li><a class="dropdown-item" href="<?= getUrl('tender_edit') ?>?id=${t.tender_id}"><i class="bi bi-pencil"></i> Edit</a></li>
                                    ${generateActions(t)}
                                    <li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item text-danger" href="#" onclick="deleteTender(${t.tender_id})"><i class="bi bi-trash"></i> Delete</a></li>
                                </ul>
                            </td>
                        </tr>
                    `;
                });
                body.html(html);
            }
            renderPagination(res.pagination);
            if (window.bmsMobileCards) window.bmsMobileCards.renderForTable('tenderTable');
        }
    });
 }

 function getStatusBadgeClass(status) {
    const s = status.toUpperCase();
    if (['PENDING', 'INVITATION', 'SUBMISSION'].includes(s)) return 'warning';
    if (['APPROVED', 'OPENING', 'EVALUATION', 'POST-QUALIFICATION', 'NEGOTIATION'].includes(s)) return 'info';
    if (s === 'AWARDED' || s === 'SUCCESS') return 'success';
    if (['LOSS', 'LOST', 'CANCELLED', 'END TENDER'].includes(s)) return 'danger';
    return 'secondary';
 }

 function generateActions(t) {
    const status = t.status.toUpperCase();
    const id = t.tender_id;
    const no = t.tender_no;
    
    // JS implementation of get_tender_action (without trailing dividers)
    let actionHtml = '';
    if (status === 'PENDING') actionHtml = `<li><a class="dropdown-item fw-bold text-success" href="#" onclick="openWorkflowModal('#approveModal', ${id}, '${no}')"><i class="bi bi-check2-circle"></i> Approve</a></li>`;
    else if (status === 'APPROVED') actionHtml = `<li><a class="dropdown-item fw-bold text-primary" href="#" onclick="openWorkflowModal('#feeModal', ${id}, '${no}')"><i class="bi bi-cash-stack"></i> Fees</a></li>`;
    else if (status === 'INVITATION') actionHtml = `<li><a class="dropdown-item fw-bold text-primary" href="#" onclick="openSubmissionModal(${id}, '${no}')"><i class="bi bi-send"></i> Mark as Submission</a></li>`;
    else if (status === 'SUBMISSION') actionHtml = `<li><a class="dropdown-item fw-bold text-primary" href="#" onclick="updateTenderStatus(${id}, 'OPENING', 'Opening')"><i class="bi bi-unlock"></i> Mark as Opening</a></li>`;
    else if (status === 'OPENING') actionHtml = `<li><a class="dropdown-item fw-bold text-primary" href="#" onclick="openEvaluationModal(${id}, '${no}', '${t.currency || 'Tshs'}', '${t.tender_amount_tzs || ''}', '${t.tender_amount_usd || ''}', '${t.submission_document_tzs || ''}', '${t.submission_document_usd || ''}')"><i class="bi bi-journal-check"></i> Mark as Evaluation</a></li>`;
    else if (status === 'EVALUATION') actionHtml = `<li><a class="dropdown-item fw-bold text-primary" href="#" onclick="openWorkflowModal('#postQualModal', ${id}, '${no}')"><i class="bi bi-award"></i> Post-Qualification</a></li>`;
    else if (status === 'POST-QUALIFICATION') actionHtml = `<li><a class="dropdown-item fw-bold text-primary" href="#" onclick="openNegotiationModal(${id}, '${no}', '${t.currency || 'Tshs'}', '${t.tender_amount_tzs || ''}', '${t.tender_amount_usd || ''}', '${t.submission_document_tzs || ''}', '${t.submission_document_usd || ''}', '${t.tender_sum || 0}')"><i class="bi bi-chat-dots"></i> Negotiation</a></li>`;
    else if (status === 'NEGOTIATION') actionHtml = `<li><a class="dropdown-item fw-bold text-primary" href="#" onclick="openWorkflowModal('#decisionModal', ${id}, '${no}')"><i class="bi bi-check2-circle"></i> Decision</a></li>`;
    // AWARDED status no longer needs manual project migration

    return actionHtml ? `<li><hr class="dropdown-divider"></li>${actionHtml}` : '';
 }

 function renderPagination(p) {
    const start = (p.page - 1) * p.limit + 1;
    const end = Math.min(start + p.limit - 1, p.total);
    $('#paginationInfo').text(`Showing ${p.total > 0 ? start : 0} to ${end} of ${p.total} entries`);

    let html = '';
    // Previous
    html += `<li class="page-item ${p.page <= 1 ? 'disabled' : ''}"><a class="page-link" href="#" onclick="loadTenders(${p.page - 1})">Previous</a></li>`;
    
    // Page numbers
    for (let i = 1; i <= p.pages; i++) {
        if (i === 1 || i === p.pages || (i >= p.page - 1 && i <= p.page + 1)) {
            html += `<li class="page-item ${i === p.page ? 'active' : ''}"><a class="page-link" href="#" onclick="loadTenders(${i})">${i}</a></li>`;
        } else if (i === p.page - 2 || i === p.page + 2) {
            html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
        }
    }

    // Next
    html += `<li class="page-item ${p.page >= p.pages ? 'disabled' : ''}"><a class="page-link" href="#" onclick="loadTenders(${p.page + 1})">Next</a></li>`;
    $('#paginationControls').html(html);
 }

  // PDF Export Logic using html2pdf.js  // Professional PDF Export using jsPDF & autoTable (The Projects Strategy)
  async function exportPDF() {
    const { jsPDF } = window.jspdf;
    // A3 Landscape allows all columns to breathe and text to be large enough
    const doc = new jsPDF('l', 'pt', 'a3');
    const pageWidth = doc.internal.pageSize.getWidth();
    const pageHeight = doc.internal.pageSize.getHeight();
    
    logActivityAction('PRINT', 'Tender Registry Print', 'Exported tenders registry list as professional PDF');

    Swal.fire({
      title: 'Generating Professional PDF',
      text: 'Please wait while we prepare your document...',
      icon: 'info',
      allowOutsideClick: false,
      didOpen: () => { Swal.showLoading(); }
    });

    // ── Pre-Capture: Info ──
    const companyName = "<?= getSetting('company_name', 'BMS') ?>";
    const companyLogo = "<?= !empty($c_logo) ? '../../../' . $c_logo : '' ?>";
    const exportTime = "<?= date('d M, Y \a\t H:i:s') ?>";
    const exportUser = "<?= ucwords(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?> - <?= ucwords($_SESSION['user_role'] ?? 'Staff') ?>";

    let logoImgData = null;
    if (companyLogo) {
      try {
        logoImgData = await new Promise((resolve) => {
          const img = new Image();
          img.crossOrigin = "Anonymous";
          img.onload = () => resolve({ data: img, w: img.naturalWidth, h: img.naturalHeight });
          img.onerror = () => resolve(null);
          img.src = companyLogo;
        });
      } catch(e) { logoImgData = null; }
    }

    // ── Header Drawing Logic ──
    let currentY = 25;
    if (logoImgData) {
      const hScale = 60 / logoImgData.h;
      const dw = logoImgData.w * hScale;
      doc.addImage(logoImgData.data, 'JPEG', (pageWidth - dw) / 2, currentY, dw, 60);
      currentY += 85;
    }

    doc.setFontSize(30);
    doc.setTextColor(13, 110, 253);
    doc.setFont('helvetica', 'bold');
    doc.text(companyName.toUpperCase(), pageWidth / 2, currentY, { align: 'center' });
    currentY += 40;

    doc.setFontSize(22);
    doc.setTextColor(73, 80, 87);
    doc.text("TENDERS REGISTRY REPORT", pageWidth / 2, currentY, { align: 'center' });
    currentY += 20;

    doc.setDrawColor(13, 110, 253);
    doc.setLineWidth(4);
    doc.line(pageWidth / 2 - 80, currentY, pageWidth / 2 + 80, currentY);
    currentY += 50;

    // ── Footer Function ──
    const drawFooter = (doc, pageNumber) => {
      doc.setFontSize(12);
      doc.setTextColor(100, 100, 100);
      doc.setFont('helvetica', 'normal');
      
      doc.setDrawColor(200, 200, 200);
      doc.setLineWidth(0.5);
      doc.line(40, pageHeight - 50, pageWidth - 40, pageHeight - 50);

      const footerText1 = `This document was exported by ${exportUser} on ${exportTime}`;
      doc.text(footerText1, pageWidth / 2, pageHeight - 35, { align: 'center' });

      doc.setTextColor(13, 110, 253);
      doc.setFont('helvetica', 'bold');
      doc.text('Powered By BJP Technologies  © 2026', pageWidth / 2, pageHeight - 15, { align: 'center' });
      
      doc.setTextColor(150, 150, 150);
      doc.setFontSize(10);
      doc.text(`Page ${pageNumber}`, pageWidth - 50, pageHeight - 15);
    };

    // ── Manually Extract Table Data (SKIP ACTION COLUMN AT INDEX 7) ──
    const tableHead = [];
    $('#tenderTable thead tr th').each(function(index, el) {
       if (index < 7) { // 0-6 are data, 7 is Action
          tableHead.push($(el).text().trim().toUpperCase());
       }
    });

    const tableBody = [];
    $('#tenderTable tbody tr').each(function() {
       const rowData = [];
       $(this).find('td').each(function(index, el) {
          if (index < 7) { // Skip Action column at index 7
             rowData.push($(el).text().trim());
          }
       });
       if (rowData.length > 0) tableBody.push(rowData);
    });

    // ── Table Generation ──
    doc.autoTable({
      head: [tableHead],
      body: tableBody,
      startY: currentY,
      theme: 'striped',
      tableWidth: 'auto', // Force to span the whole page width (margin to margin)
      headStyles: { 
        fillColor: [10, 88, 202], // Darker Blue for Header
        textColor: [255, 255, 255], 
        fontSize: 16.5, 
        fontStyle: 'bold', 
        halign: 'center',
        cellPadding: 15
      },
      styles: { 
        fontSize: 13.5, 
        cellPadding: 12, 
        overflow: 'linebreak', 
        halign: 'left',
        valign: 'middle',
        lineColor: [200, 200, 200],
        lineWidth: 0.1
      },
      columnStyles: {
        0: { halign: 'center', cellWidth: 90, fontStyle: 'bold' }, // S/NO (Wide enough to prevent S/N O wrap)
        5: { halign: 'center' }, // Deadline
        6: { halign: 'center' }  // Status
      },
      margin: { top: 40, bottom: 65, left: 30, right: 30 },
      didDrawPage: (data) => {
        drawFooter(doc, data.pageNumber);
      }
    });

    doc.save('Tenders_Registry_Report_' + new Date().toISOString().slice(0, 10) + '.pdf');
    Swal.close();
    
    Swal.fire({
      title: 'Success!',
      text: 'Tenders Registry Report has been successfully exported to PDF.',
      icon: 'success',
      confirmButtonColor: '#28a745',
      confirmButtonText: 'OK'
    });
  }



 $(document).ready(function() {
    loadTenders();

    // §UI-3 — searchable Select2 on the DB-backed dropdowns inside the
    // Quick-Add-Employee modal (employment type / department / designation).
    // No client DataTable on the tender list: it is AJAX-paginated (loadTenders),
    // which a DataTable would conflict with.
    $('#quickAddEmployeeModal').on('shown.bs.modal', function () {
        const modal = $(this);
        modal.find('.tender-emp-s2').each(function () {
            if (!$(this).hasClass('select2-hidden-accessible')) {
                $(this).select2({ theme: 'bootstrap-5', dropdownParent: modal, width: '100%' });
            }
        });
    });

    // Trigger filter with Apply button
    $('#applyBtn').on('click', () => loadTenders(1));

    // Clear filters
    $('#clearBtn').on('click', function() {
        logActivityAction('FILTER', 'Tender Registry Filter Reset', 'Cleared all filters from tender registry');
        $('#searchInput').val('');
        $('#statusFilter').val('');
        $('#categoryFilter').val('');
        $('#dateFrom').val('');
        $('#dateTo').val('');
        loadTenders(1);
    });

    // Also allow auto-filter for better UX, or keep it purely manual with Apply button
    // Let's keep it manual as implied by adding an Apply button
    $('#searchInput').on('keypress', function(e) {
        if(e.which == 13) loadTenders(1);
    });

    $('#pageLimit').on('change', () => loadTenders(1));

    // Decision Modal logic
    $('#decisionStatus').on('change', function() {
        if ($(this).val() === 'LOSS') {
            $('#lossReasonBlock').removeClass('d-none').find('textarea').attr('required', true);
            $('#tenderSumBlock, #awardLetterBlock').addClass('d-none').find('input').attr('required', false);
        } else if ($(this).val() === 'AWARDED') {
            $('#lossReasonBlock').addClass('d-none').find('textarea').attr('required', false);
            $('#tenderSumBlock, #awardLetterBlock').removeClass('d-none');
            $('#tenderSumBlock').find('input').attr('required', true);
        } else {
            $('#lossReasonBlock, #tenderSumBlock, #awardLetterBlock').addClass('d-none').find('input, textarea').attr('required', false);
        }
    });
 });

 function deleteTender(id) {
    Swal.fire({
        title: 'Delete Tender?',
        text: 'Are you sure you want to delete this tender? This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'Yes, Delete it'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: '<?= buildUrl("api/tender_workflow") ?>',
                method: 'POST',
                data: { tender_id: id, action: 'DELETE' },
                success: function(res) {
                    if (res.success) {
                        logActivityAction('DELETE', 'Tender Deletion', 'Confirmed deletion of tender ID: ' + id, 'tender', id);
                        Swal.fire('Deleted!', res.message, 'success').then(() => loadTenders(currentPage));
                    } else {
                        Swal.fire('Error', res.message, 'error');
                    }
                }
            });
        }
    });
 }

 function openWorkflowModal(modalId, id, no) {
    logActivityAction('ACTION', 'Workflow Start', 'Initiated workflow step for tender: ' + no + ' using modal: ' + modalId, 'tender', id);
    $('.tender-id-input').val(id);
    $('.tender-no-display').text(no);
    $(modalId).modal('show');
 }

  function openSubmissionModal(id, no) {
    $('#submissionForm .tender-id-input').val(id);
    $('#submissionModal .tender-no-display').text(no);
    $('#submissionModal').modal('show');
  }

  $('.cur-selector').on('change', function() {
    const val = $(this).val();
    if (val === 'Tshs') {
        $('#sub_form_tzs').removeClass('d-none').find('input').attr('required', true);
        $('#sub_form_usd').addClass('d-none').find('input').attr('required', false);
    } else if (val === 'USD') {
        $('#sub_form_tzs').addClass('d-none').find('input').attr('required', false);
        $('#sub_form_usd').removeClass('d-none').find('input').attr('required', true);
    } else { // Both
        $('#sub_form_tzs, #sub_form_usd').removeClass('d-none').find('input').attr('required', true);
    }
  });

  /* ---- Staff Logic for Submission ---- */
  let assignedStaff = [];

  function showAddEmployeeModal() {
    $('#quickAddEmployeeModal').modal('show');
  }

  function showSelectEmployeeField() {
    $('#staff_selection_row').toggleClass('d-none');
    if (!$('#staff_selection_row').hasClass('d-none')) {
        const tenderId = $('#submissionForm .tender-id-input').val();
        // Clear and initialize Select2 if not done
        if ($('#staff_select_input').children().length <= 1) {
            $('#staff_select_input').html('<option value="">Searching employees...</option>');
            $.ajax({
                url: '<?= buildUrl("api/tender_workflow") ?>',
                method: 'GET',
                data: { action: 'GET_STAFF_LIST', tender_id: tenderId },
                dataType: 'json',
                success: function(staff) {
                    let html = '<option value="">-- Choose Employee --</option>';
                    staff.forEach(s => {
                        html += `<option value="${s.employee_id}" data-name="${s.first_name} ${s.last_name}" data-des="${s.designation_name}">${s.first_name} ${s.last_name} (${s.employee_number}) - ${s.designation_name}</option>`;
                    });
                    $('#staff_select_input').html(html).select2({
                        theme: 'bootstrap-5',
                        dropdownParent: $('#submissionModal'),
                        width: '100%'
                    });
                },
                error: function(xhr) {
                    console.error('Staff fetch error:', xhr.responseText);
                    Swal.fire('Error', 'Failed to load employee list. ' + (xhr.responseJSON ? xhr.responseJSON.message : ''), 'error');
                }
            });
        }
    }
  }

  function addStaffToList(manualStaff = null) {
    let empId, role, name, des;

    if (manualStaff) {
        empId = manualStaff.employee_id;
        role = manualStaff.role;
        name = manualStaff.name;
        des = manualStaff.designation;
    } else {
        empId = $('#staff_select_input').val();
        role = $('#staff_role_input').val();
        if (!empId) { Swal.fire('Error', 'Please select an employee', 'error'); return; }
        if (!role) { Swal.fire('Error', 'Please assign a role/position', 'error'); return; }
        
        const option = $('#staff_select_input option:selected');
        name = option.data('name');
        des = option.data('des');
    }

    if (assignedStaff.some(s => s.employee_id == empId)) { 
        Swal.fire('Notice', 'This employee is already in the assignment list.', 'info'); 
        return; 
    }

    assignedStaff.push({ employee_id: empId, name: name, designation: des, role: role });
    renderStaffTable();
    
    if (!manualStaff) {
        $('#staff_role_input').val('');
        $('#staff_select_input').val('').trigger('change');
    }
  }

  function renderStaffTable() {
    const body = $('#tender_staff_body');
    if (assignedStaff.length === 0) {
        body.html('<tr><td colspan="5" class="text-center py-4 text-muted small"><i class="bi bi-info-circle me-1"></i> No staff assigned yet. Select or add employees to associate them with this tender submission.</td></tr>');
        return;
    }

    let html = '';
    assignedStaff.forEach((s, idx) => {
        html += `
            <tr class="small">
                <td class="ps-3 fw-bold text-muted">${idx + 1}</td>
                <td class="fw-bold text-dark">${s.name} <input type="hidden" name="staff_ids[]" value="${s.employee_id}"></td>
                <td><span class="text-muted">${s.designation}</span></td>
                <td><span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-10 px-2">${s.role}</span> <input type="hidden" name="staff_roles[]" value="${s.role}"></td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="removeStaff(${idx})" title="Remove Staff"><i class="bi bi-trash fs-6"></i></button>
                </td>
            </tr>
        `;
    });
    body.html(html);
  }

  function removeStaff(idx) {
    assignedStaff.splice(idx, 1);
    renderStaffTable();
  }

  $('#quickAddEmployeeForm').on('submit', function(e) {
    e.preventDefault();
    const $btn = $(this).find('button[type="submit"]');
    const formData = $(this).serialize();
    const directRole = $('input[name="tender_role_direct"]').val();

    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Registering...');
    
    $.ajax({
        url: '<?= buildUrl("api/tender_workflow") ?>',
        method: 'POST',
        data: formData,
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                $('#quickAddEmployeeModal').modal('hide');
                Swal.fire('Success', 'Employee registered successfully!', 'success');
                
                // If a role was provided, add to the assignment list immediately
                if (directRole) {
                    addStaffToList({
                        employee_id: res.employee_id,
                        name: $('input[name="first_name"]').val() + ' ' + $('input[name="last_name"]').val(),
                        designation: $('select[name="designation_id"] option:selected').text(),
                        role: directRole
                    });
                }
                
                // Force reload of staff list next time select is opened
                $('#staff_select_input').html('<option value="">-- Choose Employee --</option>');
                $('#quickAddEmployeeForm')[0].reset();
            } else {
                Swal.fire('Error', res.message, 'error');
            }
            $btn.prop('disabled', false).html('<i class="bi bi-save me-1"></i> Register & Assign');
        },
        error: function() {
            Swal.fire('Error', 'Failed to communicate with the server.', 'error');
            $btn.prop('disabled', false).html('<i class="bi bi-save me-1"></i> Register & Assign');
        }
    });
  });

 function openOpeningModal(id, no, currency, amountTzs, amountUsd, docTzs, docUsd) {
    $('#openingForm .tender-id-input').val(id);
    $('#openingModal .tender-no-display').text(no);

    $('#open_display_currency').text(currency || 'Tshs');
    $('#open_tzs_block, #open_usd_block').addClass('d-none');

    const fmt = (v) => v ? parseFloat(v).toLocaleString('en-US', {minimumFractionDigits: 2}) : '—';
    const docLink = (path, label) => path
        ? `<a href="${APP_URL}/${path}" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-file-earmark-arrow-down me-1"></i>${label}</a>`
        : '<span class="text-muted fst-italic">No document uploaded</span>';

    if (currency === 'Tshs') {
        $('#open_tzs_block').removeClass('d-none');
        $('#open_display_amount_tzs').text(fmt(amountTzs));
        $('#open_display_doc_tzs').html(docLink(docTzs, 'View Document'));
    } else if (currency === 'USD') {
        $('#open_usd_block').removeClass('d-none');
        $('#open_display_amount_usd').text(fmt(amountUsd));
        $('#open_display_doc_usd').html(docLink(docUsd, 'View Document'));
    } else { // Tshs & USD
        $('#open_tzs_block, #open_usd_block').removeClass('d-none');
        $('#open_display_amount_tzs').text(fmt(amountTzs));
        $('#open_display_doc_tzs').html(docLink(docTzs, 'View Tshs Doc'));
        $('#open_display_amount_usd').text(fmt(amountUsd));
        $('#open_display_doc_usd').html(docLink(docUsd, 'View USD Doc'));
    }

    $('#openingModal').modal('show');
 }

 function openEvaluationModal(id, no, currency, amountTzs, amountUsd, docTzs, docUsd) {
    $('#evaluationForm .tender-id-input').val(id);
    $('#evaluationModal .tender-no-display').text(no);

    $('#eval_display_currency').text(currency || 'Tshs');
    $('#eval_tzs_block, #eval_usd_block').addClass('d-none');

    const fmt = (v) => v ? parseFloat(v).toLocaleString('en-US', {minimumFractionDigits: 2}) : '—';
    const docLink = (path, label) => path
        ? `<a href="${APP_URL}/${path}" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-file-earmark-arrow-down me-1"></i>${label}</a>`
        : '<span class="text-muted fst-italic">No document uploaded</span>';

    if (currency === 'Tshs') {
        $('#eval_tzs_block').removeClass('d-none');
        $('#eval_display_amount_tzs').text(fmt(amountTzs));
        $('#eval_display_doc_tzs').html(docLink(docTzs, 'View Document'));
    } else if (currency === 'USD') {
        $('#eval_usd_block').removeClass('d-none');
        $('#eval_display_amount_usd').text(fmt(amountUsd));
        $('#eval_display_doc_usd').html(docLink(docUsd, 'View Document'));
    } else {
        $('#eval_tzs_block, #eval_usd_block').removeClass('d-none');
        $('#eval_display_amount_tzs').text(fmt(amountTzs));
        $('#eval_display_doc_tzs').html(docLink(docTzs, 'View Tshs Doc'));
        $('#eval_display_amount_usd').text(fmt(amountUsd));
        $('#eval_display_doc_usd').html(docLink(docUsd, 'View USD Doc'));
    }

    $('#evaluationModal').modal('show');
 }

 function openNegotiationModal(id, no, currency, amountTzs, amountUsd, docTzs, docUsd, currentSum) {
    $('#negotiationModal .tender-id-input').val(id);
    $('#negotiationModal .tender-no-display').text(no);

    $('#neg_display_currency').text(currency || 'Tshs');
    $('#neg_tzs_block, #neg_usd_block').addClass('d-none');

    const fmt = (v) => v ? parseFloat(v).toLocaleString('en-US', {minimumFractionDigits: 2}) : '—';
    const docLink = (path, label) => path
        ? `<a href="${APP_URL}/${path}" target="_blank" class="btn btn-sm btn-outline-info"><i class="bi bi-file-earmark-arrow-down me-1"></i>${label}</a>`
        : '<span class="text-muted fst-italic">No document uploaded</span>';

    if (currency === 'Tshs') {
        $('#neg_tzs_block').removeClass('d-none');
        $('#neg_display_amount_tzs').text(fmt(amountTzs));
        $('#neg_display_doc_tzs').html(docLink(docTzs, 'View Document'));
    } else if (currency === 'USD') {
        $('#neg_usd_block').removeClass('d-none');
        $('#neg_display_amount_usd').text(fmt(amountUsd));
        $('#neg_display_doc_usd').html(docLink(docUsd, 'View Document'));
    } else { // Tshs & USD
        $('#neg_tzs_block, #neg_usd_block').removeClass('d-none');
        $('#neg_display_amount_tzs').text(fmt(amountTzs));
        $('#neg_display_doc_tzs').html(docLink(docTzs, 'View Tshs Doc'));
        $('#neg_display_amount_usd').text(fmt(amountUsd));
        $('#neg_display_doc_usd').html(docLink(docUsd, 'View USD Doc'));
    }

    // Set defaults in negotiation inputs
    $('input[name="confirmed_tender_sum"]').val(currentSum);
    $('select[name="confirmed_currency"]').val(currency === 'Tshs & USD' ? 'Tshs' : currency);

    $('#negotiationModal').modal('show');
 }

 function updateTenderStatus(id, status, label) {
    Swal.fire({
        title: 'Change Status?',
        text: `Are you sure you want to mark this tender as ${label}?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Mark it'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: '<?= buildUrl("api/tender_workflow") ?>',
                method: 'POST',
                data: { tender_id: id, status: status, action: 'UPDATE_STATUS' },
                success: function(res) {
                    if (res.success) {
                        Swal.fire('Success', res.message, 'success').then(() => loadTenders(currentPage));
                    } else {
                        Swal.fire('Error', res.message, 'error');
                    }
                }
            });
        }
    });
 }

  // Forms submission via AJAX
  $('#approveForm, #feeForm, #submissionForm, #openingForm, #evaluationForm, #postQualForm, #negotiationForm, #decisionForm, #awardForm').on('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    const modalId = '#' + $(this).closest('.modal').attr('id');
    const $btn = $(this).find('button[type="submit"]');
    
    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Processing...');

    $.ajax({
        url: '<?= buildUrl("api/tender_workflow") ?>',
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(res) {
            if (res.success) {
                $(modalId).modal('hide');
                Swal.fire('Success', res.message, 'success').then(() => loadTenders(currentPage));
            } else {
                Swal.fire('Error', res.message, 'error');
                $btn.prop('disabled', false).text('Try Again');
            }
        },
        error: function() {
            Swal.fire('Error', 'Server communication error', 'error');
            $btn.prop('disabled', false).text('Try Again');
        }
    });
 });

 $('select[name="participation_fee_required"]').on('change', function() {
    if ($(this).val() === 'Yes') {
        $('#feeAmountBlock').removeClass('d-none').find('input').attr('required', true);
    } else {
        $('#feeAmountBlock').addClass('d-none').find('input').attr('required', false);
    }
 });
 </script>
 
 <style>
 .force-visible-pdf { display: block !important; }
 .force-hidden-pdf { display: none !important; }

 /* ===== GLOBAL: No horizontal scroll on mobile ===== */
 body, html {
    overflow-x: hidden !important;
    max-width: 100vw;
 }
 #tenderReportContainer {
    overflow-x: hidden !important;
    max-width: 100%;
 }

 .custom-stat-card {
    background-color: #d1e7dd !important;
    border-color: #badbcc !important;
    transition: transform 0.2s;
    border-radius: 8px;
 }
 .custom-stat-card:hover { transform: translateY(-3px); }
 .custom-stat-card h4, .custom-stat-card h5,
 .custom-stat-card small,
 .custom-stat-card i {
    color: #0f5132 !important;
 }
 .stats-icon i { font-size: 1.2rem; }

 /* ===== TABLE: No overflow ===== */
 #tenderTable {
    word-break: break-word;
    table-layout: fixed;
    width: 100% !important;
 }
 /* Text cells fill 100% of their percentage column — no fixed max-width cap */
 .tender-no-text {
    display: block;
    width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
 }
 .entity-text {
    display: block;
    width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
 }
 .status-badge {
    white-space: nowrap;
    font-size: 0.75rem;
    display: inline-block;
    max-width: 100%;
 }
 .deadline-cell { white-space: nowrap; }

 /* ===== MOBILE OVERRIDES ===== */
 @media (max-width: 767.98px) {
    .tender-no-text  { font-size: 0.78rem; }
    .entity-text     { font-size: 0.78rem; }
    .status-badge    { font-size: 0.60rem; padding: 0.2em 0.3em; }
    .deadline-cell   { font-size: 0.75rem; }
    #tenderTable th,
    #tenderTable td  { padding: 0.3rem 0.2rem; }
    #tenderTable th:first-child,
    #tenderTable td:first-child { padding-left: 0.35rem; }
    .dropdown-menu   { font-size: 0.85rem; }
 }

 @media print {
    .d-print-none, .card-header, .btn-primary, .dropdown, #paginationControls, .bg-white.p-3.rounded-4, .btn, .input-group, .form-select, .badge.bg-light.text-success { display: none !important; }
    #tenderTable th:last-child, #tenderTable td:last-child { display: none !important; }
    .card { border: none !important; box-shadow: none !important; }
    .card-body, .table-responsive { overflow: visible !important; display: block !important; }
    
    body { padding: 0 !important; margin: 0 !important; background-color: #fff !important; }
    .container-fluid { 
        width: 100% !important; 
        max-width: none !important; 
        padding: 0 !important; 
        padding-bottom: 6cm !important; /* Extreme buffer for Last Page Row safety */
    }
    
    /* Ensure table content is fully visible and fits the page */
    #tenderTable {
        table-layout: auto !important;
        width: 100% !important;
        border-collapse: collapse !important;
        overflow: visible !important;
    }

    /* Standardized Printer/PDF Page Margins */
    @page {
        size: auto;
        margin: 0.5in 0.5in 75mm 0.5in !important; /* MASSIVE 75mm protection zone */
    }

    /* Force headers and specific text to stay on one row */
    .d-print-table-header {
        display: table-header-group !important;
    }
    #tenderTable th {
        white-space: nowrap !important;
        font-weight: bold !important;
        background-color: #f8f9fa !important;
        -webkit-print-color-adjust: exact;
        border: 1px solid #000 !important;
        padding: 8px 4px !important;
        font-size: 8.5pt !important;
        vertical-align: middle !important;
    }
    #tenderTable td {
        border: 1px solid #ddd !important;
        padding: 8px 4px !important;
        font-size: 8.5pt !important;
        vertical-align: middle !important;
    }

    /* Columns settings */
    .deadline-cell, .tender-no-text {
        white-space: nowrap !important;
    }
    .entity-text {
        white-space: normal !important;
        overflow: visible !important;
        display: block !important;
        width: 100% !important;
        min-width: 150px;
    }
    
    .table .badge {
        display: inline-block !important;
        background: transparent !important;
        color: #000 !important;
        border: none !important;
        padding: 0 !important;
        font-size: inherit !important;
    }

    /* Fixed Printing Footer - Implementation from projects.php */
    .print-footer {
        position: fixed !important;
        bottom: 0 !important;
        left: 0;
        right: 0;
        height: 1.5cm; /* Shorter than the 1in (2.54cm) margin to guarantee clear space */
        display: flex;
        flex-direction: column;
        justify-content: center;
        text-align: center;
        background: white !important; /* Masking layer */
        padding: 0;
        border-top: 1px solid #ddd !important; 
        font-size: 10px;
        z-index: 999999 !important;
        -webkit-print-color-adjust: exact;
        pointer-events: none;
    }

    /* Table footer spacer reserved for the fixed footer on each page */
    .d-print-table-footer {
        display: table-footer-group !important;
    }
    
    /* Prevent row splitting and text overlap */
    #tenderTable tr {
        page-break-inside: avoid !important;
        page-break-after: auto;
    }
    
    /* Ensure no hidden parents */
    #tenderReportContainer {
        overflow: visible !important;
        padding-bottom: 0 !important;
    }
 }
 </style>

 <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
 <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.25/jspdf.plugin.autotable.min.js"></script>
 <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<?php includeFooter(); ?>
