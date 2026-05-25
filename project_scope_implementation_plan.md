# BMS — Project-Scope Implementation Plan (2026-05-24)

**Goal.** Add a second, orthogonal access-control axis on top of the
existing role/permission system: every user gets explicitly assigned to
zero-or-more projects, and the system only shows them rows that belong
to those projects. Cross-resource visibility (warehouses, suppliers,
customers, employees) derives transitively from the user's assigned
projects, plus a narrow override table for exceptions.

**Guiding decisions (confirmed):**
- Every scope branch forks from `main`. No stacking — independent
  branches merge in any order.
- Default-deny on rollout: a non-admin user with no `user_projects`
  rows sees nothing on scoped pages until an admin assigns projects.
- Admin always bypasses every scope check (`isAdmin()` returns true).
- One phase at a time, with explicit "Go" approval per phase. Same
  workflow as `security_implementation_plan.md`.

---

## Two-axis model

| Axis | Question it answers | Source of truth |
|---|---|---|
| **Role** (existing) | What verbs can this user do? — view / create / edit / delete / review / approve | `roles`, `permissions`, `role_permissions` |
| **Project scope** (NEW) | Which rows can this user touch? | `user_projects` + `user_scope_overrides` |

Both checks must pass on every request. Admin bypasses both. Failing
either denies the request — no implicit grants, no shortcuts.

### Concrete example

A user with role **Manager** assigned to **Project A only**:

1. Visits `/purchase_orders.php` — list filtered to only Project A's POs.
2. Visits `/purchase_order_details.php?id=42` where PO 42 is on Project B
   — gets 403, even though their Manager role allows viewing POs.
3. Visits `/warehouses.php` — sees only warehouses that appear on
   Project A's POs / GRNs / stock movements (transitive derivation).
4. If admin adds a `user_scope_overrides` row granting all warehouses
   — sees every warehouse, but their *role* still gates the verbs
   (can view but not delete unless `canDelete('warehouses')` is on).

The override **never** bypasses the role check; it only widens the
scope filter for one resource type.

---

## Data model — two new tables, one migration

```sql
-- Primary: which projects can this user touch?
CREATE TABLE user_projects (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  user_id      INT NOT NULL,
  project_id   INT NOT NULL,
  assigned_by  INT NOT NULL,
  assigned_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_user_project (user_id, project_id),
  INDEX idx_user (user_id),
  INDEX idx_project (project_id)
);

-- Optional: cross-project resource grants for the rare exception case.
-- e.g., "Procurement Officer must see ALL warehouses regardless of
-- which projects they are on" → one row with resource_id = NULL.
CREATE TABLE user_scope_overrides (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  user_id        INT NOT NULL,
  resource_type  ENUM('warehouse','supplier','customer','employee') NOT NULL,
  resource_id    INT NULL,        -- NULL = grant ALL of this resource type
  granted_by     INT NOT NULL,
  granted_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_type (user_id, resource_type)
);
```

That's the whole new schema. No per-resource junction tables (no
`user_warehouses`, `user_suppliers`, etc.) — derivation handles those.

---

## Runtime model — session cache + helpers

### Session bootstrap

At login / role-reload, populate once:

```php
$_SESSION['scope'] = [
    'is_admin'    => true|false,
    'projects'    => [3, 7, 12],
    'warehouses'  => [1, 4],         // derived from projects + overrides
    'suppliers'   => [22, 31, 88],
    'customers'   => [5, 14],
    'employees'   => [101, 102, 144],
    'computed_at' => 1716598200,
];
```

Single SQL pass at login (UNION ALL across the source tables tagged
with `project_id`). Subsequent requests read the arrays — no per-query
joins.

### Central helpers (one file: `core/project_scope.php`)

```php
function userCan($resourceType, $resourceId): bool;
function scopeFilterSql($resourceType, $alias = ''): string;
function refreshScopeCache(int $userId): void;
function loadUserScope(int $userId): void;     // called at login
```

Usage mirrors the `canX()` pattern from the security rollout:

```php
// LIST page — filter the query
$sql = "SELECT * FROM purchase_orders WHERE status != 'deleted' "
     . scopeFilterSql('project', 'purchase_orders');

// DETAIL page — block direct URL access
if (!userCan('project', $po['project_id'])) {
    http_response_code(403); die('Not your project');
}
```

`scopeFilterSql()` returns an empty string for admins so existing
queries are unchanged on the admin path.

---

## What gets scoped — table-by-scope map

The full BMS catalogue mapped to which scope helper applies.

| Scope filter | Tables it gates |
|---|---|
| **`project`** (direct `project_id` column) | projects, project_milestones, project_inspections, project_planning_reports, project_planning_tasks, project_progress_reports, project_progress_report_details, project_progress_report_attachments, project_scope_documents, project_notes, project_goods_returns, project_goods_return_items, project_sub_contractors, sub_contractor_projects, interim_payment_certificates, employees, budgets, invoices, quotations, sales_orders, purchase_orders, purchase_receipts, purchase_returns, rfq, deliveries, delivery_orders, expenses, payment_vouchers, sc_payments, supplier_invoices, payments, stock_movements, products (where project_id present), nip_material_lists, pos_sales, documents, notifications, messages, sms_alerts, email_logs |
| **`warehouse`** (derived) | warehouses, product_stocks |
| **`supplier`** (derived) | suppliers, supplier_payments, sub_contractors |
| **`customer`** (derived) | customers, customer_documents, customer_groups |
| **`employee`** (derived) | employee_details, payroll, leaves, attendance, payslips |
| (unscoped — always visible) | users, roles, permissions, system_settings, document_templates, brands, tax_settings, etc. |

A page accessing two scoped resource types (e.g., a PO has both
`project_id` and `warehouse_id`) gets gated on the more authoritative
one — usually `project`. The transitive derivation makes the secondary
check redundant.

---

## Phase overview

| # | Phase | What | PRs | Risk |
|---|---|---|---|---|
| **A** | Foundation | Migration (2 tables), `core/project_scope.php` helper, session bootstrap, admin UI `user_projects.php`. **No query filters applied yet** — runtime unchanged. | 1 | 🟢 Low |
| **B** | Operations + Projects scope | Apply `scopeFilterSql('project', ...)` to ~14 operations and project-management pages. | 1 | 🟠 Medium |
| **C** | Finance + Procurement scope | Apply to invoices, quotations, SOs, POs, GRNs, DNs, DOs, RFQs, expenses, vouchers, sc_payments, budgets, supplier_invoices, payments. | 1 | 🟠 Medium |
| **D** | HR + Inventory scope | employees, payroll, leaves, attendance, stock_movements, products (where project-tagged), nip_material_lists. | 1 | 🟠 Medium |
| **E** | Cross-resource scope | warehouses, suppliers, customers, sub_contractors — transitive filtering. | 1 | 🟠 Medium |
| **F** | CI lock-in | `scratch/project_scope_audit.php` + `tests/test_project_scope_cli.php` with ceiling 0. Any future query against a scoped table without `scopeFilterSql()` fails CI. | 1 | 🟢 Low |
| **TOTAL** | | | **6 PRs** | **~5 working days** |

Phase 7-style view-page logging is **not in scope** here.

---

## Master safety net (every phase)

1. Forks from `main` — no stacking.
2. Touches ≤ 12 files per PR (audit + ceiling drop in F may exceed).
3. **Smoke test** before push:
   - `php -l` clean on every changed file.
   - Admin still sees every row (sanity).
   - Non-admin with a project assignment sees only that project's rows.
   - Non-admin with no assignment sees zero rows on scoped pages.
4. **Activity log** entry on every scope denial (`logActivity` already
   wired in by Phase 4/9). Admins can see attempted cross-scope access.
5. **Rollback line** in every PR body — single `git revert <sha>`.
6. **Deploy-comms note** in Phase B+ PR bodies, identical pattern to
   security Phase 2/5.

---

## Phase A — Foundation (this PR)

**Branch:** `feat/scope-00-foundation`
**Risk:** 🟢 Low (additive only — no SELECT changed, no row hidden)
**PRs:** 1

### What ships

| File | Purpose |
|---|---|
| `migrations/2026_05_24_project_scope_foundation.php` (new) | Creates `user_projects` and `user_scope_overrides` tables. Idempotent (`CREATE TABLE IF NOT EXISTS`). |
| `core/project_scope.php` (new) | `userCan()`, `scopeFilterSql()`, `loadUserScope()`, `refreshScopeCache()`. Loaded from `core/permissions.php` so it's available wherever permissions are. |
| `core/permissions.php` (1 line) | `require_once __DIR__ . '/project_scope.php';` |
| `header.php` (3 lines) | Call `loadUserScope($_SESSION['user_id'])` once per request if `$_SESSION['scope']` isn't populated yet. |
| `app/constant/settings/user_projects.php` (new) | Admin UI: pick a user → tick projects → save. Tab for resource overrides. |
| `migrations/2026_05_24_project_scope_perm_seed.php` (new) | Adds `user_projects` page_key to `permissions` table so the admin UI itself is permission-gated. |
| `roots.php` (1 entry) | Routes `user_projects` → the new UI page. |

### Acceptance gate

```bash
php migrations/2026_05_24_project_scope_foundation.php   # both tables present, idempotent
php migrations/2026_05_24_project_scope_foundation.php   # second run is a no-op
# Manual: login as admin → /user_projects.php → see list of users + tick boxes
# Manual: login as non-admin → /user_projects.php → /unauthorized
# Smoke: load any existing page (e.g., /purchase_orders.php) — list unchanged
#        (no filters wired in this PR; we only laid the foundation)
```

### Rollback

Single `git revert <sha>`. The two new tables stay (no data loss) but
the helper file is gone and `header.php` falls back to its pre-PR
shape; existing pages keep working because no query was modified.

---

## Phase B — Operations + Projects scope

**Branch:** `feat/scope-01-operations-gates`
**Risk:** 🟠 Medium (default-deny activates here for these tables)

Apply `scopeFilterSql('project', $alias)` to every list page and API
that selects from a project-scoped operations table, and call
`userCan('project', $row['project_id'])` at the top of every detail
page.

**Pages touched (~14):**

```
app/bms/operations/project_view.php           ← gate by ?id=
app/bms/operations/project_budget_report.php   ← gate by ?id=
app/bms/operations/project_financial_report.php
app/bms/operations/project_progress_report.php
app/bms/operations/inspection_view.php
app/bms/operations/print_ipc.php
app/bms/operations/warehouse_stock_view.php
app/bms/operations/projects.php (list)         ← filter SQL
app/bms/operations/assets.php (list)           ← filter SQL
app/bms/operations/inspections.php (list)
app/bms/operations/maintenance.php (list)
api/operations/save_progress_report.php        ← assert project access
api/operations/save_inspection.php             ← assert project access
api/operations/save_ipc.php                    ← assert project access
```

**Smoke test (must pass before merge):**
- Admin still sees every project — unchanged.
- Manager assigned to Project A only — `/projects.php` shows Project A only.
- Manager tries `/project_view.php?id=B` directly — 403.
- Non-admin with **no** assignments — `/projects.php` is empty.

**Deploy-comms PR body block** (mandatory, mirrors security Phase 2):

```markdown
**⚠️ Deploy notes:**
Once this merges, non-admin users without project assignments will see
empty pages under Operations. BEFORE notifying staff:
1. Admin logs in → /user_projects.php
2. Assigns each non-admin user to their working projects.
3. Recommended deploy window: after hours.
4. Break-glass admin credentials handy in case of unexpected lockouts.
```

---

## Phase C — Finance + Procurement scope

**Branch:** `feat/scope-02-finance-procurement-gates`
**Risk:** 🟠 Medium

Apply scope filter to the finance and procurement tables:
invoices, quotations, sales_orders, purchase_orders, purchase_receipts,
purchase_returns, rfq, deliveries, delivery_orders, expenses,
payment_vouchers, sc_payments, budgets, supplier_invoices, payments.

Same pattern, same smoke test, same deploy-comms note as Phase B.

---

## Phase D — HR + Inventory scope

**Branch:** `feat/scope-03-hr-inventory-gates`
**Risk:** 🟠 Medium

`employees`, `payroll`, `leaves`, `attendance`, `payslips`,
`stock_movements`, `products` (where `project_id` is set),
`nip_material_lists`.

Special case: `employees.project_id` already exists in the schema, so
the filter is direct. Payroll/leaves/attendance derive through
`employee_id → project_id`.

---

## Phase E — Cross-resource scope

**Branch:** `feat/scope-04-cross-resource-gates`
**Risk:** 🟠 Medium

Filter `warehouses`, `suppliers`, `customers`, `sub_contractors` using
the derived `$_SESSION['scope']['warehouses']` etc. arrays.

For the manual-override case (Procurement Officer with cross-project
warehouse visibility), the `user_scope_overrides` row simply extends
the derived set at session load.

---

## Phase F — CI lock-in

**Branch:** `feat/scope-05-ci-lock-in`
**Risk:** 🟢 Low

1. **`scratch/project_scope_audit.php`** (new): walks every PHP file
   under `app/` and `api/`, finds queries against scoped tables, flags
   any that don't include `scopeFilterSql()` or an explicit `WHERE
   project_id IN (...)`.
2. **`tests/test_project_scope_cli.php`** (new): reads the audit
   output, ceiling at 0. Any future regression fails CI.
3. Document the runbook for admins (who to call when an assignment
   looks wrong).

---

## Decisions to revisit in v2

| Decision | v1 choice | When to revisit |
|---|---|---|
| `access_level` column on `user_projects` (viewer/member/manager per project) | OUT | When a user needs different verb-level access on different projects (e.g., Manager on A, Viewer on B). Workaround in v1: separate accounts or per-project role mapping. |
| Row-level "created_by" scoping (your own records only) | OUT | If a particular project gets bigger and same-project users need to be siloed from each other. |
| Project hierarchies (programmes) | OUT | When two projects roll up under one programme and users assigned to the programme need both. |

---

## Quick re-run commands

```bash
# After Phase A:
php migrations/2026_05_24_project_scope_foundation.php
php scratch/project_scope_audit.php > scratch/project_scope_findings.txt

# Smoke a non-admin scope:
php -r 'require "includes/config.php"; require "core/project_scope.php";
        loadUserScope(42); print_r($_SESSION["scope"] ?? "no scope");'

# Final CI lock-in check (after Phase F):
php tests/test_project_scope_cli.php
```

---

## Phase tracker

Update this table as each phase ships.

| Phase | Status | Branch |
|---|---|---|
| A — Foundation | ⏳ in flight | `feat/scope-00-foundation` |
| B — Operations + Projects | ⏳ pending | `feat/scope-01-operations-gates` |
| C — Finance + Procurement | ⏳ pending | `feat/scope-02-finance-procurement-gates` |
| D — HR + Inventory | ⏳ pending | `feat/scope-03-hr-inventory-gates` |
| E — Cross-resource | ⏳ pending | `feat/scope-04-cross-resource-gates` |
| F — CI lock-in | ⏳ pending | `feat/scope-05-ci-lock-in` |
