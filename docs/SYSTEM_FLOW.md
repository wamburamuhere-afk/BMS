# BMS — System Flow Documentation

**Version:** 1.0  
**Date:** 2026-04-18  
**System:** Business Management System (BMS)  
**Environment:** WampServer (Apache + PHP + MySQL), Windows, UTC+03:00 (Africa/Dar_es_Salaam)

---

## Table of Contents

1. [Application Bootstrap Flow](#1-application-bootstrap-flow)
2. [Authentication Flow](#2-authentication-flow)
3. [Request Routing Flow](#3-request-routing-flow)
4. [Permission & Authorization Flow](#4-permission--authorization-flow)
5. [Page Rendering Flow](#5-page-rendering-flow)
6. [API / AJAX Flow](#6-api--ajax-flow)
7. [Module Flows](#7-module-flows)
   - 7.1 [Sales Flow](#71-sales-flow)
   - 7.2 [Purchasing Flow](#72-purchasing-flow)
   - 7.3 [Inventory / Stock Flow](#73-inventory--stock-flow)
   - 7.4 [Accounting Flow](#74-accounting-flow)
   - 7.5 [HR & Payroll Flow](#75-hr--payroll-flow)
   - 7.6 [Loans Flow](#76-loans-flow)
   - 7.7 [Operations Flow](#77-operations-flow)
8. [Database Connection Flow](#8-database-connection-flow)
9. [File Upload Flow](#9-file-upload-flow)
10. [PDF Generation Flow](#10-pdf-generation-flow)
11. [User & Role Management Flow](#11-user--role-management-flow)
12. [System Settings Flow](#12-system-settings-flow)
13. [Key Files Reference](#13-key-files-reference)

---

## 1. Application Bootstrap Flow

Every HTTP request follows this sequence before any page logic runs:

```
Browser Request
    │
    ▼
Apache mod_rewrite (.htaccess)
    │  ├─ Block: roots.php, includes/config.php, core/*.php  → 403 Forbidden
    │  ├─ Pass:  existing static files (css, js, images)     → serve directly
    │  └─ All other URLs                                     → index.php
    │
    ▼
index.php
    │
    ├─ require_once roots.php
    │       │
    │       ├─ session_start()  (if not already started)
    │       ├─ ob_start()       (output buffer)
    │       ├─ Define constants: ROOT_DIR, all MODULE _DIR constants
    │       ├─ require_once includes/config.php    → creates $pdo (PDO/MySQL)
    │       ├─ require_once helpers.php            → loads 400+ utility functions
    │       ├─ require_once core/permissions.php   → loads RBAC functions
    │       └─ require_once actions/check_auth.php → isAuthenticated(), getCurrentUserId()
    │
    ├─ Parse REQUEST_URI → $clean_uri
    │
    ├─ If root URL ("/"):
    │       ├─ Session has user_id?  → redirect to /dashboard
    │       └─ No session           → redirect to /login
    │
    └─ Else: handleRoute($clean_uri)
            ├─ Look up $clean_uri in $routes array
            ├─ Found → require the mapped PHP file
            └─ Not found → 404
```

**Key Constants Defined in roots.php:**

| Constant | Path |
|---|---|
| `ROOT_DIR` | `C:\wamp64\www\bms` |
| `BMS_DIR` | `ROOT_DIR/app/bms` |
| `ACCOUNTS_DIR` | `ROOT_DIR/app/constant/accounts` |
| `REPORTS_DIR` | `ROOT_DIR/app/constant/reports` |
| `SETTINGS_DIR` | `ROOT_DIR/app/constant/settings` |
| `API_DIR` | `ROOT_DIR/api` |
| `AJAX_DIR` | `ROOT_DIR/ajax` |

---

## 2. Authentication Flow

### 2.1 Login

```
User submits login form (AJAX POST to actions/login.php)
    │
    ▼
actions/login.php
    │
    ├─ session_start()
    ├─ require_once includes/config.php   → $pdo available
    ├─ SELECT * FROM users WHERE username = ?
    ├─ password_verify($input, $user['password'])   ← bcrypt check
    │
    ├─ FAIL → JSON { success: false, message: "Invalid username or password" }
    │
    └─ SUCCESS:
            ├─ UPDATE users SET last_login = NOW()
            ├─ Set session variables:
            │       $_SESSION['user_id']
            │       $_SESSION['role_id']
            │       $_SESSION['role']
            │       $_SESSION['user_role']
            │       $_SESSION['first_name']
            │       $_SESSION['last_name']
            ├─ loadUserPermissions($role_id)
            │       └─ SELECT page_key, can_view, can_create, can_edit, can_delete
            │              FROM role_permissions JOIN permissions
            │              WHERE role_id = ?
            │          → stores as $_SESSION['permissions'][page_key][view/create/edit/delete]
            └─ JSON { success: true }
                    └─ Browser redirects to /dashboard
```

### 2.2 Session Guard (Every Protected Page)

```
header.php included at top of each module page
    │
    ├─ Check $_SESSION['user_id'] exists
    │       └─ Missing → header("Location: login") + exit
    │
    ├─ SELECT username FROM users WHERE user_id = ?
    ├─ SELECT role_id, role_name FROM users JOIN roles
    ├─ Update $_SESSION['role_id'], ['user_role'], ['role']
    ├─ loadUserPermissions($role_id)   ← reloads on every request
    ├─ Fetch company_name, company_logo from system_settings
    └─ Render HTML <head> + navbar + sidebar
```

### 2.3 Logout

```
GET /logout
    │
    ▼
logout.php
    ├─ session_start()
    ├─ session_unset()
    ├─ session_destroy()
    └─ redirect to /login
```

---

## 3. Request Routing Flow

```
$routes array (defined in roots.php) — 1100+ entries
    │
    ├─ Format:  'clean-url'  =>  CONSTANT_DIR . '/file.php'
    │
    ├─ Example mappings:
    │       'dashboard'              → app/dashboard.php
    │       'customers'             → app/bms/customer/customers.php
    │       'invoices'              → app/bms/invoice/invoices.php
    │       'purchase-orders'       → app/bms/purchase/purchase_orders.php
    │       'api/get_customers'     → api/account/get_customers.php
    │       'bank-reconciliation'   → app/constant/accounts/bank_reconciliation.php
    │
    └─ handleRoute() logic:
            1. Lookup $routes[$clean_uri]
            2. If found → require($file)  → page renders
            3. If not found → try $clean_uri . '.php' as literal file path
            4. Still not found → return false → index.php sends 404

URL Pattern (Clean URLs via .htaccess):
    Browser:    /customers
    Apache:     rewrites to index.php
    index.php:  $clean_uri = "customers"
    routes:     CUSTOMERS_DIR . '/customers.php'  → rendered
```

---

## 4. Permission & Authorization Flow

### 4.1 RBAC Model

```
Database Tables:
    users         → user_id, role_id, username, password, ...
    roles         → role_id, role_name
    permissions   → permission_id, page_key, description
    role_permissions → role_id, permission_id, can_view, can_create, can_edit, can_delete
```

### 4.2 Permission Check Flow

```
Page requires permission check
    │
    ├─ isAdmin()?   ($_SESSION['role_id'] == 1)
    │       └─ YES → full access, no further checks
    │
    └─ NO → check $_SESSION['permissions'][$page_key][$action]
                ├─ true  → allowed
                └─ false → requireViewPermission() → redirect to /unauthorized (HTTP 403)
```

### 4.3 Available Permission Functions

| Function | Purpose |
|---|---|
| `isAdmin()` | True if role_id === 1 (Super Admin) |
| `canView($pageKey)` | Check view permission |
| `canCreate($pageKey)` | Check create permission |
| `canEdit($pageKey)` | Check edit permission |
| `canDelete($pageKey)` | Check delete permission |
| `hasPermission($pageKey)` | Any of view/edit/delete |
| `requireViewPermission($pageKey)` | Check or redirect to /unauthorized |
| `loadUserPermissions($roleId)` | Load permissions into session |
| `reloadPermissions()` | Re-fetch permissions mid-session |

### 4.4 Module-Level Access Helpers

| Function | Checks permissions for |
|---|---|
| `hasReportsAccess()` | financial_statements, sales_reports, etc. |
| `hasAccountsAccess()` | expenses, journals, budget, etc. |
| `hasCommunicationAccess()` | sms_alerts, campaign_management, etc. |
| `hasDocumentsAccess()` | invoices, customers, suppliers, etc. |

---

## 5. Page Rendering Flow

Each BMS module page follows this pattern:

```
Module Page (e.g., customers.php)
    │
    ├─ [Optional] require HEADER_FILE  (header.php)
    │       ├─ Auth check
    │       ├─ Permissions reload
    │       ├─ Company branding
    │       └─ Render HTML head + navbar + sidebar
    │
    ├─ PHP logic block:
    │       ├─ Validate permissions (canView/canCreate etc.)
    │       ├─ Handle POST data if form submitted
    │       └─ Run DB queries for page data
    │
    ├─ HTML template block:
    │       ├─ Bootstrap 5 grid layout
    │       ├─ DataTables for lists
    │       ├─ Bootstrap modals for create/edit forms
    │       └─ Inline <script> for page-specific JS
    │
    └─ [Optional] require FOOTER_FILE  (footer.php)
            └─ Closing HTML tags + global scripts
```

---

## 6. API / AJAX Flow

All dynamic data operations use jQuery AJAX → PHP JSON endpoints:

```
User Action (button click, form submit, dropdown change)
    │
    ▼
jQuery $.ajax() call
    │
    ├─ URL:    /api/{category}/{endpoint}   (e.g., /api/account/get_accounts)
    ├─ Method: GET or POST
    ├─ Data:   form data or JSON payload
    │
    ▼
api/{category}/{endpoint}.php
    │
    ├─ session check (isAuthenticated or direct $pdo check)
    ├─ Input sanitization
    ├─ PDO prepared statement → MySQL query
    ├─ Build response array
    └─ echo json_encode($response)
    │
    ▼
jQuery success callback
    ├─ Parse JSON response
    ├─ Update DOM (DataTable reload, modal close, SweetAlert2 confirmation)
    └─ On error → SweetAlert2 error dialog
```

**API Directory Structure:**

```
api/
├─ account/      → Chart of accounts, journals, bank operations (40+ endpoints)
├─ sales/        → Invoices, sales orders, POS
├─ stock/        → Warehouses, movements, transfers
├─ document/     → Document library operations
├─ cash_register/→ Cash register shifts, transactions
├─ petty_cash/   → Petty cash entries
├─ payroll/      → Payroll calculations
├─ pos/          → Point of Sale transactions
├─ operations/   → Projects, assets, maintenance
├─ reports/      → Report data generation
└─ helpers/      → Shared utilities
```

---

## 7. Module Flows

### 7.1 Sales Flow

```
Customer → Quotation → Sales Order → Invoice → Payment

1. QUOTATION
   ├─ Create quotation (products, quantities, prices)
   ├─ Send to customer
   └─ Convert to Sales Order (one-click)

2. SALES ORDER
   ├─ Created from quotation or directly
   ├─ Status: Pending → Confirmed → Processing → Delivered → Completed
   └─ Convert to Invoice

3. INVOICE
   ├─ Created from Sales Order or directly
   ├─ Line items: product, qty, unit price, tax, discount
   ├─ Totals: subtotal, tax amount, discount, grand total
   ├─ Status: Draft → Sent → Partially Paid → Paid → Overdue
   ├─ Print/PDF via TCPDF
   └─ Record Payment → updates invoice status + creates accounting entry

4. PAYMENT RECORDING
   ├─ Amount, date, payment method (cash/bank/mobile)
   ├─ Creates: payment record in payments table
   ├─ Updates: invoice balance_due, status
   └─ Creates: double-entry journal (Debit Bank/Cash, Credit Accounts Receivable)

5. POINT OF SALE (POS)
   ├─ Select products → add to cart
   ├─ Apply discount/tax
   ├─ Choose payment method
   ├─ Print receipt
   └─ Creates: pos_sales + pos_sale_items records
```

### 7.2 Purchasing Flow

```
Need → RFQ → Purchase Order → GRN → Delivery Note → Supplier Payment

1. REQUEST FOR QUOTATION (RFQ)
   ├─ Select supplier, products, quantities
   └─ Send to supplier for pricing

2. PURCHASE ORDER (PO)
   ├─ Created from RFQ or directly
   ├─ Status: Draft → Sent → Confirmed → Partially Received → Received → Cancelled
   └─ Approval workflow if configured

3. GOODS RECEIVED NOTE (GRN)
   ├─ Triggered when goods arrive from supplier
   ├─ Linked to PO
   ├─ Record actual quantities received
   ├─ Status: Draft → Confirmed
   ├─ On confirm → stock_movements updated (stock IN)
   └─ Creates accounting entry: Debit Inventory, Credit Accounts Payable

4. DELIVERY NOTE
   ├─ Outbound: records products delivered to customer
   └─ Linked to sales order

5. SUPPLIER PAYMENT
   ├─ Record payment to supplier
   ├─ Linked to PO/GRN
   └─ Updates supplier_ledger, creates journal entry
```

### 7.3 Inventory / Stock Flow

```
Product Setup → Warehouse Assignment → Stock Movements

1. PRODUCT SETUP
   ├─ Define: name, SKU, category, brand, unit of measure
   ├─ Set: cost price, selling price, tax rate
   ├─ Assign: default warehouse + location
   └─ Upload product image

2. WAREHOUSES & LOCATIONS
   ├─ warehouses: top-level storage facility
   └─ locations: sub-sections within a warehouse (aisles, bins, shelves)

3. STOCK MOVEMENT TRIGGERS
   ├─ GRN confirmed       → stock IN  (product_stocks.quantity + movement record)
   ├─ Invoice/Sales Order → stock OUT
   ├─ Sales Return        → stock IN
   ├─ Purchase Return     → stock OUT
   ├─ Stock Adjustment    → manual correction (with reason)
   └─ Stock Transfer      → OUT from source warehouse, IN to destination

4. INVENTORY VALUATION
   ├─ Calculates: stock_quantity × cost_price per product per warehouse
   └─ Reports: total inventory value by warehouse, category, or product

5. LOW STOCK ALERTS
   └─ Triggered when product_stocks.quantity < products.reorder_level
```

### 7.4 Accounting Flow

```
All financial transactions feed into double-entry bookkeeping.

1. CHART OF ACCOUNTS
   ├─ Hierarchy: account_type → account_category → account
   ├─ Types: Asset, Liability, Equity, Revenue, Expense
   └─ Each account has: code, name, type, balance

2. JOURNAL ENTRIES
   ├─ Manual journals: debit/credit pairs
   └─ Auto-journals from: invoices, payments, GRN, payroll, expenses

3. BANK ACCOUNTS
   ├─ Record: deposits, withdrawals, transfers
   ├─ Bank Reconciliation:
   │       ├─ Import or enter bank statement
   │       ├─ Match transactions to ledger
   │       └─ Flag unmatched items
   └─ Cash Registers: shift-based cash tracking (open float → close shift)

4. BUDGETS
   ├─ Create budget per account per period
   ├─ Track: budget vs actual variance
   └─ Reports: budget performance

5. EXPENSES
   ├─ Record expense: amount, category, date, vendor
   ├─ Attach receipt
   ├─ Approval flow (if configured)
   └─ Creates journal entry on approval

6. FINANCIAL STATEMENTS GENERATED:
   ├─ Income Statement (Profit & Loss)
   ├─ Balance Sheet
   ├─ Trial Balance
   ├─ Cash Flow Statement
   └─ General Ledger
```

### 7.5 HR & Payroll Flow

```
1. EMPLOYEE SETUP
   ├─ Personal details, photo, contact info
   ├─ Assign: department, designation, employment_type
   ├─ Assign: shift schedule
   └─ Define: salary components (basic, allowances, deductions)

2. ATTENDANCE
   ├─ Mark daily: present / absent / late / half-day
   ├─ Import bulk attendance
   └─ Audit log on every change

3. LEAVE MANAGEMENT
   ├─ Employee submits leave application
   ├─ Manager approves/rejects
   ├─ System deducts from leave_balance
   └─ Reflected in attendance and payroll

4. PAYROLL PROCESSING
   ├─ Select payroll period (month/year)
   ├─ System calculates:
   │       basic salary + allowances − deductions − PAYE tax
   ├─ Review payslips per employee
   ├─ Approve payroll batch
   ├─ Creates payroll_items records
   ├─ Creates accounting journal: Debit Salary Expense, Credit Cash/Bank
   └─ Export payslips (PDF via TCPDF)
```

### 7.6 Loans Flow

```
1. LOAN APPLICATION
   ├─ Customer/employee submits application
   ├─ Select: loan_product, loan_type, amount, term
   └─ Attach collateral documents

2. APPROVAL
   ├─ Review application, risk factors
   └─ Approve / Reject / Request more info

3. REPAYMENT SCHEDULE GENERATION
   ├─ Choose formula:
   │       a. Flat Rate:      interest = principal × rate × term
   │       b. Reducing Balance: interest recalculated on remaining principal
   │       c. EMI:            fixed monthly installments
   └─ System generates loan_repayment_schedule table entries

4. DISBURSEMENT
   ├─ Record disbursement date and amount
   └─ Creates accounting entry: Debit Loan Receivable, Credit Cash/Bank

5. REPAYMENTS
   ├─ Record each repayment against schedule
   ├─ Allocates: principal vs interest portions
   ├─ Updates: loan balance, schedule status
   └─ Creates journal entry

6. COLLECTIONS
   ├─ Track overdue schedules
   └─ Generate collection letters / SMS reminders
```

### 7.7 Operations Flow

```
1. PROJECTS
   ├─ Create project: name, client, start/end date, budget
   ├─ Add milestones and tasks
   ├─ Track progress (%)
   ├─ Link expenses and revenues
   └─ Generate project financial report

2. TENDERS
   ├─ Create tender: title, category, geographic scope (region/district/ward)
   ├─ Attach tender documents
   ├─ Track submission deadline and status
   └─ Link to project if awarded

3. ASSETS
   ├─ Register asset: name, category, cost, purchase date
   ├─ Calculate depreciation
   └─ Track location and responsible employee

4. MAINTENANCE
   ├─ Log maintenance request for an asset
   ├─ Assign technician
   ├─ Track completion
   └─ Record maintenance cost
```

---

## 8. Database Connection Flow

```
includes/config.php (loaded by roots.php on every request)
    │
    ├─ date_default_timezone_set('Africa/Dar_es_Salaam')
    ├─ new PDO("mysql:host=localhost;dbname=bms", "root", "")
    ├─ PDO::ATTR_ERRMODE = ERRMODE_EXCEPTION
    ├─ SET time_zone = '+03:00'   ← MySQL timezone sync
    └─ $pdo available globally via require_once
```

**Connection Details:**
- Host: `localhost`
- Database: `bms`
- User: `root`
- Password: *(empty — development only)*
- MySQL Timezone: `+03:00`
- PHP Timezone: `Africa/Dar_es_Salaam`

**Query Pattern (throughout codebase):**
```php
$stmt = $pdo->prepare("SELECT ... FROM table WHERE col = ?");
$stmt->execute([$value]);
$result = $stmt->fetchAll(PDO::FETCH_ASSOC);
```

---

## 9. File Upload Flow

```
User selects file → form submit → actions/upload_attachments.php
    │
    ├─ Validate: file type, size
    ├─ Generate unique filename
    ├─ Move to: uploads/{category}/{filename}
    └─ Store path in DB (relative path saved, served as /{path})

Upload Categories (uploads/ subdirectories):
    company/        → company logo, company documents
    products/       → product images
    customers/      → customer photos, KYC
    employees/      → employee photos, contracts
    projects/       → project attachments
    vouchers/       → payment voucher files
    collateral/     → loan collateral documents
    compliance/     → compliance documents
    contracts/      → contract files
    documents/      → general document library
    tenders/        → tender documents
    loans/          → loan application documents
    maintenance/    → maintenance reports
```

---

## 10. PDF Generation Flow

```
User clicks "Print" or "Download PDF"
    │
    ▼
Module print file (e.g., invoice_print.php, payslip_print.php)
    │
    ├─ Query DB for document data
    ├─ require_once TCPDF/tcpdf.php
    ├─ Create $pdf = new TCPDF(...)
    ├─ Set: page format, margins, fonts
    ├─ AddPage()
    ├─ Build HTML content string
    ├─ $pdf->writeHTML($html)
    └─ $pdf->Output('filename.pdf', 'I')  → stream to browser
                                    or 'D'  → force download
```

**TCPDF is used for:**
- Invoice printing
- Payslip printing
- Purchase Order documents
- Delivery Notes
- GRN documents
- Financial reports

---

## 11. User & Role Management Flow

```
Settings → Users / Roles

1. CREATE USER
   ├─ Input: first_name, last_name, username, email, password, role_id
   ├─ password_hash($password, PASSWORD_BCRYPT)
   └─ INSERT INTO users

2. CREATE ROLE
   ├─ Input: role_name
   └─ INSERT INTO roles

3. ASSIGN PERMISSIONS TO ROLE
   ├─ Select role
   ├─ For each page/module: toggle can_view, can_create, can_edit, can_delete
   └─ UPSERT role_permissions (role_id, permission_id, can_*)

4. PERMISSION TAKES EFFECT
   └─ Next time user loads a page, header.php calls loadUserPermissions()
      which re-queries role_permissions and refreshes $_SESSION['permissions']
```

---

## 12. System Settings Flow

```
Settings → Company Profile / System Settings

Storage: system_settings table (key-value pairs)
    ├─ setting_key   VARCHAR
    └─ setting_value TEXT

Common Keys:
    company_name    → displayed in header, login page, PDFs
    company_logo    → path to uploads/company/ image
    company_type    → drives module visibility (e.g., 'lending' shows Loans menu)
    currency        → TZS by default
    tax_rate        → default VAT rate

Read pattern (via helpers.php):
    get_setting('company_name', 'Default Value')
        └─ SELECT setting_value FROM system_settings WHERE setting_key = ?

Write pattern:
    UPDATE system_settings SET setting_value = ? WHERE setting_key = ?
    or INSERT if not exists
```

---

## 13. Key Files Reference

| File | Purpose |
|---|---|
| `index.php` | Front controller — entry point for all requests |
| `roots.php` | Bootstrap: session, constants, includes, route map (1100+ routes) |
| `header.php` | Session guard + HTML head + navbar + sidebar |
| `footer.php` | Closing HTML + global scripts |
| `helpers.php` | 400+ utility functions (formatting, settings, URL helpers, logging) |
| `includes/config.php` | PDO MySQL connection, timezone |
| `core/permissions.php` | All RBAC functions (canView, canEdit, loadUserPermissions, etc.) |
| `actions/check_auth.php` | `isAuthenticated()`, `getCurrentUserId()` |
| `actions/login.php` | POST login handler → sets session, loads permissions |
| `actions/logout.php` | Destroys session, redirects to login |
| `actions/upload_attachments.php` | File upload handler |
| `.htaccess` | URL rewriting, security (blocks config/core files) |
| `app/dashboard.php` | Main dashboard (charts, KPIs, summary cards) |
| `app/bms/customer/customers.php` | Customer list module |
| `app/bms/invoice/invoices.php` | Invoice list module |
| `app/constant/accounts/` | Accounting modules (journals, reconciliation, etc.) |
| `app/constant/reports/` | All financial and operational reports |
| `app/constant/settings/` | System administration modules |
| `api/` | JSON API endpoints (100+ files) |
| `ajax/` | Legacy AJAX handlers |
| `TCPDF/` | PDF generation library |
| `uploads/` | All user-uploaded files (13 categories) |
| `backups/` | MySQL database backup dumps |

---

## Data Flow Summary Diagram

```
                        ┌─────────────────────────────┐
                        │          Browser             │
                        └────────────┬────────────────┘
                                     │ HTTP Request
                                     ▼
                        ┌─────────────────────────────┐
                        │  Apache mod_rewrite          │
                        │  (.htaccess)                 │
                        └────────────┬────────────────┘
                                     │ Route to index.php
                                     ▼
                        ┌─────────────────────────────┐
                        │  index.php (Front Controller)│
                        │  ├─ Bootstrap (roots.php)    │
                        │  ├─ Session Start            │
                        │  ├─ DB Connect (config.php)  │
                        │  ├─ Load Helpers             │
                        │  ├─ Load Permissions         │
                        │  └─ Route Dispatch           │
                        └────────────┬────────────────┘
                                     │
                    ┌────────────────┼────────────────┐
                    ▼                ▼                ▼
             ┌──────────┐    ┌──────────┐    ┌──────────┐
             │  Module  │    │   API    │    │  Static  │
             │  Pages   │    │Endpoints │    │  Files   │
             │ app/bms/ │    │  api/    │    │ assets/  │
             └────┬─────┘    └────┬─────┘    └──────────┘
                  │              │
                  ▼              ▼
             ┌─────────────────────────────┐
             │         MySQL (bms)          │
             │  159 tables, stored procs    │
             └─────────────────────────────┘
```

---

*Document generated: 2026-04-18 | BMS v1.0*
