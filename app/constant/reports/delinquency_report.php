<?php
// File: app/constant/reports/delinquency_report.php
// Phase 5c — Stub for the future Delinquency report. Pre-gated so an
// unauthorised user cannot reach a blank-page response if the route is
// stumbled upon. Implementation lands in the loans/finance refresh.
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('financial_reports');
includeHeader();
?>
<div class="container py-5">
    <div class="alert alert-info">
        <h4 class="mb-2"><i class="bi bi-info-circle me-2"></i>Delinquency Report — Coming Soon</h4>
        <p class="mb-0">This report is being built and will be available in a future release.</p>
    </div>
</div>
<?php includeFooter(); ?>
