<?php
/**
 * Cash Flow Statement — canonical route (GL-derived, reconciling).
 * ----------------------------------------------------------------------------
 * Serves the single-source Statement of Cash Flows (app/bms/invoice/reps/cash_flow.php
 * -> api/account/get_cash_flow.php / glCashFlow), retiring the old indirect-method page
 * that did NOT reconcile (off ≈648M — non-cash depreciation treated as an investing
 * cash flow; broken add-back). The GL version reconciles by construction: operating +
 * investing + financing == the net change in cash, and it ties to the Balance Sheet.
 *
 * IMPORTANT (rendering): the GL partial includes get_cash_flow.php, which sets a JSON
 * Content-Type ONLY when headers are not yet sent. So this wrapper must mirror the
 * reports.php hub EXACTLY — permission first, then includeHeader() (which sends the
 * page headers), with NO extra ob_start() of its own. Buffering the output here would
 * leave headers unsent, the API would set application/json, and the browser would show
 * the HTML as raw code. roots.php already manages the one global output buffer.
 */
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';

if (function_exists('autoEnforcePermission')) {
    autoEnforcePermission('financial_reports');
}

includeHeader();
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
