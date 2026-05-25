<?php
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../core/permissions.php';

// Phase 5d — gate help page (key seeded in 2026_05_24_phase5d_loans_seed.php)
autoEnforcePermission('help');

require_once __DIR__ . '/../../../header.php';

$company_name = get_setting('company_name', 'Business Management System');
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12 mt-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2 class="mb-0"><i class="bi bi-question-circle"></i> Help Center</h2>
                    <p class="text-muted">Find answers, guides, and support resources</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Search -->
    <div class="row mb-4">
        <div class="col-lg-8 mx-auto">
            <div class="card border-0 shadow-sm rounded-4 bg-primary bg-gradient text-white">
                <div class="card-body p-5 text-center">
                    <h3 class="fw-bold mb-2">How can we help you?</h3>
                    <p class="opacity-75 mb-4">Search our knowledge base for quick answers</p>
                    <div class="input-group input-group-lg">
                        <span class="input-group-text bg-white border-0"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" class="form-control border-0" id="helpSearch" placeholder="Type your question here..." autocomplete="off">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Links -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <a href="#getting-started" class="text-decoration-none">
                <div class="card border-0 shadow-sm rounded-4 h-100 help-card">
                    <div class="card-body p-4 text-center">
                        <div class="bg-primary bg-opacity-10 d-inline-flex align-items-center justify-content-center rounded-circle mb-3" style="width: 60px; height: 60px;">
                            <i class="bi bi-rocket-takeoff text-primary" style="font-size: 1.5rem;"></i>
                        </div>
                        <h6 class="fw-bold text-dark">Getting Started</h6>
                        <p class="text-muted small mb-0">Learn the basics of the system</p>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-3 mb-3">
            <a href="#sales" class="text-decoration-none">
                <div class="card border-0 shadow-sm rounded-4 h-100 help-card">
                    <div class="card-body p-4 text-center">
                        <div class="bg-success bg-opacity-10 d-inline-flex align-items-center justify-content-center rounded-circle mb-3" style="width: 60px; height: 60px;">
                            <i class="bi bi-cart-check text-success" style="font-size: 1.5rem;"></i>
                        </div>
                        <h6 class="fw-bold text-dark">Sales & Orders</h6>
                        <p class="text-muted small mb-0">Manage sales and invoicing</p>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-3 mb-3">
            <a href="#finance" class="text-decoration-none">
                <div class="card border-0 shadow-sm rounded-4 h-100 help-card">
                    <div class="card-body p-4 text-center">
                        <div class="bg-warning bg-opacity-10 d-inline-flex align-items-center justify-content-center rounded-circle mb-3" style="width: 60px; height: 60px;">
                            <i class="bi bi-cash-coin text-warning" style="font-size: 1.5rem;"></i>
                        </div>
                        <h6 class="fw-bold text-dark">Finance</h6>
                        <p class="text-muted small mb-0">Accounting and reports</p>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-3 mb-3">
            <a href="#admin" class="text-decoration-none">
                <div class="card border-0 shadow-sm rounded-4 h-100 help-card">
                    <div class="card-body p-4 text-center">
                        <div class="bg-danger bg-opacity-10 d-inline-flex align-items-center justify-content-center rounded-circle mb-3" style="width: 60px; height: 60px;">
                            <i class="bi bi-shield-lock text-danger" style="font-size: 1.5rem;"></i>
                        </div>
                        <h6 class="fw-bold text-dark">Administration</h6>
                        <p class="text-muted small mb-0">Users, roles, and settings</p>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <!-- FAQ Sections -->
    <div class="row">
        <div class="col-lg-8">

            <!-- Getting Started -->
            <div class="card border-0 shadow-sm rounded-4 mb-4" id="getting-started">
                <div class="card-header bg-white border-0 p-4">
                    <h5 class="fw-bold mb-0"><i class="bi bi-rocket-takeoff me-2 text-primary"></i>Getting Started</h5>
                </div>
                <div class="card-body p-0">
                    <div class="accordion accordion-flush" id="accordionGettingStarted">
                        <div class="accordion-item faq-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#gs1">
                                    How do I log in to the system?
                                </button>
                            </h2>
                            <div id="gs1" class="accordion-collapse collapse" data-bs-parent="#accordionGettingStarted">
                                <div class="accordion-body text-muted">
                                    <p>Enter your <strong>username</strong> and <strong>password</strong> on the login page. If you forgot your password, contact the system administrator to reset it.</p>
                                    <p>After logging in, you'll be taken to the <strong>Dashboard</strong> which shows an overview of your business metrics.</p>
                                </div>
                            </div>
                        </div>

                        <div class="accordion-item faq-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#gs2">
                                    How do I navigate the system?
                                </button>
                            </h2>
                            <div id="gs2" class="accordion-collapse collapse" data-bs-parent="#accordionGettingStarted">
                                <div class="accordion-body text-muted">
                                    <p>The main navigation bar at the top contains all modules:</p>
                                    <ul>
                                        <li><strong>Dashboard</strong> – Overview of business performance</li>
                                        <li><strong>Finance</strong> – Accounts, transactions, expenses</li>
                                        <li><strong>Sales</strong> – Products, customers, orders, invoices, POS</li>
                                        <li><strong>Operations</strong> – Employees, payroll, assets</li>
                                        <li><strong>Reports</strong> – Financial and business reports</li>
                                        <li><strong>Admin</strong> – User management and system settings</li>
                                    </ul>
                                    <p>Click your <strong>profile icon</strong> (top-right) to access personal settings, change password, or log out.</p>
                                </div>
                            </div>
                        </div>

                        <div class="accordion-item faq-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#gs3">
                                    How do I change my password?
                                </button>
                            </h2>
                            <div id="gs3" class="accordion-collapse collapse" data-bs-parent="#accordionGettingStarted">
                                <div class="accordion-body text-muted">
                                    <ol>
                                        <li>Click your <strong>username</strong> in the top-right corner</li>
                                        <li>Select <strong>"Settings"</strong> from the dropdown</li>
                                        <li>Go to the <strong>"Security"</strong> tab</li>
                                        <li>Enter your current password, then your new password twice</li>
                                        <li>Click <strong>"Update Password"</strong></li>
                                    </ol>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sales & Orders -->
            <div class="card border-0 shadow-sm rounded-4 mb-4" id="sales">
                <div class="card-header bg-white border-0 p-4">
                    <h5 class="fw-bold mb-0"><i class="bi bi-cart-check me-2 text-success"></i>Sales & Orders</h5>
                </div>
                <div class="card-body p-0">
                    <div class="accordion accordion-flush" id="accordionSales">
                        <div class="accordion-item faq-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#s1">
                                    How do I create a new sales order?
                                </button>
                            </h2>
                            <div id="s1" class="accordion-collapse collapse" data-bs-parent="#accordionSales">
                                <div class="accordion-body text-muted">
                                    <ol>
                                        <li>Go to <strong>Sales → Orders</strong></li>
                                        <li>Click <strong>"New Order"</strong></li>
                                        <li>Select a customer and add products</li>
                                        <li>Review quantities and pricing</li>
                                        <li>Click <strong>"Save Order"</strong></li>
                                    </ol>
                                    <p>You can later convert an order into an invoice from the order details page.</p>
                                </div>
                            </div>
                        </div>

                        <div class="accordion-item faq-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#s2">
                                    How do I generate an invoice?
                                </button>
                            </h2>
                            <div id="s2" class="accordion-collapse collapse" data-bs-parent="#accordionSales">
                                <div class="accordion-body text-muted">
                                    <ol>
                                        <li>Go to <strong>Sales → Invoices</strong></li>
                                        <li>Click <strong>"Create Invoice"</strong> OR open an existing order and click <strong>"Generate Invoice"</strong></li>
                                        <li>Fill in invoice details and line items</li>
                                        <li>Click <strong>"Save"</strong></li>
                                        <li>Use the <strong>"Print"</strong> button to print or save as PDF</li>
                                    </ol>
                                </div>
                            </div>
                        </div>

                        <div class="accordion-item faq-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#s3">
                                    How do I use the POS (Point of Sale)?
                                </button>
                            </h2>
                            <div id="s3" class="accordion-collapse collapse" data-bs-parent="#accordionSales">
                                <div class="accordion-body text-muted">
                                    <ol>
                                        <li>Go to <strong>Sales → POS</strong></li>
                                        <li>Start a new <strong>shift</strong> by entering the opening balance</li>
                                        <li>Search or browse products and click to add them to the cart</li>
                                        <li>Adjust quantities as needed</li>
                                        <li>Click <strong>"Pay"</strong> and select payment method (Cash, M-Pesa, Card)</li>
                                        <li>Complete the transaction and print the receipt</li>
                                    </ol>
                                </div>
                            </div>
                        </div>

                        <div class="accordion-item faq-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#s4">
                                    How do I add a new product?
                                </button>
                            </h2>
                            <div id="s4" class="accordion-collapse collapse" data-bs-parent="#accordionSales">
                                <div class="accordion-body text-muted">
                                    <ol>
                                        <li>Go to <strong>Sales → Products</strong></li>
                                        <li>Click <strong>"Add Product"</strong></li>
                                        <li>Fill in product details: name, SKU, price, category, stock quantity</li>
                                        <li>Upload a product image (optional)</li>
                                        <li>Click <strong>"Save"</strong></li>
                                    </ol>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Finance -->
            <div class="card border-0 shadow-sm rounded-4 mb-4" id="finance">
                <div class="card-header bg-white border-0 p-4">
                    <h5 class="fw-bold mb-0"><i class="bi bi-cash-coin me-2 text-warning"></i>Finance & Accounting</h5>
                </div>
                <div class="card-body p-0">
                    <div class="accordion accordion-flush" id="accordionFinance">
                        <div class="accordion-item faq-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#f1">
                                    How do I record an expense?
                                </button>
                            </h2>
                            <div id="f1" class="accordion-collapse collapse" data-bs-parent="#accordionFinance">
                                <div class="accordion-body text-muted">
                                    <ol>
                                        <li>Go to <strong>Finance → Expenses</strong></li>
                                        <li>Click <strong>"Add Expense"</strong></li>
                                        <li>Select expense category, enter amount, date, and description</li>
                                        <li>Attach receipt (optional)</li>
                                        <li>Click <strong>"Save"</strong></li>
                                    </ol>
                                    <p>Expenses are automatically reflected in your financial reports.</p>
                                </div>
                            </div>
                        </div>

                        <div class="accordion-item faq-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#f2">
                                    How do I view financial reports?
                                </button>
                            </h2>
                            <div id="f2" class="accordion-collapse collapse" data-bs-parent="#accordionFinance">
                                <div class="accordion-body text-muted">
                                    <p>Go to <strong>Reports</strong> in the navigation menu. Available reports include:</p>
                                    <ul>
                                        <li><strong>Income Statement</strong> – Revenue vs expenses for a period</li>
                                        <li><strong>Balance Sheet</strong> – Assets, liabilities, and equity</li>
                                        <li><strong>Cash Flow</strong> – Money in and out</li>
                                        <li><strong>Trial Balance</strong> – Account balances summary</li>
                                        <li><strong>General Ledger</strong> – Detailed transaction history</li>
                                    </ul>
                                    <p>Most reports allow you to filter by <strong>date range</strong> and export to <strong>PDF or Excel</strong>.</p>
                                </div>
                            </div>
                        </div>

                        <div class="accordion-item faq-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#f3">
                                    How do I record a journal entry?
                                </button>
                            </h2>
                            <div id="f3" class="accordion-collapse collapse" data-bs-parent="#accordionFinance">
                                <div class="accordion-body text-muted">
                                    <ol>
                                        <li>Go to <strong>Finance → Journal Entries</strong></li>
                                        <li>Click <strong>"New Entry"</strong></li>
                                        <li>Add debit and credit lines — totals must balance</li>
                                        <li>Add description and reference number</li>
                                        <li>Click <strong>"Save"</strong></li>
                                    </ol>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Administration -->
            <div class="card border-0 shadow-sm rounded-4 mb-4" id="admin">
                <div class="card-header bg-white border-0 p-4">
                    <h5 class="fw-bold mb-0"><i class="bi bi-shield-lock me-2 text-danger"></i>Administration</h5>
                </div>
                <div class="card-body p-0">
                    <div class="accordion accordion-flush" id="accordionAdmin">
                        <div class="accordion-item faq-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a1">
                                    How do I add a new user?
                                </button>
                            </h2>
                            <div id="a1" class="accordion-collapse collapse" data-bs-parent="#accordionAdmin">
                                <div class="accordion-body text-muted">
                                    <ol>
                                        <li>Go to <strong>Admin → Users</strong></li>
                                        <li>Click <strong>"Add New User"</strong></li>
                                        <li>Fill in username, full name, email, and password</li>
                                        <li>Assign a role (Admin, Manager, User, etc.)</li>
                                        <li>Click <strong>"Create User"</strong></li>
                                    </ol>
                                    <p class="mb-0"><em>Note: Only administrators can add or manage users.</em></p>
                                </div>
                            </div>
                        </div>

                        <div class="accordion-item faq-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a2">
                                    How do I backup the database?
                                </button>
                            </h2>
                            <div id="a2" class="accordion-collapse collapse" data-bs-parent="#accordionAdmin">
                                <div class="accordion-body text-muted">
                                    <ol>
                                        <li>Go to <strong>Admin → Backup</strong></li>
                                        <li>Click <strong>"Generate Backup"</strong> to create a new backup</li>
                                        <li>Download the backup file for safe keeping</li>
                                        <li>To restore, upload a <code>.sql</code> file and click <strong>"Upload & Restore"</strong></li>
                                    </ol>
                                    <div class="alert alert-warning small mb-0 mt-2">
                                        <i class="bi bi-exclamation-triangle me-1"></i>
                                        <strong>Warning:</strong> Restoring a backup will overwrite all current data. Always create a fresh backup before restoring.
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="accordion-item faq-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a3">
                                    How do I update company information?
                                </button>
                            </h2>
                            <div id="a3" class="accordion-collapse collapse" data-bs-parent="#accordionAdmin">
                                <div class="accordion-body text-muted">
                                    <ol>
                                        <li>Go to <strong>Admin → Company Profile</strong></li>
                                        <li>Update your company name, email, phone, address, and website</li>
                                        <li>Upload your company logo</li>
                                        <li>Click <strong>"Save"</strong></li>
                                    </ol>
                                    <p class="mb-0">These details will appear on invoices, receipts, and reports.</p>
                                </div>
                            </div>
                        </div>

                        <div class="accordion-item faq-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a4">
                                    How do I configure tax settings?
                                </button>
                            </h2>
                            <div id="a4" class="accordion-collapse collapse" data-bs-parent="#accordionAdmin">
                                <div class="accordion-body text-muted">
                                    <ol>
                                        <li>Go to <strong>Admin → Tax</strong></li>
                                        <li>Set the tax name (e.g., VAT, GST)</li>
                                        <li>Enter the default tax rate (%)</li>
                                        <li>Choose pricing method (Tax Exclusive or Inclusive)</li>
                                        <li>Click <strong>"Save Settings"</strong></li>
                                    </ol>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="col-lg-4">

            <!-- Keyboard Shortcuts -->
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-white border-0 p-4">
                    <h5 class="fw-bold mb-0"><i class="bi bi-keyboard me-2 text-secondary"></i>Keyboard Shortcuts</h5>
                </div>
                <div class="card-body p-4 pt-0">
                    <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                        <span class="small">Dashboard</span>
                        <span><kbd>Alt</kbd> + <kbd>D</kbd></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                        <span class="small">New Order</span>
                        <span><kbd>Alt</kbd> + <kbd>N</kbd></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                        <span class="small">POS</span>
                        <span><kbd>Alt</kbd> + <kbd>P</kbd></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center py-2">
                        <span class="small">Search</span>
                        <span><kbd>Ctrl</kbd> + <kbd>K</kbd></span>
                    </div>
                </div>
            </div>

            <!-- Contact Support -->
            <div class="card border-0 shadow-sm rounded-4 mb-4 bg-light">
                <div class="card-body p-4 text-center">
                    <div class="bg-info bg-opacity-10 d-inline-flex align-items-center justify-content-center rounded-circle mb-3" style="width: 60px; height: 60px;">
                        <i class="bi bi-headset text-info" style="font-size: 1.5rem;"></i>
                    </div>
                    <h5 class="fw-bold">Need More Help?</h5>
                    <p class="text-muted small mb-3">Contact our support team for assistance with technical issues or questions.</p>
                    <div class="d-grid gap-2">
                        <a href="mailto:support@<?= strtolower(str_replace(' ', '', $company_name)) ?>.com" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-envelope me-1"></i> Email Support
                        </a>
                        <a href="tel:+255000000000" class="btn btn-outline-success btn-sm">
                            <i class="bi bi-telephone me-1"></i> Call Support
                        </a>
                    </div>
                </div>
            </div>

            <!-- System Info -->
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white border-0 p-4">
                    <h5 class="fw-bold mb-0"><i class="bi bi-info-circle me-2 text-primary"></i>System Info</h5>
                </div>
                <div class="card-body p-4 pt-0 small">
                    <div class="d-flex justify-content-between py-2 border-bottom">
                        <span class="text-muted">System</span>
                        <span class="fw-bold">BMS</span>
                    </div>
                    <div class="d-flex justify-content-between py-2 border-bottom">
                        <span class="text-muted">Version</span>
                        <span class="fw-bold">1.0.0</span>
                    </div>
                    <div class="d-flex justify-content-between py-2 border-bottom">
                        <span class="text-muted">PHP Version</span>
                        <span class="fw-bold"><?= phpversion() ?></span>
                    </div>
                    <div class="d-flex justify-content-between py-2">
                        <span class="text-muted">Server</span>
                        <span class="fw-bold"><?= php_uname('s') ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.help-card {
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    cursor: pointer;
}
.help-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 0.5rem 1.5rem rgba(0,0,0,0.12) !important;
}

.accordion-button:not(.collapsed) {
    background-color: rgba(13, 110, 253, 0.05);
    color: #0d6efd;
    font-weight: 600;
}

.accordion-button:focus {
    box-shadow: none;
}

.faq-item {
    border-left: 3px solid transparent;
    transition: border-color 0.2s ease;
}

.faq-item:has(.accordion-button:not(.collapsed)) {
    border-left-color: #0d6efd;
}

kbd {
    background-color: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    color: #333;
    font-size: 0.8rem;
    padding: 2px 6px;
}
</style>

<script>
$(document).ready(function() {
    // Search functionality
    $('#helpSearch').on('input', function() {
        const query = $(this).val().toLowerCase().trim();

        if (query.length === 0) {
            $('.faq-item').show();
            $('.accordion-collapse').removeClass('show');
            return;
        }

        $('.faq-item').each(function() {
            const text = $(this).text().toLowerCase();
            if (text.includes(query)) {
                $(this).show();
                $(this).find('.accordion-collapse').addClass('show');
            } else {
                $(this).hide();
            }
        });
    });
});
</script>

<?php
require_once __DIR__ . '/../../../footer.php';
ob_end_flush();
?>
