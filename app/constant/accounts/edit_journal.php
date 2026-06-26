<?php
// Ensure database connection is available
global $pdo, $pdo_accounts;

// Include roots configuration
require_once __DIR__ . '/../../../roots.php';

// Include necessary cores
require_once ROOT_DIR . '/core/permissions.php';

// Enforce authentication
if (!isAuthenticated()) {
    redirectTo('login');
}

// Phase 5b — enforce view permission on journal edit page
autoEnforcePermission('journals');

// Enforce permission
autoEnforcePermission();
requireEditPermission('journals');

$entry_id = $_GET['id'] ?? 0;

if ($entry_id <= 0) {
        redirectTo('journals');
}

// Fetch journal entry header
$stmt = $pdo->prepare("SELECT * FROM journal_entries WHERE entry_id = ?");
$stmt->execute([$entry_id]);
$journal = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$journal) {
        redirectTo('journals');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $entry_date = $_POST['entry_date'];
        $description = $_POST['description'];
        $notes = $_POST['notes'];
        $status = $_POST['status'];
        
        $debit_accounts = $_POST['debit_accounts'] ?? [];
        $debit_amounts = $_POST['debit_amounts'] ?? [];
        $debit_descriptions = $_POST['debit_descriptions'] ?? [];
        
        $credit_accounts = $_POST['credit_accounts'] ?? [];
        $credit_amounts = $_POST['credit_amounts'] ?? [];
        $credit_descriptions = $_POST['credit_descriptions'] ?? [];
        
        // Validation
        if (empty($debit_accounts) || empty($credit_accounts)) {
            throw new Exception("Please add at least one debit and one credit account.");
        }
        
        $total_debits = 0;
        foreach ($debit_amounts as $amt) $total_debits += (float)$amt;
        
        $total_credits = 0;
        foreach ($credit_amounts as $amt) $total_credits += (float)$amt;
        
        if (abs($total_debits - $total_credits) > 0.01) {
            throw new Exception("Journal entry is not balanced. Difference: " . abs($total_debits - $total_credits));
        }
        
        $pdo->beginTransaction();
        
        // Update header — keep the debit/credit side in sync with the edited lines
        // (the first selected debit + credit account land in the header columns).
        $first_debit_acc = 0;
        foreach ($debit_accounts as $i => $aid) { if (!empty($aid) && !empty($debit_amounts[$i])) { $first_debit_acc = (int)$aid; break; } }
        $first_credit_acc = 0;
        foreach ($credit_accounts as $i => $aid) { if (!empty($aid) && !empty($credit_amounts[$i])) { $first_credit_acc = (int)$aid; break; } }
        $sql = "UPDATE journal_entries SET entry_date = ?, description = ?, notes = ?, status = ?, updated_by = ?, updated_at = NOW(), amount = ?, debit_account_id = ?, credit_account_id = ? WHERE entry_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$entry_date, $description, $notes, $status, $_SESSION['user_id'], $total_debits, $first_debit_acc, $first_credit_acc, $entry_id]);
        
        // Delete old items
        $pdo->prepare("DELETE FROM journal_entry_items WHERE entry_id = ?")->execute([$entry_id]);
        
        // Insert new debits
        foreach ($debit_accounts as $i => $account_id) {
            if (empty($account_id) || empty($debit_amounts[$i])) continue;
            $sql = "INSERT INTO journal_entry_items (entry_id, account_id, type, amount, description) VALUES (?, ?, 'debit', ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$entry_id, $account_id, $debit_amounts[$i], $debit_descriptions[$i] ?? '']);
        }
        
        // Insert new credits
        foreach ($credit_accounts as $i => $account_id) {
            if (empty($account_id) || empty($credit_amounts[$i])) continue;
            $sql = "INSERT INTO journal_entry_items (entry_id, account_id, type, amount, description) VALUES (?, ?, 'credit', ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$entry_id, $account_id, $credit_amounts[$i], $credit_descriptions[$i] ?? '']);
        }
        
        // Log activity
        $log_sql = "INSERT INTO activity_logs (user_id, action, description, created_at) VALUES (?, ?, ?, NOW())";
        $log_stmt = $pdo->prepare($log_sql);
        $log_stmt->execute([$_SESSION['user_id'], 'update_journal', "Updated journal entry: $description ({$journal['reference_number']})"]);
        
        $pdo->commit();
        logAudit($pdo, $_SESSION['user_id'], 'update_journal', ['description' => "Updated journal entry: $description ({$journal['reference_number']})"]);
            redirectTo('journals');
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

// Fetch journal items for display
$stmt = $pdo->prepare("
    SELECT jei.*, ca.account_name 
    FROM journal_entry_items jei 
    LEFT JOIN accounts ca ON jei.account_id = ca.account_id 
    WHERE jei.entry_id = ?
");
$stmt->execute([$entry_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$debits = array_filter($items, function($item) { return $item['type'] === 'debit'; });
$credits = array_filter($items, function($item) { return $item['type'] === 'credit'; });

// Fetch accounts for dropdowns
$accounts = $pdo->query("
    SELECT ca.*, at.type_name as account_type
    FROM accounts ca
    LEFT JOIN account_types at ON ca.account_type_id = at.type_id
    WHERE ca.status = 'active'
      AND ca.is_subledger = 0
    ORDER BY at.type_name, ca.account_name
")->fetchAll(PDO::FETCH_ASSOC);

// Now include header.php
includeHeader();
?>

<div class="container mt-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-pencil"></i> Edit Journal Entry</h2>
                    <p class="text-muted">Modify journal entry: <?= htmlspecialchars($journal['reference_number']) ?></p>
                </div>
                <a href="<?= getUrl('journals') ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Back to Journals
                </a>
            </div>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" id="journalForm">
        <div class="card mb-4">
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Entry Date *</label>
                        <input type="date" class="form-control" name="entry_date" value="<?= $journal['entry_date'] ?>" required>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Reference Number</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($journal['reference_number']) ?>" readonly>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status">
                            <option value="draft" <?= $journal['status'] == 'draft' ? 'selected' : '' ?>>Draft</option>
                            <option value="posted" <?= $journal['status'] == 'posted' ? 'selected' : '' ?>>Posted</option>
                            <option value="void" <?= $journal['status'] == 'void' ? 'selected' : '' ?>>Void</option>
                        </select>
                    </div>
                    <div class="col-12 mb-3">
                        <label class="form-label">Description *</label>
                        <input type="text" class="form-control" name="description" value="<?= htmlspecialchars($journal['description']) ?>" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="2"><?= htmlspecialchars($journal['notes']) ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <!-- Debits -->
                <div class="card mb-4 border-danger">
                    <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Debits</h5>
                        <button type="button" class="btn btn-sm btn-light" onclick="addRow('debit')">Add Row</button>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm" id="debitTable">
                            <thead>
                                <tr>
                                    <th>Account</th>
                                    <th width="150">Amount</th>
                                    <th width="40"></th>
                                </tr>
                            </thead>
                            <tbody id="debitBody">
                                <?php foreach ($debits as $item): ?>
                                <tr>
                                    <td>
                                        <select class="form-select form-select-sm" name="debit_accounts[]" required>
                                            <option value="">Select Account</option>
                                            <?php foreach ($accounts as $acc): ?>
                                                <option value="<?= $acc['account_id'] ?>" <?= $item['account_id'] == $acc['account_id'] ? 'selected' : '' ?>><?= htmlspecialchars($acc['account_code'] . ' — ' . $acc['account_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td><input type="number" step="0.01" class="form-control form-control-sm debit-amount" name="debit_amounts[]" value="<?= $item['amount'] ?>" required onchange="calculateTotals()"></td>
                                    <td><button type="button" class="btn btn-sm btn-link text-danger" onclick="this.closest('tr').remove(); calculateTotals();"><i class="bi bi-trash"></i></button></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <!-- Credits -->
                <div class="card mb-4 border-success">
                    <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Credits</h5>
                        <button type="button" class="btn btn-sm btn-light" onclick="addRow('credit')">Add Row</button>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm" id="creditTable">
                            <thead>
                                <tr>
                                    <th>Account</th>
                                    <th width="150">Amount</th>
                                    <th width="40"></th>
                                </tr>
                            </thead>
                            <tbody id="creditBody">
                                <?php foreach ($credits as $item): ?>
                                <tr>
                                    <td>
                                        <select class="form-select form-select-sm" name="credit_accounts[]" required>
                                            <option value="">Select Account</option>
                                            <?php foreach ($accounts as $acc): ?>
                                                <option value="<?= $acc['account_id'] ?>" <?= $item['account_id'] == $acc['account_id'] ? 'selected' : '' ?>><?= htmlspecialchars($acc['account_code'] . ' — ' . $acc['account_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td><input type="number" step="0.01" class="form-control form-control-sm credit-amount" name="credit_amounts[]" value="<?= $item['amount'] ?>" required onchange="calculateTotals()"></td>
                                    <td><button type="button" class="btn btn-sm btn-link text-danger" onclick="this.closest('tr').remove(); calculateTotals();"><i class="bi bi-trash"></i></button></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-body bg-light">
                <div class="row text-center">
                    <div class="col-md-4">
                        <h6>Total Debits</h6>
                        <h4 id="displayTotalDebits">0.00</h4>
                    </div>
                    <div class="col-md-4">
                        <h6>Total Credits</h6>
                        <h4 id="displayTotalCredits">0.00</h4>
                    </div>
                    <div class="col-md-4">
                        <h6>Difference</h6>
                        <h4 id="displayDifference" class="text-danger">0.00</h4>
                    </div>
                </div>
            </div>
        </div>

        <div class="text-end mb-5">
            <button type="submit" class="btn btn-primary btn-lg" id="submitBtn">
                <i class="bi bi-check-circle"></i> Update Journal Entry
            </button>
        </div>
    </form>
</div>

<script>
function addRow(type) {
    const tbody = document.getElementById(type + 'Body');
    const row = document.createElement('tr');
    row.innerHTML = `
        <td>
            <select class="form-select form-select-sm" name="${type}_accounts[]" required>
                <option value="">Select Account</option>
                <?php foreach ($accounts as $acc): ?>
                    <option value="<?= $acc['account_id'] ?>"><?= htmlspecialchars($acc['account_code'] . ' — ' . $acc['account_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </td>
        <td><input type="number" step="0.01" class="form-control form-control-sm ${type}-amount" name="${type}_amounts[]" required onchange="calculateTotals()"></td>
        <td><button type="button" class="btn btn-sm btn-link text-danger" onclick="this.closest('tr').remove(); calculateTotals();"><i class="bi bi-trash"></i></button></td>
    `;
    tbody.appendChild(row);
}

function calculateTotals() {
    let totalDebits = 0;
    document.querySelectorAll('.debit-amount').forEach(el => totalDebits += parseFloat(el.value || 0));
    
    let totalCredits = 0;
    document.querySelectorAll('.credit-amount').forEach(el => totalCredits += parseFloat(el.value || 0));
    
    const diff = Math.abs(totalDebits - totalCredits);
    
    document.getElementById('displayTotalDebits').textContent = totalDebits.toFixed(2);
    document.getElementById('displayTotalCredits').textContent = totalCredits.toFixed(2);
    document.getElementById('displayDifference').textContent = diff.toFixed(2);
    
    const submitBtn = document.getElementById('submitBtn');
    if (diff < 0.01 && totalDebits > 0) {
        submitBtn.disabled = false;
        document.getElementById('displayDifference').className = 'text-success';
    } else {
        submitBtn.disabled = true;
        document.getElementById('displayDifference').className = 'text-danger';
    }
}

// Initial calculation
// Initial calculation
calculateTotals();

// Audit Log: Viewed Edit Journal Page
document.addEventListener('DOMContentLoaded', function() {
    logReportAction('Viewed Edit Journal Page', 'User viewed the edit page for journal entry #<?= $entry_id ?>');
});
</script>

<?php includeFooter(); ?>
