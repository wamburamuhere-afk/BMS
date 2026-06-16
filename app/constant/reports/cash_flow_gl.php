<?php
/**
 * Cash Flow Statement — canonical route (GL-derived, reconciling).
 * ----------------------------------------------------------------------------
 * This route now serves the single-source Statement of Cash Flows
 * (app/bms/invoice/reps/cash_flow.php -> api/account/get_cash_flow.php / glCashFlow),
 * retiring the old indirect-method page that DID NOT reconcile — its computed change
 * in cash disagreed with the actual cash movement (≈648M) because non-cash
 * depreciation was treated as an investing cash flow and the depreciation add-back
 * was broken.
 *
 * Why (research-backed): under IAS 7 the statement MUST reconcile — operating +
 * investing + financing has to equal the net change in cash. The GL version does so
 * BY CONSTRUCTION: every posted entry that touches a cash account is classified by
 * its non-cash contra leg, so the three sections sum to the actual cash movement by
 * the double-entry identity (verified: residual = 0; it ties to the Balance Sheet).
 *
 * Thin wrapper: supplies the page chrome (header / breadcrumb / footer) and delegates
 * the body to the shared GL partial; the partial reads $_GET (start_date, end_date,
 * project_id, method) itself, so filters pass straight through.
 */
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';

includeHeader();

if (function_exists('autoEnforcePermission')) {
    autoEnforcePermission('financial_reports');
}
?>
<div class="container-fluid mt-4">
    <nav aria-label="breadcrumb" class="mb-3 d-print-none">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= getUrl('reports') ?>">Reports</a></li>
            <li class="breadcrumb-item active">Cash Flow Statement</li>
        </ol>
    </nav>
    <?php include __DIR__ . '/../../bms/invoice/reps/cash_flow.php'; ?>
</div>
<?php
includeFooter();
ob_end_flush();
