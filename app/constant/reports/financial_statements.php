<?php
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';
includeHeader();
if (function_exists('autoEnforcePermission')) autoEnforcePermission('financial_reports');

// financial_statements.php — Links to all sub-reports
$reports = [
    ['icon'=>'bi-cash-stack','title'=>'Cash Flow Statement','desc'=>'Operating, investing and financing activities','url'=>'cash_flow','color'=>'primary'],
    ['icon'=>'bi-building','title'=>'Balance Sheet','desc'=>'Assets, liabilities and equity position','url'=>'balance_sheet','color'=>'success'],
    ['icon'=>'bi-calculator','title'=>'Trial Balance','desc'=>'Verification that debits equal credits','url'=>'trial_balance','color'=>'info'],
    ['icon'=>'bi-graph-up','title'=>'Income Statement','desc'=>'Revenue, expenses and net profit','url'=>'income_statement','color'=>'warning'],
    ['icon'=>'bi-journal-text','title'=>'General Ledger','desc'=>'Detailed account transaction history','url'=>'ledger_report','color'=>'secondary'],
    ['icon'=>'bi-receipt','title'=>'Tax Report','desc'=>'VAT and tax collection summary','url'=>'tax_report','color'=>'danger'],
];
?>
<div class="container-fluid py-4">
    <div class="row mb-5 align-items-center">
        <div class="col-md-6">
            <h2 class="fw-bold text-primary mb-0"><i class="bi bi-file-earmark-bar-graph me-2"></i>Financial Statements</h2>
            <p class="text-muted mb-0">All financial reports in one place</p>
        </div>
    </div>
    <div class="row g-4">
        <?php foreach($reports as $r): ?>
        <div class="col-md-4">
            <a href="<?= getUrl($r['url']) ?>" class="text-decoration-none">
                <div class="card border-0 shadow-sm h-100 report-card border-start border-4 border-<?= $r['color'] ?>">
                    <div class="card-body p-4">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="report-icon bg-<?= $r['color'] ?> bg-opacity-10 text-<?= $r['color'] ?> rounded-3 p-3">
                                <i class="bi <?= $r['icon'] ?> fs-3"></i>
                            </div>
                            <div>
                                <h5 class="fw-bold mb-0 text-dark"><?= $r['title'] ?></h5>
                                <p class="text-muted small mb-0"><?= $r['desc'] ?></p>
                            </div>
                        </div>
                        <div class="text-end">
                            <span class="badge bg-<?= $r['color'] ?> bg-opacity-10 text-<?= $r['color'] ?> px-3 py-2">
                                View Report <i class="bi bi-arrow-right ms-1"></i>
                            </span>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<style>
.report-card { transition: transform 0.2s, box-shadow 0.2s; cursor: pointer; border-radius: 12px; }
.report-card:hover { transform: translateY(-4px); box-shadow: 0 12px 30px rgba(0,0,0,0.1) !important; }
.report-icon { width: 64px; height: 64px; display: flex; align-items: center; justify-content: center; }
</style>
<script>$(document).ready(function(){ if(typeof logReportAction==='function') logReportAction('Viewed Financial Statements Hub','All reports overview page'); });</script>
<?php includeFooter(); ob_end_flush(); ?>
