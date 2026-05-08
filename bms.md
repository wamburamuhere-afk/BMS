# Business Management System (BMS) — Project Summary

## Overview

BMS is a comprehensive, web-based **Business Management System** built for small to medium enterprises. It covers the full operational lifecycle of a business: sales, purchasing, inventory, HR, accounting, document management, and reporting — all accessible through a role-based, multi-user web interface.

The system is localised for **East Africa (Tanzania)** — timezone is set to `Africa/Dar_es_Salaam (UTC+03:00)`, and geographic data (districts, councils, wards) is included for tender and operations management.

---

## Technology Stack

| Layer | Technology |
|---|---|
| Server | Apache (WampServer on Windows) |
| Backend | PHP 7.x / 8.x (procedural + PDO) |
| Database | MySQL (database name: `bms`) |
| Frontend | HTML5, Bootstrap 5.3, jQuery 3.7, Bootstrap Icons, Font-Awesome 6 |
| UI Extras | Select2 (dropdowns), SweetAlert2 (modals/alerts), Chart.js (dashboards) |
| PDF Generation | TCPDF library |
| Session Handling | PHP native sessions |

---

## Database

- **Engine:** MySQL
- **Database name:** `bms`
- **Host:** `localhost`
- **Connection:** PHP PDO with `ERRMODE_EXCEPTION`
- **Config file:** `includes/config.php`
- **Timezone:** forced to `+03:00` on every connection

Key table groups (inferred from schema and module files):

| Group | Tables |
|---|---|
| Users & Access | `users`, `roles`, `permissions`, `role_permissions` |
| Customers | `customers`, `customer_groups` |
| Suppliers | `suppliers`, `supplier_categories`, `supplier_payments` |
| Products & Stock | `products`, `categories`, `brands`, `warehouses`, `locations`, `stock_movements`, `stock_adjustments`, `stock_transfers` |
| Sales | `invoices`, `invoice_items`, `sales_orders`, `quotations`, `sales_returns`, `payments` |
| Purchases | `purchase_orders`, `rfq` (Request for Quotation), `grn` (Goods Received Notes), `purchase_returns`, `delivery_notes` |
| Accounting | `transactions`, `journals`, `chart_of_accounts`, `bank_accounts`, `budgets`, `expenses`, `petty_cash`, `payment_vouchers`, `cash_registers` |
| HR | `employees`, `attendance`, `leaves`, `payroll`, `repayment_cycles` |
| Operations | `projects`, `tenders`, `maintenance`, `assets` |
| Loans | `loans`, `loan_applications`, `repayment_schedules` |
| System | `system_settings`, `audit_logs`, `activity_log` |

---

## Project Structure

```
bms/
├── index.php                  # Entry point & router dispatcher
├── roots.php                  # Central routing map + module constants
├── login.php                  # Login page (UI)
├── logout.php                 # Session destruction
├── header.php                 # Global HTML header, nav, session guard
├── footer.php                 # Global HTML footer
├── helpers.php                # Global utility functions (loans, stats, permissions helpers)
├── unauthorized.php           # 403 page
│
├── includes/
│   └── config.php             # DB connection (PDO, MySQL), timezone
│
├── core/
│   └── permissions.php        # RBAC: loadUserPermissions(), canView(), canEdit(), canDelete()
│
├── actions/                   # Form POST handlers (login, register, uploads)
│
├── app/
│   ├── dashboard.php          # Main dashboard
│   ├── activity_log.php       # User activity log
│   ├── bms/                   # Core business modules
│   │   ├── banking/           # Banking & reconciliation
│   │   ├── customer/          # Customer management
│   │   ├── grn/               # Goods Received Notes
│   │   ├── invoice/           # Invoices, payments, income statement
│   │   ├── loans/             # Loan applications & schedules
│   │   ├── operations/        # Projects, assets, maintenance
│   │   ├── pos/               # Point of Sale + HR (employees, payroll, attendance, leaves)
│   │   ├── product/           # Products, categories, brands, services
│   │   ├── purchase/          # Purchase orders, RFQ, returns
│   │   ├── sales/             # Sales orders, quotations, returns
│   │   ├── stock/             # Warehouses, locations, transfers, adjustments
│   │   ├── Suppliers/         # Supplier management
│   │   └── tenders/           # Tender management
│   └── constant/              # Cross-cutting/shared modules
│       ├── accounts/          # Chart of accounts, budgets, journals, petty cash, reconciliation
│       ├── communication/     # Email/SMS templates, campaigns, messaging
│       ├── document/          # Document library, templates, e-signatures, compliance
│       ├── integrations/      # API dashboard, payment gateways, webhooks
│       ├── profile/           # User profile
│       ├── reports/           # Financial statements, audit, balance sheet, cash flow
│       ├── resources/         # Shared resources
│       └── settings/          # Users, roles, system settings, tax, company profile, backup
│
├── api/                       # JSON API endpoints (called via AJAX)
├── ajax/                      # Legacy AJAX handlers
├── assets/                    # CSS, JS, fonts, images
├── uploads/                   # User-uploaded files (logos, attachments)
├── docs/ / documents/         # Document storage
├── TCPDF/                     # PDF generation library
└── backups/                   # Database backup files
```

---

## Application Flow

### 1. Entry & Routing

```
Browser Request (any URL)
        │
        ▼
    index.php
        │
        ├─ Loads roots.php
        │       ├─ Starts session
        │       ├─ Loads includes/config.php  →  MySQL PDO connection
        │       ├─ Loads helpers.php          →  utility functions
        │       ├─ Loads core/permissions.php →  RBAC helpers
        │       └─ Loads actions/check_auth.php
        │
        ├─ If root URL: redirect to /dashboard or /login
        └─ Otherwise: look up URL in $routes[] map → require matching PHP file
```

The routing system is a **flat PHP array** mapping clean URL strings to absolute file paths. There is no framework — `index.php` resolves the `REQUEST_URI`, strips the base path, and `require`s the matching file.

### 2. Authentication

```
GET /login
    └─ login.php renders the login form (Bootstrap 5)

POST actions/login.php  (AJAX, JSON response)
    ├─ Fetch user row by username
    ├─ password_verify() against bcrypt hash
    ├─ On success:
    │       ├─ Set $_SESSION[user_id, role_id, role, first_name, last_name]
    │       ├─ loadUserPermissions(role_id) → load RBAC into $_SESSION['permissions']
    │       └─ Update users.last_login = NOW()
    └─ Return JSON {success: true} → JS redirects to /dashboard
```

### 3. Session Guard

Every protected page starts with `require_once roots.php`, which triggers `header.php`. The header checks `$_SESSION['user_id']`; if absent it redirects to `/login`. Role and permissions are reloaded on each request from the database.

### 4. RBAC (Role-Based Access Control)

- Roles are stored in the `roles` table.
- Granular permissions per page are stored in `permissions` and linked via `role_permissions` (can_view, can_create, can_edit, can_delete per `page_key`).
- `loadUserPermissions($roleId)` fetches all permissions for the user's role and stores them in `$_SESSION['permissions']`.
- Helper functions: `canView($page)`, `canCreate($page)`, `canEdit($page)`, `canDelete($page)`, `isAdmin()`, `hasPermission($key)`.
- The admin role bypasses all permission checks.

### 5. Page Render Cycle

```
Routed PHP file (e.g. customers.php)
    ├─ require_once roots.php       (DB, session, permissions)
    ├─ require_once header.php      (HTML <head>, navbar, sidebar)
    ├─ Permission check (canView / redirect to /unauthorized)
    ├─ DB queries via $pdo (PDO prepared statements)
    ├─ Render HTML with embedded PHP
    └─ require_once footer.php      (scripts, closing tags)
```

### 6. API / AJAX Pattern

Frontend pages make `$.ajax()` calls to `/api/*.php` endpoints. Each API file:
1. Connects via the shared PDO from `includes/config.php`
2. Validates input
3. Executes queries
4. Returns `json_encode([...])` with success/error payload

---

## Modules & Features

### Sales & Revenue
- **Invoices** — create, edit, view, print, record payments
- **Sales Orders** — order lifecycle with status tracking
- **Quotations** — convert to sales orders
- **Sales Returns** — reverse sales with stock restoration
- **Point of Sale (POS)** — quick-sell interface with receipt printing
- **Income Statement** — P&L reporting

### Purchasing
- **RFQ** (Request for Quotation) — send to suppliers, approve
- **Purchase Orders** — full PO lifecycle, approval workflow
- **GRN** (Goods Received Notes) — receive stock against POs
- **Delivery Notes** — outbound delivery tracking
- **Purchase Returns** — return goods to suppliers

### Inventory & Stock
- **Products & Services** — catalogue with categories, brands, units
- **Warehouses & Locations** — multi-warehouse support
- **Stock Movements** — full audit trail of all stock changes
- **Stock Adjustments** — manual corrections with reasons
- **Stock Transfers** — move stock between warehouses
- **Inventory Valuation** — current stock value reporting
- **Low-Stock Alerts** — dashboard notifications

### Accounting
- **Chart of Accounts** — double-entry bookkeeping foundation
- **Journals** — manual journal entries
- **Transactions** — ledger transaction tracking
- **Bank Accounts & Reconciliation** — match transactions to bank statements
- **Budgets** — budget creation and variance tracking
- **Expenses** — expense management with categories and approval
- **Petty Cash** — petty cash register with print
- **Payment Vouchers** — formal voucher with print
- **Cash Registers** — shift-based cash register sessions
- **Trial Balance** — debit/credit summary
- **Financial Statements** — balance sheet, cash flow, income statement

### HR & Payroll
- **Employees** — staff records, contracts, departments
- **Attendance** — mark present/absent/late, import/export
- **Leaves** — leave applications, balances, approval workflow
- **Payroll** — payroll processing, bulk updates, payslips, export
- **Shifts** — shift management

### Operations
- **Projects** — project management with milestones, progress reports, budgets, financial reports
- **Tenders** — tender lifecycle management with geographic data (districts/councils/wards)
- **Assets** — fixed asset register, maintenance tracking
- **Maintenance** — maintenance scheduling and records

### Loans (partial)
- **Loan Applications** — apply, view schedules
- **Repayment Schedules** — flat rate, reducing balance, EMI calculations
- Other loan sub-modules (payments, products, overdue) are marked "coming soon"

### Customers & Suppliers
- Customer groups, import, statements, documents
- Supplier categories, payment history

### Document Management
- Document library, templates (with preview), workflows
- E-signatures, compliance documents, loan documents

### Communication
- SMS alerts, SMS templates
- Email templates, campaigns
- Message centre, notification centre, lead generation

### Reports
- Sales, purchase, inventory, asset, audit, tax reports
- Ledger report, customer analysis, product analysis, trends analysis
- Performance dashboard, employee report, compliance report, repayment report

### Settings
- Users management (add, edit, roles)
- Role & permission management
- System settings (company profile, logo, type)
- Tax settings, payment settings
- Backup & restore
- Notification settings

---

## Security Model

- Passwords hashed with PHP `password_hash()` / `password_verify()` (bcrypt)
- All DB queries use **PDO prepared statements** (SQL injection protection)
- RBAC enforced on every page via session-loaded permissions
- Session cookie scoped to `/` with no fixed lifetime (browser session)
- Audit logging via `audit_logs` table for sensitive operations
- `htmlspecialchars()` used on output for XSS protection

---

## Configuration

| Setting | Value / Location |
|---|---|
| DB host | `localhost` |
| DB name | `bms` |
| DB user | `root` (WampServer default) |
| DB password | *(empty — local dev)* |
| Timezone | `Africa/Dar_es_Salaam` (UTC+03:00) |
| Config file | `includes/config.php` |
| PDF library | `TCPDF/` |
| Uploads | `uploads/` |
| Backups | `backups/` |
