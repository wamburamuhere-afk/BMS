# Leaves Module Upgrade — Phased Plan

Status: **DRAFT — awaiting approval.** Nothing implemented yet.
Date: 2026-07-09

---

## 0. What I found before planning (verified against the live DB, not assumed)

These are the reasons this is not a pure UI task.

### 0.1 `leaves.leave_type` is an ENUM, not a link to `leave_types`

```
leaves.leave_type   ENUM('annual','sick','maternity','paternity','study','unpaid','other')
leave_types         type_id, type_name ('Annual Leave'), max_days_per_year, is_paid, ...
```

`leaves.php:353` joins them by **name**:

```sql
LEFT JOIN leave_types lt ON l.leave_type = lt.type_name
```

`'annual'` never equals `'Annual Leave'`. **Verified: this join matches 0 of 27 leave rows.**
Every "max days" lookup driven by it is dead.

### 0.2 A new leave type cannot be stored today

The form posts `type_name`. `api/apply_leave.php:56-77` maps it back to the ENUM
via a hard-coded `$type_map`, and **falls back to `'other'`** when there is no match.
So if the user adds "Compassionate Leave" in the new management page, every leave
booked against it is silently stored as `'other'`. Adding a type is meaningless
until `leaves` references `leave_types.type_id`.

### 0.3 `half_day` and `is_paid` are collected and thrown away

`api/apply_leave.php:53-54` reads both. The INSERT column list (`:100`) is:

```
employee_id, leave_type, start_date, end_date,
total_days, days_count, reason, notes, status, created_by, applied_by, created_at
```

Neither `half_day` nor `is_paid` is in it, and neither column exists on `leaves`.
**The Half Day dropdown has never done anything.** So does the Paid/Unpaid selector
the user wants removed — removing it loses nothing.

### 0.4 15 of 27 leave rows have an empty `leave_type`

`sql_mode` is empty (non-strict), so MySQL silently coerced invalid ENUM values to `''`.
Rows #12–#16 and others carry `leave_type = ""`. This data needs repairing as part of
the migration, not left behind.

### 0.5 `leave_details.php` already exists — it just isn't linked

`app/bms/pos/leave_details.php` (460 lines) exists and already has a PRINT button.
`leaves.php` Actions ▸ View calls `viewLeave(id)`, which opens the **`#viewLeaveModal`
modal** instead. So this sub-task is mostly "route View to the page and bring the page
up to standard", not "build a page".

### 0.6 There is no leave-types management page anywhere

Confirmed: no `leave_type*.php` under `app/`. It must be created, and per the request it
must **not** appear in the header nav — reachable only from a link under the Leave Type field.

---

## Phase 1 — Schema foundation (migration)

Nothing in the UI can work correctly until `leaves` points at `leave_types`.

### 1.1 Migration `migrations/2026_07_09_leaves_type_fk.php`
- `ALTER TABLE leaves ADD COLUMN leave_type_id INT NULL AFTER employee_id`
- `ADD CONSTRAINT fk_leaves_type FOREIGN KEY (leave_type_id) REFERENCES leave_types(type_id)`
- `ADD COLUMN half_day ENUM('none','first_half','second_half','other') NOT NULL DEFAULT 'none'`
- `ADD COLUMN leave_hours DECIMAL(4,2) NULL` — used only when `half_day = 'other'`
- `ADD COLUMN is_paid TINYINT(1) NULL` — **snapshot** of the type's `is_paid` at apply time,
  so re-classifying a leave type later does not silently rewrite history.
- Idempotent: every step guarded by an `information_schema` existence check.

### 1.2 Backfill (criteria-based, no hard-coded ids)
- Map each existing ENUM value → `leave_types.type_id` by matching on the first word of
  `type_name` (`'annual'` → `'Annual Leave'`), case-insensitive.
- The 15 rows with `leave_type = ''` cannot be inferred. Set `leave_type_id = NULL` and
  report the count in the migration output rather than guessing. **Ask the user** whether
  to map them to a new "Unspecified (legacy)" type or leave NULL.
- Backfill `is_paid` from the resolved type.

### 1.3 Keep the ENUM in place (dual-write), do not drop it
`leave_type` is read by `leaves.php`, `leave_details.php`, `leave_reports.php`,
`export_leaves.php`, `project_view.php`. Dropping it now breaks all of them. Phase 1 writes
**both** columns; a later cleanup phase removes the ENUM once every reader is migrated.

### 1.4 Verify
- Assert every non-empty ENUM row resolved to a `leave_type_id`.
- Assert the previously-dead join now matches on `leave_type_id` for all backfilled rows.

---

## Phase 2 — Leave Types management page (standalone, not in the header)

### 2.1 `app/bms/pos/leave_types.php`
- Full CRUD list: **add, edit, delete** (soft-delete → `status='inactive'`).
- Columns: Type Name · Max Days/Year · Max Consecutive · Paid/Unpaid · Requires Document ·
  Min Days Before Apply · Status · Actions.
- Follows `.claude/templates.md` §8 exactly: DataTable, mobile card view, stat cards,
  `bg-primary` modal headers, `btn-primary` save, Bootstrap Icons.
- New `page_key` = `leave_types`, added to the `permissions` table via migration.
- **Not added to the header nav** — deliberately omitted from `includes/header.php`.

### 2.2 APIs (per `.claude/templates.md` §9 + `security.md`)
- `api/add_leave_type.php`, `api/update_leave_type.php`, `api/delete_leave_type.php`,
  `api/get_leave_type.php`
- `csrf_check()`, `canCreate/canEdit/canDelete('leave_types')`, `logActivity`, `logAudit`.
- **Delete guard:** refuse to deactivate a type that has leaves booked against it in the
  current year; return a clear message instead of orphaning rows.

### 2.3 Paid / Unpaid stated professionally on this page
The Paid/Unpaid selector moves off the leave form and onto the **type**. On the add/edit
type form: a clear `Paid Leave` / `Unpaid Leave` radio pair with helper text
("Unpaid leave is deducted from salary and does not accrue"), and the list shows a badge.

### 2.4 The link under the Leave Type field
In both the Apply and Edit leave modals, directly beneath the Leave Type `<select>`:

```html
<small><a href="<?= getUrl('leave_types') ?>" target="_blank">
  <i class="bi bi-gear"></i> Manage leave types &amp; maximum days</a></small>
```

Opens in a new tab so a half-filled leave application is never lost. Rendered only when
`canView('leave_types')`. On return, the type `<select>` refreshes via AJAX.

---

## Phase 3 — Leave form changes (`app/bms/pos/leaves.php`)

### 3.1 Leave Type select → value becomes `type_id`
- `<option value="<?= $type['type_id'] ?>">` instead of `type_name`.
- Keep `data-max-days`, `data-requires-doc`; add `data-is-paid`.
- Applies to **both** the Apply modal (`:745`) and the Edit modal (`:947`).

### 3.2 Remove the Paid/Unpaid selector (`apply_is_paid`, `:778-784`)
Paid status now comes from the chosen type. Show it as a **read-only badge** next to the
type ("This type is Unpaid") so the applicant still sees it. Server snapshots it into
`leaves.is_paid`.

### 3.3 Half Day → add "Other (specify)"
```
No | First Half | Second Half | Other (specify)
```
Choosing **Other (specify)** reveals a number input: *Hours of leave* (`leave_hours`,
step 0.5, min 0.5, max = the working-day length). Mirrors the existing `toggleEmpOther`
swap-in-place pattern already used for Department / Payment Frequency, per the codebase's
established convention. `calculateDays()` updates `total_days` from the hours.

### 3.4 Remove the "Additional Notes" section (`:790-791`)
- Remove the textarea from the Apply and Edit modals.
- **The `notes` column stays** and continues to be displayed where already stored — 
  `apply_leave.php` currently persists it, so dropping the field must not null existing notes.
  Confirm `update_leave.php` uses `isset($_POST['notes'])` before removing (same trap as
  `bank_branch`); if it does not, guard it.

### 3.5 API updates
- `apply_leave.php` / `update_leave.php`: accept `leave_type_id`, validate it exists and is
  active, write `leave_type_id` **and** the legacy ENUM (dual-write), snapshot `is_paid`,
  persist `half_day` + `leave_hours`.
- Enforce `max_days_per_year` and `max_consecutive_days` **server-side** — currently only a
  client-side hint.

---

## Phase 4 — `leave_details.php` as a real page

### 4.1 Route Actions ▸ View to the page
Replace `viewLeave(id)` (modal) with a link to `leave_details.php?id=`, in all three places:
table row `:483`, desktop button `:518`, mobile card `:568`. Remove `#viewLeaveModal` once
nothing references it.

### 4.2 Show *every* field from the registration form
Employee, leave type (+ max days/year, paid/unpaid), start, end, total days, half-day /
hours, reason, contact during leave, handover to, supporting document link, status,
applied by, approved by, approved date. Nothing from the form omitted.

### 4.3 Consistency with the other detail pages
- `require_once` the **shared header and footer** (same includes as `employee_details.php`),
  so nav, colours and layout match.
- Same card/section structure, same `.claude/ui-constants.md` colour rules (blue-scale
  status badges, `text-primary` accents, `#e7f0ff` stat cards).
- `assertScopeForRecordHtml()` via the employee's project, and `autoEnforcePermission('leaves')`.

### 4.4 Print — identical to the other pages
- Reuse `includes/print_footer_css.php` (the shared `@media print` block) rather than
  writing new print CSS.
- Print output = header + body + footer only; nav, buttons, filters hidden via `d-print-none`.
- Compare the printed output side by side with an existing print page before signing off.

---

## Phase 5 — Re-scout, tests, verification

Per the standing rule: re-scout before each phase, and write tests after.

### 5.1 Re-scout
Grep for every remaining reader of `leaves.leave_type` (`leave_reports.php`,
`export_leaves.php`, `project_view.php`, `bulk_update_leave_status.php`, `duplicate_leave.php`)
and confirm each was updated or deliberately left on the ENUM.

### 5.2 Tests — `tests/test_leaves_upgrade_cli.php`
- Migration is idempotent (run twice, same result).
- Backfill maps every non-empty ENUM row to the right `type_id`.
- A **newly added** leave type can be selected and stored — the bug from §0.2.
- `half_day='other'` persists `leave_hours`; `half_day='none'` nulls it.
- `is_paid` is snapshotted from the type, and does not change when the type is later edited.
- Server rejects a leave exceeding `max_days_per_year` / `max_consecutive_days`.
- Delete guard: a type with booked leaves cannot be deactivated.
- Removing the notes field does not null existing `notes`.
- `leave_types.php` is **absent** from the header nav markup.
- End-to-end: apply a leave through the real endpoint, assert the row, then delete it.

### 5.3 Runtime verification
Drive the real pages (as done for products/warehouses): render `leaves.php` and
`leave_details.php`, submit a real apply request, print-preview the details page.

---

## Phase 6 — Cleanup (separate PR)

Only once every reader uses `leave_type_id`:
- Drop the `leaves.leave_type` ENUM column.
- Remove the `$type_map` fallback in `apply_leave.php`.

---

## Open questions for the user

1. **The 15 rows with an empty `leave_type`.** Map them to a new "Unspecified (legacy)"
   leave type, or leave `leave_type_id` NULL and show them as "—"?
2. **Hours cap for "Other (specify)".** Is a working day 8 hours? The max for the hours
   input should be less than a full day, otherwise it is just a full-day leave.
3. **Deleting a leave type** — soft-delete (`status='inactive'`, hidden from the dropdown,
   historical leaves keep resolving) is what I plan. Confirm you don't want a hard delete.
4. **Scope of one PR.** Phases 1–5 in a single PR is large. I suggest: PR-A = Phase 1
   (migration + backfill, no UI change), PR-B = Phases 2–3, PR-C = Phase 4, PR-D = Phase 6.
