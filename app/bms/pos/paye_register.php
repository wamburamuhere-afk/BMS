<?php
// Include roots configuration
require_once dirname(__DIR__, 3) . '/roots.php';

// Enforce permission BEFORE any output
autoEnforcePermission('payroll');

includeHeader();

// Activity log — viewing a sensitive statutory register.
logActivity($pdo, $_SESSION['user_id'] ?? 0, "Viewed PAYE Register report");

// ── Filters (default: current month) ─────────────────────────────────────────
$f_from = preg_match('/^\d{4}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m');
$f_to   = preg_match('/^\d{4}-\d{2}$/', $_GET['to'] ?? '')   ? $_GET['to']   : date('Y-m');
$f_dept = (isset($_GET['dept']) && $_GET['dept'] !== '') ? (int)$_GET['dept'] : 0;
$f_status = in_array($_GET['st'] ?? '', ['paid','approved','pending'], true) ? $_GET['st'] : '';

$departments = $pdo->query("SELECT department_id, department_name FROM departments WHERE status='active' ORDER BY department_name")->fetchAll(PDO::FETCH_ASSOC);

$where = ["p.payroll_period >= ?", "p.payroll_period <= ?", "p.payment_status <> 'cancelled'"];
$params = [$f_from, $f_to];
// Project-scope (employees) so non-admins only see their scope.
if (function_exists('scopeFilterSqlNullable')) { $where[] = ltrim(scopeFilterSqlNullable('project', 'e'), ' AND') ?: '1'; }
if ($f_dept)   { $where[] = "e.department_id = ?"; $params[] = $f_dept; }
if ($f_status) { $where[] = "p.payment_status = ?"; $params[] = $f_status; }
$whereSql = implode(' AND ', array_filter($where));

$stmt = $pdo->prepare("
    SELECT p.payroll_id, p.payroll_period, p.gross_salary, p.nssf_employee, p.tax_amount,
           p.net_salary, p.payment_status, p.payment_date,
           e.employee_number, e.first_name, e.last_name, d.department_name
      FROM payroll p
      JOIN employees e   ON e.employee_id = p.employee_id
      LEFT JOIN departments d ON d.department_id = e.department_id
     WHERE $whereSql
  ORDER BY p.payroll_period DESC, e.first_name, e.last_name
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$t_gross = $t_nssf = $t_taxable = $t_paye = $t_net = 0.0;
foreach ($rows as $r) {
    $t_gross   += (float)$r['gross_salary'];
    $t_nssf    += (float)$r['nssf_employee'];
    $taxable    = max(0, (float)$r['gross_salary'] - (float)$r['nssf_employee']);
    $t_taxable += $taxable;
    $t_paye    += (float)$r['tax_amount'];
    $t_net     += (float)$r['net_salary'];
}
$rangeLabel = date('M Y', strtotime($f_from.'-01')) . ' – ' . date('M Y', strtotime($f_to.'-01'));
$statusBadge = function ($s) {
    $map = ['paid'=>'background:#052c65;color:#fff;','approved'=>'background:#0d6efd;color:#fff;','rejected'=>'background:#dc3545;color:#fff;'];
    $st = $map[$s] ?? 'background:#e9ecef;color:#495057;';
    return '<span class="badge" style="'.$st.'padding:5px 10px;border-radius:20px;">'.ucfirst($s ?: 'pending').'</span>';
};
?>

<div class="container-fluid mt-4">
    <!-- Print header -->
    <div class="d-none d-print-block text-center mb-2">
        <h4 style="margin:0;text-transform:uppercase;">PAYE Register</h4>
        <div class="small text-muted">Period: <?= safe_output($rangeLabel) ?> · Generated <?= date('d M Y') ?></div>
    </div>

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-4 d-print-none">
        <div>
            <h2 class="fw-bold text-dark mb-1"><i class="bi bi-person-vcard text-primary"></i> PAYE Register</h2>
            <p class="text-muted mb-0">Per-employee PAYE (on gross − NSSF) — supporting schedule for the TRA PAYE return</p>
        </div>
        <div class="d-flex gap-2">
            <button onclick="window.print()" class="btn btn-outline-primary"><i class="bi bi-printer me-1"></i> Print</button>
            <a href="<?= getUrl('statutory_remittances') ?>" class="btn btn-outline-primary"><i class="bi bi-receipt me-1"></i> Remittances</a>
            <a href="<?= getUrl('payroll') ?>" class="btn btn-outline-primary"><i class="bi bi-arrow-left me-1"></i> Payroll</a>
        </div>
    </div>

    <!-- Filters -->
    <form class="card border-0 shadow-sm mb-4 d-print-none" method="get">
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-6 col-md-3">
                    <label class="form-label small fw-bold text-muted">From (month)</label>
                    <input type="month" name="from" value="<?= safe_output($f_from) ?>" class="form-control">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small fw-bold text-muted">To (month)</label>
                    <input type="month" name="to" value="<?= safe_output($f_to) ?>" class="form-control">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small fw-bold text-muted">Department</label>
                    <select name="dept" class="form-select">
                        <option value="">All</option>
                        <?php foreach ($departments as $d): ?>
                            <option value="<?= (int)$d['department_id'] ?>" <?= $f_dept===(int)$d['department_id']?'selected':'' ?>><?= safe_output($d['department_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small fw-bold text-muted">Status</label>
                    <select name="st" class="form-select">
                        <option value="">All</option>
                        <option value="pending"  <?= $f_status==='pending' ?'selected':'' ?>>Pending</option>
                        <option value="approved" <?= $f_status==='approved'?'selected':'' ?>>Approved</option>
                        <option value="paid"     <?= $f_status==='paid'    ?'selected':'' ?>>Paid</option>
                    </select>
                </div>
                <div class="col-12 col-md-2">
                    <button class="btn btn-primary w-100"><i class="bi bi-funnel me-1"></i> Filter</button>
                </div>
            </div>
        </div>
    </form>

    <!-- Summary cards -->
    <div class="row g-3 mb-4 d-print-none">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe !important;">
                <div class="fs-5 fw-bold text-primary"><?= count($rows) ?></div><div class="small text-muted">Records</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-5 fw-bold text-dark">TSh <?= number_format($t_taxable,0) ?></div><div class="small text-muted">Total taxable</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-5 fw-bold" style="color:#052c65;">TSh <?= number_format($t_paye,0) ?></div><div class="small text-muted">Total PAYE</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-5 fw-bold text-dark">TSh <?= number_format($t_nssf,0) ?></div><div class="small text-muted">Total NSSF</div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3 border-bottom d-print-none"><h5 class="mb-0 fw-bold">PAYE by Employee — <?= safe_output($rangeLabel) ?></h5></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle" style="width:100%">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3" style="width:60px;">S/NO</th>
                            <th>Employee</th>
                            <th>Dept</th>
                            <th>Period</th>
                            <th class="text-end">Gross</th>
                            <th class="text-end">NSSF</th>
                            <th class="text-end">Taxable</th>
                            <th class="text-end">PAYE</th>
                            <th class="text-end">Net</th>
                            <th class="text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($rows): $i=1; foreach ($rows as $r):
                        $taxable = max(0, (float)$r['gross_salary'] - (float)$r['nssf_employee']); ?>
                        <tr>
                            <td class="ps-3"><?= $i++ ?></td>
                            <td><div class="fw-semibold"><?= safe_output($r['first_name'].' '.$r['last_name']) ?></div><div class="small text-muted">#<?= safe_output($r['employee_number'] ?: '—') ?></div></td>
                            <td><?= safe_output($r['department_name'] ?: '—') ?></td>
                            <td><?= safe_output(date('M Y', strtotime($r['payroll_period'].'-01'))) ?></td>
                            <td class="text-end"><?= number_format((float)$r['gross_salary'],0) ?></td>
                            <td class="text-end text-muted"><?= number_format((float)$r['nssf_employee'],0) ?></td>
                            <td class="text-end"><?= number_format($taxable,0) ?></td>
                            <td class="text-end fw-bold" style="color:#052c65;"><?= number_format((float)$r['tax_amount'],0) ?></td>
                            <td class="text-end"><?= number_format((float)$r['net_salary'],0) ?></td>
                            <td class="text-center"><?= $statusBadge($r['payment_status'] ?? 'pending') ?></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="10" class="text-center text-muted py-4">No payroll records for this range.</td></tr>
                    <?php endif; ?>
                    </tbody>
                    <?php if ($rows): ?>
                    <tfoot>
                        <tr class="fw-bold table-light">
                            <td colspan="4" class="text-end">Totals</td>
                            <td class="text-end"><?= number_format($t_gross,0) ?></td>
                            <td class="text-end"><?= number_format($t_nssf,0) ?></td>
                            <td class="text-end"><?= number_format($t_taxable,0) ?></td>
                            <td class="text-end" style="color:#052c65;"><?= number_format($t_paye,0) ?></td>
                            <td class="text-end"><?= number_format($t_net,0) ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__, 3) . '/footer.php'; ?>
