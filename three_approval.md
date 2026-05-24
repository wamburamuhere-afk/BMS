# BMS — Three-Approval Workflow Standard

**Reference implementation:** `app/bms/sales/quotations/` (full chain)
**Reference print:** `app/bms/sales/quotations/print_quotation.php` (signature block)
**Companion specs:** `i_e_print.md` (print layout), `view.md` (view-page layout)

Every primary BMS document **must** pass through the three states below — in this
exact order — before it can be referred to / converted into another document.

```
   pending  ──►  reviewed  ──►  approved
   (auto)        (manual)        (manual)
```

> **Important:** this spec only adds the three-approval gate. **Do not touch any
> existing CSS defined in print pages, view pages, or list pages.** The only CSS
> referenced here is the signature block already present in
> `print_quotation.php` — it is copied verbatim, not redefined.

---

## 1. The three required states

| # | State       | When set                                 | By whom                | Side effects                                             |
|---|-------------|------------------------------------------|------------------------|----------------------------------------------------------|
| 1 | `pending`   | Automatically on create                  | System                 | Document is editable by creator + admin                  |
| 2 | `reviewed`  | When a reviewer clicks **Review**        | User with `can_review` | Document still editable by admin (creator locked out)    |
| 3 | `approved`  | When an approver clicks **Approve**      | User with `can_approve`| Document **immutable except admin**; can now be referred |

### Rules

1. `pending` is the **default** for every new row — set in the create page / save API.
2. `reviewed` can only come from `pending`.
3. `approved` can only come from `reviewed`. **Never** `pending → approved` directly.
4. The **Review** and **Approve** buttons are *coexisting capabilities* (defined on
   the same page), but they're **mutually exclusive at any given moment** —
   exactly one of them is visible per row, depending on the current status.
5. After `approved`:
   - The document **cannot be edited or deleted** by anyone **except admin**.
   - The document **can be referred / converted** to the next document
     (e.g., approved Quotation → Sales Order, approved RFQ → Purchase Order).
   - The document **can still be printed**; pre-approval prints get a "DRAFT"
     watermark (see §6).
6. Any **automatic side-effect** that the document already triggers (stock
   movement, ledger posting, conversion link, etc.) **continues to run** as it
   does today — this spec only adds the gate, it does not remove existing behaviour.

---

## 2. Database — schema requirements

Every document table must have:

```sql
status        ENUM('pending','reviewed','approved', ...other states the doc already has...)
              NOT NULL DEFAULT 'pending',
reviewed_by   INT NULL,
reviewed_at   DATETIME NULL,
approved_by   INT NULL,
approved_at   DATETIME NULL,
created_by    INT NOT NULL,
created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
```

**Naming rule:** the enum value is `'reviewed'` — never `'review'`.

Any existing additional states already in the enum (`processing`, `shipped`,
`delivered`, `paid`, `ordered`, `completed`, `cancelled`, etc.) **remain**
untouched. The three states are inserted at the start of the chain, not
replacing the others.

---

## 3. Required APIs — `review_X.php` + `approve_X.php`

Two endpoints per document, modelled exactly on:

- `api/account/review_quotation.php`
- `api/account/approve_quotation.php`

### `review_X.php` — guard: current status must be `pending`

```php
$pdo->beginTransaction();
$cur = $pdo->prepare("SELECT status FROM <table> WHERE id = ? FOR UPDATE");
$cur->execute([$id]);
$row = $cur->fetch(PDO::FETCH_ASSOC);

if (!$row)                       throw new Exception("Document not found.");
if ($row['status'] !== 'pending') throw new Exception("Only a pending document can be reviewed.");

$pdo->prepare("
    UPDATE <table>
       SET status = 'reviewed',
           reviewed_by = ?, reviewed_at = NOW(),
           updated_by = ?, updated_at = NOW()
     WHERE id = ?
")->execute([$_SESSION['user_id'], $_SESSION['user_id'], $id]);

logActivity($pdo, $_SESSION['user_id'], "Reviewed <DocType> #$id");
$pdo->commit();
```

### `approve_X.php` — guard: current status must be `reviewed`

```php
if ($row['status'] !== 'reviewed') {
    throw new Exception("Only a reviewed document can be approved (current: ".ucfirst($row['status']).").");
}

$pdo->prepare("
    UPDATE <table>
       SET status = 'approved',
           approved_by = ?, approved_at = NOW(),
           updated_by = ?, updated_at = NOW()
     WHERE id = ?
")->execute([$_SESSION['user_id'], $_SESSION['user_id'], $id]);
```

**Both endpoints must:**
- `isAuthenticated()` + `canReview('page_key')` / `canApprove('page_key')`
- `csrf_check()`
- Run inside a transaction with `SELECT … FOR UPDATE`
- Call `logActivity()` on success

---

## 4. List page (`X.php`) — status column + sequential action

Add to the existing list page, **without changing other columns or styles**:

### 4.1 Status column
A new `<th>Status</th>` column rendering a badge:

```php
<?php
$status      = $row['status'] ?: 'pending';
$badgeClass  = [
    'pending'   => 'status-pending',
    'reviewed'  => 'status-reviewed',
    'approved'  => 'status-approved',
    'rejected'  => 'status-rejected',
    'cancelled' => 'status-cancelled',
][$status] ?? 'status-secondary';
?>
<span class="badge <?= $badgeClass ?>"><?= ucfirst($status) ?></span>
```

Status-badge CSS already exists in `app/bms/sales/quotations/quotations.php`
(starting line 84). Reuse those classes — **do not redefine**.

### 4.2 Status filter dropdown
A `<select>` above the table wired to `WHERE q.status = ?`. Pattern is in
`quotations.php` (line 35).

### 4.3 Per-row sequential action (the "status changer")

In the row's action dropdown — **only the next valid step is shown**:

```php
<?php if ($status === 'pending' && $can_review): ?>
    <a class="dropdown-item text-primary" href="javascript:void(0)"
       onclick="reviewDoc(<?= $row['id'] ?>)">
        <i class="bi bi-check2"></i> Review
    </a>
<?php endif; ?>

<?php if ($status === 'reviewed' && $can_approve): ?>
    <a class="dropdown-item text-success" href="javascript:void(0)"
       onclick="approveDoc(<?= $row['id'] ?>)">
        <i class="bi bi-check2-all"></i> Approve
    </a>
<?php endif; ?>

<?php if ($status === 'approved' && empty($row['converted_to_X_id'])): ?>
    <a class="dropdown-item text-info" href="javascript:void(0)"
       onclick="convertDoc(<?= $row['id'] ?>)">
        <i class="bi bi-arrow-right-circle"></i> Convert
    </a>
<?php endif; ?>
```

Reference: `quotations.php:374-388`.

**Edit/Delete buttons** must additionally check:
```php
if ($status !== 'approved' || isAdmin()) { /* show Edit & Delete */ }
```

---

## 5. View / details page (`X_view.php`) — 4 additions

All four are **additive** — they slot into the existing view layout without
removing or restyling anything that's already there.

### 5.1 Status badge
Top of the page, next to the document number — same CSS classes from §4.1.

### 5.2 Audit trail panel
Small block showing:
```
Created by:   {first_name last_name} on {created_at}
Reviewed by:  {reviewed_by → name} on {reviewed_at}        ← shown only if reviewed
Approved by:  {approved_by → name} on {approved_at}        ← shown only if approved
```

### 5.3 Sequential action buttons
Same pattern as §4.3 — exactly one of **Review / Approve / Convert** is visible
based on current status + permission.

### 5.4 Edit/Delete gating
```php
$can_edit_now   = $can_edit   && ($status !== 'approved' || isAdmin());
$can_delete_now = $can_delete && ($status !== 'approved' || isAdmin());
```
Use `$can_edit_now` / `$can_delete_now` to render the Edit / Delete buttons.

---

## 6. Print page (`print_X.php`) — 2 additions

### 6.1 Status in the doc-title-box
Already mandated by `i_e_print.md` §4 — every print already shows
`Status: {{STATUS}}` in the blue title box (top-right). **Nothing to change** if
the print page already follows `i_e_print.md`.

### 6.2 DRAFT watermark for pre-approved prints
When `status !== 'approved'`, overlay a watermark — **do not touch the existing
print CSS, just append this self-contained block**:

```html
<?php if (($order['status'] ?? '') !== 'approved'): ?>
<div class="three-approval-watermark"><?= strtoupper($order['status']) ?></div>
<style>
    .three-approval-watermark {
        position: fixed; top: 35%; left: 0; right: 0;
        text-align: center;
        font-size: 120px; font-weight: 800;
        color: rgba(220, 53, 69, 0.18);
        transform: rotate(-30deg);
        pointer-events: none; z-index: 9999;
        letter-spacing: 4px;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
</style>
<?php endif; ?>
```

This block is **isolated** (its own class name, its own style tag) — it
**cannot** touch any existing CSS.

### 6.3 Signature row — **EXACT** copy from `print_quotation.php`

Every print page must render the three-signature row below. **Use this CSS and
HTML verbatim — do not rename classes, do not change sizes, do not destroy any
other CSS the print page already has.**

#### CSS (lines 346-368 of `print_quotation.php`)

```css
/* ── SIGNATURE ── */
.signature-box {
    margin-top: 46px;
    display: flex;
    justify-content: space-around;
    gap: 40px;
}
.signature-line {
    width: 210px;
    padding-top: 7px;
    text-align: center;
    border-top: 1.5px solid #1a252f;
    font-size: 11px;
    color: #1a252f;
    font-weight: 600;
}
.signature-line small {
    display: block;
    margin-top: 4px;
    font-size: 10px;
    font-weight: 400;
    color: #495057;
}
```

#### HTML (lines 588-600 of `print_quotation.php`)

```html
<div class="signature-box">
    <div class="signature-line">
        Created By<br>
        <small><?= safe_output($creator_name) ?><?= $creator_role ? ' — ' . safe_output($creator_role) : '' ?></small>
    </div>
    <div class="signature-line">
        Reviewed By<br>
        <small><?= safe_output($reviewer_name) ?><?= $reviewer_role ? ' — ' . safe_output($reviewer_role) : '' ?></small>
    </div>
    <div class="signature-line">
        Approved By<br>
        <small><?= safe_output($approver_name) ?><?= $approver_role ? ' — ' . safe_output($approver_role) : '' ?></small>
    </div>
</div>
```

**Data binding rules:**

| Slot          | Source                                                                          |
|---------------|---------------------------------------------------------------------------------|
| `Created By`  | `users` row joined on `<table>.created_by` → `first_name + last_name` + role     |
| `Reviewed By` | `users` row joined on `<table>.reviewed_by` → blank if not yet reviewed         |
| `Approved By` | `users` row joined on `<table>.approved_by` → blank if not yet approved         |

When a slot's data is null (e.g., printing a `pending` doc), the `<small>` line
is empty but the **signature line and label remain** — the printed paper still
has a physical line for handwritten signing.

---

## 7. Conversion / referral guard

Every "create child from parent" endpoint **must** start with:

```php
$src = $pdo->prepare("SELECT status FROM <parent_table> WHERE id = ?");
$src->execute([$id]);
$row = $src->fetch(PDO::FETCH_ASSOC);

if (!$row || $row['status'] !== 'approved') {
    throw new Exception("Only an approved <DocType> can be referred / converted.");
}
```

Reference: `api/account/convert_quote_to_order.php:39-40`.

---

## 8. Permissions

Two new permission flags per document page-key, in `role_permissions`:

| Flag         | Helper                          | Default holders                |
|--------------|---------------------------------|--------------------------------|
| `can_review` | `canReview('page_key')`         | Manager / Senior officer       |
| `can_approve`| `canApprove('page_key')`        | Manager / Director             |

Admin (`role_id = 1`) automatically returns `true` for all `canX()` checks.

---

## 9. Compliance map — applied to the 13 I/E print documents

| Doc                | Table                          | Has 3-state? | Has review API? | Has approve API? | Gap                                   |
|--------------------|--------------------------------|--------------|-----------------|------------------|----------------------------------------|
| Quotation **(REF)**| `quotations`                   | ✅           | ✅              | ✅               | none — reference implementation        |
| Purchase Order     | `purchase_orders`              | ⚠️ `review`  | ✅              | ✅               | rename enum `'review'` → `'reviewed'` |
| RFQ                | `rfq`                          | ⚠️ `review`  | ✅              | ✅               | rename enum `'review'` → `'reviewed'` |
| Delivery Note      | `deliveries`                   | ⚠️ `review`  | ❌              | ✅ (`approve_dn`)| add `review_dn`, rename `'review'`     |
| Invoice            | `invoices`                     | ✅           | ❌              | ❌               | add both APIs + UI wiring              |
| Sales Order        | `sales_orders`                 | ❌           | ❌              | ❌               | add `reviewed` state + both APIs + UI  |
| Purchase Return    | `purchase_returns`             | ❌           | ❌              | ❌               | add `reviewed` state + both APIs + UI  |
| Sales Return       | `sales_returns`                | ❌           | ❌              | ❌               | add `reviewed` state + both APIs + UI  |
| GRN                | `purchase_receipts`            | ❌           | ❌              | ❌               | add all three states + both APIs + UI  |
| Stock Transfer     | `stock_transfers`              | ❌           | ❌              | ❌               | add `reviewed`+`approved` + APIs + UI  |
| Stock Adjustment   | `stock_movements` (no status!) | ❌           | ❌              | ❌               | add status col + all states + APIs     |
| IPC                | `interim_payment_certificates` | ❌ Title-case| ❌              | ❌               | lowercase enum + add `reviewed` + APIs |
| Payment Voucher    | `payment_vouchers`             | ❌           | ❌              | ❌               | add `pending`+`reviewed` + APIs + UI   |
| Petty Cash         | `petty_cash_transactions`      | ❌ (no col!) | ❌              | ❌               | add status column + all states + APIs  |

---

## 10. Per-document touchpoint checklist

Use as a PR checklist when wiring three-approval to a new document.

- [ ] **Migration** — add/rename enum + 4 audit columns (`reviewed_by/at`, `approved_by/at`)
- [ ] **`api/.../review_X.php`** — new file (guard: status='pending')
- [ ] **`api/.../approve_X.php`** — new file (guard: status='reviewed')
- [ ] **`X_create.php`** — confirm default status is `'pending'`
- [ ] **`X_edit.php`** — block edit when `status='approved' && !isAdmin()`
- [ ] **`X_view.php`** — status badge
- [ ] **`X_view.php`** — audit panel (Created/Reviewed/Approved by + when)
- [ ] **`X_view.php`** — sequential Review/Approve/Convert buttons
- [ ] **`X_view.php`** — Edit/Delete gated by `$can_edit_now`/`$can_delete_now`
- [ ] **`X.php` (list)** — status column with badge
- [ ] **`X.php` (list)** — status filter dropdown
- [ ] **`X.php` (list)** — per-row sequential action items
- [ ] **`print_X.php`** — DRAFT watermark when `status !== 'approved'`
- [ ] **`print_X.php`** — signature row with **exact CSS + HTML from §6.3**
- [ ] **`api/.../convert_X_to_Y.php`** — guard `status='approved'`
- [ ] **`roots.php`** — route entries for the two new APIs
- [ ] **`role_permissions`** — seed `can_review` + `can_approve` rows
- [ ] **`logActivity()`** — called inside both `review_X.php` and `approve_X.php`

---

## 11. CSS protection rule (must read)

When applying this spec to an existing page:

1. **Do not** modify or remove any CSS rule that is already defined in the
   target file.
2. **Only add** the small isolated blocks listed in §4 (status badge — already
   exists in `quotations.php`), §6.2 (`.three-approval-watermark`), and §6.3
   (signature block — copied verbatim).
3. If a print page already has a `.signature-box` / `.signature-line` block
   that differs from §6.3, **leave the existing CSS untouched** and only adjust
   the HTML to render all three labels (`Created By`, `Reviewed By`,
   `Approved By`). The visual styling stays as the page already has it.
4. If the page has no signature CSS at all, paste §6.3 verbatim.

The goal of this spec is the **workflow gate**, not a CSS refactor.
