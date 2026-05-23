



<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Business Management System</title>
    
    <!-- jQuery first -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <!-- Select2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
  

<!-- SweetAlert2 (REQUIRED for Swal.fire) -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
    // Global App Configuration
    const APP_URL = '/'.replace(/\/$/, '');

    // Set global SweetAlert2 defaults - Green OK button everywhere
    const originalSwalFire = Swal.fire.bind(Swal);
    Swal.fire = function(...args) {
        // Only modify object-style calls to preserve .then() chaining
        if (args.length === 1 && typeof args[0] === 'object') {
            const options = { ...args[0] };
            // Always set green confirm button
            if (!options.confirmButtonColor) options.confirmButtonColor = '#28a745';
            if (!options.confirmButtonText) options.confirmButtonText = 'OK';
            // Ensure success alerts always show the OK button
            if (options.icon === 'success') {
                if (options.showConfirmButton === undefined) options.showConfirmButton = true;
            }
            return originalSwalFire(options);
        }
        // For positional calls: Swal.fire('title', 'text', 'icon')
        // Convert to object to apply green defaults
        if (typeof args[0] === 'string') {
            const options = { title: args[0] };
            if (args[1]) options.text = args[1];
            if (args[2]) options.icon = args[2];
            options.confirmButtonColor = '#28a745';
            options.confirmButtonText = 'OK';
            if (options.icon === 'success') options.showConfirmButton = true;
            return originalSwalFire(options);
        }
        return originalSwalFire(...args);
    };
    // Global Activity Logging Helper
    function logActivityAction(action, activity_type, description, entity_type = null, entity_id = null) {
        $.post(APP_URL + '/api/log_audit', { // keeping API name to avoid breakage elsewhere if any
            action: action,
            activity_type: activity_type,
            description: description,
            entity_type: entity_type,
            entity_id: entity_id
        });
    }

    // Global helper for logging activities moved to header.php
    function logReportAction(action, description) {
        if (navigator.sendBeacon) {
            const formData = new FormData();
            formData.append('action', action);
            formData.append('description', description);
            navigator.sendBeacon(APP_URL + '/api/log_audit', formData);
        } else {
            $.post(APP_URL + '/api/log_audit', {
                action: action,
                description: description
            });
        }
    }
</script>


    <!-- Font Awesome 5 CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">

    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css">
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    
    <link rel="stylesheet" href="/style.css">
    <link rel="stylesheet" href="/assets/css/responsive.css">

    <style>
        /* Enhanced Unbreakable Fixed Header */
        .navbar {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            right: 0 !important;
            width: 100% !important;
            z-index: 1050 !important; /* Above everything */
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            padding: 0; 
        }

        /* Push body content down for all screens */
        body {
            padding-top: 105px !important; /* Header height approximate */
        }
        
        .header-top-bar {
            background-color: rgba(0,0,0,0.1);
            padding: 0.6rem 0;
            border-bottom: 1px solid rgba(255,255,255,0.08);
        }

        .header-nav-bar {
            padding: 0.2rem 0;
        }

        .user-info-dropdown .dropdown-toggle::after {
            display: none;
        }
        
        .user-info-dropdown .dropdown-toggle {
            cursor: pointer;
            transition: all 0.2s ease;
            border-radius: 8px;
        }
        
        .user-info-dropdown .dropdown-toggle:hover {
            background-color: rgba(255,255,255,0.1);
        }

        .user-info-text {
            font-size: 0.85rem;
            color: rgba(255,255,255,0.9);
            line-height: 1.2;
        }
        
        /* Adjust main content for fixed header */
        .container.mt-4, .container-fluid.mt-4 {
            padding-top: 0px !important;
        }

        @media (max-width: 992px) {
            body {
                padding-top: 100px !important;
            }
        }
        
        /* Smooth scrolling for anchor links */
        html {
            scroll-padding-top: 115px;
        }

        /* Compact dropdowns */
        .dropdown-menu {
            border: none;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
            max-height: 80vh;
            overflow-y: auto;
            font-size: 0.9rem;
        }
        
        .dropdown-item {
            padding: 0.4rem 1rem;
        }
        
        .dropdown-header {
            font-size: 0.8rem;
            font-weight: 600;
            padding: 0.3rem 1rem;
        }
        
        /* Mega menu for reports */
        .mega-dropdown {
            position: static !important;
        }
        
        .mega-dropdown-menu {
            width: 100%;
            max-width: 1200px;
            left: 50% !important;
            transform: translateX(-50%) !important;
            padding: 1.5rem;
        }
        
        .mega-column {
            padding: 0 1rem;
        }
        
        .mega-column h6 {
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 0.8rem;
            color: #495057;
            border-bottom: 1px solid #dee2e6;
            padding-bottom: 0.5rem;
        }
        
        /* Compact navigation for many items */
        .navbar-nav {
            flex-wrap: wrap;
        }
        
        .nav-item {
            margin-right: 0.2rem;
        }
        
        .nav-link {
            padding: 0.5rem 0.8rem;
            font-size: 0.9rem;
        }
        
        /* Company type badge */
        .company-badge {
            font-size: 0.7rem;
            padding: 0.15rem 0.4rem;
            margin-left: 0.3rem;
        }
        
        /* Responsive adjustments */
        @media (max-width: 992px) {
            .header-top-bar .container-fluid {
                flex-direction: row !important;
                flex-wrap: nowrap;
                align-items: center !important;
            }
            .header-top-bar .navbar-toggler {
                position: static;
                margin-left: 0.5rem;
            }
            .user-info-text {
                display: none !important;
            }
            .navbar-brand .company-name-wrapper span {
                max-width: none !important;
                overflow: visible !important;
                text-overflow: clip !important;
                white-space: nowrap;
                font-size: 1rem !important;
            }
            .header-nav-bar {
                background-color: rgba(0,0,0,0.05);
            }
            /* Force dropdown menu to be right-aligned on mobile to prevent overflow */
            .user-info-dropdown .dropdown-menu {
                left: auto !important;
                right: 0 !important;
                transform: none !important;
                margin-top: 0.5rem !important;
            }
        }

        /* Hover effects for Nav Links */
        .nav-link {
            transition: all 0.2s ease;
            position: relative;
            font-weight: 500;
        }
        
        .nav-link:hover {
            color: #fff !important;
            transform: translateY(-1px);
        }

        .nav-item.dropdown:hover > .nav-link {
            color: #fff !important;
        }

        /* Active line under nav items on hover */
        @media (min-width: 992px) {
            .nav-link::after {
                content: '';
                position: absolute;
                width: 0;
                height: 2px;
                bottom: 5px;
                left: 50%;
                background-color: #fff;
                transition: all 0.3s ease;
                transform: translateX(-50%);
                opacity: 0;
            }
            .nav-link:hover::after {
                width: 70%;
                opacity: 1;
            }
        }

        /* Dropdown refinement */
        .dropdown-menu {
            border-top: 3px solid #0d6efd;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Company Name Header Handling */
        .company-name-wrapper {
            display: inline-block;
            vertical-align: middle;
        }

        @media (max-width: 768px) {
            .company-name-wrapper {
                overflow: hidden;
                white-space: nowrap;
                max-width: 140px; /* Precise limit for mobile header */
            }
            .mobile-marquee {
                display: inline-block;
                padding-left: 10px; /* Slight offset for readability */
                animation: marquee-text 12s linear infinite;
            }
            @keyframes marquee-text {
                0% { transform: translateX(100%); }
                100% { transform: translateX(-100%); }
            }
        }

        /* Print styles */
        @media print {
            .navbar {
                display: none !important;
            }
        }
    </style>

</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary flex-column align-items-stretch">
        <!-- Top Row: Logo, Company & User -->
        <div class="header-top-bar">
            <div class="container-fluid d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center">
                    <a class="navbar-brand d-flex align-items-center me-3" href="/dashboard">
                                                    <img src="/uploads/company/logo_1770623052.png" alt="Logo" height="32" class="me-2">
                                                <div class="company-name-wrapper">
                            <span class="fw-bold fs-5 mobile-marquee">BEJUNDAS FINANCIAL SERVICES LTD</span>
                        </div>
                        <span class="company-badge badge bg-light text-dark ms-2 d-none d-sm-inline-block" style="font-size: 0.6rem;">
                            MIC                        </span>
                    </a>
                </div>

                <div class="d-flex align-items-center">
                    <!-- Integrated User Account Dropdown (FAR RIGHT) -->
                    <div class="dropdown user-info-dropdown">
                        <div class="dropdown-toggle d-flex align-items-center p-2" id="userTopDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-person-circle fs-4 text-white me-2"></i>
                            <div class="user-info-text d-none d-sm-block me-2">
                                <strong class="text-white d-block">admin</strong>
                                <small class="text-white text-opacity-75" style="font-size: 0.7rem; text-transform: uppercase;">Admin</small>
                            </div>
                            <i class="bi bi-chevron-down text-white text-opacity-50 small"></i>
                        </div>
                        <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 mt-2" aria-labelledby="userTopDropdown" style="min-width: 180px;">
                            <li><a class="dropdown-item py-2" href="/profile"><i class="bi bi-person me-2"></i> Profile</a></li>
                            <li><a class="dropdown-item py-2" href="/my_settings"><i class="bi bi-gear me-2"></i> Settings</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item py-2" href="/help"><i class="bi bi-question-circle me-2"></i> Help</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item py-2 text-danger fw-bold" href="/logout"><i class="bi bi-box-arrow-right me-2"></i> Logout</a></li>
                        </ul>
                    </div>

                    <button class="navbar-toggler ms-2" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Bottom Row: Navigation Modules -->
        <div class="header-nav-bar">
            <div class="container-fluid">
                <div class="collapse navbar-collapse" id="navbarNav">
                    <ul class="navbar-nav me-auto">
                        <!-- Core Modules -->
                                                <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="coreDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-house"></i> Core
                            </a>
                            <ul class="dropdown-menu" aria-labelledby="coreDropdown">
                                <li><h6 class="dropdown-header">Business Core</h6></li>
                                                                <li><a class="dropdown-item" href="/dashboard"><i class="bi bi-speedometer2"></i> Dashboard</a></li>
                                                                                                <li><a class="dropdown-item" href="/customers"><i class="bi bi-people"></i> Customers</a></li>
                                                                                                <li><a class="dropdown-item" href="/suppliers"><i class="bi bi-truck"></i> Suppliers</a></li>
                                                                                                <li><a class="dropdown-item" href="/products"><i class="bi bi-box text-success"></i> Inventory Products</a></li>
                                                                                                <li><a class="dropdown-item" href="/services"><i class="bi bi-box-seam text-primary"></i> Non-Inventory Products</a></li>
                                                            </ul>
                        </li>
                                                
                        <!-- Financial Modules -->
                                                <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="financeDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-cash-stack"></i> Finance
                            </a>
                            <ul class="dropdown-menu" aria-labelledby="financeDropdown">
                                <li><h6 class="dropdown-header">Accounting</h6></li>
                                                                <li><a class="dropdown-item" href="/expenses"><i class="bi bi-currency-dollar"></i> Expenses</a></li>
                                                                                                <li><a class="dropdown-item" href="/budget"><i class="bi bi-pie-chart"></i> Budget</a></li>
                                                                                                <li><a class="dropdown-item" href="/chart_of_accounts"><i class="bi bi-diagram-3"></i> Chart of Accounts</a></li>
                                                                
                                <li><h6 class="dropdown-header">Banking & Cash</h6></li>
                                                                <li><a class="dropdown-item" href="/bank_accounts"><i class="bi bi-bank"></i> Bank Accounts</a></li>
                                                                                                <li><a class="dropdown-item" href="/cash_register"><i class="bi bi-cash"></i> Cash Register</a></li>
                                                                                                <li><a class="dropdown-item" href="/petty_cash"><i class="bi bi-wallet"></i> Petty Cash</a></li>
                                                                                                <li><a class="dropdown-item" href="/bank_reconciliation"><i class="bi bi-check-circle"></i> Reconciliation</a></li>
                                                                
                                <li><h6 class="dropdown-header">Sales & Purchases</h6></li>
                                                                <li><a class="dropdown-item" href="/invoices"><i class="bi bi-receipt"></i> Invoices</a></li>
                                                                                                <li><a class="dropdown-item" href="/purchase_orders"><i class="bi bi-file-text"></i> Purchase Orders</a></li>
                                                                                                <li><a class="dropdown-item" href="/payment_vouchers"><i class="bi bi-credit-card"></i> Payment Vouchers</a></li>
                                                            </ul>
                        </li>
                                                
                        <!-- Sales -->
                                                <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="salesDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-cart"></i> Sales
                            </a>
                            <ul class="dropdown-menu" aria-labelledby="salesDropdown">
                                <li><h6 class="dropdown-header">Sales Operations</h6></li>
                                                                <li><a class="dropdown-item" href="/sales_orders"><i class="bi bi-bag"></i>Sales Orders</a></li>
                                                                                                <li><a class="dropdown-item" href="/invoices"><i class="bi bi-receipt"></i> Invoices</a></li>
                                                                                                <li><a class="dropdown-item" href="/pos"><i class="bi bi-cart-check"></i> POS</a></li>
                                                                                                <li><a class="dropdown-item" href="/quotations"><i class="bi bi-file-text"></i> Quotations</a></li>
                                                                <li><h6 class="dropdown-header">Returns</h6></li>
                                                                <li><a class="dropdown-item" href="/sales_returns"><i class="bi bi-arrow-return-left"></i> Sales Returns</a></li>
                                                            </ul>
                        </li>
                                                
                        <!-- Inventory -->
                                                <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="inventoryDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-boxes"></i> Inventory
                            </a>
                            <ul class="dropdown-menu" aria-labelledby="inventoryDropdown">
                                <li><h6 class="dropdown-header">Stock Management</h6></li>
                                                                <li><a class="dropdown-item" href="/products"><i class="bi bi-box text-success"></i> Inventory Products</a></li>
                                <li><a class="dropdown-item" href="/services"><i class="bi bi-box-seam text-primary"></i> Non-Inventory Products</a></li>
                                                                                                <li><a class="dropdown-item" href="/categories"><i class="bi bi-tags"></i> Categories</a></li>
                                                                                                <li><a class="dropdown-item" href="/stock_adjustments"><i class="bi bi-arrow-left-right"></i> Adjustments</a></li>
                                                                                                <li><a class="dropdown-item" href="/inventory_valuation"><i class="bi bi-calculator"></i> Valuation</a></li>
                                                                <li><h6 class="dropdown-header">Warehouse</h6></li>
                                                                <li><a class="dropdown-item" href="/warehouses"><i class="bi bi-house-door"></i> Warehouses</a></li>
                                                                                                <li><a class="dropdown-item" href="/locations"><i class="bi bi-geo-alt"></i> Locations</a></li>
                                                            </ul>
                        </li>
                                                
                        <!-- Purchases -->
                                                <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="purchasesDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-basket"></i> Procurement
                            </a>
                            <ul class="dropdown-menu" aria-labelledby="purchasesDropdown">
                                <li><h6 class="dropdown-header">Procurement</h6></li>
                                
                                                                <li><a class="dropdown-item" href="/suppliers"><i class="bi bi-truck"></i> Suppliers</a></li>
                                                                                                <li><a class="dropdown-item" href="/rfq"><i class="bi bi-file-earmark-text"></i> RFQ</a></li>
                                                                
                                                                <li><a class="dropdown-item" href="/purchase_orders"><i class="bi bi-file-text"></i>Purchase Order</a></li>
                                                                                                 <li><a class="dropdown-item" href="/delivery_notes"><i class="bi bi-file-earmark-check"></i> DN</a></li>
                                                                                                <li><a class="dropdown-item" href="/grn"><i class="bi bi-check-square"></i> GRN</a></li>
                                                               
                                                                <li><a class="dropdown-item" href="/purchase_returns"><i class="bi bi-arrow-return-right"></i> Return Note</a></li>
                                                                                                <li><a class="dropdown-item" href="/tenders"><i class="bi bi-clipboard-check"></i> Tenders</a></li>
                                                            </ul>
                        </li>
                                                
                        <!-- Operations -->
                                                <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="operationsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-gear"></i> Operations
                            </a>
                            <ul class="dropdown-menu" aria-labelledby="operationsDropdown">
                                <li><h6 class="dropdown-header">Human Resources</h6></li>
                                                                <li><a class="dropdown-item" href="/employees"><i class="bi bi-person-badge"></i> Employees</a></li>
                                                                                                <li><a class="dropdown-item" href="/payroll"><i class="bi bi-cash"></i> Payroll</a></li>
                                                                                                <li><a class="dropdown-item" href="/attendance"><i class="bi bi-clock"></i> Attendance</a></li>
                                                                                                <li><a class="dropdown-item" href="/leaves"><i class="bi bi-calendar"></i> Leaves</a></li>
                                                                <li><h6 class="dropdown-header">Assets & Maintenance</h6></li>
                                                                <li><a class="dropdown-item" href="/assets"><i class="bi bi-pc-display"></i> Assets</a></li>
                                                                                                <li><a class="dropdown-item" href="/maintenance"><i class="bi bi-tools"></i> Maintenance</a></li>
                                                            </ul>
                        </li>
                                                
                        <!-- Projects -->
                                                <li class="nav-item">
                            <a class="nav-link" href="/projects">
                                <i class="bi bi-kanban"></i> Projects
                            </a>
                        </li>
                            
                        <!-- Comms -->
                                                <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="communicationDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-chat"></i> Comms
                            </a>
                            <ul class="dropdown-menu" aria-labelledby="communicationDropdown">
                                <li><h6 class="dropdown-header">Communication</h6></li>
                                                                <li><a class="dropdown-item" href="/message_center"><i class="bi bi-chat-left"></i> Messages</a></li>
                                                                                                <li><a class="dropdown-item" href="/email_templates"><i class="bi bi-envelope"></i> Email</a></li>
                                                                                                <li><a class="dropdown-item" href="/sms_templates"><i class="bi bi-chat-text"></i> SMS</a></li>
                                                                                                <li><a class="dropdown-item" href="/notification_center"><i class="bi bi-bell"></i> Notifications</a></li>
                                                                <li><h6 class="dropdown-header">Marketing</h6></li>
                                                                <li><a class="dropdown-item" href="/campaigns"><i class="bi bi-megaphone"></i> Campaigns</a></li>
                                                                                                <li><a class="dropdown-item" href="/leads"><i class="bi bi-person-plus"></i> Leads</a></li>
                                                            </ul>
                        </li>
                                                
                        <!-- Docs -->
                                                <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="documentsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-files"></i> Docs
                            </a>
                            <ul class="dropdown-menu" aria-labelledby="documentsDropdown">
                                <li><h6 class="dropdown-header">Document Management</h6></li>
                                                                <li><a class="dropdown-item" href="/library"><i class="bi bi-folder"></i> Library</a></li>
                                                                                                <li><a class="dropdown-item" href="/templates"><i class="bi bi-file-earmark"></i> Templates</a></li>
                                                                                                <li><a class="dropdown-item" href="/e_signatures"><i class="bi bi-pen"></i> E-Sign</a></li>
                                                                <li><h6 class="dropdown-header">Compliance</h6></li>
                                                                <li><a class="dropdown-item" href="/compliance_documents"><i class="bi bi-shield-check"></i> Compliance</a></li>
                                                                                                <li><a class="dropdown-item" href="/audit_logs"><i class="bi bi-clock-history"></i> Audit Logs</a></li>
                                                            </ul>
                        </li>
                                                
                        <!-- Reports -->
                                                <li class="nav-item mega-dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="reportsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-graph-up"></i> Reports
                            </a>
                            <div class="dropdown-menu mega-dropdown-menu" aria-labelledby="reportsDropdown">
                                <div class="row">
                                    <div class="col-lg-3 mega-column">
                                        <h6>Financial Reports</h6>
                                        <a class="dropdown-item" href="/income_statement"><i class="bi bi-graph-up-arrow"></i> Income Statement</a>                                        <a class="dropdown-item" href="/balance_sheet"><i class="bi bi-bar-chart"></i> Balance Sheet</a>                                        <a class="dropdown-item" href="/cash_flow"><i class="bi bi-arrow-left-right"></i> Cash Flow</a>                                        <a class="dropdown-item" href="/trial_balance"><i class="bi bi-journal"></i> Trial Balance</a>                                        <a class="dropdown-item" href="/ledger_report"><i class="bi bi-journal-text"></i> General Ledger</a>                                    </div>
                                    <div class="col-lg-3 mega-column">
                                        <h6>Business Reports</h6>
                                        <a class="dropdown-item" href="/sales_report"><i class="bi bi-cart"></i> Sales Report</a>                                        <a class="dropdown-item" href="/purchase_report"><i class="bi bi-basket"></i> Purchase Report</a>                                        <a class="dropdown-item" href="/inventory_report"><i class="bi bi-boxes"></i> Inventory Report</a>                                        <a class="dropdown-item" href="/profit_loss_report"><i class="bi bi-graph-up"></i> Profit & Loss</a>                                        <a class="dropdown-item" href="/expense_report"><i class="bi bi-cash-stack"></i> Expense Report</a>                                    </div>
                                    <div class="col-lg-3 mega-column">
                                        <h6>Analytics</h6>
                                        <a class="dropdown-item" href="/performance_dashboard"><i class="bi bi-speedometer2"></i> Performance</a>                                        <a class="dropdown-item" href="/customer_analysis"><i class="bi bi-people"></i> Customer Analysis</a>                                        <a class="dropdown-item" href="/product_analysis"><i class="bi bi-box"></i> Product Analysis</a>                                        <a class="dropdown-item" href="/sales_forecast"><i class="bi bi-graph-up-arrow"></i> Sales Forecast</a>                                        <a class="dropdown-item" href="/trends_analysis"><i class="bi bi-activity"></i> Trends</a>                                    </div>
                                    <div class="col-lg-3 mega-column">
                                        <h6>Compliance & Operations</h6>
                                        <a class="dropdown-item" href="/tax_report"><i class="bi bi-percent"></i> Tax Report</a>                                        <a class="dropdown-item" href="/audit_report"><i class="bi bi-shield-check"></i> Audit Report</a>                                        <a class="dropdown-item" href="/compliance_report"><i class="bi bi-file-check"></i> Compliance</a>                                        <a class="dropdown-item" href="/employee_report"><i class="bi bi-person-badge"></i> Employee Report</a>                                        <a class="dropdown-item" href="/asset_report"><i class="bi bi-pc-display"></i> Asset Report</a>                                    </div>
                                </div>
                            </div>
                        </li>
                                                
                        <!-- Admin -->
                                                <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="adminDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-sliders"></i> Admin
                            </a>
                            <ul class="dropdown-menu" aria-labelledby="adminDropdown">
                                <li><h6 class="dropdown-header">User Management</h6></li>
                                <li><a class="dropdown-item" href="/users"><i class="bi bi-people"></i> Users</a></li>
                                <li><a class="dropdown-item" href="/user_roles"><i class="bi bi-shield-check"></i> Roles & Permissions</a></li>
                                <li><h6 class="dropdown-header">System Configuration</h6></li>
                                <li><a class="dropdown-item" href="/system_settings"><i class="bi bi-gear"></i> Settings</a></li>
                                <li><a class="dropdown-item" href="/company_profile"><i class="bi bi-building"></i> Company Profile</a></li>
                                <li><a class="dropdown-item" href="/backup_restore"><i class="bi bi-database"></i> Backup</a></li>
                                <li><h6 class="dropdown-header">Business Settings</h6></li>
                                <li><a class="dropdown-item" href="/tax_settings"><i class="bi bi-percent"></i> Tax</a></li>
                                <li><a class="dropdown-item" href="/payment_settings"><i class="bi bi-credit-card"></i> Payments</a></li>
                                <li><a class="dropdown-item" href="/notification_settings"><i class="bi bi-bell"></i> Notifications</a></li>
                            </ul>
                        </li>
                                            </ul>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content Area -->
    <div class="container-fluid mt-4">

<script>
// Initialize Bootstrap dropdowns
document.addEventListener('DOMContentLoaded', function() {
    // Enable all dropdowns
    var dropdownElements = document.querySelectorAll('.dropdown-toggle');
    dropdownElements.forEach(function(dropdown) {
        new bootstrap.Dropdown(dropdown);
    });
    
    // Mega dropdown positioning
    var megaDropdowns = document.querySelectorAll('.mega-dropdown');
    megaDropdowns.forEach(function(mega) {
        mega.addEventListener('show.bs.dropdown', function(e) {
            var menu = this.querySelector('.dropdown-menu');
            menu.style.left = '50%';
            menu.style.transform = 'translateX(-50%)';
        });
    });
    
    // Close dropdowns when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.dropdown-toggle')) {
            var openDropdowns = document.querySelectorAll('.dropdown-menu.show');
            openDropdowns.forEach(function(dropdown) {
                bootstrap.Dropdown.getInstance(dropdown.previousElementSibling).hide();
            });
        }
    });
});
</script>
<!-- ═══════════════════════════════════════════════════════════════════════
     STYLES
════════════════════════════════════════════════════════════════════════ -->
<style>
/* ── Nav Buttons ─────────────────────────────────────────────────────── */
.wh-nav-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border: 2px solid #dee2e6;
    background: #fff;
    border-radius: 6px;
    font-weight: 600;
    font-size: 0.82rem;
    color: #495057;
    cursor: pointer;
    transition: all .18s;
    white-space: nowrap;
}
.wh-nav-btn:hover {
    border-color: #0d6efd;
    color: #0d6efd;
    background: #f0f5ff;
}
.wh-nav-btn.active {
    border-color: #0d6efd;
    background: #0d6efd;
    color: #fff;
}
.wh-nav-btn .badge {
    background: rgba(255,255,255,0.25);
    color: inherit;
    font-size: 0.72rem;
    padding: 2px 7px;
    border-radius: 10px;
}
.wh-nav-btn.active .badge { background: rgba(255,255,255,0.3); color:#fff; }

/* ── Section Panels ──────────────────────────────────────────────────── */
.section-panel { display: none; }
.section-panel.active { display: block; }

/* ── PRINT ───────────────────────────────────────────────────────────── */
@page { margin: 10mm 8mm 16mm 8mm; }
@media print {

    body.wh-printing * { visibility: hidden; }

    /* Header — fixed at TOP of every page */
    body.wh-printing #whPrintHeader {
        display: block !important;
        visibility: visible !important;
        position: fixed;
        top: 0;
        left: 0; right: 0;
        height: 40mm;
        background: #fff;
        text-align: center;
        border-bottom: 2px solid #0d6efd;
        padding-bottom: 4px;
        z-index: 9999;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    body.wh-printing #whPrintHeader * { visibility: visible !important; }
    body.wh-printing #whPrintHeader img   { max-height: 55px; width: auto; margin-bottom: 2px; }
    body.wh-printing #whPrintHeader h1    { font-size: 13pt; font-weight: 800; color: #0d6efd; text-transform: uppercase; margin: 0; }
    body.wh-printing #whPrintHeader h2    { font-size: 10pt; font-weight: 700; text-transform: uppercase; color: #212529; margin: 2px 0 0; }
    body.wh-printing #whPrintHeader small { font-size: 8.5pt; color: #6c757d; display: block; margin-top: 2px; }

    /* Footer — fixed at BOTTOM of every page */
    body.wh-printing #whPrintFooter {
        display: block !important;
        visibility: visible !important;
        position: fixed;
        bottom: 0;
        left: 0; right: 0;
        height: 28mm;
        background: #fff;
        border-top: 1px solid #dee2e6;
        text-align: center;
        padding-top: 5px;
        font-size: 8pt;
        color: #495057;
        z-index: 9999;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    body.wh-printing #whPrintFooter * { visibility: visible !important; }
    body.wh-printing #whPrintFooter p { margin: 0 0 2px; }
    body.wh-printing #whPrintFooter .powered { font-weight: 700; color: #0d6efd; }

    /* Show only the active section panel */
    body.wh-printing .section-panel.active {
        visibility: visible !important;
        display: block !important;
    }
    body.wh-printing .section-panel.active * { visibility: visible !important; }

    /* Section heading bar */
    body.wh-printing .section-heading {
        background: #0d6efd !important;
        color: #fff !important;
        padding: 6px 10px !important;
        font-weight: 700 !important;
        font-size: 10pt !important;
        text-transform: uppercase !important;
        margin-bottom: 8px !important;
        border-radius: 3px !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    /* Tables */
    body.wh-printing .section-panel table {
        width: 100% !important;
        border-collapse: collapse !important;
        font-size: 9pt !important;
    }
    body.wh-printing .section-panel th,
    body.wh-printing .section-panel td {
        border: 1px solid #dee2e6 !important;
        padding: 5px 7px !important;
        vertical-align: middle !important;
        white-space: normal !important;
        word-break: break-word !important;
    }
    body.wh-printing .section-panel thead th {
        background: #f8f9fa !important;
        font-size: 8pt !important;
        font-weight: 700 !important;
        text-transform: uppercase !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    body.wh-printing .section-panel tr { page-break-inside: avoid; break-inside: avoid; }
    body.wh-printing .table-responsive { overflow: visible !important; }
    body.wh-printing .card { box-shadow: none !important; border: 1px solid #dee2e6 !important; }
}
</style>

<!-- Print header/footer — kept hidden; JS moves them to body level before printing -->
<div id="whPrintHeader" style="display:none;">
            <img src="/uploads/company/logo_1770623052.png" alt="Logo">
        <h1>BEJUNDAS FINANCIAL SERVICES LTD</h1>
    <h2 id="printSectionHeading">Stock Summary</h2>
    <small>POLES &nbsp;|&nbsp; Upgrade of Transmission Line</small>
</div>
<div id="whPrintFooter" style="display:none;">
    <p>This document was <strong>Printed</strong> by
       <strong>Admin Admin - Admin</strong>
       on <strong>20 Apr, 2026 at 07:25:06</strong>
    </p>
    <p class="powered">Powered By BJP Technologies &copy; 2026, All Rights Reserved</p>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════
     SCREEN: Page content
════════════════════════════════════════════════════════════════════════ -->
<div class="container-fluid mt-4">

    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-3 d-print-none">
        <ol class="breadcrumb mb-0" style="font-size:0.75rem;">
            <li class="breadcrumb-item"><a href="/dashboard" class="text-decoration-none text-muted">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="/projects" class="text-decoration-none text-muted">Projects</a></li>
            <li class="breadcrumb-item"><a href="/project_view?id=16" class="text-decoration-none text-muted">Upgrade of Transmission Line</a></li>
            <li class="breadcrumb-item active text-primary fw-bold">Warehouse Stock &amp; History</li>
        </ol>
    </nav>

    <!-- Toolbar -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2 d-print-none wh-toolbar">
        <div>
            <h4 class="fw-bold mb-0">
                <i class="bi bi-building text-primary me-2"></i>POLES            </h4>
            <small class="text-muted">Upgrade of Transmission Line &mdash; Warehouse Stock &amp; History</small>
        </div>
        <div class="d-flex gap-2">
            <a href="/project_view?id=16&tab=inventory" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Back to Project
            </a>
            <button onclick="doPrint()" class="btn btn-primary btn-sm">
                <i class="bi bi-printer me-1"></i> Print
            </button>
        </div>
    </div>

    <!-- ── Navigation Buttons ─────────────────────────────────────────────── -->
    <div class="d-flex flex-wrap gap-2 mb-4 d-print-none wh-nav-bar">
        <button class="wh-nav-btn active" data-panel="sec-stock-summary">
            <i class="bi bi-boxes"></i> Stock Summary
            <span class="badge">4</span>
        </button>
        <button class="wh-nav-btn" data-panel="sec-received">
            <i class="bi bi-truck"></i> Materials Received
            <span class="badge">0</span>
        </button>
        <button class="wh-nav-btn" data-panel="sec-issued">
            <i class="bi bi-truck-flatbed"></i> Materials Issued
            <span class="badge">3</span>
        </button>
        <button class="wh-nav-btn" data-panel="sec-adjustments">
            <i class="bi bi-arrow-left-right"></i> Adjustments
            <span class="badge">2</span>
        </button>
        <button class="wh-nav-btn" data-panel="sec-movements">
            <i class="bi bi-clock-history"></i> Movement History
            <span class="badge">3</span>
        </button>
    </div>

    <!-- ─── SECTION 1: Stock Summary ─────────────────────────────────────── -->
    <div class="section-panel active" id="sec-stock-summary">
        <div class="section-heading d-none d-print-block">1. Stock Summary</div>
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white d-flex justify-content-between align-items-center py-3 d-print-none">
                <h6 class="mb-0 fw-bold">
                    <i class="bi bi-boxes text-primary me-2"></i>Stock Summary
                    <span class="badge bg-success ms-2">4</span>
                </h6>
            </div>
            <div class="card-body p-0">
                                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0" id="tblStockSummary">
                        <thead class="table-light text-uppercase small fw-bold">
                            <tr>
                                <th class="ps-3" style="width:50px;">S/NO</th>
                                <th>Product</th>
                                <th>SKU</th>
                                <th>Category</th>
                                <th class="text-center">Stock Qty</th>
                                <th class="text-center">Reserved</th>
                                <th class="text-center">Available</th>
                                <th>Unit</th>
                            </tr>
                        </thead>
                        <tbody>
                                                        <tr>
                                <td class="ps-3 text-muted fw-bold">1</td>
                                <td><div class="fw-bold small">CAR</div></td>
                                <td><code class="small">PROD1769844179695</code></td>
                                <td><small class="text-muted">test</small></td>
                                <td class="text-center fw-bold">30.000</td>
                                <td class="text-center text-warning">11.000</td>
                                <td class="text-center fw-bold text-success">
                                    19.000                                </td>
                                <td><span class="badge bg-light text-dark border small">kg</span></td>
                            </tr>
                                                        <tr>
                                <td class="ps-3 text-muted fw-bold">2</td>
                                <td><div class="fw-bold small">DOG</div></td>
                                <td><code class="small">PROD1770715331489</code></td>
                                <td><small class="text-muted">Equity</small></td>
                                <td class="text-center fw-bold">119.000</td>
                                <td class="text-center text-warning">20.999</td>
                                <td class="text-center fw-bold text-success">
                                    98.001                                </td>
                                <td><span class="badge bg-light text-dark border small">set</span></td>
                            </tr>
                                                        <tr>
                                <td class="ps-3 text-muted fw-bold">3</td>
                                <td><div class="fw-bold small">LAPTOP</div></td>
                                <td><code class="small">PROD1773816235360</code></td>
                                <td><small class="text-muted">Equity</small></td>
                                <td class="text-center fw-bold">432.000</td>
                                <td class="text-center text-warning">0.000</td>
                                <td class="text-center fw-bold text-success">
                                    432.000                                </td>
                                <td><span class="badge bg-light text-dark border small">pcs</span></td>
                            </tr>
                                                        <tr>
                                <td class="ps-3 text-muted fw-bold">4</td>
                                <td><div class="fw-bold small">motorcycle</div></td>
                                <td><code class="small">PROD1769254247465</code></td>
                                <td><small class="text-muted">Equity</small></td>
                                <td class="text-center fw-bold">-120.000</td>
                                <td class="text-center text-warning">0.000</td>
                                <td class="text-center fw-bold text-danger">
                                    -120.000                                </td>
                                <td><span class="badge bg-light text-dark border small">pcs</span></td>
                            </tr>
                                                    </tbody>
                    </table>
                </div>
                            </div>
        </div>
    </div>

    <!-- ─── SECTION 2: Materials Received ────────────────────────────────── -->
    <div class="section-panel" id="sec-received">
        <div class="section-heading d-none d-print-block">2. Materials Received</div>
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white d-flex justify-content-between align-items-center py-3 d-print-none">
                <h6 class="mb-0 fw-bold">
                    <i class="bi bi-truck text-success me-2"></i>Materials Received
                    <span class="badge bg-secondary ms-2">0</span>
                </h6>
            </div>
            <div class="card-body p-0">
                                    <div class="p-4 text-center text-muted">
                        <i class="bi bi-truck d-block mb-2 fs-2 opacity-25"></i>
                        <p>No materials received in this warehouse.</p>
                    </div>
                            </div>
        </div>
    </div>

    <!-- ─── SECTION 3: Materials Issued ──────────────────────────────────── -->
    <div class="section-panel" id="sec-issued">
        <div class="section-heading d-none d-print-block">3. Materials Issued</div>
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white d-flex justify-content-between align-items-center py-3 d-print-none">
                <h6 class="mb-0 fw-bold">
                    <i class="bi bi-truck-flatbed text-danger me-2"></i>Materials Issued
                    <span class="badge bg-secondary ms-2">3</span>
                </h6>
            </div>
            <div class="card-body p-0">
                                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0" id="tblIssued">
                        <thead class="table-light text-uppercase small fw-bold">
                            <tr>
                                <th class="ps-3" style="width:50px;">S/NO</th>
                                <th>Product</th>
                                <th>SKU</th>
                                <th>DN #</th>
                                <th>Date</th>
                                <th class="text-center">Qty Issued</th>
                                <th>Unit</th>
                                <th>Supplier</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                                                        <tr>
                                <td class="ps-3 text-muted fw-bold">1</td>
                                <td><div class="fw-bold small">CAR</div></td>
                                <td><code class="small">PROD1769844179695</code></td>
                                <td><span class="badge bg-light text-primary border small">DN-20260417-650</span></td>
                                <td><small>17 Apr 2026</small></td>
                                <td class="text-center fw-bold text-danger">-11.000</td>
                                <td><small>kg</small></td>
                                <td><small>MENGI</small></td>
                                <td><span class="badge bg-primary small">
                                    approved                                </span></td>
                            </tr>
                                                        <tr>
                                <td class="ps-3 text-muted fw-bold">2</td>
                                <td><div class="fw-bold small">DOG</div></td>
                                <td><code class="small">PROD1770715331489</code></td>
                                <td><span class="badge bg-light text-primary border small">DN-20260417-650</span></td>
                                <td><small>17 Apr 2026</small></td>
                                <td class="text-center fw-bold text-danger">-20.999</td>
                                <td><small>set</small></td>
                                <td><small>MENGI</small></td>
                                <td><span class="badge bg-primary small">
                                    approved                                </span></td>
                            </tr>
                                                        <tr>
                                <td class="ps-3 text-muted fw-bold">3</td>
                                <td><div class="fw-bold small">DOG</div></td>
                                <td><code class="small">PROD1770715331489</code></td>
                                <td><span class="badge bg-light text-primary border small">DN-20260417-191</span></td>
                                <td><small>17 Apr 2026</small></td>
                                <td class="text-center fw-bold text-danger">-1.000</td>
                                <td><small>set</small></td>
                                <td><small>MAPAMBANO</small></td>
                                <td><span class="badge bg-success small">
                                    delivered                                </span></td>
                            </tr>
                                                    </tbody>
                    </table>
                </div>
                            </div>
        </div>
    </div>

    <!-- ─── SECTION 4: Adjustments ───────────────────────────────────────── -->
    <div class="section-panel" id="sec-adjustments">
        <div class="section-heading d-none d-print-block">4. Adjustments</div>
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white d-flex justify-content-between align-items-center py-3 d-print-none">
                <h6 class="mb-0 fw-bold">
                    <i class="bi bi-arrow-left-right text-warning me-2"></i>Adjustments
                    <span class="badge bg-secondary ms-2">2</span>
                </h6>
            </div>
            <div class="card-body p-0">
                                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0" id="tblAdjustments">
                        <thead class="table-light text-uppercase small fw-bold">
                            <tr>
                                <th class="ps-3" style="width:50px;">S/NO</th>
                                <th>Date</th>
                                <th>Product</th>
                                <th>SKU</th>
                                <th>Type</th>
                                <th class="text-center">Quantity</th>
                                <th>Unit</th>
                                <th>Adjusted By</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                                                                                    <tr>
                                <td class="ps-3 text-muted fw-bold">1</td>
                                <td><small>19 Apr 2026</small></td>
                                <td><div class="fw-bold small">motorcycle</div></td>
                                <td><code class="small">PROD1769254247465</code></td>
                                <td><span class="badge bg-secondary">Correction</span></td>
                                <td class="text-center fw-bold text-success">+120.000</td>
                                <td><small>pcs</small></td>
                                <td><small class="text-muted">admin</small></td>
                                <td><small class="text-muted">nothing</small></td>
                            </tr>
                                                                                    <tr>
                                <td class="ps-3 text-muted fw-bold">2</td>
                                <td><small>16 Apr 2026</small></td>
                                <td><div class="fw-bold small">DOG</div></td>
                                <td><code class="small">PROD1770715331489</code></td>
                                <td><span class="badge bg-success">Adj In</span></td>
                                <td class="text-center fw-bold text-success">+120.000</td>
                                <td><small>set</small></td>
                                <td><small class="text-muted">admin</small></td>
                                <td><small class="text-muted">nothing</small></td>
                            </tr>
                                                    </tbody>
                    </table>
                </div>
                            </div>
        </div>
    </div>

    <!-- ─── SECTION 5: Movement History ──────────────────────────────────── -->
    <div class="section-panel" id="sec-movements">
        <div class="section-heading d-none d-print-block">5. Movement History</div>
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white d-flex justify-content-between align-items-center py-3 d-print-none">
                <h6 class="mb-0 fw-bold">
                    <i class="bi bi-clock-history text-info me-2"></i>Movement History
                    <span class="badge bg-secondary ms-2">3</span>
                </h6>
            </div>
            <div class="card-body p-0">
                                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0" id="tblMovements">
                        <thead class="table-light text-uppercase small fw-bold">
                            <tr>
                                <th class="ps-3" style="width:50px;">S/NO</th>
                                <th>Date / Time</th>
                                <th>Product</th>
                                <th>SKU</th>
                                <th>Type</th>
                                <th class="text-center">Quantity</th>
                                <th>Unit</th>
                                <th>Ref #</th>
                            </tr>
                        </thead>
                        <tbody>
                                                                                    <tr>
                                <td class="ps-3 text-muted fw-bold">1</td>
                                <td><small>19 Apr 2026 00:21</small></td>
                                <td><div class="fw-bold small">motorcycle</div></td>
                                <td><code class="small">PROD1769254247465</code></td>
                                <td><span class="badge bg-secondary">Correction</span></td>
                                <td class="text-center fw-bold text-success">+120.000</td>
                                <td><small>pcs</small></td>
                                <td><small class="text-primary">ADJ-20260131-230</small></td>
                            </tr>
                                                                                    <tr>
                                <td class="ps-3 text-muted fw-bold">2</td>
                                <td><small>17 Apr 2026 16:31</small></td>
                                <td><div class="fw-bold small">DOG</div></td>
                                <td><code class="small">PROD1770715331489</code></td>
                                <td><span class="badge bg-danger">Issue Out</span></td>
                                <td class="text-center fw-bold text-danger">-1.000</td>
                                <td><small>set</small></td>
                                <td><small class="text-primary">DO-20260417-549</small></td>
                            </tr>
                                                                                    <tr>
                                <td class="ps-3 text-muted fw-bold">3</td>
                                <td><small>16 Apr 2026 21:41</small></td>
                                <td><div class="fw-bold small">DOG</div></td>
                                <td><code class="small">PROD1770715331489</code></td>
                                <td><span class="badge bg-success">Adj In</span></td>
                                <td class="text-center fw-bold text-success">+120.000</td>
                                <td><small>set</small></td>
                                <td><small class="text-primary">ADJ-20260131-230</small></td>
                            </tr>
                                                    </tbody>
                    </table>
                </div>
                            </div>
        </div>
    </div>

</div><!-- /.container-fluid -->

<script>
var sectionNames = {
    'sec-stock-summary': 'Stock Summary',
    'sec-received':      'Materials Received',
    'sec-issued':        'Materials Issued',
    'sec-adjustments':   'Adjustments',
    'sec-movements':     'Movement History'
};

var tableMap = {
    'sec-stock-summary': '#tblStockSummary',
    'sec-received':      '#tblReceived',
    'sec-issued':        '#tblIssued',
    'sec-adjustments':   '#tblAdjustments',
    'sec-movements':     '#tblMovements'
};

var lenValues  = [10, 25, 50, 100, -1];
var lenLabels  = ['10', '25', '50', '100', 'All'];

function attachLengthButtons(tableId) {
    var $hdr = $(tableId).closest('.section-panel').find('.card-header');
    var opts = '';
    lenValues.forEach(function (n, i) {
        opts += '<option value="' + n + '"' + (n === 25 ? ' selected' : '') + '>' + lenLabels[i] + '</option>';
    });
    var html = '<div class="d-flex align-items-center gap-2 d-print-none me-auto">'
             + '<small class="text-muted">Show:</small>'
             + '<select class="form-select form-select-sm dt-len-select" style="width:75px;" data-table="' + tableId + '">' + opts + '</select>'
             + '</div>';
    $hdr.prepend(html);
}

$(document).ready(function () {
    var dtOpts = {
        responsive: true,
        autoWidth: false,
        pageLength: 25,
        dom: '<"top d-print-none"f>rt<"bottom d-print-none"ip><"clear">'
    };
    ['#tblStockSummary','#tblReceived','#tblIssued','#tblAdjustments','#tblMovements'].forEach(function (id) {
        if ($(id).length) {
            $(id).DataTable(dtOpts);
            attachLengthButtons(id);
        }
    });

    // Length dropdown change (delegated — works across all tables)
    $(document).on('change', '.dt-len-select', function () {
        var tblId = $(this).data('table');
        var len   = parseInt($(this).val());
        $(tblId).DataTable().page.len(len).draw();
    });

    $('.wh-nav-btn').on('click', function () {
        var panelId = $(this).data('panel');

        $('.wh-nav-btn').removeClass('active');
        $(this).addClass('active');

        $('.section-panel').removeClass('active');
        $('#' + panelId).addClass('active');

        // Update print heading to match active section
        document.getElementById('printSectionHeading').textContent = sectionNames[panelId] || panelId;

        var tblId = tableMap[panelId];
        if (tblId && $.fn.DataTable.isDataTable(tblId)) {
            $(tblId).DataTable().columns.adjust().responsive.recalc();
        }
    });
});

function doPrint() {
    // Set heading to match currently active section
    var activeBtn = document.querySelector('.wh-nav-btn.active');
    var panelId   = activeBtn ? activeBtn.dataset.panel : 'sec-stock-summary';
    document.getElementById('printSectionHeading').textContent = sectionNames[panelId] || 'Stock Summary';


    // Move header & footer to body level so position:fixed works outside container-fluid
    var hdr = document.getElementById('whPrintHeader');
    var ftr = document.getElementById('whPrintFooter');
    hdr.style.display = 'block';
    ftr.style.display = 'block';
    document.body.appendChild(hdr);
    document.body.appendChild(ftr);

    document.body.classList.add('wh-printing');

    window.addEventListener('afterprint', function restore() {
        document.body.classList.remove('wh-printing');
        hdr.style.display = 'none';
        ftr.style.display = 'none';
        window.removeEventListener('afterprint', restore);
    });

    window.print();
}
</script>

<footer></div><footer class="text-center py-3 text-muted d-print-none"><p>© 2026 Business Management System. All Rights Reserved.</p></footer>
<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<!-- Global Modal Close on Success -->
<script>
$(document).ajaxSuccess(function(event, xhr, settings, data) {
    // If the response indicates success, try to close any open modals
    // EXCLUSIONS: 
    // 1. Only close modals if the request was NOT a GET request
    // 2. Do NOT close for activity logging or heartbeat requests
    const isLogRequest = settings.url.includes('log_activity') || settings.url.includes('log_audit');
    
    if (data && data.success === true && settings.type !== 'GET' && !isLogRequest) {
        const openModal = document.querySelector('.modal.show');
        if (openModal) {
            const modalInstance = bootstrap.Modal.getInstance(openModal);
            if (modalInstance) {
                modalInstance.hide();
            }
        }
    }
});
</script>

<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>