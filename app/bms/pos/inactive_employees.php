<?php
ob_start();

// File: app/bms/pos/inactive_employees.php
//
// Deliberately NOT in the header navigation. Reached only from the
// "Inactive Employees" link on app/bms/pos/employees.php.

$page_title = 'Inactive Employees';
require_once __DIR__ . '/../../../roots.php';

// Reuse the same authorization boundary as the Inactivate/Reactivate actions.
$can_reactivate = isAdmin() || canDelete('employees');
$can_view       = isAdmin() || canView('employees');

if (!$can_view) {
    header("Location: " . getUrl('unauthorized'));
    exit();
}

includeHeader();

$stmt = $pdo->query("
    SELECT
        e.employee_id, e.employee_number, e.first_name, e.last_name,
        e.employment_status, e.inactivation_reason, e.updated_at,
        d.department_name, des.designation_name,
        CONCAT(u.first_name, ' ', u.last_name) AS updated_by_name
    FROM employees e
    LEFT JOIN departments d ON e.department_id = d.department_id
    LEFT JOIN designations des ON e.designation_id = des.designation_id
    LEFT JOIN users u ON e.updated_by = u.user_id
    WHERE e.status != 'active'
    ORDER BY e.updated_at DESC
");
$inactiveEmployees = $stmt->fetchAll(PDO::FETCH_ASSOC);

$reasonLabels = [
    'terminated' => 'Terminated',
    'resigned'   => 'Resigned',
];
?>

<?php // Shared print footer styling — single source, same as every other print view.
require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>

<style>
/* The shared print footer is styled for standalone print pages (always visible).
   This is a normal browsing page, so hide it on screen and only show it in print.
   Scoped here rather than changing includes/print_footer_css.php, which every
   other print view depends on. */
.print-footer, .footer-spacer { display: none !important; }

@media print {
    .print-footer { display: flex !important; }

    /* size:auto lets the user pick Portrait or Landscape; the table below is
       built with percentage widths so it fits either without clipping. */
    @page { size: auto; margin: 10mm 8mm 15mm 8mm; }
    body { padding-top: 0 !important; margin-top: 0 !important; background: #fff !important; }

    /* Keep the shared company header compact so it doesn't dominate a portrait page */
    .bms-print-header img { max-height: 50px !important; }
    .bms-print-header .bph-company { font-size: 18pt !important; }

    /* Nothing may overflow the paper width */
    html, body, .container-fluid { max-width: 100% !important; overflow-x: hidden !important; }
    .container-fluid { padding-left: 0 !important; padding-right: 0 !important; }

    /* Hide on-screen chrome so only the report prints */
    .navbar, .breadcrumb, .btn, .dropdown, .d-print-none,
    .dataTables_length, .dataTables_filter, .dataTables_info, .dataTables_paginate,
    .dt-buttons { display: none !important; }

    #tableView { display: block !important; padding: 0 !important; width: 100% !important; }
    #cardView { display: none !important; }

    /* ── Undo DataTables scrollX for print ──────────────────────────────────
       With scrollX:true DataTables splits the grid into TWO tables (a header
       table in .dataTables_scrollHead and a body table in .dataTables_scrollBody)
       with pixel-fixed widths and an overflow-x box. On paper that makes the
       headings sit out of line with their columns and clips anything scrolled
       off-screen. Collapse it back into one full-width table for printing. */
    .dataTables_scroll, .dataTables_scrollHead, .dataTables_scrollBody,
    .dataTables_scrollHeadInner {
        overflow: visible !important;
        width: 100% !important;
        height: auto !important;
        max-height: none !important;
        border: 0 !important;
    }
    /* The header table lives in its own wrapper — hide it and let the body
       table print its real <thead> instead, so headings and cells share one
       table and therefore one set of column widths. */
    .dataTables_scrollHead { display: none !important; }
    .dataTables_scrollBody > table > thead {
        display: table-header-group !important;
        visibility: visible !important;
    }
    /* DataTables collapses the body table's duplicate header: each <th> label is
       wrapped in a div.dataTables_sizing pinned to height:0. Restore both, or the
       column headings print blank and appear "out of line" with their data. */
    .dataTables_scrollBody > table > thead th {
        height: auto !important;
        padding: 6px 8px !important;
        border-bottom: 1px solid #dee2e6 !important;
        background: #f8f9fa !important;
        font-weight: 600 !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    .dataTables_scrollBody > table > thead th .dataTables_sizing {
        height: auto !important;
        display: block !important;
        overflow: visible !important;
    }
    /* Sorting arrows are meaningless on paper */
    .dataTables_scrollBody > table > thead th::before,
    .dataTables_scrollBody > table > thead th::after { display: none !important; }

    /* Both tables carry inline pixel widths from scrollX — override them.
       table-layout:fixed + percentage widths is what makes the 8 columns fit
       PORTRAIT as well as landscape: 'auto' lets long text (e.g. "Human
       Resources Department") push columns off the right edge of the paper. */
    #inactiveTable, .dataTables_scrollBody table, .dataTables_scrollHead table {
        width: 100% !important;
        max-width: 100% !important;
        table-layout: fixed !important;
        border-collapse: collapse !important;
        font-size: 7.5pt !important;
    }
    #inactiveTable th, #inactiveTable td {
        border: 1px solid #ccc !important;
        padding: 4px 3px !important;
        white-space: normal !important;   /* wrap instead of clipping */
        word-wrap: break-word !important;
        overflow-wrap: break-word !important;
        overflow: visible !important;
        vertical-align: middle !important;
        /* scrollX writes inline pixel widths on every cell — neutralise them
           so the percentage column widths below actually apply. */
        width: auto !important;
        min-width: 0 !important;
        max-width: none !important;
    }
    #inactiveTable thead th { text-transform: uppercase !important; }

    /* Percentage widths so the layout adapts to Portrait *and* Landscape.
       8 printed columns (Actions is excluded from print) = 100%. */
    #inactiveTable th:nth-child(1), #inactiveTable td:nth-child(1) { width: 9%  !important; } /* Employee #     */
    #inactiveTable th:nth-child(2), #inactiveTable td:nth-child(2) { width: 15% !important; } /* Name           */
    #inactiveTable th:nth-child(3), #inactiveTable td:nth-child(3) { width: 16% !important; } /* Department     */
    #inactiveTable th:nth-child(4), #inactiveTable td:nth-child(4) { width: 16% !important; } /* Designation    */
    #inactiveTable th:nth-child(5), #inactiveTable td:nth-child(5) { width: 10% !important; } /* Reason         */
    #inactiveTable th:nth-child(6), #inactiveTable td:nth-child(6) { width: 12% !important; } /* Note           */
    #inactiveTable th:nth-child(7), #inactiveTable td:nth-child(7) { width: 11% !important; } /* Inactivated By */
    #inactiveTable th:nth-child(8), #inactiveTable td:nth-child(8) { width: 11% !important; } /* Inactivated On */

    /* Badge must not force a column wider than its share */
    #inactiveTable td .badge {
        font-size: 6.5pt !important;
        padding: 2px 4px !important;
        white-space: normal !important;
    }

    /* Buffer row repeats on every page so the fixed footer never overlaps content */
    .ie-print-buf { display: table-footer-group !important; }
    .ie-print-buf td { height: 1.2cm !important; border: none !important; }

    .card, .table-responsive, #tableView {
        border: none !important; box-shadow: none !important;
        padding: 0 !important; margin: 0 !important;
    }
    .badge { white-space: normal !important; display: inline-block !important; word-break: break-word !important; }

    /* Summary card: centre it and keep its tint on paper (it prints as a
       narrow left-hugging box otherwise, because of the Bootstrap grid). */
    #print-stats-cards { justify-content: center !important; margin-bottom: 5mm !important; }
    #print-stats-cards > [class*="col-"] { flex: 0 0 auto !important; max-width: 60mm !important; }
    #print-stats-cards .card {
        border: 1px solid #b6ccfe !important;
        background: #e7f0ff !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    /* Report title block under the shared (global) company header.
       (bph-* classes are used but unstyled elsewhere in the app — styled here.)
       Forced visible: Bootstrap's .d-none otherwise wins over .d-print-block. */
    .ie-report-head { display: block !important; text-align: center; margin-bottom: 6mm; }
    .ie-report-head .bph-title {
        font-size: 14pt; font-weight: 700; text-transform: uppercase;
        letter-spacing: 1px; color: #495057; margin: 2px 0;
    }
    .ie-report-head .bph-sub { font-size: 9pt; color: #6c757d; margin: 0; }
    .ie-report-head .bph-bar {
        border-bottom: 3px solid #0d6efd; width: 100px; margin: 6px auto 0;
        -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important;
    }
}
</style>

<div class="container-fluid mt-4">
    <?php
        // NOTE: the company header (logo + name) is rendered GLOBALLY by header.php,
        // which already calls renderPrintHeader() for every page. Do NOT call it
        // again here — doing so prints the logo and company name twice.
        // Only the report-specific title block belongs on this page.
    ?>
    <div class="ie-report-head d-none d-print-block">
        <h2 class="bph-title">Inactive Employees Report</h2>
        <p class="bph-sub">Generated on: <?= date('d M Y, H:i') ?></p>
        <div class="bph-bar"></div>
    </div>

    <div class="row mb-4 d-print-none">
        <div class="col-12">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                <div>
                    <h2 class="fw-bold text-dark mb-1"><i class="bi bi-person-x-fill text-primary"></i> Inactive Employees</h2>
                    <p class="text-muted mb-0">Deactivated staff — no data lost, reactivate anytime</p>
                </div>
                <a href="<?= getUrl('employees') ?>" class="btn btn-outline-primary px-4 shadow-sm" style="font-weight: 600;">
                    <i class="bi bi-arrow-left me-1"></i> Back to Employees
                </a>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4" id="print-stats-cards">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe;">
                <div class="fs-4 fw-bold text-primary"><?= count($inactiveEmployees) ?></div>
                <div class="small text-muted">Total Inactive</div>
            </div>
        </div>
    </div>

    <!-- Action toolbar (mirrors suppliers.php) -->
    <div class="row mb-4 d-print-none">
        <div class="col-12">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <div class="d-flex flex-wrap shadow-sm bg-white" style="border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden;">
                    <button type="button" class="btn btn-white btn-sm fw-medium px-3 border-0" onclick="copyTable()" style="background: #fff; height: 38px;">
                        <i class="bi bi-clipboard text-info me-1"></i> Copy
                    </button>
                    <div class="bg-light d-none d-sm-block" style="width: 1px; height: 38px;"></div>
                    <button type="button" class="btn btn-white btn-sm fw-medium px-3 border-0" onclick="exportInactiveEmployees()" style="background: #fff; height: 38px;">
                        <i class="bi bi-file-earmark-spreadsheet text-success me-1"></i> CSV
                    </button>
                    <div class="bg-light d-none d-sm-block" style="width: 1px; height: 38px;"></div>
                    <button type="button" class="btn btn-white btn-sm fw-medium px-3 border-0" onclick="printTable()" style="background: #fff; height: 38px;">
                        <i class="bi bi-printer text-primary me-1"></i> Print
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div id="tableView">
        <table id="inactiveTable" class="table table-hover align-middle w-100">
            <thead class="table-light">
                <tr>
                    <th>Employee #</th>
                    <th>Name</th>
                    <th>Department</th>
                    <th>Designation</th>
                    <th>Reason</th>
                    <th>Note</th>
                    <th>Inactivated By</th>
                    <th>Inactivated On</th>
                    <th class="text-end d-print-none">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($inactiveEmployees as $emp): ?>
                <tr>
                    <td><?= safe_output($emp['employee_number']) ?></td>
                    <td><?= safe_output(trim($emp['first_name'] . ' ' . $emp['last_name'])) ?></td>
                    <td><?= safe_output($emp['department_name'], '—') ?></td>
                    <td><?= safe_output($emp['designation_name'], '—') ?></td>
                    <td><span class="badge" style="background:#6c757d;color:#fff;"><?= safe_output($reasonLabels[$emp['employment_status']] ?? $emp['employment_status'], '—') ?></span></td>
                    <td><?= safe_output($emp['inactivation_reason'], '—') ?></td>
                    <td><?= safe_output($emp['updated_by_name'], '—') ?></td>
                    <td><?= $emp['updated_at'] ? date('d M Y, H:i', strtotime($emp['updated_at'])) : '—' ?></td>
                    <td class="text-end d-print-none">
                        <div class="dropdown d-flex justify-content-end">
                            <button class="btn btn-sm btn-outline-primary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-gear-fill me-1"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">
                                <li>
                                    <a class="dropdown-item py-2 rounded" href="<?= getUrl('employee_details') ?>?id=<?= (int)$emp['employee_id'] ?>">
                                        <i class="bi bi-person-badge-fill text-primary me-2"></i> View Full Profile
                                    </a>
                                </li>
                                <?php if ($can_reactivate): ?>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <button class="dropdown-item py-2 rounded text-primary" onclick="confirmReactivate(<?= (int)$emp['employee_id'] ?>, '<?= addslashes(trim($emp['first_name'] . ' ' . $emp['last_name'])) ?>')">
                                        <i class="bi bi-arrow-counterclockwise me-2"></i> Reactivate
                                    </button>
                                </li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <!-- Buffer tfoot: reserves space for the fixed print footer on every page -->
            <tfoot class="ie-print-buf d-none d-print-table-footer-group">
                <tr><td colspan="9"></td></tr>
            </tfoot>
        </table>
    </div>

    <div id="cardView" class="row g-2 d-none"></div>
</div>

<script>
$(document).ready(function () {
    if (!$.fn.DataTable.isDataTable('#inactiveTable')) {
        $('#inactiveTable').DataTable({
            responsive: false,
            scrollX: true,
            pageLength: 25,
            order: [[7, 'desc']],
            dom: 'rtipB',
            buttons: [
                // Hidden DataTables buttons — driven by the visible toolbar above.
                // ':not(:last-child)' drops the Actions column from every export.
                { extend: 'copyHtml5',  className: 'd-none', exportOptions: { columns: ':not(:last-child)' } },
                { extend: 'csvHtml5',   className: 'd-none', title: 'Inactive Employees',
                  exportOptions: { columns: ':not(:last-child)' } },
                { extend: 'excelHtml5', className: 'd-none', exportOptions: { columns: ':not(:last-child)' } }
            ],
            language: { emptyTable: 'No inactive employees.', zeroRecords: 'No matching records.' },
            drawCallback: function () {
                renderCards(this.api().rows({ page: 'current' }).data().toArray());
            }
        });
    }

    function applyView() {
        if (window.innerWidth < 768) {
            $('#tableView').addClass('d-none');
            $('#cardView').removeClass('d-none');
        } else {
            $('#tableView').removeClass('d-none');
            $('#cardView').addClass('d-none');
        }
    }
    applyView();
    $(window).on('resize', applyView);
});

// ── Toolbar actions (Copy / CSV / Print) ─────────────────────────────────────
// Copy and CSV delegate to the hidden DataTables buttons above.
function copyTable() {
    $('#inactiveTable').DataTable().button('.buttons-copy').trigger();
}

function exportInactiveEmployees() {
    $('#inactiveTable').DataTable().button('.buttons-csv').trigger();
}

// Print uses window.print() so the SHARED print header (renderPrintHeader) and
// SHARED print footer (includes/print_footer_html.php) are both included —
// they are part of this page, not a separate popup window.
function printTable() {
    // On mobile the table is swapped for the card view; the printed report must
    // still show the table, so force it visible for the duration of the print.
    var wasHidden = $('#tableView').hasClass('d-none');
    if (wasHidden) {
        $('#tableView').removeClass('d-none');
        $('#cardView').addClass('d-none');
    }

    var restore = function () {
        if (wasHidden) {
            $('#tableView').addClass('d-none');
            $('#cardView').removeClass('d-none');
        }
        window.removeEventListener('afterprint', restore);
    };
    window.addEventListener('afterprint', restore);

    window.print();
}

function renderCards(rows) {
    if (!rows.length) {
        $('#cardView').html('<div class="col-12 text-center py-5 text-muted">No inactive employees</div>');
        return;
    }
    let html = '';
    rows.forEach(row => {
        html += `
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-3">
                    <div class="fw-bold">${row[1]}</div>
                    <small class="text-muted">${row[2]} — ${row[3]}</small><br>
                    <small class="text-muted">${row[4]} on ${row[7]}</small>
                </div>
                <div class="card-footer bg-white border-top p-0">
                    <div style="display:flex;flex-wrap:nowrap;gap:4px;padding:6px;">
                        ${row[8]}
                    </div>
                </div>
            </div>
        </div>`;
    });
    $('#cardView').html(html);
}

function confirmReactivate(employeeId, employeeName) {
    var safeName = $('<div>').text(employeeName).html();
    Swal.fire({
        title: 'Reactivate Employee?',
        html: '<p class="text-start mb-0">This restores <strong>' + safeName + '</strong> to active — they will reappear in attendance, leave, payroll, and reporting pickers immediately.</p>',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#0d6efd',
        cancelButtonColor: '#aaa',
        confirmButtonText: 'Yes, reactivate'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: APP_URL + '/api/reactivate_employee',
                method: 'POST',
                data: { employee_id: employeeId },
                dataType: 'json',
                success: function (response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Success!',
                            text: 'Employee has been reactivated.',
                            confirmButtonText: 'OK',
                            confirmButtonColor: '#0d6efd'
                        }).then(() => location.reload());
                    } else {
                        Swal.fire({ icon: 'error', title: 'Reactivate Failed', text: response.message });
                    }
                },
                error: function () {
                    Swal.fire({ icon: 'error', title: 'Server Error', text: 'Error reactivating employee. Please try again.' });
                }
            });
        }
    });
}
</script>

<?php // Shared print footer (printed-by / role / timestamp) — single source. ?>
<?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>

<?php includeFooter(); ?>
