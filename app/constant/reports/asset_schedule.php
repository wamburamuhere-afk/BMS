<?php
/**
 * app/constant/reports/asset_schedule.php
 *
 * Asset / PPE Schedule report (Asset Register & PPE Schedule, Phase 7.5):
 * the grouped Cost → Depreciation → Net Book Value movement for a financial
 * year, categories across columns and movement lines down rows, with a TOTAL
 * column and a Book / Tax area switch. Print + Excel export.
 */
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/asset_ppe_schedule_service.php';
require_once __DIR__ . '/../../../core/asset_depreciation_run.php';
require_once __DIR__ . '/../../../core/asset_settings.php';

autoEnforcePermission('assets');

$settings = getAssetSettings($pdo);
$defaultFy = (int)date('Y', strtotime($settings['financial_year_start']));
$fy   = isset($_GET['fy']) && $_GET['fy'] !== '' ? (int)$_GET['fy'] : $defaultFy;
$area = (isset($_GET['area']) && $_GET['area'] === 'tax') ? 'tax' : 'book';

[$ps, $pe] = fyBoundsForYear($settings, $fy);
$schedule  = buildPpeSchedule($pdo, $ps, $pe, $area);
$rows   = $schedule['rows'];
$totals = $schedule['totals'];

// Statutory PPE-note labels derived from the period (e.g. 01.01.2025 / 31.12.2025).
$openLabel  = date('d.m.Y', strtotime($ps));
$closeLabel = date('d.m.Y', strtotime($pe));
$yearLabel  = date('Y', strtotime($pe));
$asAtTitle  = strtoupper(date('j F Y', strtotime($pe)));

if (function_exists('logActivity')) {
    logActivity($pdo, $_SESSION['user_id'] ?? 0, 'Viewed PPE Schedule', "FY $fy, area $area");
}

includeHeader();

function ncell($v) { return number_format((float)$v, 0); }
?>

<div class="container-fluid py-4">
    <nav aria-label="breadcrumb" class="mb-3 d-print-none">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= getUrl('assets') ?>">Assets</a></li>
            <li class="breadcrumb-item active">PPE Schedule</li>
        </ol>
    </nav>

    <div class="row mb-3 align-items-center">
        <div class="col-md-7">
            <h2 class="fw-bold text-primary mb-0"><i class="bi bi-table me-2"></i> Asset / PPE Schedule</h2>
            <p class="text-muted small mb-0">Property, Plant &amp; Equipment movement — Cost → Depreciation → Net Book Value.</p>
        </div>
        <div class="col-md-5">
            <form class="row g-2 justify-content-end d-print-none" method="get" action="<?= getUrl('asset_schedule') ?>">
                <div class="col-auto">
                    <select name="area" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="book" <?= $area==='book'?'selected':'' ?>>Book (financial statements)</option>
                        <option value="tax"  <?= $area==='tax' ?'selected':'' ?>>Tax (capital allowances)</option>
                    </select>
                </div>
                <div class="col-auto">
                    <input type="number" name="fy" class="form-select form-select-sm" style="width:110px" value="<?= $fy ?>" min="2000" max="2100" placeholder="FY">
                </div>
                <div class="col-auto"><button class="btn btn-primary btn-sm"><i class="bi bi-arrow-repeat"></i></button></div>
                <div class="col-auto"><button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print</button></div>
                <div class="col-auto"><a class="btn btn-outline-success btn-sm" href="<?= buildUrl('api/assets/export_ppe_schedule.php') ?>?fy=<?= $fy ?>&area=<?= $area ?>"><i class="bi bi-file-earmark-excel"></i> Excel</a></div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white">
            <div class="d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold text-uppercase">Schedule of Property, Plant and Equipment as at <?= $asAtTitle ?></h6>
                <span class="badge bg-<?= $area==='book'?'primary':'success' ?>-subtle text-<?= $area==='book'?'primary':'success' ?>-emphasis border"><?= $area==='book'?'Book area':'Tax area' ?></span>
            </div>
        </div>
        <div class="card-body p-0">
            <?php if (!$rows): ?>
                <div class="p-4 text-muted text-center">No assets to report for this period.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-bordered table-sm mb-0 align-middle text-end" style="min-width:920px">
                    <thead class="table-light">
                        <tr>
                            <th rowspan="2" class="text-start ps-3 align-middle" style="min-width:190px">Asset Class <span class="text-muted fw-normal">(TZS)</span></th>
                            <th colspan="4" class="text-center">COST</th>
                            <th colspan="4" class="text-center">DEPRECIATION</th>
                            <th rowspan="2" class="text-end align-middle bg-light">Net Book Value<br><span class="fw-normal small text-muted"><?= $closeLabel ?></span></th>
                        </tr>
                        <tr>
                            <th>At <?= $openLabel ?></th>
                            <th>Additions <?= $yearLabel ?></th>
                            <th>Disposal</th>
                            <th>At <?= $closeLabel ?></th>
                            <th>At <?= $openLabel ?></th>
                            <th>Charge for the Year</th>
                            <th>Less Acc Depr on Disposal</th>
                            <th>At <?= $closeLabel ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                        <tr>
                            <td class="text-start ps-3"><?= safe_output($r['category']) ?><?= $r['is_depreciable'] ? '' : ' <span class="badge bg-info-subtle text-info-emphasis border">Land</span>' ?></td>
                            <td><?= ncell($r['cost_opening']) ?></td>
                            <td><?= ncell($r['cost_additions']) ?></td>
                            <td><?= ncell($r['cost_disposals']) ?></td>
                            <td class="fw-semibold"><?= ncell($r['cost_closing']) ?></td>
                            <td><?= ncell($r['dep_opening']) ?></td>
                            <td><?= ncell($r['dep_charge']) ?></td>
                            <td><?= ncell($r['dep_disposal']) ?></td>
                            <td class="fw-semibold"><?= ncell($r['dep_closing']) ?></td>
                            <td class="fw-bold bg-light"><?= ncell($r['nbv']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="table-primary fw-bold">
                            <td class="text-start ps-3">TOTAL</td>
                            <td><?= ncell($totals['cost_opening']) ?></td>
                            <td><?= ncell($totals['cost_additions']) ?></td>
                            <td><?= ncell($totals['cost_disposals']) ?></td>
                            <td><?= ncell($totals['cost_closing']) ?></td>
                            <td><?= ncell($totals['dep_opening']) ?></td>
                            <td><?= ncell($totals['dep_charge']) ?></td>
                            <td><?= ncell($totals['dep_disposal']) ?></td>
                            <td><?= ncell($totals['dep_closing']) ?></td>
                            <td><?= ncell($totals['nbv']) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="p-3 small text-muted">
                <i class="bi bi-info-circle me-1"></i> Closing Cost − Closing Accumulated Depreciation = Net Book Value, per category and in total. Gains/losses on disposal are recognised in the P&amp;L and are not shown here. Depreciation figures come from posted runs — use <a href="<?= getUrl('assets') ?>">Run Depreciation</a> to post the period first.
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php includeFooter(); ?>
