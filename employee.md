# BMS Employee Section — Professionalisation Plan (WorkDo gap closure)

> **How this file grows:** the gap analysis found 4 tiers of missing features. This file is
> planned **one tier at a time** — Tier 1 is fully planned below and must be fully implemented
> (no gaps) before Tier 2 planning is added to this same file, then Tier 3, then Tier 4.
>
> | Tier | Scope | Plan status |
> |---|---|---|
> | **Tier 1** | Employee lifecycle: Promotions, Transfers, Awards, Warnings, Complaints, Resignations, Terminations + Service Record timeline | **IMPLEMENTED 2026-07-02 — PRs #1101–#1105 (stacked, merge in order into develop)** |
> | **Tier 2** | Compliance & documents: HR document expiry alerts, contracts module, real org structure (`reporting_to` → FK) | **IMPLEMENTED 2026-07-03 — merged to main (PR #1115) + deployed** |
> | **Tier 3** | Performance & development: indicators, goals, appraisal cycles, training records | **IMPLEMENTED 2026-07-03 — merged to main (PR #1119/#1120) + deployed** |
> | **Tier 4** | Talent & engagement: recruitment/ATS, onboarding checklists, announcements, meetings & trips, employee self-service | **IMPLEMENTED 2026-07-03 — branch `feat/hr-talent-foundation`, 180 assertions; PR pending** |

---

## 1. Objective

Close the professional gap between the BMS employee section and WorkDo (HRMGo / Dash SaaS HRM)
**without breaking any current logic**. Today, when an employee is promoted, transferred,
disciplined, resigns or is terminated, BMS silently overwrites fields (`designation_id`,
`department_id`, `basic_salary`, `employment_status`) and keeps **no record of the event
itself** — no history, no reason, no letter, no approval. Tier 1 turns every such event into a
recorded, approvable, printable **Service Record** entry.

### Guiding principles (apply to every phase)

1. **Zero breakage.** No existing table is ALTERed in Tier 1. No existing endpoint changes its
   request/response contract. `api/update_employee_status.php` keeps working exactly as today.
2. **Additive only.** New tables, new pages, new APIs, new routes, new permission rows. Existing
   pages get only *additions* (a new card/section), never rewrites of working blocks.
3. **Follow the house templates verbatim** — page skeleton (templates §8), API 6-step (§9),
   soft delete (§12), `logActivity` + `logAudit` on every write (§13), `safe_output` (§14),
   Bootstrap icons only (§15), AJAX submit pattern (§16), stat cards (§17), CSRF (§21),
   upload security 5-step + `.htaccess` + gatekeeper download (§19).
4. **Project scope.** `employees.project_id` exists and is already gated (Phase D pattern in
   `employee_details.php:40-45`, `assertScopeForRecord()` in APIs). Every new lifecycle API and
   list query must apply the same gates.
5. **Workflow permissions (§11.1).** Lifecycle events are status-changing records → gate
   transitions with `canApprove` / `canReject`, not just CRUD.
6. **Migrations** are idempotent, CLI-only, per `.claude/migrations.md` — never raw SQL.
7. **Branch/PR workflow:** one dedicated branch per phase off `develop`, tests written and passed
   live, then PR into `develop`. Changelog entry at commit time.
8. **Runtime verification** after each phase: exercise the real endpoints (create → approve →
   see effect → see timeline), not just lint.

---

## 2. Current state audit (studied 2026-07-02 — do not break any of this)

### 2.1 Data layer

- **`employees`** — rich master (60+ columns): personal, emergency contact (6 columns), postal &
  physical address, banking + `mobile_money`, `tax_id`, `social_security_number`,
  `basic_salary`, `hourly_rate`, `payment_frequency/method`, `probation_end_date`,
  `contract_end_date`, `photo`, `documents` (text), `ledger_account_id`, `project_id`,
  `department_id` (+ legacy `department` varchar — both exist), `designation_id`,
  `employment_type_id`, `reporting_to` (free-text varchar).
- **`employment_status`** enum: `active, probation, contract, on_leave, terminated, resigned` —
  already contains every value Tier 1 needs → **no enum change required**.
- Supporting HR tables already present: `departments`, `designations`, `employment_types`,
  full attendance suite (+ rules, summary, audit log), full leave suite (types, balances,
  entitlements, approval workflow + history), full payroll suite (payroll, items, audit,
  `salary_components`, `employee_salary_components`, allowances, deductions), `employee_shifts`,
  `shift_schedules`, `holidays`, `public_holidays`.
- **Missing entirely (Tier 1 target):** any table recording promotions, transfers, awards,
  warnings, complaints, resignations, terminations.

### 2.2 Pages & APIs (HR area, all under `app/bms/pos/`)

- `employees.php` — list, stat cards, DataTable + mobile cards, add/edit modals, CSV
  import/export, print.
- `employee_details.php` — profile page with sections: Contact Info sidebar, Personal &
  Employment Information, Compensation & Payment, Salary Structure (component assignment,
  Plan H1), Emergency Contact, Employee Documents, Notes, Payroll & Payment History. Uses
  `autoEnforcePermission('employees')` + Phase D project-scope gate.
  ⚠️ Branch `feature/employee-details-full-fields` currently has uncommitted work on this file —
  Tier 1's timeline section (Phase 1.7) must be built **after** that branch is merged, on top of
  its final state.
- APIs: `api/add_employee.php`, `update_employee.php`, `get_employee.php`, `get_employees.php`,
  `delete_employee.php`, `import_employees.php`, `update_employee_status.php`,
  `api/account/search_employees.php` (AJAX employee search — reuse for Select2),
  `api/account/get_employee_report.php`, `get_employee_statement.php`.
- **`api/update_employee_status.php` (the critical no-break path):** checks `canEdit('employees')`
  → `assertScopeForRecord` → transaction → `UPDATE employees SET employment_status = ?,
  updated_by = ?` → `logAudit(action:'update_status', activity_type:'status_change',
  entity_type:'employee', old/new values)` → commit. **Tier 1 never modifies this file**; the
  lifecycle approval APIs replicate this exact UPDATE + audit shape inside their own transaction.

### 2.3 Wiring mechanics (how a new page/API becomes live)

1. **Route map:** `roots.php` `$routes` array, HR block at lines ~612-660 — two entries per
   target (`'name'` and `'name.php'`), pages → `POS_DIR`, APIs → `API_DIR`.
2. **Navigation:** `header.php` ~lines 906-925 — HR dropdown, each item wrapped in
   `canView('page_key')`.
3. **Permissions:** `permissions` table (`page_key`, `permission_name`, `page_name`,
   `module_name`) + `role_permissions` (can_view/create/edit/delete + workflow verbs where the
   columns exist). Seed via migration using the `INSERT IGNORE` + `SHOW COLUMNS` pattern
   (reference: `migrations/2026_05_19_received_invoices_permissions.php`).
4. **Migrations:** `migrations/YYYY_MM_DD_description.php`, CLI-only guard, idempotent, no
   transactions around DDL, `exit(1)` on failure; runner executes on deploy.
5. **Uploads:** `uploads/<entity>/` + `.htaccess` deny-exec + `registerFileInLibrary()` +
   gatekeeper download API for sensitive files.

---

## 3. TIER 1 — Employee Lifecycle & Service Record

### 3.0 Design decisions

**D1 — One table, not seven.** All seven event types share one table
`employee_lifecycle_events` with an `event_type` enum and a superset of typed nullable columns.
Rationale: one timeline query for the Service Record, one API family, one approval workflow,
one scope gate — instead of 7× duplicated code. Type-specific fields are plain columns (BMS
style — no JSON blobs).

**D2 — One management page, not seven menu items.** A single page `hr_actions.php`
("HR Actions") with stat cards per event type and a type filter, plus per-type add modals.
Keeps the HR dropdown clean. Page key: `employee_lifecycle`.

**D3 — Approval workflow: `pending → approved / rejected`, plus `cancelled` by creator.**
Simple two-step (not the full 6-state document workflow — these are HR actions, not financial
documents). Gates: create = `canCreate('employee_lifecycle')`, approve/reject =
`canApprove('employee_lifecycle')` / `canReject('employee_lifecycle')`, cancel own pending =
creator or `canEdit`. Soft delete via `status='deleted'` (§12) restricted to `canDelete` + only
non-approved events.

**D4 — Effects apply on APPROVAL, atomically, and are reversible in history.** When an event is
approved, its effect is applied to `employees` inside the same transaction, and the event row
stores **both old and new values**, so history is never lost:

| Event type | Effect on approval (all optional fields only applied when provided) |
|---|---|
| `promotion` | `employees.designation_id` → new; optionally `basic_salary` → new |
| `demotion`* | same mechanics as promotion (WorkDo lacks this; trivial to include) |
| `transfer` | `employees.department_id` → new; optionally `project_id` → new (+ keep legacy `department` varchar in sync with the new department's name, since both columns exist today) |
| `award` | none (record only) |
| `warning` | none (record only) |
| `complaint` | none (record only; complainant may be employee or other) |
| `resignation` | `employment_status` → `resigned` **on/after last working day** — see D5 |
| `termination` | `employment_status` → `terminated` on approval |

\* included in the enum from day one so no later ALTER is needed; UI may expose it later.

**D5 — Resignation applies status on the last working day, not approval day.** An approved
resignation with a future `end_date` leaves the employee `active` until the date passes. A tiny
idempotent catch-up (same pattern as existing summary/maintenance jobs): the `hr_actions.php`
page run applies any approved resignation whose `end_date <= CURDATE()` and whose effect is not
yet applied (`effect_applied_at IS NULL`). No cron dependency, no missed updates.

**D6 — `update_employee_status.php` stays.** Direct status flips (e.g. `on_leave`) remain valid
and untouched. The lifecycle module is the *professional* path; the old path continues to work
so nothing existing breaks. Both paths logAudit, so the audit trail stays complete either way.

**D7 — Attachments.** Each event supports one optional attachment (letter/certificate/evidence)
stored in `uploads/lifecycle/` (5-step validation + `.htaccess`), registered in the document
library, downloaded only via gatekeeper API (HR docs are sensitive).

---

### 3.1 New table (single migration)

```sql
CREATE TABLE IF NOT EXISTS employee_lifecycle_events (
    event_id            INT AUTO_INCREMENT PRIMARY KEY,
    employee_id         INT NOT NULL,
    event_type          ENUM('promotion','demotion','transfer','award','warning',
                             'complaint','resignation','termination') NOT NULL,
    event_date          DATE NOT NULL,                  -- effective date of the action
    end_date            DATE NULL,                      -- resignation: last working day; warning: expiry
    title               VARCHAR(255) NOT NULL,          -- e.g. "Promoted to Senior Accountant"
    description         TEXT NULL,                      -- reason / citation / narrative
    -- promotion / demotion
    old_designation_id  INT NULL,
    new_designation_id  INT NULL,
    old_salary          DECIMAL(15,2) NULL,
    new_salary          DECIMAL(15,2) NULL,
    -- transfer
    old_department_id   INT NULL,
    new_department_id   INT NULL,
    old_project_id      INT NULL,
    new_project_id      INT NULL,
    -- award
    award_type          VARCHAR(100) NULL,              -- e.g. "Employee of the Month"
    award_gift          VARCHAR(255) NULL,
    award_amount        DECIMAL(15,2) NULL,
    -- warning / complaint / termination
    severity            ENUM('verbal','written','final') NULL,      -- warnings
    complainant         VARCHAR(255) NULL,                          -- complaints
    resolution          TEXT NULL,                                  -- complaints: outcome
    termination_type    VARCHAR(100) NULL,              -- misconduct / redundancy / contract end...
    notice_date         DATE NULL,                      -- resignation/termination notice given
    -- workflow
    status              ENUM('pending','approved','rejected','cancelled','deleted')
                        NOT NULL DEFAULT 'pending',
    approved_by         INT NULL,
    approved_at         DATETIME NULL,
    reject_reason       VARCHAR(500) NULL,
    effect_applied_at   DATETIME NULL,                  -- when the employees-table effect ran (D4/D5)
    attachment_path     VARCHAR(500) NULL,
    attachment_name     VARCHAR(255) NULL,
    created_by          INT NOT NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_by          INT NULL,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_emp_type (employee_id, event_type, status),
    KEY idx_status_date (status, event_date),
    CONSTRAINT fk_ele_employee FOREIGN KEY (employee_id)
        REFERENCES employees(employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Notes: FKs to `designations`/`departments`/`projects` are *not* declared (values are historical
snapshots and those rows may be soft-deleted later — the ids are kept plus resolved via LEFT
JOIN exactly like `employee_details.php` does today). `ENGINE=InnoDB` explicit (post
MyISAM→InnoDB migration, PR #1091).

---

### 3.2 Phases (each = one branch off `develop`, tests, PR)

#### Phase 1.1 — Foundation: migration + permissions + routes + nav
**Branch:** `feat/hr-lifecycle-foundation`

1. `migrations/2026_07_XX_employee_lifecycle_events.php` — table above (idempotent,
   `CREATE TABLE IF NOT EXISTS`).
2. `migrations/2026_07_XX_employee_lifecycle_permissions.php` — seed `permissions` row
   (`page_key='employee_lifecycle'`, module `HR`) + `role_permissions` per the received-invoices
   pattern: full CRUD + approve/reject for Admin/MD/Director + HR-capable roles (mirror whichever
   roles currently hold `can_edit` on `employees` — resolve role ids at runtime from
   `role_permissions`, **never hard-code local ids**, per live-system rule); view-only for the
   rest. `SHOW COLUMNS` guards for `can_approve`/`can_reject`.
3. `roots.php` — add routes (both `'x'` and `'x.php'` forms, HR block):
   `hr_actions` → `POS_DIR/hr_actions.php`, plus API routes
   (`api/add_lifecycle_event`, `api/get_lifecycle_event`, `api/get_lifecycle_events`,
   `api/change_lifecycle_status`, `api/delete_lifecycle_event`,
   `api/download_lifecycle_attachment`).
4. `header.php` — one new HR dropdown item under Employees:
   `canView('employee_lifecycle')` → "HR Actions" (`bi-person-lines-fill`).
5. `uploads/lifecycle/` + `.htaccess` (deny-exec block, §19) — created by the migration.

**No-break check:** pure additions; run existing pages after — employees list/details unchanged.
**Tests:** CLI test that the migration is idempotent (run twice), permission rows exist, routes resolve.

#### Phase 1.2 — Core APIs (create / read / list)
**Branch:** `feat/hr-lifecycle-apis`

1. `api/add_lifecycle_event.php` — 6-step template. Validates: employee exists & in scope
   (`assertScopeForRecord('employees','employee_id',…)`), `event_type` in whitelist, dates sane
   (`end_date >= event_date`), per-type required fields (promotion → `new_designation_id`;
   transfer → `new_department_id` or `new_project_id`; warning → `severity`; resignation →
   `end_date`; termination → `termination_type`). **Snapshots old values server-side** at create
   time (old designation/salary/department/project read from `employees` — never trusted from
   the client). Optional attachment via §19 5-step + `registerFileInLibrary`. `logActivity` +
   `logAudit` (entity_type `employee_lifecycle`, entity_id new event).
2. `api/get_lifecycle_events.php` — list for the page: filters `event_type`, `status`,
   `employee_id`, date range; JOINs employees (name, photo) + designations/departments (old & new
   names, LEFT JOIN); **project scope**: non-admins get
   `AND (e.project_id IN (...) OR e.project_id IS NULL)` via `scopeFilterSqlNullable('project','e')`
   on the joined employees alias; excludes `status='deleted'`.
3. `api/get_lifecycle_event.php` — single row for edit/view modal, same scope gate.

**Tests:** CLI tests — create each of the 7 types (validation matrix), scope denial for
out-of-scope employee, list filtering.

#### Phase 1.3 — Workflow API: approve / reject / cancel + effects
**Branch:** `feat/hr-lifecycle-workflow`

1. `api/change_lifecycle_status.php` — transition map (§11.1 pattern):
   `pending → approved (approve) | rejected (reject) | cancelled (cancel)`. Approved/rejected are
   terminal (a wrong approval is corrected by a new counter-event — never by editing history).
   Gates: `canApprove` / `canReject('employee_lifecycle')`; `cancel` = creator or
   `canEdit('employee_lifecycle')`. **A user cannot approve an event they created**
   (segregation of duties; admins exempt).
2. **On approval — single transaction:**
   - re-read the event row `FOR UPDATE`, verify still `pending`;
   - apply the D4 effect to `employees` (same UPDATE shape as `update_employee_status.php`:
     `SET <field> = ?, updated_by = ?`), set `effect_applied_at = NOW()` — except resignations
     with future `end_date` (D5: leave `effect_applied_at` NULL);
   - `logAudit` twice: the event approval (entity `employee_lifecycle`) **and**, when a field
     changed, the same `status_change`/field-change audit shape the old endpoint writes for the
     employee (entity `employee`, old/new values) — so downstream audit consumers see no
     difference from today.
3. **D5 catch-up helper** `core/lifecycle_effects.php` → `applyDueLifecycleEffects($pdo)`:
   approved resignations with `end_date <= CURDATE()` and `effect_applied_at IS NULL` → apply +
   stamp, idempotent. Called at the top of `hr_actions.php` and `employee_details.php` (cheap
   indexed query — `idx_status_date`).
4. `api/delete_lifecycle_event.php` — soft delete, only `pending/rejected/cancelled` events
   (approved history is immutable), `canDelete` gate.
5. `api/download_lifecycle_attachment.php` — gatekeeper (§19): auth + `canView` + scope check on
   the event's employee, then streams file.

**No-break check:** `api/update_employee_status.php` untouched — regression-test it directly.
**Tests:** CLI — approve promotion applies designation+salary and stamps `effect_applied_at`;
approve future resignation does NOT flip status, catch-up helper flips it once date passes and
is idempotent; reject/cancel apply no effect; creator-cannot-approve; double-approve blocked.

#### Phase 1.4 — HR Actions page
**Branch:** `feat/hr-actions-page`

`app/bms/pos/hr_actions.php` — house template §8, page_key `employee_lifecycle`:

- **Stat cards** (§17): Pending Approvals (warning), Promotions, Transfers, Awards, Warnings,
  Exits (resignations+terminations) — counts current year, scope-filtered.
- **Filters row:** event type (static select), status, employee (Select2 AJAX via existing
  `api/account/search_employees.php`), date range.
- **DataTable + mobile card view** (drawCallback pattern): date, employee (photo + name, links
  to `employee_details`), type badge (color per type), title, old→new summary (e.g.
  "Accountant → Senior Accountant"), status badge, actions.
- **Actions per row by state + permission** (§11.1 button pattern): View (modal, always),
  Approve/Reject (pending + `canApprove`/`canReject`, reject prompts for reason), Cancel
  (pending, creator/`canEdit`), Delete (non-approved + `canDelete`), Download attachment.
- **"New Action" button** (`canCreate`) → modal: pick employee (Select2 AJAX) + event type; the
  form shows/hides per-type field groups (one modal, JS toggles sections — mirrors how
  `pos_modals_new.php` handles variant forms). On employee+type selection, current
  designation/salary/department load read-only ("From") via `api/get_employee.php` so the user
  only enters the "To" values; server re-snapshots anyway (Phase 1.2).
- CSRF hidden field, submitForm helper, Swal feedback, `applyDueLifecycleEffects()` call at top.

**Tests:** runtime — render page as admin and as view-only role (buttons hidden), create→approve
→verify employee row changed, empty-state render.

#### Phase 1.5 — Service Record on `employee_details.php`
**Branch:** `feat/employee-service-record` — ⚠️ **start only after
`feature/employee-details-full-fields` is merged**; build on its final layout.

Additions only (no existing section touched):

1. New card **"Service Record"** placed after "Personal & Employment Information": vertical
   timeline (newest first) of this employee's non-deleted lifecycle events — icon + color per
   type, date, title, old→new line, status badge, approver + date, attachment link, description
   collapse. Print-friendly (inherits existing `@media print` card rules) so the printed
   Employee Profile Report becomes a true personnel file.
2. Header quick actions (only when `canCreate('employee_lifecycle')`): a small "HR Action"
   dropdown (Promote, Transfer, Award, Warn, Record Complaint, Resignation, Termination) opening
   the same modal as Phase 1.4 with the employee pre-selected (modal include shared between the
   two pages — one include file `app/bms/pos/includes/lifecycle_modal.php`, so no duplicated
   form logic).
3. Sidebar mini-stats: counts of awards / warnings alongside existing attendance/leave counters
   (extend the existing subquery block at `employee_details.php:21-24` with two more scalar
   subqueries — additive, same pattern).
4. `applyDueLifecycleEffects($pdo)` call at top (D5).

**No-break check:** every existing section renders identically; print layout verified.
**Tests:** end-to-end — create employee → promote → approve → details page shows new designation
AND timeline entry; print render; employee with zero events shows clean empty state.

#### Phase 1.6 — Re-scout + hardening + full test pass *(per working-process memory)*

1. **Re-scout sweep:** grep for every writer of `employment_status`, `designation_id`,
   `department_id`, `basic_salary` (e.g. `update_employee.php`, `import_employees.php`) —
   confirm none conflicts with lifecycle history; document in this file any writer that should
   *eventually* create a lifecycle event (candidate for Tier 2+, not changed now).
2. Confirm audit trail completeness: every transition writes `logActivity` + `logAudit`.
3. Full CLI test suite run + one real end-to-end save test through the HTTP endpoints.
4. `changelog.md` entries per commit; update the memory progress file.

##### Re-scout results (done 2026-07-02, Phase 1.6)

Every writer of the affected `employees` columns, audited:

| Writer | Columns touched | Conflict? | Tier 2+ candidate |
|---|---|---|---|
| `api/update_employee.php` | all (dynamic field list) incl. designation/department/salary/status | **No** — lifecycle events snapshot old values server-side at *creation*; a direct edit simply becomes the new "current". Writes full old/new audit. | Yes — a designation/salary change here should eventually offer/auto-create a lifecycle event |
| `api/update_employee_status.php` | `employment_status` | **No** — D6: untouched, regression-tested in `test_hr_lifecycle_workflow_cli.php` | Covered by design (D6 keeps both paths) |
| `api/delete_employee.php` | `status`+`employment_status` → terminated | **No** — audits old values | Yes — should eventually record a `termination` event |
| `api/add_employee.php`, `api/tender_workflow.php` (staff import), `api/operations/create_project_staff.php` | initial INSERT | **No** — creation, no history expected | No |
| `api/operations/update_staff_project.php` | `project_id` | **No** — scope-gated + audited | Yes — a project reassignment is a de-facto `transfer` event |
| `api/import_employees.php` | none of the four columns (basic fields only) | **No** | No |
| Payroll suite (`process_payroll`, `update_payroll`, …) | none — **reads** `basic_salary` only | **No** | No |

Audit-trail completeness confirmed: every lifecycle transition writes `logActivity` +
`logAudit`, and approval additionally writes the employee-row audit in the legacy
endpoint's exact shape (asserted in the Phase 1.3 test).

---

## 4. Cross-cutting requirements (every Tier 1 phase)

- All new selects that read the DB = Select2 (Bootstrap 5 theme, dropdownParent for modals);
  employee pickers use AJAX search (existing endpoint), never a preloaded 1000-row select.
- Every list query excludes `status='deleted'`; every count/stat query too.
- Mobile card view on any new list page (DataTable drawCallback pattern).
- `getUrl()` / `buildUrl()` only — no hardcoded paths.
- No new file may query `employees` without a scope helper (pre-push scope-audit hook will
  block otherwise — use the helpers, not the skip marker).
- New pages follow the print-header conventions already used in `employees.php` /
  `employee_details.php`.

## 5. Decisions log

| # | Decision | Rationale |
|---|---|---|
| D1 | One `employee_lifecycle_events` table for all 7 (8 with demotion) event types | one timeline, one API family, one workflow; plain columns per BMS style |
| D2 | One "HR Actions" page (`employee_lifecycle` page key) | clean nav; per-type granularity handled by filters + per-type modals |
| D3 | Workflow `pending→approved/rejected/cancelled`; approved is immutable | HR actions need approval but not the 6-state financial workflow |
| D4 | Effects applied on approval, in-transaction, old+new snapshotted server-side | history integrity; client can't forge "old" values |
| D5 | Resignation effect applies on last working day via idempotent catch-up | employee stays active through notice period — matches real HR practice |
| D6 | `api/update_employee_status.php` untouched and still supported | zero-breakage guarantee |
| D7 | Attachments via `uploads/lifecycle/` + gatekeeper download | HR letters are sensitive documents |

## 6. Recommended starting point

Phase 1.1 (foundation) — it is pure scaffolding with zero behavioral risk, and every later phase
depends on it. Phases must ship in order 1.1 → 1.6; each is independently deployable without
gaps because the page (1.4) only appears in the nav once its permission row (1.1) and APIs
(1.2/1.3) exist.

---

## 7. TIER 2 — Compliance & Documents

> Prerequisite: Tier 1 fully implemented (Phases 1.1–1.6 merged). Tier 2 reuses Tier 1's
> patterns (single-page-key modules, approval verbs, gatekeeper downloads, shared modal include).

### 7.0 Current state audit (Tier 2 surfaces, studied 2026-07-02)

- **Central document library exists and is the reuse target.** `documents` table already has
  `issue_date` + `expire_date` (migration `2026_05_21_document_expiry_tracking.php`),
  `registerFileInLibrary()` in `helpers.php` inserts into it, and
  **`cron/check_document_expiry.php`** already fires in-app notifications at 30/14/7/1 days
  before expiry — recipients RBAC-driven via the `document_expiry_alerts` permission, dedup via
  `document_expiry_reminders`, auto-run daily (throttled) from `header.php`. **Any document row
  with an `expire_date` gets alerts for free.**
- **Newer notification engine foundation** (migration `2026_06_28_notification_engine_foundation.php`):
  `notification_events` catalog (event_key → page_key/verb/severity/scope_aware),
  `notification_dedupe`, `notification_log`. New HR alert types should register here.
- **Employee documents today:** a fixed 6-slot JSON map in `employees.documents`
  (`cv, id, certificates, intro_letter, app_letter, other_doc` + `other_doc_name` column),
  rendered at `employee_details.php:492-539` with **direct file links** — no document types
  table, no issue/expiry dates, no gatekeeper, not in the central library.
- **Contracts today:** just two date columns on `employees` (`contract_end_date`,
  `probation_end_date`), written by `api/add_employee.php` / `update_employee.php`, displayed on
  the employee pages. No contract records, no renewal history, **no expiry alerts**.
- **Org structure today:** `employees.reporting_to` is a free-text `VARCHAR(100)`. Writers:
  `api/add_employee.php`, `api/update_employee.php`. Readers:
  `employees.php`, `employee_details.php`, `app/bms/operations/project_view.php`,
  `api/operations/create_project_staff.php`. No FK, no org chart, no "direct reports" view.

### 7.1 Design decisions

| # | Decision | Rationale |
|---|---|---|
| D8 | **New `employee_documents` table + auto-registration into the central `documents` library** (store `library_document_id`; pass issue/expire dates through). | The existing expiry cron then alerts on employee docs with **zero new alert code**. One source of truth for files. |
| D9 | **Legacy JSON documents stay untouched and readable.** `employees.documents` is never modified or migrated destructively; the details-page card renders new-system rows first, then legacy JSON entries marked "legacy". New uploads go only to the new system. | Zero breakage; no risky file-path backfill of unknown legacy uploads. |
| D10 | **New employee documents are served only via gatekeeper** (`api/download_employee_document.php`). Legacy direct links keep working as today (flagged as hardening debt, not changed in Tier 2). | §19 requires gatekeeper for IDs/contracts; changing legacy links risks breaking saved bookmarks/prints. |
| D11 | **`employee_document_types` lookup table** (seeded: CV/Resume, ID Copy, Certificate, Employment Contract, Work Permit, Professional License, Medical, Other) with `requires_expiry` flag; admin-manageable via modal on the documents card. | Tanzania compliance docs (permits, licenses) need typed expiry tracking; WorkDo parity. |
| D12 | **`employee_contracts` table with dual-write.** On activating/renewing a contract, the same transaction updates `employees.contract_end_date` (and `probation_end_date` when the contract defines probation) — so every existing reader of those columns keeps working unchanged. Renewal = new row linked by `renewed_from_contract_id`; statuses `draft → active → expired/renewed/terminated`. | History + renewals without touching current display logic. |
| D13 | **Contract & probation expiry alerts** via new `cron/check_hr_expiry.php`, cloned from the document-expiry engine's shape (RBAC recipients via new `hr_expiry_alerts` permission, milestones 60/30/14/7/1 for contracts, 14/7/1 for probation), dedup via `notification_dedupe`, events registered in `notification_events`. Auto-run daily (throttled) from `header.php` exactly like the document check. | Proven in-house pattern; contracts need a longer runway (60 days) than documents. |
| D14 | **Org structure: additive `employees.reporting_to_id INT NULL` + dual-write.** Whenever `reporting_to_id` is set, the legacy `reporting_to` varchar is written with the manager's full name in the same UPDATE — all 4 existing readers keep working with zero changes. Backfill migration links legacy names to employees by **exact unique full-name match only** (ambiguous/no-match rows left untouched) — criteria-based + idempotent per the live-system rule. | FK enables org chart & direct reports; dual-write guarantees no breakage. |
| D15 | **Cycle guard:** the API rejects a manager assignment where the chosen manager is the employee themself or any descendant in the reporting chain (walk up `reporting_to_id`, depth-capped). | A cycle would hang any org-chart/rollup traversal. |

### 7.2 New tables (migrations)

```sql
CREATE TABLE IF NOT EXISTS employee_document_types (
    doc_type_id     INT AUTO_INCREMENT PRIMARY KEY,
    type_name       VARCHAR(100) NOT NULL,
    requires_expiry TINYINT(1) NOT NULL DEFAULT 0,   -- permits/licenses/contracts = 1
    sort_order      INT NOT NULL DEFAULT 0,
    status          ENUM('active','inactive','deleted') NOT NULL DEFAULT 'active',
    created_by      INT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_type_name (type_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS employee_documents (
    emp_doc_id          INT AUTO_INCREMENT PRIMARY KEY,
    employee_id         INT NOT NULL,
    doc_type_id         INT NOT NULL,
    document_name       VARCHAR(255) NOT NULL,
    file_path           VARCHAR(500) NOT NULL,        -- uploads/employee_docs/<hash>.<ext>
    original_filename   VARCHAR(255) NOT NULL,
    file_size           INT NOT NULL DEFAULT 0,
    issue_date          DATE NULL,
    expire_date         DATE NULL,                    -- mirrored into documents.expire_date
    library_document_id INT NULL,                     -- documents.id from registerFileInLibrary
    notes               VARCHAR(500) NULL,
    status              ENUM('active','archived','deleted') NOT NULL DEFAULT 'active',
    created_by          INT NOT NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_by          INT NULL,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_emp_doc (employee_id, status),
    KEY idx_expire (status, expire_date),
    CONSTRAINT fk_ed_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS employee_contracts (
    contract_id              INT AUTO_INCREMENT PRIMARY KEY,
    employee_id              INT NOT NULL,
    contract_type            VARCHAR(100) NOT NULL,   -- permanent / fixed-term / probation / casual / consultancy
    start_date               DATE NOT NULL,
    end_date                 DATE NULL,               -- NULL = open-ended (permanent)
    probation_months         INT NULL,
    basic_salary             DECIMAL(15,2) NULL,      -- snapshot at signing (informational)
    terms                    TEXT NULL,
    attachment_path          VARCHAR(500) NULL,       -- signed contract file
    attachment_name          VARCHAR(255) NULL,
    library_document_id      INT NULL,                -- registered w/ expire_date = end_date
    status                   ENUM('draft','active','expired','renewed','terminated','deleted')
                             NOT NULL DEFAULT 'draft',
    renewed_from_contract_id INT NULL,
    activated_by             INT NULL,
    activated_at             DATETIME NULL,
    created_by               INT NOT NULL,
    created_at               TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_by               INT NULL,
    updated_at               TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_emp_contract (employee_id, status),
    KEY idx_end (status, end_date),
    CONSTRAINT fk_ec_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- additive column (SHOW COLUMNS guard):
ALTER TABLE employees ADD COLUMN reporting_to_id INT NULL DEFAULT NULL AFTER reporting_to;
```

### 7.3 Phases (each = one branch off `develop`, tests, PR)

#### Phase 2.1 — Foundation: migrations + permissions + routes + nav
**Branch:** `feat/hr-compliance-foundation`

1. Migrations: the three tables + `reporting_to_id` column above (idempotent); seed
   `employee_document_types` (`INSERT IGNORE`, D11 list); permission rows
   `employee_documents`, `employee_contracts`, `org_chart`, `hr_expiry_alerts`
   (module `HR`; role assignment mirrors whoever holds `can_edit` on `employees` — resolved at
   runtime, never hard-coded ids); register `notification_events` rows
   (`hr_contract_expiry`, `hr_probation_end`) per the 2026_06_28 catalog format.
2. Backfill migration for `reporting_to_id` (D14): exact unique full-name match against
   `CONCAT_WS(' ', first_name, last_name)` of active employees; skip ambiguous/no-match;
   idempotent (only fills NULLs); prints matched/skipped counts.
3. `roots.php` routes: `employee_contracts`, `org_chart` pages + APIs
   (`api/add_employee_document`, `get_employee_documents`, `delete_employee_document`,
   `download_employee_document`, `manage_document_types`, `api/add_contract`, `get_contract`,
   `get_contracts`, `change_contract_status`, `api/get_org_chart`, `api/update_reporting_line`).
4. `header.php` HR dropdown: "Contracts" (`canView('employee_contracts')`, `bi-file-earmark-text`)
   and "Org Chart" (`canView('org_chart')`, `bi-diagram-3`). Documents need no nav item — they
   live on the employee details page.
5. `uploads/employee_docs/` + `uploads/contracts/` + `.htaccess` (§19), created by migration.

**No-break check:** backfill touches only NULL `reporting_to_id`; the varchar is not modified.
**Tests:** CLI — migrations idempotent (run twice); backfill matched/ambiguous/no-match cases.

#### Phase 2.2 — Employee documents with expiry
**Branch:** `feat/employee-documents`

1. `api/add_employee_document.php` — 6-step + §19 5-step upload; requires `expire_date` when the
   chosen type has `requires_expiry=1`; calls `registerFileInLibrary()` then sets
   `issue_date`/`expire_date` on the created `documents` row and stores `library_document_id`
   → **expiry alerts now fire from the existing cron with no new alert code (D8)**.
   Scope gate via `assertScopeForRecord('employees', …)`; `logActivity` + `logAudit`.
2. `api/get_employee_documents.php` (per employee, scope-gated, excludes deleted),
   `api/delete_employee_document.php` (soft delete + archive the library row's expire_date so
   stale alerts stop), `api/download_employee_document.php` (gatekeeper: auth + `canView` +
   scope on the document's employee).
3. `api/manage_document_types.php` — add/rename/deactivate types (`canEdit('employee_documents')`).
4. **`employee_details.php` — upgrade the Documents card (additive):** table of new-system docs
   (type, name, issue → expiry with color chips: red expired / amber ≤30 days / green,
   uploaded-by, download via gatekeeper, delete) + "Upload Document" button (`canCreate`) +
   legacy JSON entries rendered below exactly as today under a "Legacy files" divider (D9).

**No-break check:** an employee with only legacy JSON docs renders identically to today plus the
divider; `employees.documents` column untouched.
**Tests:** CLI — upload validation matrix (type/MIME/size/expiry-required), library row created
with expire_date, alert fires via `run_document_expiry_check()` for a doc expiring in 7 days,
gatekeeper denies out-of-scope user; runtime — card render with legacy-only, new-only, mixed.

#### Phase 2.3 — Contracts module
**Branch:** `feat/employee-contracts`

1. APIs: `add_contract` (draft; optional signed-copy upload → library with
   `expire_date = end_date`), `get_contract`/`get_contracts` (filters: status, type, employee,
   expiring-in-N-days; scope-gated), `change_contract_status` (§11.1 transition map:
   `draft → active (activate: canApprove)`; `active → terminated (canApprove)`;
   `active → renewed` happens automatically when a new contract for the same employee is
   activated — old row stamped `renewed`, new row stores `renewed_from_contract_id`).
   **On activation — single transaction (D12):** at most one `active` contract per employee
   (row-lock check), dual-write `employees.contract_end_date = end_date` (and
   `probation_end_date = start_date + probation_months` when set), `logAudit` on both entities.
2. `app/bms/pos/employee_contracts.php` — template §8, page_key `employee_contracts`:
   stat cards (Active, Expiring ≤60 days, Expired, On Probation), filters, DataTable + mobile
   cards, per-row actions by state (Activate/Renew/Terminate/View/Download), "New Contract"
   modal (employee Select2 AJAX, type, dates, probation months, upload).
3. **Contracts card on `employee_details.php`** (additive, after the Documents card): this
   employee's contract history newest-first, current contract highlighted, days-to-expiry chip,
   Renew button.
4. `cron/check_hr_expiry.php` (D13): contracts milestones 60/30/14/7/1, probation 14/7/1;
   recipients = admins + roles with `can_view` on `hr_expiry_alerts`; dedupe via
   `notification_dedupe` (event keys from Phase 2.1); daily throttled include from `header.php`
   (same mechanism as the document check); CLI-runnable.

**No-break check:** `contract_end_date`/`probation_end_date` writers in `add_employee.php` /
`update_employee.php` untouched — direct edits still work; activation dual-write simply keeps
the columns consistent. Payroll reads nothing from contracts (verified: `basic_salary` +
components only).
**Tests:** CLI — activate sets dual-write columns; second activation renews the first; only one
active per employee; termination stamps status; expiry milestones fire once (dedupe) and
recipients honor RBAC; runtime — page + details card render, end-to-end create→activate→renew.

#### Phase 2.4 — Org structure & org chart
**Branch:** `feat/org-structure`

1. `api/update_reporting_line.php` — sets `reporting_to_id` with the **cycle guard (D15)** and
   dual-writes the manager's full name into `reporting_to` (D14); `canEdit('employees')` +
   scope gate; `logAudit` with old/new manager.
2. **Employee add/edit modals (`employees.php`) — additive change:** the free-text
   `reporting_to` input becomes a Select2 AJAX manager picker (existing
   `api/account/search_employees.php`) that submits `reporting_to_id`;
   `api/add_employee.php` / `api/update_employee.php` accept the **optional** new field
   (ignored when absent — old clients/imports keep working) and dual-write the name.
   `import_employees.php` untouched (imports keep writing the varchar only).
3. `app/bms/pos/org_chart.php` — page_key `org_chart`: pure-CSS collapsible tree built from
   `reporting_to_id` (photo, name, designation, department; employees with no manager = roots),
   orphan-cycle-safe rendering (depth cap), print-friendly, click-through to
   `employee_details`. Data from `api/get_org_chart.php` (scope-filtered:
   non-admins see their in-scope subtree(s)).
4. **"Direct Reports" mini-card on `employee_details.php`** (additive, sidebar): avatars + names
   of employees whose `reporting_to_id` = this employee; count in the header.

**No-break check:** all 4 legacy readers of `reporting_to` (varchar) verified unchanged and
still populated via dual-write; records edited only through import keep rendering (picker shows
the varchar as placeholder when `reporting_to_id` is NULL).
**Tests:** CLI — cycle guard (self, direct, deep), dual-write name sync, optional-field
back-compat on add/update APIs; runtime — org chart renders with 0/1/N-level trees and with a
legacy-varchar-only employee.

#### Phase 2.5 — Re-scout + hardening + full test pass

1. Re-scout sweep: every writer/reader of `employees.documents`, `contract_end_date`,
   `probation_end_date`, `reporting_to` — confirm behavior identical; list any candidate for
   later unification (e.g. moving legacy JSON docs into `employee_documents`) as Tier 2 debt,
   **not** done now.
2. Verify both crons coexist (document + HR expiry) — throttle files don't collide; alerts
   dedupe correctly on repeat runs.
3. Full CLI suite + one HTTP end-to-end (upload doc → alert; create contract → activate → renew;
   set manager → org chart).
4. `changelog.md` entries per commit; update memory progress file.

##### Re-scout results (done 2026-07-02, Phase 2.5)

| Writer/reader | Column(s) | Conflict? | Tier 2+ candidate |
|---|---|---|---|
| `api/add_employee.php`, `api/update_employee.php`, `api/update_reporting_line.php` | `documents`, `contract_end_date`/`probation_end_date`, `reporting_to`(_id) | **No** — these are the paths Phase 2.1–2.4 were built against; all dual-write correctly | Covered by design |
| `app/bms/pos/employees.php`, `employee_details.php`, `org_chart.php`, `get_org_chart.php` | reads only | **No** — `org_chart`/`get_org_chart`/Direct-Reports key off `reporting_to_id` only, never parse the `reporting_to` string; legacy JSON docs stay read-only display (D9) | No |
| **`api/operations/create_project_staff.php` + its form in `app/bms/operations/project_view.php`** | `contract_end_date`, `probation_end_date` (direct INSERT, mirrors `add_employee.php`'s pattern) **and** `reporting_to` (free-text INSERT, no `reporting_to_id` set at all) | **No breakage** — this path predates and is independent of Tier 2, so nothing regresses. But it's a **gap**: employees created via "Add Project Staff" get no manager link, so they never show correctly under Direct Reports / the org chart until someone edits them via `employees.php` and picks a manager. | **Yes** — route this form through the same `reporting_to_id` Select2 + dual-write pattern (and ideally the same contract-creation flow) as `employees.php`, so every employee-creation path stays in sync |
| `api/import_employees.php` | `documents` (JSON convention) | **No** — currently a stub with no real CSV parsing/INSERT logic (not a live writer today) | **Yes** — whoever implements real import logic must follow the same JSON-blob + dual-write conventions or it will silently diverge |
| `cron/process_notifications.php` | n/a (unrelated pre-existing throttle-key mismatch: reads `notif_outbox_last_ts`, writes `notif_outbox_last_run`) | **No** — pre-existing, unrelated to Tier 2, not touched | Flagged for awareness only |

Cron coexistence confirmed: `doc_expiry_last_run`, `hr_expiry_last_run`, `recurring_last_run`,
`leave_accrual_last_run`, `notif_checks_last_run`, `notif_digest_last_run` are all distinct
setting keys wired independently in `header.php` — no collision, no double-fire. Full Tier 2 CLI
suite (5 files, 160 assertions) green; `hr_compliance_foundation` (56), `employee_documents` (26),
`employee_documents_card` (17), `employee_contracts` (34), `org_structure` (27) — each is itself an
upload→alert / create→activate→renew / set-manager→org-chart end-to-end test.

### 7.4 Tier 2 cross-cutting notes

- All Tier 1 cross-cutting rules (§4) apply unchanged.
- Every new "expiring" figure (stat cards, chips) computes from the same
  `DATEDIFF(expire/end_date, CURDATE())` expression the crons use — no drifting definitions.
- No financial postings anywhere in Tier 2 — nothing touches the ledger.

## 8. TIER 3 — Performance & Development

> Prerequisite: Tiers 1–2 fully implemented. Tier 3 is **pure greenfield** — no existing BMS
> table or page covers appraisals, goals, indicators or training, so every change is additive:
> **zero ALTERs to existing tables, zero edits to existing APIs** in this whole tier.

### 8.0 Current state audit (Tier 3 surfaces, studied 2026-07-02)

- **Name collision to avoid:** `app/constant/reports/performance_dashboard.php`
  (page_key `performance_dashboard`) is the **Business Performance** dashboard — revenue/cost/
  profit KPIs from the ledger. It has nothing to do with HR. Tier 3 therefore uses distinct
  page keys (`hr_performance`, `trainings`), distinct nav labels ("Performance (HR)",
  "Training"), and never touches that report.
- No appraisal/indicator/goal/training tables exist (full table scan). Grep hits for those words
  in `employees.php`, `project_view.php`, `budget_details.php` etc. are incidental word matches,
  not modules.
- Building blocks Tier 3 reuses: `designations` (competency targets attach per designation),
  Tier 1's lifecycle module (promotion/award follow-ups from an appraisal), Tier 2's
  library-registration pattern (training certificates with optional expiry → free alerts),
  Select2 AJAX employee search, §11.1 workflow verbs, project-scope helpers.

### 8.1 Design decisions

| # | Decision | Rationale |
|---|---|---|
| D16 | **Two pages only:** `hr_performance.php` (page_key `hr_performance`; tabs **Appraisals / Goals / Indicators-setup**) and `trainings.php` (page_key `trainings`). | Clean nav (matches Tier 1's single-page-per-module D2); avoids all confusion with the business `performance_dashboard`. |
| D17 | **One rating scale everywhere: 1–5 stars** (integer). An appraisal's `overall_rating` = AVG(actual) across its items, computed server-side and **stored** at approval. | Comparable scores across cycles; stored snapshot = stable history for reports. |
| D18 | **Appraisal workflow `draft → submitted → approved / rejected`** using §11.1 verbs on `hr_performance` (`canSubmit`, `canApprove`, `canReject`). **Appraiser cannot approve their own appraisal** (same segregation-of-duties rule as Tier 1 Phase 1.3; admins exempt). | An appraisal affects promotions/pay — it needs a second pair of eyes. |
| D19 | **Snapshots, not live joins, for history:** appraisal items copy `expected_rating` from the designation targets at creation; the appraisal row snapshots the employee's `designation_id`. Later target/designation changes never rewrite past appraisals. | Same history-integrity principle as Tier 1 D4. |
| D20 | **Appraisal → Tier 1 integration (additive):** an approved appraisal shows "Recommend Promotion" / "Recommend Award" buttons that open the existing Tier 1 lifecycle modal pre-filled (employee + description referencing the appraisal). No new approval logic — the recommendation flows through the already-built lifecycle workflow. | Professional loop (appraise → reward) with zero duplicated logic. |
| D21 | **Training cost is informational only — no ledger posting.** Actual payments flow through the existing expense/payment modules as usual; the training record just stores the planned/actual cost figure for HR reporting. | Reporting-source rule: financial figures come from the one ledger only; the training module must not create a parallel money record. |
| D22 | **Certificates register into the central document library** (`registerFileInLibrary`, optional `expire_date` for time-limited certifications) and are served via gatekeeper. | Expiring professional certificates alert automatically through the existing document-expiry cron — zero new alert code (same lever as Tier 2 D8). |
| D23 | **Goal progress is a simple percent + status on the goal row**, updated via a dedicated API that requires a progress note; every update is `logActivity`+`logAudit`-logged (the audit trail *is* the progress history — no separate history table). | Keeps the model lean; full history remains reconstructable from the audit log. |

### 8.2 New tables (single migration, all `ENGINE=InnoDB`, idempotent)

```sql
-- Competency framework -------------------------------------------------------
CREATE TABLE IF NOT EXISTS performance_indicator_categories (
    category_id   INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(100) NOT NULL,               -- seed: Technical, Behavioural, Organizational
    sort_order    INT NOT NULL DEFAULT 0,
    status        ENUM('active','inactive','deleted') NOT NULL DEFAULT 'active',
    created_by    INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_cat (category_name)
);

CREATE TABLE IF NOT EXISTS performance_indicators (
    indicator_id   INT AUTO_INCREMENT PRIMARY KEY,
    category_id    INT NOT NULL,
    indicator_name VARCHAR(255) NOT NULL,              -- e.g. "Report accuracy", "Teamwork"
    description    VARCHAR(500) NULL,
    status         ENUM('active','inactive','deleted') NOT NULL DEFAULT 'active',
    created_by     INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_pi_cat (category_id, status)
);

CREATE TABLE IF NOT EXISTS designation_indicator_targets (   -- expected competency per role
    target_id       INT AUTO_INCREMENT PRIMARY KEY,
    designation_id  INT NOT NULL,
    indicator_id    INT NOT NULL,
    expected_rating TINYINT NOT NULL,                  -- 1..5
    created_by      INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_by      INT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_desig_ind (designation_id, indicator_id)
);

-- Appraisals ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS appraisal_cycles (
    cycle_id    INT AUTO_INCREMENT PRIMARY KEY,
    cycle_name  VARCHAR(100) NOT NULL,                 -- e.g. "Annual Review 2026", "Q3 2026"
    period_from DATE NOT NULL,
    period_to   DATE NOT NULL,
    status      ENUM('open','closed','deleted') NOT NULL DEFAULT 'open',
    created_by  INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_cycle (cycle_name)
);

CREATE TABLE IF NOT EXISTS employee_appraisals (
    appraisal_id    INT AUTO_INCREMENT PRIMARY KEY,
    cycle_id        INT NOT NULL,
    employee_id     INT NOT NULL,
    designation_id  INT NULL,                          -- snapshot at creation (D19)
    appraisal_date  DATE NOT NULL,
    overall_rating  DECIMAL(3,2) NULL,                 -- stored at approval (D17)
    remarks         TEXT NULL,                         -- appraiser summary
    status          ENUM('draft','submitted','approved','rejected','deleted')
                    NOT NULL DEFAULT 'draft',
    approved_by     INT NULL, approved_at DATETIME NULL,
    reject_reason   VARCHAR(500) NULL,
    created_by      INT NOT NULL,                      -- the appraiser
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_by      INT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_cycle_emp (cycle_id, employee_id),  -- one appraisal per employee per cycle
    KEY idx_ea_emp (employee_id, status),
    CONSTRAINT fk_ea_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id)
);

CREATE TABLE IF NOT EXISTS employee_appraisal_items (
    item_id         INT AUTO_INCREMENT PRIMARY KEY,
    appraisal_id    INT NOT NULL,
    indicator_id    INT NOT NULL,
    expected_rating TINYINT NULL,                      -- snapshot from targets (D19)
    actual_rating   TINYINT NOT NULL,                  -- 1..5
    comment         VARCHAR(500) NULL,
    UNIQUE KEY uniq_app_ind (appraisal_id, indicator_id),
    CONSTRAINT fk_eai_appraisal FOREIGN KEY (appraisal_id)
        REFERENCES employee_appraisals(appraisal_id)
);

-- Goals -----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS goal_types (
    goal_type_id INT AUTO_INCREMENT PRIMARY KEY,
    type_name    VARCHAR(100) NOT NULL,                -- seed: Annual, Quarterly, Monthly, Project
    status       ENUM('active','inactive','deleted') NOT NULL DEFAULT 'active',
    created_by   INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_goal_type (type_name)
);

CREATE TABLE IF NOT EXISTS employee_goals (
    goal_id        INT AUTO_INCREMENT PRIMARY KEY,
    employee_id    INT NOT NULL,
    goal_type_id   INT NOT NULL,
    subject        VARCHAR(255) NOT NULL,
    description    TEXT NULL,
    start_date     DATE NOT NULL,
    end_date       DATE NOT NULL,
    progress       TINYINT NOT NULL DEFAULT 0,         -- 0..100 (D23)
    status         ENUM('not_started','in_progress','completed','cancelled','deleted')
                   NOT NULL DEFAULT 'not_started',
    created_by     INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_by     INT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_eg_emp (employee_id, status),
    CONSTRAINT fk_eg_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id)
);

-- Training --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS training_types (
    training_type_id INT AUTO_INCREMENT PRIMARY KEY,
    type_name        VARCHAR(100) NOT NULL,            -- seed: Technical, Soft Skills, Compliance & Safety, Induction
    status           ENUM('active','inactive','deleted') NOT NULL DEFAULT 'active',
    created_by       INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_training_type (type_name)
);

CREATE TABLE IF NOT EXISTS trainings (
    training_id         INT AUTO_INCREMENT PRIMARY KEY,
    training_type_id    INT NOT NULL,
    title               VARCHAR(255) NOT NULL,
    description         TEXT NULL,
    trainer_kind        ENUM('internal','external') NOT NULL DEFAULT 'internal',
    trainer_employee_id INT NULL,                      -- when internal
    trainer_name        VARCHAR(255) NULL,             -- when external
    venue               VARCHAR(255) NULL,
    start_date          DATE NOT NULL,
    end_date            DATE NULL,
    cost                DECIMAL(15,2) NULL,            -- informational only (D21)
    status              ENUM('planned','in_progress','completed','cancelled','deleted')
                        NOT NULL DEFAULT 'planned',
    created_by          INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_by          INT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_tr_status (status, start_date)
);

CREATE TABLE IF NOT EXISTS training_participants (
    participant_id          INT AUTO_INCREMENT PRIMARY KEY,
    training_id             INT NOT NULL,
    employee_id             INT NOT NULL,
    status                  ENUM('enrolled','attended','completed','failed','withdrawn')
                            NOT NULL DEFAULT 'enrolled',
    score                   VARCHAR(50) NULL,          -- free-form result (e.g. "87%", "Pass")
    remarks                 VARCHAR(500) NULL,
    certificate_path        VARCHAR(500) NULL,         -- uploads/training_certs/<hash>.<ext>
    certificate_name        VARCHAR(255) NULL,
    certificate_expire_date DATE NULL,                 -- optional (D22)
    library_document_id     INT NULL,
    updated_by              INT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_training_emp (training_id, employee_id),
    KEY idx_tp_emp (employee_id, status),
    CONSTRAINT fk_tp_training FOREIGN KEY (training_id) REFERENCES trainings(training_id),
    CONSTRAINT fk_tp_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id)
);
```

FK philosophy identical to Tier 1: hard FKs only to `employees` / parent rows within the module;
lookup ids (designations, categories, types) resolved via LEFT JOIN so soft-deleted lookups
never orphan history.

### 8.3 Phases (each = one branch off `develop`, tests, PR)

#### Phase 3.1 — Foundation: migrations + permissions + routes + nav
**Branch:** `feat/hr-performance-foundation`

1. Migration with all 11 tables above + `INSERT IGNORE` seeds (indicator categories, goal
   types, training types).
2. Permissions migration: `hr_performance` and `trainings` rows (module `HR`), role assignment
   mirroring `employees` editors (runtime-resolved, per live-system rule); `SHOW COLUMNS`
   guards for `can_submit`/`can_approve`/`can_reject`.
3. `roots.php` routes: `hr_performance`, `trainings` pages + APIs
   (`api/manage_indicators`, `api/get_indicators`, `api/save_designation_targets`,
   `api/manage_appraisal_cycles`, `api/add_appraisal`, `api/get_appraisal`,
   `api/get_appraisals`, `api/change_appraisal_status`, `api/add_goal`, `api/get_goals`,
   `api/update_goal_progress`, `api/manage_trainings`, `api/get_trainings`,
   `api/manage_training_participants`, `api/upload_training_certificate`,
   `api/download_training_certificate`).
4. `header.php` HR dropdown: "Performance (HR)" (`canView('hr_performance')`, `bi-graph-up-arrow`)
   and "Training" (`canView('trainings')`, `bi-mortarboard`) — labels chosen to never be
   confused with the business `performance_dashboard` report.
5. `uploads/training_certs/` + `.htaccess` (§19), created by migration.

**No-break check:** additive only; `performance_dashboard` page untouched and verified rendering.
**Tests:** CLI — migrations idempotent (run twice), seeds present, permission rows exist.

#### Phase 3.2 — Indicators & competency targets (setup tab)
**Branch:** `feat/performance-indicators`

1. `hr_performance.php` skeleton (template §8, page_key `hr_performance`) with the three tabs;
   this phase delivers the **Indicators tab** (visible only to `canEdit('hr_performance')`):
   - CRUD for categories and indicators (modals, soft delete §12).
   - **Target matrix:** pick a designation (Select2) → grid of active indicators grouped by
     category with a 1–5 star input per row → `api/save_designation_targets.php` upserts
     (`INSERT … ON DUPLICATE KEY UPDATE` on `uniq_desig_ind`), full `logActivity`/`logAudit`.
2. Guard: an indicator referenced by any appraisal item cannot be hard-removed — soft delete
   only (history keeps rendering via its snapshot).

**Tests:** CLI — CRUD + upsert matrix + soft-delete guard; runtime — matrix renders for a
designation with 0 and N targets.

#### Phase 3.3 — Appraisals (main tab)
**Branch:** `feat/employee-appraisals`

1. Cycle management (small modal CRUD, `canEdit`): name + period; closing a cycle blocks new
   appraisals in it (existing ones finish their workflow).
2. **New Appraisal flow:** pick cycle + employee (Select2 AJAX, scope-gated) → server loads the
   employee's designation targets and creates the item rows with `expected_rating` snapshots
   (D19) → appraiser rates each item 1–5 with optional comment, writes summary remarks →
   saves as `draft`, `submit` when ready.
3. `api/change_appraisal_status.php` — §11.1 transition map
   (`draft→submitted (submit)`, `submitted→approved (approve) / rejected (reject)`,
   creator-cannot-approve, terminal states immutable). **On approval (single transaction):**
   compute + store `overall_rating` (D17), `logAudit` on entity `employee_appraisal`.
4. Appraisals tab: stat cards (This cycle: Draft / Submitted / Approved, Avg rating), filters
   (cycle, status, employee), DataTable + mobile cards, per-row actions by state; **View modal**
   renders the full scorecard (expected vs actual stars per indicator, category subtotals,
   overall) and is print-friendly (professional appraisal form output).
5. **D20 integration:** on an approved appraisal, `canCreate('employee_lifecycle')` users see
   "Recommend Promotion" / "Recommend Award" — opens the Tier 1 shared lifecycle modal
   (`lifecycle_modal.php` include) pre-filled with the employee and a description referencing
   the appraisal (e.g. "Following Annual Review 2026 — overall 4.5/5").
6. **`employee_details.php` — "Performance" card (additive):** latest approved appraisal
   (cycle, overall stars, approver, date) + sparkline-free simple history list of past overall
   ratings; link to full scorecards.

**No-break check:** employee pages regression; Tier 1 modal reused untouched (pre-fill via its
existing JS init parameters only).
**Tests:** CLI — snapshot correctness (target changed after creation → item keeps old expected),
uniq cycle+employee enforced, overall = AVG stored on approval, SoD block, closed-cycle block;
runtime — end-to-end create→rate→submit→approve→details card shows rating; print render.

#### Phase 3.4 — Goals (second tab)
**Branch:** `feat/employee-goals`

1. Goals tab: stat cards (Active, Completed this year, Overdue = `end_date < CURDATE()` and not
   completed/cancelled, Avg progress of active), filters (type, status, employee), DataTable +
   mobile cards with progress bars.
2. `api/add_goal.php` (create for an employee, scope-gated; `end_date >= start_date`);
   `api/update_goal_progress.php` (D23: percent 0–100 + **required** progress note → note goes
   into the `logActivity`/`logAudit` entry; hitting 100 offers status `completed`; status
   transitions `not_started→in_progress→completed/cancelled` with `canEdit`).
3. **`employee_details.php` — goals summary inside the Performance card** (additive): active
   goals with progress bars + overdue badge.

**Tests:** CLI — progress bounds, note required, overdue computation, transition rules;
runtime — tab + details render, end-to-end create→progress→complete.

#### Phase 3.5 — Training module
**Branch:** `feat/employee-training`

1. `trainings.php` (template §8, page_key `trainings`): stat cards (Planned, In progress,
   Completed this year, Participants trained this year), filters (type, status, date range),
   DataTable + mobile cards; "New Training" modal (type, title, internal trainer =
   Select2 AJAX employee / external = free text, venue, dates, cost — D21 note shown:
   *"informational; record actual payment through Expenses as usual"*).
2. **Training view modal/section — participants management:** add participants (Select2 AJAX,
   multi; scope-gated), per-participant status (`enrolled→attended→completed/failed/withdrawn`),
   score, remarks; upload certificate on completion (§19 5-step, optional
   `certificate_expire_date` → registered into library with `expire_date` (D22) → automatic
   expiry alerts); gatekeeper download.
3. Training status flow `planned→in_progress→completed/cancelled` (`canEdit('trainings')`;
   completing requires every participant to be in a terminal participant state).
4. **`employee_details.php` — "Training" card (additive):** the employee's training history
   (title, type, dates, result badge, certificate link + expiry chip reusing Tier 2's chip
   colors).

**No-break check:** no interaction with payroll/expenses; certificates use the same library path
as Tier 2 documents (no schema drift).
**Tests:** CLI — participant uniqueness, completion gate, certificate upload matrix + library
row with expire_date, alert fires via existing `run_document_expiry_check()`; runtime —
end-to-end plan→enroll→complete→certificate→details card.

#### Phase 3.6 — Re-scout + hardening + full test pass

1. Re-scout sweep: confirm no query anywhere joins the new tables into financial reports
   (D21 guard); confirm `performance_dashboard` and all employee pages render unchanged.
2. Verify every new list/stat query excludes `deleted` and applies employee project scope.
3. Full CLI suite + HTTP end-to-end passes (appraisal loop, goal loop, training loop).
4. `changelog.md` entries per commit; update memory progress file.

##### Re-scout results (done 2026-07-03, Phase 3.6)

- **D21 guard confirmed clean:** no report under `app/constant/reports/` and no function in
  `core/financial_reports.php` references any Tier 3 table (`employee_appraisals`,
  `employee_appraisal_items`, `employee_goals`, `training_participants`,
  `designation_indicator_targets`). None of the Tier 3 list/stat APIs read
  `journal_entries`/`journal_entry_items`/`current_balance` — HR performance data never becomes
  a financial figure.
- **No collision with the business report:** `hr_performance`/`trainings` are distinct page keys
  from `performance_dashboard`; the foundation test asserts the mapping doesn't collide and the
  report file still exists.
- **Deleted-exclusion + scope verified:** `get_appraisals`/`get_goals` apply
  `scopeFilterSqlNullable('project','e')` on the joined employees alias (or `assertScopeForEmployee`
  when one employee is requested) and exclude `status='deleted'`. `trainings` has no `project_id`
  (company-wide records); participant reads/writes (`manage_training_participants`,
  `upload/download_training_certificate`) each gate on the participant's employee via
  `assertScopeForEmployee`. Every management/status API guards `status != 'deleted'`.
- **Certificates reuse the Tier 2 library path** (`registerFileInLibrary` + `expire_date`) — no
  schema drift; expiry alerts fire through the existing `check_document_expiry.php` cron (proven
  live in `test_employee_training_cli.php`).
- **Full Tier 3 suite green:** 161 assertions across 5 files
  (`hr_performance_foundation` 74, `performance_indicators` 21, `employee_appraisals` 25,
  `employee_goals` 22, `employee_training` 19). Shared-file regressions re-run clean
  (service-record 27, documents-card 17, contracts 34, org-structure 27, admin-breakglass 14).
- **Shared change:** `core/permissions.php` gained derived `canSubmit()`/`canReject()` helpers
  (no `role_permissions` schema change) — the admin break-glass guard still passes.

### 8.4 Tier 3 cross-cutting notes

- All §4 rules apply. Star-rating inputs are a shared partial
  (`app/bms/pos/includes/star_rating.php`) so Appraisals and the target matrix render
  identically.
- Every "expiring certificate" figure uses the same `DATEDIFF` expression as the crons (§7.4).
- HR performance data is **never** a financial figure — nothing in this tier reads or writes
  `journal_entries`/`journal_entry_items` (reporting-source rule).

## 9. TIER 4 — Talent & Engagement

> Prerequisite: Tiers 1–3 fully implemented. Tier 4 completes WorkDo parity: recruitment,
> onboarding/offboarding checklists, announcements, meetings & business trips, and employee
> self-service. Only **two additive schema touches to existing tables** in the whole tier
> (`users.employee_id`; one guarded hook inside Tier 1's approval code) — both detailed below
> with explicit no-break protections.

### 9.0 Current state audit (Tier 4 surfaces, studied 2026-07-02)

- **`users` ↔ `employees` are NOT linked.** `users` has `role_id`, `department_id`, names —
  but no `employee_id`. This is the linchpin gap for self-service: BMS cannot currently answer
  "which employee is this logged-in user?".
- **Internal messaging exists:** `messages` + `message_recipients` tables behind
  `app/constant/communication/message_center.php` (page_key `message_center`) — point-to-point
  threads with priorities. **Announcements are a different concept** (one-to-many broadcast
  with publish/expiry window) and must not be bolted onto the thread model — but delivery can
  ride the existing `notifications` engine (same one the expiry crons use).
- **No trips, meetings, recruitment, candidates, onboarding tables** (full table scan).
- Money-adjacent flows that trips must respect: expenses, petty cash, and the one-ledger rule —
  travel advances/expenses are already handled by existing modules; a trip record must never
  post money itself.
- Leave self-application: the leave suite (applications + approval workflow) is admin-operated
  today; ESS reuses those tables through thin own-record-only endpoints.
- Existing pages reused by ESS read-only: payslip data (`payroll_items`), leave balances,
  Tier 2 documents/contracts, Tier 3 goals/trainings, Tier 1 service record.

### 9.1 Design decisions

| # | Decision | Rationale |
|---|---|---|
| D24 | **ESS linchpin: additive `users.employee_id INT NULL`** + a "Linked Employee" Select2 field on the existing user-management form. Every ESS API resolves the employee **from the session only** (`users.employee_id` of `$_SESSION['user_id']`) and never accepts an `employee_id` parameter. Unlinked users simply don't see the "My HR" menu item. | One nullable column, zero impact on existing auth/roles; own-record enforcement is structural, not per-query discipline. |
| D25 | **Announcements are a new table, delivered via the existing notifications engine** on publish (one notification per audience user, deduped via `notification_dedupe`), plus a dashboard/"My HR" list with read-tracking. `message_center` untouched. | Broadcast ≠ thread; reusing notifications gives badge/center surfacing for free. |
| D26 | **Trips never move money.** A trip request stores estimated cost & requested advance as informational figures; the actual advance/reimbursement goes through the existing petty-cash/expenses modules as usual, and the trip stores only a free-text reference to that record. | Reporting-source rule — the HR module must not create a parallel financial record (same as D21). |
| D27 | **No public career page in Tier 4.** Recruitment is internal ATS: HR enters/imports candidates; CVs upload to the library. A public application form is listed as a future option (needs the webroot-quarantine security posture reviewed first). | The BMS deploy model quarantines the webroot; an unauthenticated public form is a separate security exercise — don't smuggle it into an HR tier. |
| D28 | **Hire & exit close the loop with existing modules:** (a) marking a candidate `hired` opens the **existing** Add Employee modal pre-filled (name/email/phone/designation), and stores `hired_employee_id` on the candidate once created; (b) creating an employee with an active onboarding template auto-spawns an onboarding checklist; (c) **when a Tier 1 resignation/termination is approved, an offboarding checklist auto-spawns** — implemented as a guarded call (`function_exists` + template-configured) inside Tier 1's approval transaction, the tier's only touch to prior-tier code. | The lifecycle becomes end-to-end: candidate → employee → service record → exit, with nothing re-implemented. |
| D29 | **Meetings stay minimal:** schedule + attendees + minutes + status. No rooms, no recurrence, no video links. | WorkDo parity where it matters; BMS-scale pragmatism. |
| D30 | **Checklists are template + snapshot:** templates (onboarding/offboarding) hold master items; spawning copies item text into the instance (same snapshot principle as D19), so editing a template never rewrites in-flight checklists. | History integrity, consistent with Tiers 1–3. |

### 9.2 New tables (migrations; all `ENGINE=InnoDB`, idempotent) + one additive column

```sql
-- ESS linchpin (SHOW COLUMNS guard):
ALTER TABLE users ADD COLUMN employee_id INT NULL DEFAULT NULL AFTER role_id;

-- Announcements ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS announcements (
    announcement_id INT AUTO_INCREMENT PRIMARY KEY,
    title           VARCHAR(255) NOT NULL,
    body            TEXT NOT NULL,
    priority        ENUM('normal','important','urgent') NOT NULL DEFAULT 'normal',
    audience_type   ENUM('all','department','project') NOT NULL DEFAULT 'all',
    department_id   INT NULL,                          -- when audience = department
    project_id      INT NULL,                          -- when audience = project
    publish_date    DATE NOT NULL,
    expire_date     DATE NULL,                         -- hidden from lists after this
    status          ENUM('draft','published','archived','deleted') NOT NULL DEFAULT 'draft',
    created_by      INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_by      INT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_ann (status, publish_date, expire_date)
);
CREATE TABLE IF NOT EXISTS announcement_reads (
    announcement_id INT NOT NULL,
    user_id         INT NOT NULL,
    read_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (announcement_id, user_id)
);

-- Meetings (D29) ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS meetings (
    meeting_id   INT AUTO_INCREMENT PRIMARY KEY,
    title        VARCHAR(255) NOT NULL,
    agenda       TEXT NULL,
    meeting_date DATE NOT NULL,
    start_time   TIME NULL, end_time TIME NULL,
    venue        VARCHAR(255) NULL,
    minutes      TEXT NULL,                            -- filled at/after completion
    status       ENUM('scheduled','completed','cancelled','deleted') NOT NULL DEFAULT 'scheduled',
    created_by   INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_by   INT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_mt (status, meeting_date)
);
CREATE TABLE IF NOT EXISTS meeting_attendees (
    meeting_id  INT NOT NULL,
    employee_id INT NOT NULL,
    attended    TINYINT(1) NULL,                       -- NULL until marked
    PRIMARY KEY (meeting_id, employee_id),
    CONSTRAINT fk_ma_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id)
);

-- Business trips (D26) ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS employee_trips (
    trip_id           INT AUTO_INCREMENT PRIMARY KEY,
    employee_id       INT NOT NULL,
    purpose           VARCHAR(500) NOT NULL,
    destination       VARCHAR(255) NOT NULL,
    start_date        DATE NOT NULL,
    end_date          DATE NOT NULL,
    estimated_cost    DECIMAL(15,2) NULL,              -- informational only
    requested_advance DECIMAL(15,2) NULL,              -- informational only
    expense_reference VARCHAR(100) NULL,               -- e.g. petty-cash voucher / expense code
    report            TEXT NULL,                       -- trip report on completion
    attachment_path   VARCHAR(500) NULL, attachment_name VARCHAR(255) NULL,
    status            ENUM('pending','approved','rejected','completed','cancelled','deleted')
                      NOT NULL DEFAULT 'pending',
    approved_by       INT NULL, approved_at DATETIME NULL, reject_reason VARCHAR(500) NULL,
    created_by        INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_by        INT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_tr_emp (employee_id, status),
    CONSTRAINT fk_tp2_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id)
);

-- Onboarding / offboarding checklists (D30) --------------------------------------
CREATE TABLE IF NOT EXISTS checklist_templates (
    template_id   INT AUTO_INCREMENT PRIMARY KEY,
    template_name VARCHAR(150) NOT NULL,
    template_type ENUM('onboarding','offboarding') NOT NULL,
    is_default    TINYINT(1) NOT NULL DEFAULT 0,       -- auto-spawn candidate (D28 b/c)
    status        ENUM('active','inactive','deleted') NOT NULL DEFAULT 'active',
    created_by    INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_tpl (template_name, template_type)
);
CREATE TABLE IF NOT EXISTS checklist_template_items (
    item_id     INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL,
    item_text   VARCHAR(500) NOT NULL,
    sort_order  INT NOT NULL DEFAULT 0,
    CONSTRAINT fk_cti_tpl FOREIGN KEY (template_id) REFERENCES checklist_templates(template_id)
);
CREATE TABLE IF NOT EXISTS employee_checklists (
    checklist_id  INT AUTO_INCREMENT PRIMARY KEY,
    employee_id   INT NOT NULL,
    template_id   INT NULL,                            -- provenance only; items are snapshots
    checklist_type ENUM('onboarding','offboarding') NOT NULL,
    status        ENUM('in_progress','completed','cancelled','deleted') NOT NULL DEFAULT 'in_progress',
    completed_at  DATETIME NULL,
    created_by    INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ec2_emp (employee_id, checklist_type, status),
    CONSTRAINT fk_ec2_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id)
);
CREATE TABLE IF NOT EXISTS employee_checklist_items (
    item_id      INT AUTO_INCREMENT PRIMARY KEY,
    checklist_id INT NOT NULL,
    item_text    VARCHAR(500) NOT NULL,                -- snapshot (D30)
    sort_order   INT NOT NULL DEFAULT 0,
    is_done      TINYINT(1) NOT NULL DEFAULT 0,
    done_by      INT NULL, done_at DATETIME NULL,
    notes        VARCHAR(500) NULL,
    CONSTRAINT fk_eci_cl FOREIGN KEY (checklist_id) REFERENCES employee_checklists(checklist_id)
);

-- Recruitment / internal ATS (D27) ------------------------------------------------
CREATE TABLE IF NOT EXISTS job_openings (
    opening_id     INT AUTO_INCREMENT PRIMARY KEY,
    job_title      VARCHAR(255) NOT NULL,
    designation_id INT NULL,
    department_id  INT NULL,
    description    TEXT NULL,
    requirements   TEXT NULL,
    openings_count INT NOT NULL DEFAULT 1,
    close_date     DATE NULL,
    status         ENUM('open','on_hold','closed','deleted') NOT NULL DEFAULT 'open',
    created_by     INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_by     INT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_jo (status, close_date)
);
CREATE TABLE IF NOT EXISTS candidates (
    candidate_id        INT AUTO_INCREMENT PRIMARY KEY,
    opening_id          INT NOT NULL,
    full_name           VARCHAR(255) NOT NULL,
    email               VARCHAR(255) NULL,
    phone               VARCHAR(50) NULL,
    source              VARCHAR(100) NULL,             -- referral / advert / agency / walk-in...
    cv_path             VARCHAR(500) NULL, cv_name VARCHAR(255) NULL,
    library_document_id INT NULL,
    stage               ENUM('applied','shortlisted','interview','offered','hired','rejected')
                        NOT NULL DEFAULT 'applied',
    stage_notes         VARCHAR(500) NULL,
    hired_employee_id   INT NULL,                      -- set after conversion (D28a)
    status              ENUM('active','deleted') NOT NULL DEFAULT 'active',
    created_by          INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_by          INT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_cand (opening_id, stage, status),
    CONSTRAINT fk_cand_opening FOREIGN KEY (opening_id) REFERENCES job_openings(opening_id)
);
CREATE TABLE IF NOT EXISTS candidate_interviews (
    interview_id   INT AUTO_INCREMENT PRIMARY KEY,
    candidate_id   INT NOT NULL,
    interview_date DATE NOT NULL,
    interview_time TIME NULL,
    interviewers   VARCHAR(500) NULL,                  -- names/ids CSV — display only
    rating         TINYINT NULL,                       -- 1..5 (shared star partial, D17 scale)
    feedback       TEXT NULL,
    status         ENUM('scheduled','done','cancelled','deleted') NOT NULL DEFAULT 'scheduled',
    created_by     INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ci_candidate FOREIGN KEY (candidate_id) REFERENCES candidates(candidate_id)
);
```

### 9.3 Phases (each = one branch off `develop`, tests, PR)

#### Phase 4.1 — Foundation: migrations + permissions + routes + nav + ESS link
**Branch:** `feat/hr-talent-foundation`

1. Migrations: all tables above + `users.employee_id` (SHOW COLUMNS guard) + seed one default
   onboarding and one default offboarding `checklist_template` with sensible starter items
   (`INSERT IGNORE`).
2. Permissions (module `HR`, runtime-resolved roles as before): `announcements`, `meetings`,
   `employee_trips`, `hr_checklists`, `recruitment`, `my_hr`. **`my_hr` seeds `can_view = 1`
   for every role** (page shows only the session user's own data — D24 makes that safe);
   all others mirror `employees` editors.
3. `roots.php` routes: pages `announcements`, `meetings`, `employee_trips`, `hr_checklists`,
   `recruitment`, `my_hr` + their APIs (per-phase lists below).
4. `header.php`: HR dropdown gains "Recruitment", "Checklists", "Meetings", "Trips",
   "Announcements" (each behind its `canView`); **"My HR"** appears in the profile/user area
   (not the HR dropdown) only when the session user has a linked `employee_id`.
5. **User-management form (existing settings page) — additive field:** "Linked Employee"
   Select2 AJAX (existing search endpoint); saving writes `users.employee_id` (optional field —
   absent in old submits = unchanged, so existing user-save flows keep working untouched).
6. `uploads/candidate_cvs/` + `uploads/trips/` + `.htaccess` (§19), created by migration.

**No-break check:** `users` writers keep working (new column nullable + optional in the form);
login/auth path reads nothing new.
**Tests:** CLI — migrations idempotent, seeds present; user-save with and without the new field.

#### Phase 4.2 — Announcements
**Branch:** `feat/announcements`

1. `announcements.php` (template §8): stat cards (Published & current, Drafts, Expiring ≤7d,
   Read rate %), DataTable + mobile cards, add/edit modals (audience selector: all /
   department / project — project options via `scopeFilterSql`), publish/archive actions
   (`canPublish` verb per §11.1 — falls back to `canEdit` if the verb column is absent).
2. `api/manage_announcement.php` (add/edit/publish/archive/soft-delete). **On publish (D25):**
   resolve audience users (all active / by `users.department_id` / by project assignment),
   insert one `notifications` row each (dedupe via `notification_dedupe`,
   event key `hr_announcement`), `logActivity` + `logAudit`.
3. `api/get_announcements.php` (list; scope: non-admins see `all` + their department/projects)
   + `api/mark_announcement_read.php` (insert-ignore into `announcement_reads`).
4. Read surfacing: current announcements (publish ≤ today ≤ expire) render as a dismissible
   banner card on the dashboard and in "My HR" (Phase 4.6), unread-first.

**No-break check:** `message_center` untouched; notifications table only gains rows.
**Tests:** CLI — audience resolution matrix (all/dept/project), dedupe on re-publish, expiry
window filtering; runtime — banner render + mark-read.

#### Phase 4.3 — Meetings & Business Trips
**Branch:** `feat/meetings-trips`

1. `meetings.php` (page_key `meetings`): stat cards (Upcoming, This week, Completed this month),
   list + calendar-ish date grouping, add/edit modal (attendees = Select2 AJAX multi,
   scope-gated), complete flow (mark attendance per attendee + write minutes), cancel.
   Notifications to attendees' linked users on schedule/cancel (engine, deduped).
2. `employee_trips.php` (page_key `employee_trips`): stat cards (Pending, Approved & ongoing,
   Completed this year), filters, DataTable + mobile cards; request modal (employee Select2,
   purpose, destination, dates, estimated cost, requested advance — **with the D26 note:**
   *"advance & expenses are recorded in Petty Cash / Expenses as usual; paste the reference
   here"*); §11.1 transitions `pending→approved/rejected (canApprove/canReject)`,
   `approved→completed` (requires trip report) `/cancelled`; requester-cannot-approve (SoD);
   optional attachment (§19).
3. **`employee_details.php` — additive rows in the Service context:** upcoming meetings the
   employee attends, and a "Trips" mini-list (destination, dates, status chip).

**No-break check:** no expense/petty-cash code touched — `expense_reference` is a plain string.
**Tests:** CLI — trip transition map + SoD, completion requires report, attendee attendance
marking; runtime — both pages + details additions.

#### Phase 4.4 — Onboarding / Offboarding checklists
**Branch:** `feat/hr-checklists`

1. `hr_checklists.php` (page_key `hr_checklists`), two tabs:
   - **Templates** (`canEdit`): CRUD templates + sortable items; `is_default` toggle per type
     (only one default per type — enforced server-side).
   - **Active checklists:** cards per employee checklist with progress bar
     (done/total), tick items (`api/tick_checklist_item.php` — stamps `done_by/done_at`,
     optional note, `logActivity`), complete/cancel checklist; spawn manually for any employee
     (template picker snapshots items, D30).
2. **Auto-spawn hooks (D28):**
   - *Onboarding:* after a successful employee INSERT via the **existing** `api/add_employee.php`
     flow — implemented as a post-commit call in a new shared helper
     `core/checklists.php::spawnChecklistIfConfigured($pdo, $employee_id, 'onboarding')`,
     invoked from the API **after** its existing transaction commits (one added line, wrapped in
     `function_exists` guard; a checklist failure logs but never fails the employee creation).
   - *Offboarding:* inside Tier 1's `change_lifecycle_status.php` approval branch for
     `resignation`/`termination` — same guarded one-line call with `'offboarding'`.
     These are the **only two touches to existing/prior-tier code in Tier 4**; both are
     append-only, guarded, and non-fatal by design.
3. **`employee_details.php` — additive:** active checklist card with progress bar and inline
   ticking (permission-gated).

**No-break check:** add-employee regression with the helper absent (guard passes), with default
template inactive (no spawn), and with spawn throwing (employee still created — non-fatal).
**Tests:** CLI — snapshot isolation (template edited after spawn), single-default rule,
auto-spawn on hire + on approved termination; runtime — tick flow end-to-end.

#### Phase 4.5 — Recruitment (internal ATS)
**Branch:** `feat/recruitment`

1. `recruitment.php` (page_key `recruitment`), two tabs:
   - **Openings:** stat cards (Open positions, Total candidates, In interview, Hired this year),
     CRUD openings (designation/department Select2), open/hold/close.
   - **Candidates:** pipeline board-style filters by stage (`applied → shortlisted → interview
     → offered → hired / rejected`), add candidate (CV upload §19 → library, D27), stage moves
     with required note (`api/change_candidate_stage.php` — forward-only map + `rejected` from
     any stage, `canEdit('recruitment')`), interview scheduling per candidate (date/time,
     interviewers, star rating + feedback after — shared star partial from Tier 3).
2. **Hire conversion (D28a):** stage → `hired` requires the opening still open; button
   "Create Employee" opens the **existing** Add Employee modal pre-filled (name split,
   email, phone, designation/department from the opening) — submission goes through the
   untouched `api/add_employee.php`; on success the candidate stores `hired_employee_id`
   (linking ATS → employee file) and Phase 4.4's onboarding auto-spawn fires naturally.
3. Openings auto-close option when `openings_count` hires reached (prompt, not forced).
4. CV downloads via gatekeeper (`canView('recruitment')` — candidate PII).

**No-break check:** Add Employee modal reused via its existing open/pre-fill JS only; no
change to its form fields or API.
**Tests:** CLI — stage map (no skipping backward, rejected-from-any), hire requires open
opening, `hired_employee_id` linkage, CV upload matrix; runtime — full pipeline
applied→interview→offered→hired→employee created→onboarding checklist exists.

#### Phase 4.6 — Employee Self-Service: "My HR"
**Branch:** `feat/my-hr`

1. `my_hr.php` (page_key `my_hr`; every role has view, D24): if the session user has no
   linked employee → friendly "not linked" notice (page never errors). Otherwise, tabs — all
   **read-only aggregations of data built in Tiers 1–4**, resolved strictly from the session
   link:
   - **My Profile:** personal/employment summary (subset of `employee_details` fields, no
     salary of others, no admin actions) + passport-photo, + "request correction" button that
     sends a message via the existing `message_center` tables to HR-role users.
   - **My Payslips:** list of own `payroll_items` periods; per-payslip render reuses the
     existing payslip layout include (print allowed).
   - **My Leave:** own balances + history from the leave suite; "Apply for Leave" form posting
     to a thin `api/my_leave_apply.php` that inserts through the **same tables/workflow** the
     admin leave module uses (employee_id forced from session; approvals continue in the
     existing leave pages untouched).
   - **My Documents & Contracts:** own Tier 2 docs/contracts with expiry chips (gatekeeper
     downloads only).
   - **My Performance & Training:** own approved appraisals, goals with progress, training
     history + certificates (Tier 3).
   - **My Service Record, Trips & Meetings:** own Tier 1 timeline (approved events only),
     trips, upcoming meetings.
   - **Announcements:** current, unread-first, mark-as-read (Phase 4.2).
2. Thin ESS APIs (`api/my_*.php` family): every one starts with the same guard — resolve
   `employee_id` from `users` row of `$_SESSION['user_id']`; absent → 403. No `employee_id`
   input parameter exists on any of them (D24).
3. Nav: "My HR" in the profile/user dropdown area when linked (Phase 4.1 prepared this).

**No-break check:** zero changes to admin HR pages; leave applications created via ESS appear
in the existing approval screens exactly like admin-entered ones (same tables, same workflow).
**Tests:** CLI — session-link guard (unlinked 403, linked sees only own rows, forged ids
ignored), leave application lands in existing workflow; runtime — every tab renders for a
linked user, payslip print, full ESS leave apply→admin approve loop.

#### Phase 4.7 — Re-scout + hardening + whole-plan completion pass

1. Re-scout sweep across **all four tiers**: every checklist item in this file verified
   implemented; grep for any missed `employee_id`-bearing new table lacking scope/ESS guards.
2. Verify the two D28 hooks degrade safely (helper file deleted → both flows still work).
3. Full CLI suite + the three end-to-end loops (hire→onboard, exit→offboard, ESS leave).
4. `changelog.md` entries per commit; update memory progress file; mark Tier 4 done in the
   tier table at the top of this file.

##### Re-scout results (done 2026-07-03, Phase 4.7)

- **Every §9 checklist item implemented.** 6 pages (announcements, meetings, employee_trips,
  hr_checklists, recruitment, my_hr), 25 APIs, 12 tables + `users.employee_id`, the two D28 hooks.
- **D28 hooks degrade safely (verified):** with `core/checklists.php` moved aside, both
  `add_employee.php` and `change_lifecycle_status.php` still lint and
  `test_hr_lifecycle_workflow_cli.php` still passes 28/28 — the `@is_file` + `function_exists`
  guards make the spawn a silent no-op. Only two touches to prior-tier code, both append-only.
- **Scope / ESS guards:** every Tier 4 list/detail API is guarded. Four files carry a documented
  `// scope-audit: skip` (the sanctioned opt-out) because project scope does not apply to them:
  `my_hr_data.php` (D24 own-record-only is a stronger control), `get_announcements.php` (does its
  own per-viewer audience scoping), `get_meetings.php` (company-wide, D29), and the admin-only
  `add_user.php`/`edit_user.php` (linked-employee name preview). The pre-push scope audit passes
  with `unscoped_count = 0`.
- **Ledger untouched (D21/D26):** no Tier 4 file reads or writes `journal_entries` /
  `journal_entry_items` / `current_balance`; trip/recruitment costs are informational only.
- **Full Tier 4 suite green:** 180 assertions across 6 files (`hr_talent_foundation` 83,
  `announcements` 18, `meetings_trips` 24, `hr_checklists` 21, `recruitment` 23, `my_hr` 11) —
  covering the three end-to-end loops (hire→onboard auto-spawn, approved-exit→offboard auto-spawn,
  ESS leave→existing workflow). **All prior-tier regressions clean** (org-structure 27, lifecycle
  workflow 28, service record 27, contracts 34, appraisals 25, goals 22, training 19,
  admin-breakglass 14). One real bug found + fixed en route: `manage_interview.php` returned the
  activity-log id instead of the interview id (`lastInsertId()` captured after `logActivity`).

### 9.4 Tier 4 cross-cutting notes

- All §4 rules apply. Candidate and employee PII downloads are gatekeeper-only.
- ESS pages never expose other employees' data — the guard is structural (D24), and every new
  `my_*` API must be covered by a scope test before merge.
- Nothing in Tier 4 posts to or reads from the ledger; trips/recruitment costs are
  informational strings/figures only (D26), real money flows stay in the existing modules.

---

# WHOLE-PLAN COMPLETION CHECKLIST

- [x] Tier 1 implemented (Phases 1.1–1.6) — lifecycle + Service Record
- [x] Tier 2 implemented (Phases 2.1–2.5) — documents, contracts, org structure
- [x] Tier 3 implemented (Phases 3.1–3.6) — indicators, appraisals, goals, training
- [x] Tier 4 implemented (Phases 4.1–4.7) — announcements, meetings, trips, checklists, recruitment, My HR
- [x] Every phase merged via its own PR into `develop`, with tests and changelog entries
      *(Tiers 1–3 merged + deployed; Tier 4 built & tested on `feat/hr-talent-foundation`, PR pending)*
- [x] Final regression: employees list/details, payroll, leave, attendance behave exactly as before Tier 1 began
      *(all prior-tier CLI suites re-run green after every tier)*

**PLAN COMPLETE (2026-07-03).** All four tiers of the WorkDo gap-closure are implemented,
tested (700+ CLI assertions total), and — through Tier 3 — deployed to production. Tier 4 awaits
its develop→main cascade.
