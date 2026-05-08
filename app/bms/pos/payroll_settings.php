<?php
// Include roots configuration
require_once dirname(__DIR__, 3) . '/roots.php';

// Enforce permission BEFORE any output
autoEnforcePermission('payroll');

// Include the header
includeHeader();


$page_title = 'Payroll Settings';

// Fetch current settings
$settings_query = "SELECT * FROM payroll_settings ORDER BY category, setting_key";
$settings_stmt = $pdo->query($settings_query);
$all_settings = $settings_stmt->fetchAll(PDO::FETCH_ASSOC);

// Group settings by category
$settings = [];
foreach ($all_settings as $setting) {
    $settings[$setting['category']][] = $setting;
}

// Fetch tax brackets
$tax_query = "SELECT * FROM tax_brackets WHERE is_active = 1 ORDER BY min_income ASC";
$tax_stmt = $pdo->query($tax_query);
$tax_brackets = $tax_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
:root {
    --glass-bg: rgba(255, 255, 255, 0.95);
    --glass-border: rgba(255, 255, 255, 0.2);
    --primary-gradient: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
}

.settings-card {
    background: var(--glass-bg);
    backdrop-filter: blur(10px);
    border: 1px solid var(--glass-border);
    border-radius: 15px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.05);
}

.nav-tabs-custom {
    border: none;
    background: #f8fafc;
    padding: 5px;
    border-radius: 12px;
    display: inline-flex;
}

.nav-tabs-custom .nav-link {
    border: none;
    border-radius: 8px;
    padding: 10px 20px;
    color: #64748b;
    transition: all 0.3s;
}

.nav-tabs-custom .nav-link.active {
    background: white;
    color: #4f46e5;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
}

.badge-status {
    padding: 6px 12px;
    border-radius: 20px;
    font-weight: 500;
}
</style>

<div class="container-fluid py-4">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold text-dark mb-1">Payroll Configuration</h2>
            <p class="text-muted mb-0">Industrial-standard tax brackets and statutory logic</p>
        </div>
        <a href="<?= getUrl('payroll') ?>" class="btn btn-light border shadow-sm">
            <i class="bi bi-arrow-left me-2"></i>Back to Payroll
        </a>
    </div>

    <div id="message"></div>

    <!-- Settings Tabs -->
    <div class="mb-4">
        <ul class="nav nav-tabs-custom shadow-sm" id="settingsTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="tax-tab" data-bs-toggle="tab" data-bs-target="#tax" type="button">
                    <i class="bi bi-calculator me-2"></i>Tax Brackets
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="general-tab" data-bs-toggle="tab" data-bs-target="#general" type="button">
                    <i class="bi bi-sliders me-2"></i>General Settings
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="statutory-tab" data-bs-toggle="tab" data-bs-target="#statutory" type="button">
                    <i class="bi bi-shield-check me-2"></i>Statutory Rates
                </button>
            </li>
        </ul>
    </div>

    <div class="tab-content" id="settingsTabContent">
        <!-- Tax Brackets Tab -->
        <div class="tab-pane fade show active" id="tax" role="tabpanel">
            <div class="card settings-card border-0">
                <div class="card-header bg-transparent border-0 pt-4 px-4 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold mb-0">Tax Brackets</h5>
                    <button class="btn btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#addTaxBracketModal">
                        <i class="bi bi-plus-lg me-2"></i>Add Bracket
                    </button>
                </div>
                <div class="card-body p-4">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> Configure progressive tax rates. The system will automatically calculate tax based on these brackets.
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>Bracket Name</th>
                                    <th>Min Income</th>
                                    <th>Max Income</th>
                                    <th>Tax Rate (%)</th>
                                    <th>Country</th>
                                    <th>Effective From</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($tax_brackets) > 0): ?>
                                    <?php foreach ($tax_brackets as $bracket): ?>
                                    <tr>
                                        <td><?= safe_output($bracket['bracket_name']) ?></td>
                                        <td><?= format_currency($bracket['min_income']) ?></td>
                                        <td><?= $bracket['max_income'] ? format_currency($bracket['max_income']) : '<span class="badge bg-secondary">No Limit</span>' ?></td>
                                        <td><strong><?= number_format($bracket['tax_rate'], 2) ?>%</strong></td>
                                        <td><?= safe_output($bracket['country']) ?></td>
                                        <td><?= date('d M Y', strtotime($bracket['effective_from'])) ?></td>
                                        <td>
                                            <span class="badge bg-<?= $bracket['is_active'] ? 'success' : 'secondary' ?>">
                                                <?= $bracket['is_active'] ? 'Active' : 'Inactive' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-primary" onclick="editTaxBracket(<?= $bracket['tax_bracket_id'] ?>)">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button class="btn btn-sm btn-outline-danger" onclick="deleteTaxBracket(<?= $bracket['tax_bracket_id'] ?>)">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8" class="text-center text-muted py-4">
                                            No tax brackets configured. Add one to get started.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- General Settings Tab -->
        <div class="tab-pane fade" id="general" role="tabpanel">
            <div class="card settings-card border-0">
                <div class="card-header bg-transparent border-0 pt-4 px-4">
                    <h5 class="fw-bold mb-0">General Configurations</h5>
                </div>
                <div class="card-body p-4">
                    <form id="generalSettingsForm">
                        <?php if (isset($settings['general'])): ?>
                            <div class="row">
                            <?php foreach ($settings['general'] as $setting): ?>
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-muted small fw-bold">
                                    <?= strtoupper(str_replace('_', ' ', $setting['setting_key'])) ?>
                                </label>
                                <input type="text" class="form-control bg-light border-0" 
                                       name="<?= $setting['setting_key'] ?>" 
                                       value="<?= safe_output($setting['setting_value']) ?>"
                                       data-setting-id="<?= $setting['setting_id'] ?>">
                                <small class="text-muted"><?= safe_output($setting['description']) ?></small>
                            </div>
                            <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="mt-4">
                            <button type="submit" class="btn btn-primary shadow-sm px-4">
                                <i class="bi bi-check2-circle me-2"></i>Save General Settings
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Statutory Deductions Tab -->
        <div class="tab-pane fade" id="statutory" role="tabpanel">
            <div class="card settings-card border-0">
                <div class="card-header bg-transparent border-0 pt-4 px-4">
                    <h5 class="fw-bold mb-0">Statutory Deduction Rules</h5>
                </div>
                <div class="card-body p-4">
                    <form id="statutorySettingsForm">
                        <?php if (isset($settings['statutory'])): ?>
                            <div class="row">
                            <?php foreach ($settings['statutory'] as $setting): ?>
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-muted small fw-bold">
                                    <?= strtoupper(str_replace('_', ' ', $setting['setting_key'])) ?>
                                </label>
                                <div class="input-group">
                                    <input type="number" step="0.01" class="form-control bg-light border-0" 
                                           name="<?= $setting['setting_key'] ?>" 
                                           value="<?= safe_output($setting['setting_value']) ?>"
                                           data-setting-id="<?= $setting['setting_id'] ?>">
                                    <span class="input-group-text bg-light border-0">%</span>
                                </div>
                                <small class="text-muted"><?= safe_output($setting['description']) ?></small>
                            </div>
                            <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="mt-4">
                            <button type="submit" class="btn btn-primary shadow-sm px-4">
                                <i class="bi bi-check2-circle me-2"></i>Save Statutory Rates
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Tax Bracket Modal -->
<div class="modal fade" id="addTaxBracketModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 20px;">
            <div class="modal-header border-0 pb-0 pt-4 px-4">
                <h5 class="fw-bold">New Tax Bracket</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="addTaxBracketForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Bracket Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="bracket_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Country</label>
                        <input type="text" class="form-control" name="country" value="Tanzania">
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Min Income <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" class="form-control" name="min_income" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Max Income</label>
                            <input type="number" step="0.01" class="form-control" name="max_income" placeholder="Leave empty for highest bracket">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Tax Rate (%) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" class="form-control" name="tax_rate" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Effective From <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="effective_from" value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-plus-circle"></i> Add Bracket
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Save General Settings
    $('#generalSettingsForm').on('submit', function(e) {
        e.preventDefault();
        saveSettings($(this), 'general');
    });

    // Save Statutory Settings
    $('#statutorySettingsForm').on('submit', function(e) {
        e.preventDefault();
        saveSettings($(this), 'statutory');
    });

    // Add Tax Bracket
    $('#addTaxBracketForm').on('submit', function(e) {
        e.preventDefault();
        
        const formData = $(this).serialize();
        
        $.ajax({
            url: APP_URL + '/api/payroll/add_tax_bracket',
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: response.message,
                        timer: 1500,
                        showConfirmButton: false
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire('Error', response.message, 'error');
                }
            },
            error: function() {
                Swal.fire('Error', 'Error adding tax bracket', 'error');
            }
        });
    });
});

function saveSettings(form, category) {
    const formData = form.serializeArray();
    const settings = [];
    
    formData.forEach(item => {
        const input = form.find(`[name="${item.name}"]`);
        settings.push({
            key: item.name,
            value: item.value,
            id: input.data('setting-id')
        });
    });
    
    $.ajax({
        url: APP_URL + '/api/payroll/update_settings',
        type: 'POST',
        data: { settings: JSON.stringify(settings) },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Settings Saved',
                    text: response.message,
                    timer: 1500,
                    showConfirmButton: false
                });
            } else {
                Swal.fire('Error', response.message, 'error');
            }
        },
        error: function() {
            Swal.fire('Error', 'Error updating settings', 'error');
        }
    });
}

function editTaxBracket(id) {
    Swal.fire('Information', 'Edit functionality coming soon', 'info');
}

function deleteTaxBracket(id) {
    Swal.fire({
        title: 'Delete Bracket?',
        text: 'Are you sure you want to delete this tax bracket?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Yes, Delete'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: APP_URL + '/api/payroll/delete_tax_bracket',
                type: 'POST',
                data: { tax_bracket_id: id },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Deleted!',
                            text: response.message,
                            timer: 1500,
                            showConfirmButton: false
                        }).then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire('Error', response.message, 'error');
                    }
                },
                error: function() {
                    Swal.fire('Error', 'Error deleting tax bracket', 'error');
                }
            });
        }
    });
}

function showMessage(type, message) {
    $('#message').html(`<div class="alert alert-${type} alert-dismissible fade show">
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>`);
}
</script>

<?php includeFooter(); ?>
