# BMS — Internal & External Print Standard (I/E Print)

**Reference implementation:** `app/bms/sales/quotations/print_quotation.php`
**Shared includes:** `includes/print_footer_css.php`, `includes/print_footer_html.php`
**Paper size:** A4 portrait (`@page` size is the browser default, no explicit size declared)

Use this document as the single source of truth when building any new print page in BMS
— internal documents (GRN, Delivery Note, Stock Adjustment, Attendance Report, Payslip…)
and external documents (Quotation, Sales Order, Invoice, Receipt, PO, Payment Voucher…).
Following it keeps every printed BMS document visually consistent on the same A4 page.

---

## 1. Page margins (canonical)

Set in the print page itself via `@page`. **All BMS prints use these values.**

```css
@page { margin: 10mm 8mm 16mm 8mm; }   /* top right bottom left */
```

| Side | mm | cm | Notes |
|---|---|---|---|
| Top | `10mm` | **1.00 cm** | |
| Right | `8mm` | **0.80 cm** | |
| Bottom | `16mm` | **1.60 cm** | larger — leaves space for the shared footer |
| Left | `8mm` | **0.80 cm** | |

---

## 2. Body inner padding

Additional whitespace **inside** the page margin so content doesn't touch the
inner edge of the margin band:

```css
body {
    padding: 20px 20px 0 20px;            /* 20px = ≈ 0.53 cm */
    margin: 0;
}
@media print {
    body { padding-bottom: 4mm !important; }
}
```

Effective whitespace from paper edge to first visible content row:

| Side | margin + padding | result |
|---|---|---|
| Top | 1.00 + 0.53 | **≈ 1.53 cm** |
| Right | 0.80 + 0.53 | **≈ 1.33 cm** |
| Left | 0.80 + 0.53 | **≈ 1.33 cm** |
| Bottom | 1.60 + 0.40 | **≈ 2.00 cm** |

---

## 3. Footer (shared across every print page)

The shared footer is in `includes/print_footer_css.php` (CSS) and
`includes/print_footer_html.php` (HTML). **Do NOT duplicate footer styles** on
individual print pages — always include the shared files.

### Position & dimensions

```css
.print-footer {
    position: fixed;                       /* repeats on every printed page */
    bottom: 0; left: 0; right: 0;          /* pinned to bottom edge of content area */
    height: 16px;                          /* ≈ 4.2 mm ≈ 0.42 cm */
    background: #fff;
    border-top: 1px solid #dee2e6;
    padding: 0 22px;                       /* side padding ≈ 5.8 mm */
    text-align: center;
    display: flex; flex-direction: column; justify-content: flex-end;
}
.print-footer p      { font-size: 7px; line-height: 1; color: #2c3e50; margin: 0; }
.print-footer .brand { font-size: 7px; color: #3498db; font-weight: 600; }
```

### Content (auto-filled from session)

```
This document was Printed by {first_name last_name} — {role} on {dd MMM yyyy at HH:mm:ss}
Powered By BJP Technologies © {year}, All Rights Reserved
```

### Footer rules

- **Position:** `bottom: 0` (sits at the lower edge of the content area, just above the
  1.6 cm bottom page margin band).
- **Repeats automatically on every printed page** (because `position: fixed`).
- **Height:** `16px` — do not enlarge; keep it discreet.
- **Font size:** `7px` — keep it small.
- **Do not redeclare** the footer styles inside individual print pages.

---

## 4. Header layout (per-page, not shared)

Each print page declares its own header. Standard pattern:

```html
<div class="header">                       <!-- flex row -->
    <div class="company-info">             <!-- left: company block -->
        <h1>{{COMPANY_NAME}}</h1>
        <div class="company-addr-row">
            <img src="{{LOGO}}" alt="Logo"> <!-- max-height: 60px -->
            <div class="company-addr-info">
                <p>{{ADDRESS}}</p>
                <p>P.O. Box {{POSTAL_ADDRESS}}</p>
                <p>Phone: {{PHONE}}</p>
                <p>Web: {{WEBSITE}}</p>
                <p>Email: {{EMAIL}}</p>
                <p>TIN: {{TIN}} | VRN: {{VRN}}</p>
            </div>
        </div>
    </div>
    <div class="doc-title-box">            <!-- right: blue document title box -->
        <h2>{{DOC_TYPE}}</h2>              <!-- QUOTATION / INVOICE / PO / ... -->
        <p><strong>Doc #:</strong> {{NUMBER}}</p>
        <p><strong>Date:</strong> {{DATE}}</p>
        <p><strong>Status:</strong> {{STATUS}}</p>
    </div>
</div>
```

```css
.header {
    display: flex; justify-content: space-between; align-items: flex-start;
    margin-bottom: 28px;
    padding-bottom: 18px;
    border-bottom: 3px solid #3498db;      /* canonical accent line */
}
.company-info h1 {
    color: #0d6efd; font-size: 22px; font-weight: 800;
    text-transform: uppercase; letter-spacing: 0.5px;
    margin: 0 0 10px 0;
}
.company-addr-row img { max-height: 60px; }
.company-addr-info p { margin: 2px 0; color: #1a252f; font-size: 11px; font-weight: 500; }
.doc-title-box {
    text-align: right;
    background: #3498db;                   /* canonical blue */
    color: #fff;
    padding: 16px 22px;
    border-radius: 8px;
    min-width: 220px;
}
.doc-title-box h2 {
    font-size: 16px; font-weight: 700; letter-spacing: 1px;
    text-transform: uppercase; margin: 0 0 10px 0;
}
.doc-title-box p { font-size: 12px; margin: 4px 0; }
```

**Web + Email rule:** print them on **two separate `<p>` rows** (not joined with `|`).

---

## 5. Typography

### Font family (always the same across every BMS print page)

```css
body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    font-size: 12px;
    color: #1a252f;
    line-height: 1.5;
}
```

### Complete font-size reference (from `print_quotation.php`)

| Element / Selector | Font size | Line height | Weight | Notes |
|---|---|---|---|---|
| `body` (base) | `12px` | `1.5` | `400` (normal) | inherits down |
| `.company-info h1` | `22px` | — | `800` | uppercase, letter-spacing 0.5px |
| `.company-addr-info p` | `11px` | `1.5` (inherited) | `500` | address, phone, web, email, TIN/VRN |
| `.doc-title-box h2` | `16px` | — | `700` | uppercase, letter-spacing 1px |
| `.doc-title-box p` | `12px` | `1.5` (inherited) | `600` (strong) | doc #, date, status |
| `.box h3` | `11px` | — | `700` | uppercase, letter-spacing 0.5px |
| `.box p` | `11.5px` | `1.5` (inherited) | `400` / `600` (strong) | 3px margin-bottom |
| `th` (items table header) | `11px` | — | `600` | uppercase, letter-spacing 0.4px |
| `tbody td` (items table body) | `13px` | **`1.6`** | `400` / `700` (fw-bold) | row height 0.75cm |
| `.totals-row` | `12px` | `1.5` (inherited) | `400` | padding 5px 0 |
| `.totals-row.grand-total` | `14px` | `1.5` (inherited) | `700` | border-top accent, padding-top 10px |
| `.bank-details h3` | `11px` | — | `700` | same style as `.box h3` |
| `.bank-details p` | `11px` | `1.5` (inherited) | `400` | |
| `.bank-details strong` | `11px` | — | `700` | section labels (Bank Transfer, M-Pesa…) |
| `.notes-section strong` | `11.5px` | — | `700` | Notes:, Terms & Conditions: |
| `.notes-section p` | `11px` | `1.5` (inherited) | `400` | |
| `.signature-line` | `11px` | — | `600` | "Created By / Reviewed By / Approved By" |
| `.signature-line small` | `10px` | — | `400` | person name + role below the line |
| `.print-footer p` | `7px` | `1` | `400` | footer text |
| `.print-footer .brand` | `7px` | `1` | `600` | "BJP Technologies" brand name |

### Quick-copy rule for any new print page

When building a new internal or external document, copy these **exactly** — do not invent new sizes:

| Purpose | Use this size |
|---|---|
| Company name heading | `22px / 800` |
| Document type heading (INVOICE, PO…) | `16px / 700` |
| Section headings inside panels | `11px / 700 / uppercase` |
| Panel body text (customer, bank, notes) | `11–11.5px / 400` |
| Items table header row | `11px / 600 / uppercase` |
| Items table data cells | `13px / 400` (bold total column: `700`) |
| Totals subtotal / tax / discount rows | `12px / 400` |
| Grand total row | `14px / 700` |
| Signature labels | `11px / 600` |
| Signature name under line | `10px / 400` |
| Footer lines | `7px / 400` (brand: `600`) |

---

## 6. Color palette

| Role | Hex | Used for |
|---|---|---|
| Primary blue (accent) | `#3498db` | header underline, doc-title-box bg, box left-border, separators |
| Deep blue (text emphasis) | `#0d6efd` | company name, sub-heads |
| Dark text | `#1a252f` | default body text |
| Slate header | `#34495e` | table `th` background |
| Light gray bg | `#f4f6f8` | `.box`, `.bank-details`, `.totals` panels |
| Alt-row gray | `#f9fafb` | `tbody tr:nth-child(even)` |
| Border / divider | `#e4e8ec` | thin separators inside boxes |
| Footer divider | `#dee2e6` | `border-top` on `.print-footer` |
| Footer secondary | `#2c3e50` | footer text |
| Brand teal | (not used in current quotation) | reserved |

Always use:
```css
print-color-adjust: exact;
-webkit-print-color-adjust: exact;
```
on any element with a background fill so the colour prints (browsers strip
backgrounds otherwise).

---

## 7. Standard panels (reusable)

### Info / Customer / Details box

```html
<div class="details-grid">
    <div class="box">
        <h3>Customer</h3>
        <p><strong>{{NAME}}</strong></p>
        <p>{{ADDRESS}}</p>
        <p>{{PHONE}}</p>
        <p>{{EMAIL}}</p>
        <p>TIN: {{TIN}} | VRN: {{VRN}}</p>
    </div>
    <div class="box">
        <h3>Document Info</h3>
        <p><strong>Project:</strong> {{PROJECT}}</p>
        <p><strong>Salesperson:</strong> {{SALESPERSON}}</p>
        <p><strong>Prepared By:</strong> {{CREATOR}}</p>
    </div>
</div>
```

```css
.details-grid { display: flex; justify-content: space-between; margin-bottom: 24px; gap: 14px; }
.box {
    width: 48%;
    background: #f4f6f8;
    padding: 14px 16px;                    /* inner: 14px top/bottom, 16px sides */
    border-radius: 6px;
    border-left: 4px solid #3498db;
}
.box h3 {
    font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;
    color: #1a252f;
    padding-bottom: 7px; margin-bottom: 10px;
    border-bottom: 1.5px solid #3498db;
}
.box p      { margin: 3px 0; color: #1a252f; font-size: 11.5px; }   /* 3px spacing */
.box strong { color: #1a252f; font-weight: 600; }
```

**De-dup rule for addresses:** when a record has both `address` and `postal_address`
that overlap, render only one line. When prefixing "P.O. Box ", skip the prefix if the
value already starts with `P.O` / `PO`. See the `q_addr_lines()` helper pattern in
`tests/test_quotation_customer_box.php` for the canonical algorithm.

### Items table

```html
<table>
    <thead>
        <tr>
            <th class="text-center" style="width:38px;">S/NO</th>
            <th class="text-center" style="width:100px;">Code</th>
            <th>Item / Description</th>
            <th class="text-right" style="width:80px;">Qty</th>
            <th class="text-right" style="width:105px;">Unit Price</th>
            <th class="text-right" style="width:115px;">Total ({{CURRENCY}})</th>
        </tr>
    </thead>
    <tbody><!-- rows --></tbody>
</table>
```

```css
table   { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
th {
    background: #34495e; color: #fff;
    font-weight: 600; font-size: 11px;
    text-transform: uppercase; letter-spacing: 0.4px;
    padding: 9px 10px; text-align: left;
}
tbody tr        { border-bottom: 1px solid #e4e8ec; }
tbody tr:nth-child(even) { background: #f9fafb; }
tbody tr:last-child      { border-bottom: 2px solid #3498db; }
tbody tr td {
    height: 0.75cm;                        /* canonical row height */
    padding: 2px 10px;
    vertical-align: middle;
    font-size: 13px;
    line-height: 1.6;                      /* canonical content line-height */
    color: #1a252f;
}
.text-right  { text-align: right; }
.text-center { text-align: center; }
.fw-bold     { font-weight: 700; }
```

### Totals + Bank details (two-column footer area before signatures)

```css
.totals-section {
    display: flex; justify-content: space-between; align-items: flex-start;
    gap: 20px; margin-bottom: 20px;
}
.bank-details {
    flex: 1;
    background: #f4f6f8;
    padding: 14px 16px;
    border-radius: 6px;
    border-left: 4px solid #3498db;
}
.bank-details p { margin: 3px 0; font-size: 11px; color: #1a252f; }
.totals {
    width: 310px; min-width: 260px; flex-shrink: 0;
    background: #f4f6f8; padding: 14px 18px; border-radius: 6px;
}
.totals-row {
    display: flex; justify-content: space-between;
    padding: 5px 0;
    font-size: 12px;
    border-bottom: 1px solid #e4e8ec;
}
.totals-row.grand-total {
    border-top: 2px solid #3498db; margin-top: 8px; padding-top: 10px;
    font-size: 14px; font-weight: 700;
}
```

**Tax row rule (Tanzania):** label the row **`VAT:`** (not `Tax`). It currently
prints **always**, showing `0.00` when no item has VAT — change to a `tax_amount > 0`
guard if the business prefers it hidden at zero.

### Signature row (Created / Reviewed / Approved)

```html
<div class="signature-box">
    <div class="signature-line">Created By<br><small>{{CREATOR}}</small></div>
    <div class="signature-line">Reviewed By<br><small>{{REVIEWER}}</small></div>
    <div class="signature-line">Approved By<br><small>{{APPROVER}}</small></div>
</div>
```

```css
.signature-box  { margin-top: 46px; display: flex; justify-content: space-around; gap: 40px; }
.signature-line {
    width: 210px; padding-top: 7px; text-align: center;
    border-top: 1.5px solid #1a252f;
    font-size: 11px; font-weight: 600; color: #1a252f;
}
.signature-line small {
    display: block; margin-top: 4px;
    font-size: 10px; font-weight: 400; color: #495057;
}
```

---

## 8. How to apply to a new print page — quickstart template

```php
<?php
// File: app/bms/<module>/print_<doc>.php
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/../../../../roots.php';
require_once __DIR__ . '/../../../../core/permissions.php';

if (!isAuthenticated()) die("Unauthorized");

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) die("Invalid ID");

// ... fetch document data into $order ...
// ... fetch $items ...
// ... fetch $comp from system_settings (WHERE setting_key LIKE 'company_%') ...
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>{{DOC_TYPE}} #<?= htmlspecialchars($order['number']) ?></title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        font-size: 12px;
        color: #1a252f;
        line-height: 1.5;
        padding: 20px 20px 0 20px;
        background: #fff;
    }
    /* ─── HEADER, BOXES, TABLE, TOTALS, SIGNATURES ─── */
    /* copy the canonical CSS from print_quotation.php verbatim         */
    /* (do NOT alter the values defined above)                          */

    @page { margin: 10mm 8mm 16mm 8mm; }   /* canonical page margin */
    @media print {
        .no-print { display: none !important; }
        body { margin: 0 !important; }
        .box, .totals, .bank-details, .notes-section > div {
            box-shadow: none; border: 1px solid #e0e0e0;
        }
    }
</style>
<?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
</head>
<body onload="window.print()">
    <div class="no-print" style="margin-bottom:20px; display:flex; gap:8px;">
        <button onclick="window.print()" style="padding:6px 16px;">Print</button>
        <button onclick="window.close()" style="padding:6px 16px;">Close</button>
    </div>

    <!-- HEADER, DETAILS GRID, ITEMS TABLE, TOTALS, SIGNATURE BOX -->

    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>
</body>
</html>
```

---

## 9. Rules to follow on every new print page

1. **Always use the canonical `@page` margins** above. Do not change them per document.
2. **Always include the shared footer**:
   - In `<head>`: `require_once ROOT_DIR . '/includes/print_footer_css.php';`
   - Just before `</body>`: `require_once ROOT_DIR . '/includes/print_footer_html.php';`
3. **Never define a custom footer inside a print page.** If a file has its own footer block (a local `.footer`, `.bms-print-footer`, `.footer-section`, or any hardcoded footer HTML), **remove it entirely** and replace with the two shared includes above. The shared footer auto-fills the printer name, role, date, and brand line — nothing needs to be duplicated locally.
4. **If a file has no footer at all**, add the two shared includes (CSS in `<head>`, HTML before `</body>`).
5. **Never redeclare `const CSRF_TOKEN`** in the page script (it's already in `header.php`).
6. **Never alter `body { padding: 20px 20px 0 20px; }`** — the 0.53 cm inner padding is the standard.
7. **Always use `print-color-adjust: exact`** on any element with a background colour.
8. **Always log a print activity** at the top:
   ```php
   logActivity($pdo, $_SESSION['user_id'], 'Print {{DocType}}',
       ($_SESSION['first_name'] ?? 'User') . " printed {{DocType}} #" . $order['number']);
   ```
9. **External documents** (Quotation, Invoice, Receipt, PO, Statement): show
   `Created By / Reviewed By / Approved By` signature row + Account Details panel.
10. **Internal documents** (GRN, DN, Stock Adjustment, Attendance, Payslip): use
    the same structure but the right-hand panel of `.totals-section` may be a
    different summary block (e.g. Sign + Date by Storekeeper / Manager).
11. **VAT label** is `VAT:` not `Tax:` (Tanzania convention).
12. **Web & Email** in the company header print on **two separate `<p>` lines**.

---

## 10. Full compliance map — all BMS print pages

Use this table as the work tracking list. Check off each file as it is normalized.

### A. External documents (sent to / received from outside parties)

| # | File | Document | Shared footer | CSS deviations from standard |
|---|---|---|---|---|
| REF | `app/bms/sales/quotations/print_quotation.php` | Quotation | ✅ | none — **REFERENCE** |
| 1 | `app/bms/sales/print_sales_order.php` | Sales Order | ✅ | `.box p` margin `5px→3px` ✅ done; `td` height `0.9cm→0.75cm` ✅ done; `td` line-height `2.2→1.6` ✅ done |
| 2 | `api/account/print_purchase_order.php` | Purchase Order | ✅ | `.po-title h2` font-size `18px→16px`; `.box p` margin `5px→3px`; `td` height `0.9cm→0.75cm`; `td` line-height `2.2→1.6` |
| 3 | `api/account/print_rfq.php` | Request for Quotation | ✅ | not yet audited |
| 4 | `app/bms/invoice/invoice_print.php` | Invoice | ✅ | not yet audited |
| 5 | `app/bms/purchase/print_purchase_return.php` | Purchase Return | ✅ | not yet audited |
| 6 | `app/bms/sales/sales_returns/print_sales_return.php` | Sales Return | ✅ | not yet audited |

### B. Internal documents (internal movement / received from supplier)

| # | File | Document | Shared footer | CSS deviations from standard |
|---|---|---|---|---|
| 7 | `api/account/print_delivery_note.php` | Delivery Note | ✅ | `.box p` margin `5px→3px`; `td` font-size `12px→13px`; add `td` height `0.75cm` + line-height `1.6` |
| 8 | `app/bms/grn/grn_print.php` | GRN | ✅ | not yet audited |
| 9 | `app/bms/stock/print_transfer.php` | Stock Transfer | ❌ **NO footer at all** — add shared footer | not yet audited |
| 10 | `app/bms/stock/adjustment_print.php` | Stock Adjustment | ❌ **own internal `.footer` CSS** — remove & replace | not yet audited |
| 11 | `app/bms/operations/print_ipc.php` | IPC | ✅ | not yet audited |
| 12 | `app/constant/accounts/payment_voucher_print.php` | Payment Voucher | ✅ | not yet audited |
| 13 | `app/constant/accounts/petty_cash_print.php` | Petty Cash | ❌ **own internal `bms-print-footer` HTML** — remove & replace | not yet audited |

### Footer action summary

| File | Action needed |
|---|---|
| `app/bms/stock/print_transfer.php` | Add `print_footer_css.php` in `<head>` + `print_footer_html.php` before `</body>` |
| `app/bms/stock/adjustment_print.php` | Remove local `.footer { … }` CSS block + its HTML; add shared includes |
| `app/constant/accounts/petty_cash_print.php` | Remove `<div class="bms-print-footer">` HTML block + any related CSS; add shared includes |

### Files excluded from normalization (different format)

| File | Reason |
|---|---|
| `api/pos/print_receipt.php` | Thermal receipt — completely different A5/receipt layout |
| `app/bms/product/print_barcode.php` | Barcode label sheet — completely different layout |
| `api/print_compliance.php` | Bulk report listing — not a transactional document |
| `api/print_audit_logs.php` | Bulk report listing — not a transactional document |
| `api/operations/print_*.php` | Bulk report listings — not transactional documents |

After fixing any file, run:

```
php -l <file>
php tests/test_csrf_token_redeclaration_cli.php
```

before committing.
