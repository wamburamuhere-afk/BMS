# BMS — Employee Inactivation Plan (replace Delete, guarantee full history)

**Status:** DRAFT for approval · **Created:** 2026-07-09
**Requested by:** user, in-session — "employees should not [be] deleted but should be
inactive if the contract is terminated or terminated due to failed probation... remove
delete from action button, put inactivate... once inactivated, [not] waiting for
attendance, [not] available/callable anywhere for any action further, even leave...
create inactive_employees.php with ability to activate again... when employee is
[inactivated] I still [need to] track all payments, attendances, leaves, HR actions
made towards them, since day one."

---

## 1. Current state (audit findings — read-only investigation, no code changed yet)

### 1a. Two status fields that don't agree with each other

`employees` has **two independent lifecycle columns**, written by different code paths that never sync each other:

| Column | Values | Written by |
|---|---|---|
| `status` | `active`, `inactive`, `terminated` | Only `api/delete_employee.php` ever writes it (`'terminated'`) |
| `employment_status` | `active`, `probation`, `contract`, `on_leave`, `terminated`, `resigned` | `api/update_employee_status.php` (direct flip, no whitelist check), `core/lifecycle_effects.php` (HR Action approval effects) |

**The "Delete" button today** (`app/bms/pos/employees.php` row action → `api/delete_employee.php:43`) is not a real delete — it's already a soft-delete:
```php
UPDATE employees SET status = 'terminated', employment_status = 'terminated', ... WHERE employee_id = ?
```
**The existing "HR Action" → Termination/Resignation workflow** (a full, already-built approval pipeline: `employee_details.php` → `lifecycle_modal.php` → `api/change_lifecycle_status.php` → `core/lifecycle_effects.php:95-105`) does the *professional* version of the same thing, but only ever writes:
```php
UPDATE employees SET employment_status = 'terminated' /* or 'resigned' */ WHERE employee_id = ?
```
**It never touches `status`.** So an employee terminated the "proper" way (through HR Action, with approval, segregation of duties, audit trail — the exact mechanism you referenced as "just like HR ACTION") still has `status = 'active'`. Every query in the system that gates on `status` alone (the majority — see §1b) still treats them as fully active.

### 1b. `status = 'inactive'` already exists in the schema — and is completely unused

```sql
`status` enum('active','inactive','terminated') DEFAULT 'active'
```
This is very likely "the basic version of this concept" you remembered. It has been sitting in the schema the whole time but **zero** code anywhere reads or writes `employees.status = 'inactive'` — no dropdown option, no filter, no page. It's the ready-made hook for this feature; no schema migration is needed to introduce it, only wiring.

### 1c. Every "pick an active employee" spot in the system, and what it actually checks today

| Area | File(s) | What it filters on | Catches HR-Action-terminated employees? |
|---|---|---|---|
| Attendance capture (who's due today) | `app/bms/pos/attendance.php:59-65` | `employment_status IN (active,probation,contract) AND status='active'` | ✅ Only correct combined check in the whole codebase |
| Leave applicant + Handover-To selects | `app/bms/pos/leaves.php:51` | `status = 'active'` only | ❌ |
| Reporting-To / manager picker | `api/get_reporting_to_options.php`, `api/update_reporting_line.php`, `api/add_employee.php`, `api/update_employee.php`, `api/get_org_chart.php`, `api/account/search_employees.php` | `status != 'deleted'` (a value that doesn't even exist) or `status != 'terminated'`, never `employment_status` | ❌ |
| Payroll run candidates | `api/process_payroll.php`, `api/preview_payroll.php`, `.../preview_project_payroll.php`, `.../process_project_payroll.php` | `status = 'active'` (or `!= 'terminated'`); `employment_status` only an optional user filter, not a default exclusion | ❌ |
| Expense "Paid To" staff picker | `app/constant/accounts/expenses.php:47` | `status = 'active'` | ❌ |
| **Write-side APIs — no server-side re-check at all** | `api/mark_attendance.php`, `api/bulk_mark_attendance.php`, `api/quick_mark_attendance.php`, `api/apply_leave.php`, `api/my_leave_apply.php` | Nothing — trusts whatever `employee_id` the client posts | ❌ (a direct/crafted POST bypasses the UI filter entirely) |

**Conclusion:** the professional HR Action termination workflow you already built is currently *cosmetic* from the rest of the system's point of view — it updates the badge on the employee's profile, but doesn't actually stop them from being handed a shift, offered as a leave applicant, or run through payroll, because those checks look at the wrong (or no) field.

### 1d. History is never lost — but two views currently hide it by accident

No hard `DELETE FROM employees` exists anywhere in the codebase (confirmed by search). Payroll/attendance/leave rows are never touched by any lifecycle action. So the underlying data for "all payments, all attendance, all leaves, all HR actions since day one" already survives permanently. The gap is **visibility**, in exactly two places:

1. **Payroll list/history page** (`app/bms/pos/payroll.php` via `api/get_payrolls.php:52`) is *employee*-driven and hardcodes `e.status = 'active'` — once an employee is soft-deleted, **all** of their payroll rows vanish from this page for **every period**, not just the current one.
2. **Attendance history** (`app/bms/pos/attendance.php` — the only attendance view in the app) iterates only active/probation/contract employees as its outer loop — a soft-deleted employee's attendance rows exist in the DB but are **never rendered anywhere**. There is also no per-employee attendance log table elsewhere (only an aggregate "total present" count on the profile).

**Not gaps (already correct today):**
- **Leaves** — `leaves.php`, `leave_details.php`, `leave_reports.php` are all *leave*-driven, no employee-status filter. A departed employee's leave history stays fully visible.
- **HR Actions / lifecycle events** — the global list and the per-employee "Service Record" timeline are both *event*-driven, no employee-status filter. Full history stays visible.
- **The employee profile page itself** (`employee_details.php`) has **no status gate at all** — it already shows a departed employee's full payroll history table (`:1262-1333`), documents, contracts, and lifecycle timeline regardless of status. The only reason it feels "gone" today is that `employees.php`'s main directory excludes `status='terminated'` rows, so there's no in-app way to *browse* to a departed employee — only a direct link, or clicking through from a record that still references them.

### 1e. One more inconsistency found along the way

The "Delete" link on `employees.php`'s row-action menu is rendered **unconditionally** — `$can_delete_employees` is computed but never actually used to hide it. Anyone who can open the dropdown sees Delete regardless of permission; the only real gate is server-side inside the API. Worth fixing as part of this work since we're touching this exact control.

---

## 2. Design decisions (recommendations — flagging for your confirmation before I build)

**D1. What does "Inactivate" on the list page actually do — a quick direct action, or the full HR Action approval flow?**
*Decided:* a quick **direct** action (like the current Delete, and like `update_employee_status.php`'s existing "direct flip" pattern) — prompts for a reason (free text) and an `employment_status` outcome (Terminated / Resigned / Failed Probation → maps to `employment_status='terminated'`, since `probation` isn't a valid finished-state), sets `status='inactive'` immediately. This stays available for quick/informal deactivation. Separately, **HR Action's Termination/Resignation approval effect gets fixed to also set `status='inactive'`** (closing the two-systems gap in §1a) — so *both* paths converge on the same, single, reliable signal. You get the light-touch list action AND the formal workflow both actually working.

**D2. Collapse `status='terminated'` into `status='inactive'`, or keep them separate?**
*Decided:* collapse. Going forward, `status` becomes a simple two-state gate: `active` vs. **not** active (`inactive` covers everything — terminated, resigned, failed probation, or just "temporarily deactivated"). The *reason* is what `employment_status` (and the reason text on the Inactivate action) is for, not a second `status` value. A one-time data migration backfills existing `status='terminated'` rows to `status='inactive'` so the whole employee base is on one consistent model. `employment_status`'s own `terminated`/`resigned` values are untouched — they still describe *why*.

**D3. Reactivating an employee — does it reset `employment_status` automatically, or ask?**
*Decided: auto-set.* Reactivate sets both `status='active'` and `employment_status='active'` immediately, no prompt. If a re-hire genuinely needs `probation` instead, that's a normal edit afterward via the existing employee edit form — not part of the reactivate action itself.

**D4. "Failed probation" — a distinct mechanism, or just a reason label?**
*Recommendation:* just a reason option under the same Inactivate action (no separate flow exists today, and building one isn't necessary — it's the same outcome as any other inactivation, just a different stated reason for the record).

---

## 3. Phased plan

Each phase = its own branch off `develop`, its own CLI test suite (transaction-wrapped, rolled back, reconciled to direct SQL, matching the convention already used across this codebase), its own PR. Nothing here gets built until you confirm D1-D4 above (and the plan generally).

### Phase 0 — Unify the status model (foundation; everything else depends on this)
- Data migration: backfill existing `employees.status='terminated'` → `'inactive'` (per D2).
- Fix `core/lifecycle_effects.php`'s termination/resignation effect to also set `status='inactive'` alongside the existing `employment_status` write — closes the gap where the formal HR Action workflow doesn't actually deactivate anyone.
- Establish `status = 'active'` as the **single canonical gate** used consistently everywhere from here on (replacing the inconsistent `!= 'deleted'` / `!= 'terminated'` / bare `= 'active'` patterns found in §1c).

### Phase 1 — Replace Delete with Inactivate on the employees list
- New `api/inactivate_employee.php` (reason + employment_status outcome per D1), replacing `api/delete_employee.php`'s role in the UI. Same audit logging standard (`logActivity` + `logAudit`) as today, at minimum.
- `employees.php` row actions: remove Delete, add **Inactivate**, properly permission-gated this time (fixes the dead-variable bug in §1e).
- Old `api/delete_employee.php` retired (or left in place but unreachable from the UI — your call at build time).

### Phase 2 — `inactive_employees.php`
- New page, `status != 'active'` employees, search/filter, shows the reason + who/when inactivated.
- **Reactivate** action (`api/reactivate_employee.php`, sets `status='active'` + prompts for new `employment_status` per D3).
- Each row links straight through to the full profile (`employee_details.php`) — already unfiltered today, so full history is one click away.

### Phase 3 — Lock down every operational picker system-wide
- Update every file listed in §1c's table to filter on the single canonical `status='active'` gate.
- Add server-side status validation to the write endpoints currently trusting the client blindly (`mark_attendance.php`, `bulk_mark_attendance.php`, `quick_mark_attendance.php`, `apply_leave.php`, `my_leave_apply.php`) — reject with a clear error if the target/self employee isn't active. This is the part that makes "not available/callable anywhere for any action" actually enforced, not just UI-deep.

### Phase 4 — Close the two real historical-visibility gaps
- Add a per-employee **Attendance History** section to `employee_details.php` (mirrors the payroll history table that already works there) — today there's only an aggregate count, no way to review an inactive employee's actual attendance log anywhere.
- Payroll list page (`get_payrolls.php`/`payroll.php`): add an explicit way to still reach an inactive employee's historical payroll from that page (e.g. an "include inactive" toggle) — even though `employee_details.php` already shows it unfiltered, the global list shouldn't be a dead end.
- Leaves and HR Actions need no fix (already correct — confirmed in §1d).

### Phase 5 — Tests
- One `tests/test_employee_inactivation_cli.php`, transaction-wrapped: inactivate doesn't touch payroll/attendance/leaves/lifecycle-event rows; reactivate restores `status='active'`; every picker in §1c's table excludes an inactive employee; the write-side endpoints reject an inactive employee's ID; historical views (profile payroll/attendance/leaves/HR-actions) remain fully intact for an inactive employee.

---

## 4. Decisions — locked

- **D1:** Inactivate is a quick direct action (reason + employment_status outcome), not routed through the HR Action approval workflow. HR Action's own Termination/Resignation approval effect is separately fixed to set the same `status` flag, so both paths converge.
- **D2:** `status='terminated'` is collapsed into `status='inactive'`; existing rows get migrated. `employment_status` keeps its own `terminated`/`resigned` values to describe the reason.
- **D3:** Reactivate auto-sets `status='active'` and `employment_status='active'`, no prompt.
- **D4:** "Failed probation" is a reason label under the same Inactivate action, not a separate mechanism.

**Branch/PR granularity:** one PR per phase (Phase 0 → 5), matching today's session's pattern, unless told otherwise.
