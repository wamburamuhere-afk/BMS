# BMS Superadmin Professional Upgrade Plan

> **Project:** BJP Technologies Co. Ltd — BMS Platform  
> **Drafted:** 2026-09-25  
> **Total effort:** ~27 hours across 10 phases | 45 tasks  
> **Priority tiers:** 🔴 Critical · 🟠 High · 🔵 Medium · ⚫ Low

---

## Phases at a Glance

| Phase | Name | Delivers | Effort | Priority | Depends on |
|-------|------|----------|--------|----------|------------|
| P0 | Schema Foundation | All new control-DB columns | ~2h | 🔴 Critical | — |
| P1 | Registration Handlers | `trial_ends_at` + owner contact + classification on every new tenant | ~3h | 🔴 Critical | P0 |
| P2 | Trial Lifecycle Display | Expiry clock on dashboard, tenant list, tenant view | ~2h | 🔴 Critical | P0, P1 |
| P3 | Trial Enforcement | Auto-suspend, email reminders, Extend Trial action | ~3h | 🔴 Critical | P1, P2 |
| P4 | Tenant List Enrichment | Owner name/phone, last-active column, activity badges | ~3h | 🟠 High | P0, P1 |
| P5 | Operator Notes | Free-text notes per tenant with last-edited metadata | ~1h | 🟠 High | P0 |
| P6 | Business Classification | Industry/country/size filters + CSV export | ~2h | 🔵 Medium | P0, P1 |
| P7 | Billing Tracking | Billing cycle, MRR tile, overdue attention | ~3h | 🔵 Medium | P0 |
| P8 | Bulk Actions | Multi-select on tenant list, bulk suspend/activate/export | ~4h | ⚫ Low | P4, P6 |
| P9 | Broadcast Messaging | Compose + audience picker + broadcast log | ~4h | ⚫ Low | P3, P7 |

**Execution order:** P0 → P1 → P2 → P3 (uninterrupted) → P4 + P5 → P6 + P7 → P8 + P9

---

## P0 — Schema Foundation (~2h) 🔴

**Goal:** Add every new column to `tenants` in the control DB via a single idempotent ALTER block. All later phases depend on this. Run `php scripts/setup_control_db.php` after editing to apply.

**File:** `scripts/setup_control_db.php`

### Trial lifecycle columns
- [ ] Add `trial_ends_at DATETIME NULL AFTER suspended_at`
- [ ] Add `trial_extended_by INT NULL AFTER trial_ends_at` — superadmin_id who last extended (no FK, for audit display)
- [ ] Backfill: `UPDATE tenants SET trial_ends_at = DATE_ADD(created_at, INTERVAL 14 DAY) WHERE trial_ends_at IS NULL`

### Owner contact columns
- [ ] Add `owner_first_name VARCHAR(100) NULL AFTER owner_email`
- [ ] Add `owner_last_name VARCHAR(100) NULL AFTER owner_first_name`
- [ ] Add `owner_phone VARCHAR(20) NULL AFTER owner_last_name`

### Business classification columns
- [ ] Add `country VARCHAR(100) NULL AFTER owner_phone`
- [ ] Add `industry VARCHAR(64) NULL AFTER country` — e.g. 'retail', 'restaurant', 'services', 'manufacturing'
- [ ] Add `company_size VARCHAR(20) NULL AFTER industry` — e.g. '1-5', '6-20', '21-100', '100+'

### Engagement, notes & billing columns
- [ ] Add `last_active_at DATETIME NULL` — updated on every tenant-user login
- [ ] Add `notes TEXT NULL`
- [ ] Add `notes_updated_at DATETIME NULL` + `notes_updated_by INT NULL`
- [ ] Add `billing_cycle ENUM('monthly','annual') NULL`
- [ ] Add `billing_amount_tzs INT NULL` — monthly equivalent in TZS; annual stored at full amount, divided by 12 for MRR
- [ ] Add `next_billing_date DATE NULL`
- [ ] Add `payment_status ENUM('current','overdue','pending','none') NOT NULL DEFAULT 'none'`
- [ ] Add `unsubscribed_at DATETIME NULL` — comms opt-out flag

---

## P1 — Registration Handlers (~3h) 🔴

**Goal:** Every new tenant — self-registered or operator-created — arrives in the control DB with `trial_ends_at`, owner contact info, and classification already set. No manual patching later.

> ⚠️ Find the `INSERT INTO tenants` in the provisioning engine (`core/tenant_provisioning.php` or equivalent) — add all fields there, not scattered across action files.

### Provisioning engine
**File:** `core/tenant_provisioning.php`

- [ ] In the `INSERT INTO tenants` statement add `trial_ends_at = DATE_ADD(NOW(), INTERVAL 14 DAY)` — applies to both self-registration and operator-created tenants
- [ ] Copy `owner_first_name`, `owner_last_name`, `owner_phone` from provisioning payload into control-DB INSERT
- [ ] Copy `country`, `industry`, `company_size` into the INSERT (allow NULL — optional fields)

### Public self-registration form
**Files:** `register.php`, `actions/register_tenant.php`

- [ ] Add `country` dropdown (Tanzania, Kenya, Uganda, Rwanda, Other) — required field
- [ ] Add `industry` dropdown (Retail/Shop, Restaurant/Café, Services/Consulting, Manufacturing, Healthcare, Transport/Logistics, Other)
- [ ] Add `company_size` radio group (1–5 staff, 6–20, 21–100, 100+)
- [ ] Validate and pass all three through to the provisioning action

### Operator "New Company" form
**Files:** `app/superadmin/tenant_new.php`, `actions/superadmin_create_tenant.php`

- [ ] Add `country` / `industry` / `company_size` to the optional "Company profile" section
- [ ] Add an optional "Override trial end date" datepicker (default: +14 days) for operator-created tenants
- [ ] Pass new fields through `superadmin_create_tenant.php` → provisioning engine

---

## P2 — Trial Lifecycle Display (~2h) 🔴

**Goal:** Replace the passive "open N days" warning with a live, colour-coded expiry clock visible on every surface.

### Dashboard — upgrade stale_trials attention feed
**File:** `app/superadmin/dashboard.php`, `core/tenant_admin.php`

- [ ] Replace `stale_trials` category with three tiers: **Trial EXPIRED** (past), **Trial expires ≤3 days** (red), **Trial expires 4–7 days** (amber)
- [ ] Add "Expiring soon" stat tile (≤7 days) alongside Active/Trial/Suspended/Closed
- [ ] Update `tenantStats()` in `core/tenant_admin.php` to return `expiring_soon` and `expired_trial` counts

### Tenant list — Trial Ends column
**Files:** `app/superadmin/tenants.php`, `core/tenant_admin.php`

- [ ] Add `trial_ends_at` to the `listTenants()` SELECT
- [ ] Add "Trial Ends" column — shown only for trial-status tenants; colour: green (>7d), amber (≤7d), red (EXPIRED), dash for non-trials

### Tenant view — expiry in Overview tab
**File:** `app/superadmin/tenant_view.php`

- [ ] Add "Trial expires" row in the Overview card with the same green/amber/red coding
- [ ] Add "Extend Trial" button in the Lifecycle card (trial tenants only) — wired to P3's action

---

## P3 — Trial Enforcement (~3h) 🔴

**Goal:** Trials auto-enforce on expiry. Tenants are locked out, email reminders fire automatically, and an operator can extend a trial with one click.

### At-login gate
**File:** `core/tenant_auth.php`

- [ ] In tenant login success path: if `status = 'trial' AND trial_ends_at < NOW()` → auto-set status to 'suspended', log to `tenant_admin_log` (action: 'auto_suspend_trial'), return "Trial expired" error
- [ ] Show helpful expired-trial message with "Contact us to continue" text

### Background enforcement job
**File (new):** `api/cron/trial_enforcement.php`

- [ ] Bearer-token protected endpoint (same pattern as other cron endpoints) — called daily by scheduled workflow
- [ ] Query: `SELECT * FROM tenants WHERE status='trial' AND trial_ends_at < NOW()` → bulk-set to 'suspended', log each to `tenant_admin_log`

### Trial reminder email sequence
**File (new):** `api/cron/trial_reminders.php`

- [ ] 7-day reminder: email to `owner_email` — "Your trial ends in 7 days"
- [ ] 3-day reminder: "Your trial ends in 3 days"
- [ ] Expiry-day email: "Your trial ended today — your account has been paused"
- [ ] Skip send if `unsubscribed_at IS NOT NULL`; log each attempt to `tenant_admin_log` (action: 'trial_reminder')

### "Extend Trial" superadmin action
**Files:** `actions/superadmin_extend_trial.php` (new), `app/superadmin/tenant_view.php`

- [ ] Accept `tenant_id` + `days` (7/14/30/custom); set `trial_ends_at = DATE_ADD(NOW(), INTERVAL ? DAY)`; if currently suspended due to expiry, restore status to 'trial'
- [ ] Log to `tenant_admin_log` (action: 'extend_trial', detail: "Extended by N days")
- [ ] Wire "Extend Trial" button in `tenant_view.php` to call this action via Swal day-picker dialog

---

## P4 — Tenant List Enrichment (~3h) 🟠

**Goal:** The tenant list becomes a CRM-style client roster — owner name visible immediately, activity status at a glance.

### last_active_at — cached on every tenant-user login
**File:** `core/tenant_auth.php`

- [ ] After successful login in tenant DB: fire `UPDATE bms_control.tenants SET last_active_at = NOW() WHERE id = ?` via `getControlPdo()` — wrapped in try/catch so a control-DB hiccup never blocks login

### Tenant list — new columns + search
**Files:** `app/superadmin/tenants.php`, `core/tenant_admin.php`

- [ ] Add `owner_first_name`, `owner_last_name`, `owner_phone`, `last_active_at` to `listTenants()` SELECT
- [ ] Replace "Owner" column (email only) with full name; email as tooltip; add "Phone" column
- [ ] Add "Last Active" column: green (≤7d), amber (8–30d), red/Dormant (>30d), grey Never
- [ ] Add status filter dropdown above the table (All / Active / Trial / Suspended / Expired Trial)

### Tenant view — show new fields in Overview
**File:** `app/superadmin/tenant_view.php`

- [ ] Add owner name + phone to the Overview identity card (alongside existing `owner_email`)
- [ ] Add "Last Active" row with relative time + colour
- [ ] Add country / industry / company_size to the Company Profile section

---

## P5 — Operator Notes (~1h) 🟠

**Goal:** Persistent per-tenant notes visible to all superadmin operators, with last-edited metadata.

**Files:** `app/superadmin/tenant_view.php`, `actions/superadmin_tenant_notes.php` (new)

- [ ] Add "Operator Notes" card below Company Profile in the Overview tab: `<textarea>` pre-filled with `$tenant['notes']`, 500-char limit
- [ ] Show last-edited metadata: "Last updated by <email> on <date>" from `notes_updated_at` + `notes_updated_by`
- [ ] Create `actions/superadmin_tenant_notes.php`: auth + CSRF + `UPDATE tenants SET notes=?, notes_updated_at=NOW(), notes_updated_by=? WHERE id=?`
- [ ] Log note saves to `tenant_admin_log` (action: 'note_update')

---

## P6 — Business Classification & Filtering (~2h) 🔵

**Goal:** Tenant list filterable by industry, country, and company size. "All restaurant clients in Kenya" in two clicks. CSV export completes it.

**Files:** `app/superadmin/tenants.php`, `core/tenant_admin.php`

- [ ] Add filter row above table: Status / Industry / Country / Company Size dropdowns (distinct values from control DB, built dynamically)
- [ ] Update `listTenants()` to accept optional filter array; append parameterised WHERE clauses per active filter
- [ ] Add active filter chips below the filter bar, each with ✕ to clear that filter
- [ ] Add "Export CSV" button — downloads all columns for the current filtered set (via DataTables built-in or new `actions/superadmin_export_tenants.php`)

---

## P7 — Billing Tracking (~3h) 🔵

**Goal:** Manual billing data tracked per tenant. Even without a payment gateway, the platform knows who pays what and when — enabling an MRR view and overdue alerts.

### Billing tab on tenant view
**Files:** `app/superadmin/tenant_view.php`, `actions/superadmin_tenant_billing.php` (new)

- [ ] Add "Billing" tab to the 4-tab nav (Overview / Modules / Usage / Activity / **Billing**)
- [ ] Billing tab form: Billing Cycle (Monthly/Annual), Amount (TZS), Next Billing Date, Payment Status
- [ ] Create `actions/superadmin_tenant_billing.php`: UPDATE billing columns; log to `tenant_admin_log` (action: 'billing_update')
- [ ] Expose billing fields in `tenant_new.php` optional section too

### Dashboard — MRR tile + overdue attention
**File:** `app/superadmin/dashboard.php`

- [ ] Add MRR stat tile: `SUM(billing_amount_tzs)` for monthly tenants + `SUM(billing_amount_tzs/12)` for annual tenants (active only)
- [ ] Add "Overdue payments" attention category: `payment_status = 'overdue'`
- [ ] Add "Due in ≤7 days" attention category: `next_billing_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)`

---

## P8 — Bulk Actions (~4h) ⚫

**Goal:** Select multiple tenants and apply an action to all at once — essential once the tenant base grows beyond ~20 companies.

### Multi-select UI
**File:** `app/superadmin/tenants.php`

- [ ] Add checkbox column (select-all in header); preserve selection across DataTable pages
- [ ] Show sticky "X selected" action bar at bottom of table when ≥1 row checked
- [ ] Action bar options: Apply Plan / Suspend / Activate / Export Selected CSV — Swal confirm on every destructive action showing affected count

### Bulk action endpoint
**File (new):** `actions/superadmin_bulk_action.php`

- [ ] Accept `action` + `tenant_ids[]`; loop with same logic as single-tenant actions; return per-tenant success/fail summary
- [ ] Log each operation individually to `tenant_admin_log` — bulk action produces the same audit trail as N individual actions

---

## P9 — Broadcast Messaging (~4h) ⚫

**Goal:** Announce new features, pricing changes, or downtime to selected tenant audiences using the existing platform email relay.

### Broadcast compose page
**Files:** `app/superadmin/broadcast.php` (new), `actions/superadmin_broadcast.php` (new)

- [ ] Add "Broadcast" to superadmin nav (between Plans and Settings)
- [ ] Compose form: Subject, Body (plain text + simple formatting), live preview panel
- [ ] Audience picker: All active / All trial / Trial expiring ≤7d / By plan / By industry / Single tenant — show recipient count live as audience changes
- [ ] Send confirmation: "You are about to email N tenants — this cannot be undone"
- [ ] Skip tenants with `unsubscribed_at IS NOT NULL`; send via platform relay; log to new `broadcast_log` control-DB table (`subject`, `audience_definition`, `recipients_count`, `sent_at`, `sent_by`)
- [ ] Add `broadcast_log` table to `setup_control_db.php`

### Broadcast history
**File:** `app/superadmin/broadcast.php`

- [ ] Table of past broadcasts below the compose form: date / subject / audience / recipients / sent by

---

## New Files Summary

| File | Phase | Purpose |
|------|-------|---------|
| `api/cron/trial_enforcement.php` | P3 | Daily auto-suspend of expired trials |
| `api/cron/trial_reminders.php` | P3 | Trial reminder email sequence |
| `actions/superadmin_extend_trial.php` | P3 | Operator "extend trial" action |
| `actions/superadmin_tenant_notes.php` | P5 | Save operator notes |
| `actions/superadmin_export_tenants.php` | P6 | CSV export of filtered tenant list |
| `actions/superadmin_tenant_billing.php` | P7 | Save billing info per tenant |
| `actions/superadmin_bulk_action.php` | P8 | Bulk tenant operations |
| `app/superadmin/broadcast.php` | P9 | Broadcast compose + history page |
| `actions/superadmin_broadcast.php` | P9 | Send broadcast action |

## Modified Files Summary

| File | Phases |
|------|--------|
| `scripts/setup_control_db.php` | P0, P9 |
| `core/tenant_provisioning.php` | P1 |
| `register.php` | P1 |
| `actions/register_tenant.php` | P1 |
| `app/superadmin/tenant_new.php` | P1, P7 |
| `actions/superadmin_create_tenant.php` | P1 |
| `app/superadmin/dashboard.php` | P2, P7 |
| `core/tenant_admin.php` | P2, P4, P6 |
| `app/superadmin/tenants.php` | P2, P4, P6, P8 |
| `app/superadmin/tenant_view.php` | P2, P3, P4, P5, P7 |
| `core/tenant_auth.php` | P3, P4 |
| `core/superadmin_ui.php` | P9 |
