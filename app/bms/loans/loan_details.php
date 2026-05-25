<?php
require_once __DIR__ . '/../../../roots.php';

// Phase 5d — gate loan details stub. 'loans' key seeded in
// migrations/2026_05_24_phase5d_loans_seed.php; admin auto-bypasses.
autoEnforcePermission('loans');
?>
<div class="container mt-5">
    <div class="alert alert-info">
        <h3><i class="bi bi-info-circle"></i> Loan Details</h3>
        <p>This module is currently under development.</p>
        <div class="mt-3">
            <a href="<?= getUrl('loans/application') ?>" class="btn btn-success">New Application</a>
            <a href="<?= getUrl('dashboard') ?>" class="btn btn-primary">Back to Dashboard</a>
        </div>
    </div>
</div>
<?php require_once FOOTER_FILE; ?>
