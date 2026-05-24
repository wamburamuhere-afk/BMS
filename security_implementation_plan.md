# BMS Security Implementation Plan (Revised 2026-05-24)

**Goal:** Close every permission gap and every activity-log gap identified in
`security_audit_2026_05_24.md`, **without breaking a single existing line of
working code**, broken into small, independently mergeable Pull Requests so
each phase can be reviewed, tested, and reverted in isolation.

**Guiding decisions (confirmed):**
- Every security branch forks from `main`. No stacking on the three-approval
  PRs — security is independent.
- Default for every new permission row: **OFF for all roles, Admin auto-
  bypasses.** True zero-trust. Tick boxes in `user_roles.php` after each PR
  ships.
- Writes first, then reads.
- One phase at a time, with explicit "Go" approval per phase.

**Revision history:**
- **v1 (original)** — 20 PRs, ~9 working days, 10 phases (0-9).
- **v2 (this revision)** — **15 PRs, ~6 working days, 12 phases (0-9 with
  refinements).** Adds 3 critical gaps found after the original write-up,
  consolidates Phase 5 from 7 sub-PRs to 4, defers Phase 7 to optional,
  merges Phase 8 into Phase 9.

---

## What changed in v2 (read this first if you've seen v1)

| Change | Why |
|---|---|
| **NEW Phase 0.5** — Admin break-glass sanity check | Discovered risk: if `loadUserPermissions()` is broken for admin, we lock ourselves out of `user_roles.php` and can never fix it from the UI. Pre-flight test catches this on every phase. |
| **NEW Phase 4.5** — API permission gate audit | Original plan locked pages but ignored APIs. A user without page access could still POST to `api/delete_invoice.php`. New audit finds these holes. |
| **Phase 0 expanded** | Log permission grants/revokes in `user_roles.php` — the most security-sensitive event becomes auditable. |
| **Phase 5 consolidated** | 7 sub-PRs → 4 sub-PRs. Each still ≤ 10 files. Saves ~3 days. |
| **Phase 7 deferred** | View-page logging adds 47 PR edits for low-value reads + creates 10× log volume. Moved to optional / per-module on demand. |
| **Phase 8 merged into Phase 9** | Orphan cleanup is one DELETE migration; folding into the CI lock-in PR saves a step. |
| **Deploy comms note** | Every Phase 2 / 5 PR body must include "tick perms BEFORE notifying staff; deploy after hours" to prevent help-desk surge. |

---

## Master safety net (applies to every phase)

Before any production code is touched, **every PR**:

1. **Forks from `main`** so it merges cleanly regardless of order.
2. **Touches ≤ 10 files** so the diff is reviewable.
3. **Runs the admin sanity check** (Phase 0.5) as its first smoke-test step.
4. **Has a CLI smoke test** that runs the changed paths and confirms:
   - PHP syntax clean (`php -l`)
   - Admin can still open every changed page
   - At least one non-admin role with the right permission can open
   - At least one non-admin role without the right permission gets blocked
   - Existing `logActivity` calls still fire (no duplicates introduced)
5. **Has a rollback line** documented in the PR body — usually a single
   `git revert <sha>`.
6. **Has a deploy-note block** in the PR body if it affects user access
   (Phase 2 + 5 specifically).
7. **Never modifies** the three-approval branches.

After every phase: **re-run both audit scripts** and assert the gap count
decreased by the expected amount.

```bash
php scratch/security_audit.php       > scratch/security_findings.txt
php scratch/activity_log_audit.php   > scratch/activity_log_findings.txt
```

---

## Phase overview (revised)

| # | Phase | Risk | PRs | Time |
|---|---|---|---|---|
| 0   | Foundation: helpers + CI guard + log permission-grant events | 🟢 Very low | 1 | Half day |
| 0.5 | Pre-flight admin break-glass sanity check | 🟢 Very low | 1 | 1 hour |
| 1   | DB cleanup (no app code) | 🟢 Low | 1 | 1 hour |
| 2   | Lock the critical admin pages | 🟠 Medium | 1 | Half day |
| 3   | Activity logs on financial-write APIs (3 sub-PRs) | 🟢 Low | 3 | 1 day |
| 4   | Activity logs on remaining write APIs (2 sub-PRs) | 🟢 Low | 2 | 1 day |
| 4.5 | **NEW:** API permission gate audit + fix | 🟠 Medium | 1 | Half day |
| 5   | Permission gates by module (4 sub-PRs, consolidated) | 🟠 Medium | 4 | 2 days |
| 6   | Update `getPagePermissionMapping()` | 🟢 Low | 1 | Half day |
| 7   | **DEFERRED:** View-page logging (optional, per-module on demand) | 🟢 Low | 0 | — |
| 8/9 | CI lock-in + orphan cleanup (merged) | 🟢 Very low | 1 | 2 hours |
| **TOTAL** | | | **15 PRs** | **~6 working days** |

---

## PHASE 0 — Foundation: helpers + CI guard + permission-event logging

**Branch:** `feat/sec-00-foundation`
**Risk:** 🟢 Very low (only adds new code; touches no existing logic except
adding `logActivity()` calls in `user_roles.php` on save)
**PRs:** 1

### What gets added

| File | Purpose |
|---|---|
| `core/security_helpers.php` (new) | Wrapper layer:<br>• `logSecure($action, $description=null)` — calls `logActivity()` once per request<br>• `enforcePageOrAdmin($pageKey)` — alias for `autoEnforcePermission()` with safer error<br>• `assertCanCreate/Edit/Delete($pageKey)` — pre-check helpers for state-changing APIs |
| `core/permissions.php` | Append one line: `require_once __DIR__ . '/security_helpers.php';` |
| `app/constant/settings/user_roles.php` | **NEW:** Add `logActivity()` calls on:<br>• Permission grant/revoke save<br>• Role create<br>• Role delete<br>So permission changes themselves become auditable. |
| `tests/test_security_coverage_cli.php` (new) | The CI regression guard. Reads `scratch/security_findings.txt` + `scratch/activity_log_findings.txt`. **Fails** if the gap count grows. |
| `.github/workflows/deploy.yml` | One new step: `php tests/test_security_coverage_cli.php` |

### Smoke test

```bash
php -l core/security_helpers.php
php tests/test_security_coverage_cli.php
# Manually break one page → verify the test fails.
# Manually save a permission change in user_roles.php → verify a new row in activity_logs.
```

### Rollback

`git revert <sha>` — purely additive except for the `logActivity()` calls in
`user_roles.php`, which are append-only and don't change existing behaviour.

---

## PHASE 0.5 — Pre-flight admin break-glass sanity check (NEW)

**Branch:** `feat/sec-00b-admin-sanity`
**Risk:** 🟢 Very low (test-only, no production code change)
**PRs:** 1

### Why this exists

Every subsequent phase relies on `isAdmin()` returning `true` for `role_id=1`
and `loadUserPermissions()` correctly granting admin full access. If either
silently breaks (e.g., schema drift, a stale `permissions` row), Phase 2
will lock us out of `users.php` and we **cannot fix permissions via the
UI**. This phase adds the guard.

### What gets added

| File | Purpose |
|---|---|
| `scratch/verify_admin_bypass.php` (new) | One-shot script that asserts:<br>1. `role_id=1` exists with `is_admin=1` flag<br>2. `isAdmin()` returns true when given role_id=1 session<br>3. At least one user account has role_id=1<br>4. Admin can read `permissions` table<br>5. Admin can write `role_permissions` table<br>**Fails loudly** if any of those is broken. |
| `tests/test_admin_breakglass_cli.php` (new) | CI-wrapped version of the same checks. Runs on every push. |

### Smoke test

```bash
php scratch/verify_admin_bypass.php   # → "✅ Admin can access permission management"
```

### How it gets used

The smoke test of **every later phase** (0, 1, 2, 3, 4, 4.5, 5, 6, 9) starts
with this script. 30 seconds. Saves us from a 2 AM panic.

---

## PHASE 1 — DB cleanup (no application code change)

**Branch:** `feat/sec-01-db-cleanup`
**Risk:** 🟢 Low (one idempotent migration; existing data untouched)
**PRs:** 1

### Migration: `migrations/2026_05_24_security_seed.php`

1. **Seed the missing permission keys** (Audit A2). All inserted with
   `can_view=0` for every existing role so only Admin can open the page until
   you tick boxes:
   ```
   supplier_payments, reports, nip_materials, financial_reports,
   documents, compliance, loan_documents, asset_report, customer_analysis,
   employee_report, product_analysis, trends_analysis, admin,
   activity_log, profile, payment_create, payslip
   ```

2. **Fix the module typo** (Audit A4):
   ```sql
   UPDATE permissions SET module_name = 'Procurement' WHERE module_name = 'procurement';
   ```

3. **Fix the blank `received_invoices` label** (Audit A5):
   ```sql
   UPDATE permissions SET page_name = 'Received Invoices' WHERE page_key = 'received_invoices';
   ```

4. **No data is deleted in this phase.** Orphan-key cleanup is in Phase 9.

### Smoke test

```bash
php scratch/verify_admin_bypass.php                                 # break-glass first
php migrations/2026_05_24_security_seed.php                         # run
php migrations/2026_05_24_security_seed.php                         # idempotent
# Verify in DB:
SELECT module_name, COUNT(*) FROM permissions GROUP BY module_name;
SELECT page_name FROM permissions WHERE page_key = 'received_invoices';
```

---

## PHASE 2 — Lock the critical admin pages

**Branch:** `feat/sec-02-lock-admin-pages`
**Risk:** 🟠 Medium (Admin login must still work; non-admins will see /unauthorized)
**PRs:** 1

### Files to be gated (the deadly intersection)

| File | Permission key |
|---|---|
| `app/activity_log.php` | `audit_logs` |
| `app/constant/settings/users.php` | `users` |
| `app/constant/settings/add_user.php` | `add_user` |
| `app/constant/settings/edit_user.php` | `edit_user` |
| `app/constant/settings/system_settings.php` | `system_settings` |
| `app/constant/settings/backup_restore.php` | `backup_restore` |
| `app/constant/settings/company_profile.php` | `company_profile` |
| `app/constant/settings/payment_settings.php` | `payment_settings` |
| `app/constant/settings/tax_settings.php` | `tax_settings` |
| `app/constant/settings/notification_settings.php` | `notification_settings` |

### Required PR body block (deploy comms note)

```markdown
**⚠️ Deploy notes:**
After this merges, any non-admin user trying to open the listed pages will
be redirected to /unauthorized. **BEFORE** notifying staff:
1. Log in as Admin → /user_roles.php
2. For each non-admin role that should retain access, tick the appropriate
   boxes for the affected pages.
3. Recommended deploy window: **after hours** to minimise help-desk impact.
4. Have the break-glass admin credentials handy in case of unexpected
   permission gaps.
```

### Smoke test

```bash
php scratch/verify_admin_bypass.php                                 # break-glass first
for f in <touched files>; do php -l "$f"; done
# Manual:
#  - Admin → every page opens normally ✅
#  - Managing Director → /unauthorized for each (no boxes yet)
#  - Tick the right boxes → Manager now opens them
php scratch/security_audit.php
# Pages-with-no-gate count drops from 76 → 66
```

---

## PHASE 3 — Activity logging on financial-write APIs (3 sub-PRs)

**Risk:** 🟢 Low (purely additive — adds `logActivity()` after successful writes; no existing logic touched)

### 3a — `api/account/` writes (17 files)
**Branch:** `feat/sec-03a-log-account-apis`

### 3b — `api/cash_register/` + `api/petty_cash/` (7 files)
**Branch:** `feat/sec-03b-log-cash-apis`

### 3c — `api/operations/` (21 files)
**Branch:** `feat/sec-03c-log-operations-apis`

### Edit pattern

```php
$stmt->execute([...]);
// NEW:
logActivity($pdo, $_SESSION['user_id'], "Deleted Invoice #$invoice_id");
```

### Smoke test for each sub-PR

```bash
php scratch/verify_admin_bypass.php
for f in <touched>; do php -l "$f"; done
# Trigger one write per endpoint, then:
SELECT COUNT(*) FROM activity_logs WHERE created_at > NOW() - INTERVAL 5 MINUTE;
# Confirm count went up by one per write.
php scratch/activity_log_audit.php
# write-APIs-missing-logs count drops:
#   3a:  101 → 84
#   3b:   84 → 77
#   3c:   77 → 56
```

---

## PHASE 4 — Activity logging on remaining write APIs (2 sub-PRs)

### 4a — `api/` root (45 files)
**Branch:** `feat/sec-04a-log-root-apis`

### 4b — `api/document/`, `api/payroll/`, `api/finance/`, `api/sc/`, `api/pos/` (10 files)
**Branch:** `feat/sec-04b-log-misc-apis`

### Acceptance gate

```bash
php scratch/activity_log_audit.php
# write-APIs-missing-logs count: 0
```

---

## PHASE 4.5 — API permission gate audit + fix (NEW)

**Branch:** `feat/sec-04c-api-permission-gates`
**Risk:** 🟠 Medium (each API gate denial blocks the matching POST/PUT/DELETE)
**PRs:** 1

### Why this exists

The original plan locked **pages** with `autoEnforcePermission()` but
ignored **APIs**. A user without page access could still POST directly to
`api/delete_voucher.php`, `api/save_invoice.php`, etc. — bypassing the
entire access-control system.

### What gets added

| File | Purpose |
|---|---|
| `scratch/api_permission_audit.php` (new) | One-shot scan: for every write API (INSERT/UPDATE/DELETE), check whether it calls `canEdit('key')`, `canCreate('key')`, or `canDelete('key')`. Output the gap list. |
| `api/**/*.php` (variable) | For each API in the gap list: add the appropriate `canX()` check at the top, mirroring the page-level permission. |

### Smoke test

```bash
php scratch/api_permission_audit.php > scratch/api_perm_gaps.txt
# Initial baseline: probably 40-60 APIs missing checks
# After PR: 0
# Then for each touched API:
curl -X POST -H "Cookie: <non-admin-session>" /api/delete_voucher.php
# → 403 Access Denied (not 200)
```

### Required PR body block

Same deploy-comms warning as Phase 2.

---

## PHASE 5 — Permission gates by module (4 consolidated sub-PRs)

**Risk:** 🟠 Medium (mitigated by per-PR smoke test with non-admin test role)

Each sub-PR: ≤ 10 files. Each adds `autoEnforcePermission()` + capability flags (`$can_edit = canEdit('key')` etc.) so write buttons hide for non-admins.

### 5a — Commercial (Customer + Sales/Invoice + Procurement) — ~30 pages

**Branch:** `feat/sec-05a-commercial-gates`

```
customer/customer_details.php, customer_documents.php, customer_groups.php,
customer_group_details.php, customer_group_members.php, customer_import.php,
edit_customer.php,
sales/sales_order_create.php, sales_order_view.php,
sales/quotations/quotation_create.php, quotation_edit.php, print_quotation.php,
sales/print_sales_order.php, sales/sales_returns/print_sales_return.php,
invoice/invoice_view.php, invoice_print.php, received_invoices.php,
received_invoices_view.php, payment_create.php,
purchase/purchase_order_details.php, purchase_return_view.php,
purchase/print_purchase_return.php,
grn/grn_view.php, grn_print.php,
Suppliers/supplier_payments.php,
purchase/nip_materials.php, view_nip_materials.php, view_material_list.php,
edit_nip_materials.php
```

> Since this is 30 files, this single PR exceeds the ≤10-files rule. **It
> will be split into 3 commits within the same PR**, grouped by sub-module
> (Customer / Sales+Invoice / Procurement+GRN+Supplier), so the diff is
> reviewable in chunks but ships as one logical unit.

### 5b — Finance & Operations — ~20 pages

**Branch:** `feat/sec-05b-finance-operations-gates`

```
pos/leave_application.php, leave_details.php, payslip.php, system_status.php,
operations/project_view.php, project_budget_report.php, project_financial_report.php,
project_progress_report.php, inspection_view.php, warehouse_stock_view.php,
operations/print_ipc.php,
accounts/expenses.php, add_journal.php, edit_journal.php, edit_expense.php,
expense_details.php, journals.php, journal_details.php, budget_details.php,
payment_voucher_details.php, payment_voucher_print.php, petty_cash_print.php
```

> Same multi-commit split (HR/POS / Operations / Accounts).

### 5c — Reports & Documents — ~21 pages

**Branch:** `feat/sec-05c-reports-documents-gates`

```
reports/delinquency_report.php, loan_performance.php, loan_portfolio.php,
repayment_report.php, audit_report.php, balance_sheet.php, cash_flow.php,
compliance_report.php, customer_analysis.php, employee_report.php,
financial_statements.php, product_analysis.php, trends_analysis.php,
trial_balance.php, asset_report.php,
document/document_library.php, e_signatures.php, compliance_documents.php,
loan_documents.php, select_document_add_esignature.php, preview_template.php
```

### 5d — Inventory, Stock, Loans, Profile — ~10 pages

**Branch:** `feat/sec-05d-inventory-misc-gates`

```
product/product_create.php, product_edit.php, product_import.php,
print_barcode.php,
stock/adjustment_print.php, print_transfer.php, ajax_get_transfer_items.php,
loans/loan_application.php, loan_details.php,
constant/profile/profile.php
```

### Required PR body block (every Phase 5 PR)

Same deploy-comms warning as Phase 2.

### Per-sub-PR smoke test

```bash
php scratch/verify_admin_bypass.php
for f in <touched>; do php -l "$f"; done
# Three logins:
#  - Admin       → every touched page opens ✅
#  - Manager     → /unauthorized for each (no boxes yet)
#  - Tick boxes  → Manager now opens them ✅
php scratch/security_audit.php
# Pages-with-no-gate count drops:
#    5a:  66 → 36
#    5b:  36 → 16
#    5c:  16 → 0   (covers the 23 fixed by Phase 1 seeds)
#    5d:  0   (cleanup remainder)
```

---

## PHASE 6 — Update `getPagePermissionMapping()` (1 PR)

**Branch:** `feat/sec-06-update-mapping-array`
**Risk:** 🟢 Low (defensive fallback layer; doesn't change behaviour if Phase
5 is complete)

### What changes

Extend `getPagePermissionMapping()` to cover all 77 files audit identified
as missing. Defence in depth when a page is reached via the URL router and
doesn't call `autoEnforcePermission()` explicitly.

### Smoke test

```bash
php scratch/security_audit.php
# Filename-not-in-map count: 0
```

---

## PHASE 7 — View-page activity logging (DEFERRED)

**Status:** **Deferred. Not in v2 critical path.**

47 PR file edits for read-access logging. Generates ~10× current
`activity_logs` volume. Write-side logging from Phases 3-4 already gives
"who deleted/changed what" — the primary compliance requirement.

**Decision:** keep out of the v2 critical path. Re-evaluate per module
when/if a specific compliance regime (PCI-DSS, SOX, ISO 27001 audit)
demands read-access tracking.

If you later decide to enable it: it's the simplest possible PR template
— one `logActivity()` line at the top of each view page.

---

## PHASE 8 + 9 — CI lock-in + Orphan cleanup (MERGED, 1 PR)

**Branch:** `feat/sec-09-ci-lock-in`
**Risk:** 🟢 Very low (test + cleanup migration)
**PRs:** 1

### What changes

1. **Orphan cleanup migration** (`migrations/2026_05_24_security_cleanup.php`):
   - DELETE permission rows that have zero references in code AND zero rows
     in `role_permissions`.
   - Idempotent.
   - Lists what's being deleted in the migration log.

2. **CI lock-in** — `tests/test_security_coverage_cli.php` updated:
   ```php
   $max_no_gate     = 0;
   $max_key_missing = 0;
   $max_unmapped    = 0;
   $max_write_unlog = 0;
   ```
   Any future PR that re-introduces a gap **fails CI**.

### Smoke test

```bash
php scratch/verify_admin_bypass.php
php migrations/2026_05_24_security_cleanup.php
php tests/test_security_coverage_cli.php   # → 0 gaps, all green
php scratch/security_audit.php
# All counts: 0
```

---

## Dependency graph (revised)

```
Phase 0   (helpers + CI baseline + permission-event logging)
Phase 0.5 (admin break-glass sanity)
   │
   ├─→ Phase 1 (DB seeds — needs Phase 0.5 to verify admin still admin)
   │      │
   │      ├─→ Phase 2 (lock admin pages — needs Phase 0.5)
   │      │
   │      └─→ Phase 5 (gates by module — needs Phase 1 keys)
   │
   ├─→ Phase 3 (financial-write API logging — independent)
   ├─→ Phase 4 (remaining write API logging — independent)
   │
   ├─→ Phase 4.5 (API permission gates — needs Phase 4 complete)
   │
   ├─→ Phase 6 (mapping array — needs Phase 5 done)
   │
   └─→ Phase 8/9 (CI lock-in + orphan cleanup — needs all above)
```

**Can run in parallel** (independent branches from main):
- Phases 3 + 4 are independent of Phases 2 + 5.
- Phase 0.5 must land first (it's a guard for everything else).

---

## How to drive this

**You approve one phase at a time, in writing.**

> You: "Go Phase 0."
> Me: *creates branch, implements, runs smoke test (including Phase 0.5 if
>      it exists), opens PR, summarizes the diff.*
> You: review the PR, merge or request changes.
> You: "Phase 0.5, please."
> ...

At no point do I work on two phases at the same time. CI guard in the final
phase ensures every standard we land stays locked in forever after.

---

## What I will never touch

- Any of the three-approval branches.
- Any file under `/api/` that already calls `logActivity` (no duplication).
- Existing permission rows other than the typo + blank-name fixes in Phase 1.
- Any logic inside `INSERT`/`UPDATE`/`DELETE` blocks — only the line that
  immediately follows on the success path.
- The pages where the audit shows a permission gate already works
  correctly.

---

## Phase tracker

Update this table as each phase ships.

| Phase | Status | Branch | PR URL | Merged on |
|---|---|---|---|---|
| 0 — Foundation + perm-event logging | ⏳ pending | `feat/sec-00-foundation` | | |
| 0.5 — Admin break-glass sanity (NEW) | ⏳ pending | `feat/sec-00b-admin-sanity` | | |
| 1 — DB cleanup | ⏳ pending | `feat/sec-01-db-cleanup` | | |
| 2 — Lock admin pages | ⏳ pending | `feat/sec-02-lock-admin-pages` | | |
| 3a — Log account APIs | ⏳ pending | `feat/sec-03a-log-account-apis` | | |
| 3b — Log cash APIs | ⏳ pending | `feat/sec-03b-log-cash-apis` | | |
| 3c — Log operations APIs | ⏳ pending | `feat/sec-03c-log-operations-apis` | | |
| 4a — Log root APIs | ⏳ pending | `feat/sec-04a-log-root-apis` | | |
| 4b — Log misc APIs | ⏳ pending | `feat/sec-04b-log-misc-apis` | | |
| 4.5 — API permission gates (NEW) | ⏳ pending | `feat/sec-04c-api-permission-gates` | | |
| 5a — Commercial gates | ⏳ pending | `feat/sec-05a-commercial-gates` | | |
| 5b — Finance & Operations gates | ⏳ pending | `feat/sec-05b-finance-operations-gates` | | |
| 5c — Reports & Documents gates | ⏳ pending | `feat/sec-05c-reports-documents-gates` | | |
| 5d — Inventory & Misc gates | ⏳ pending | `feat/sec-05d-inventory-misc-gates` | | |
| 6 — Mapping array | ⏳ pending | `feat/sec-06-update-mapping-array` | | |
| 7 — View-page logging | 🚫 DEFERRED | (not in v2 critical path) | | |
| 8/9 — CI lock-in + Orphan cleanup (MERGED) | ⏳ pending | `feat/sec-09-ci-lock-in` | | |

---

## Quick re-run commands

```bash
# Re-run permission audit
php scratch/security_audit.php > scratch/security_findings.txt

# Re-run activity-log audit
php scratch/activity_log_audit.php > scratch/activity_log_findings.txt

# After Phase 0, validate the CI guard
php tests/test_security_coverage_cli.php

# Before any phase: verify admin still has break-glass access
php scratch/verify_admin_bypass.php
```

---

## Final checklist before kick-off

- [x] Plan v2 read
- [x] Confirmed: fork from main, default OFF, writes first
- [x] Confirmed: one phase at a time, you approve each
- [x] ~15 small PRs over ~6 working days agreed
- [x] Non-admin test role available (Managing Director, role_id=2)
- [x] Break-glass strategy in place (Phase 0.5)
- [x] API gates added to plan (Phase 4.5)
- [x] Permission-grant events will be logged (in Phase 0)
- [x] Phase 7 (view-page logging) deferred to optional

When ready, just say **"Go Phase 0"** and we start.
