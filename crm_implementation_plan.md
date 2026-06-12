# BMS CRM Module — Full Implementation Plan
**Date:** 2026-06-11  
**Author:** Claude Code / W. Nyagawa  
**Scope:** Customer Relationship Management — Lead Pipeline, Activities, Conversion, Dashboard  
**Target parity:** WorkDo Dash SaaS CRM module  

---

## What is being built

A full CRM module that sits **before** the Sales module in the business flow:

```
[CRM]  Lead → Pipeline → Won
         ↓  (Convert)
[SALES] Customer → Quotation → Sales Order → Invoice → Payment → Accounts
```

Every lead that is won converts with **one click** into a Customer record and opens a pre-filled Quotation — no re-typing, no data loss.

---

## Files to create (complete inventory)

### Database migrations
| File | Purpose |
|---|---|
| `migrations/2026_06_11_crm_tables.php` | All 5 CRM tables |
| `migrations/2026_06_11_crm_permissions_seed.php` | Permissions rows + role grants |
| `migrations/2026_06_11_crm_stages_seed.php` | Default pipeline stages |

### App pages  `app/bms/crm/`
| File | Purpose |
|---|---|
| `crm_dashboard.php` | KPI cards + 4 charts + recent activity table |
| `leads.php` | Lead list — table + mobile card + Add/Edit modal |
| `lead_view.php` | Single lead detail — info, activity timeline, convert button |
| `pipeline.php` | Kanban drag-and-drop pipeline board |
| `pipeline_stages.php` | Admin: manage stage names, order, colours |

### API files  `api/crm/`
| File | Purpose |
|---|---|
| `add_lead.php` | POST — create lead |
| `edit_lead.php` | POST — update lead |
| `delete_lead.php` | POST — soft-delete |
| `get_lead.php` | GET — fetch single lead (edit modal) |
| `move_lead_stage.php` | POST — move lead to new pipeline stage (Kanban drag) |
| `convert_lead.php` | POST — win + create Customer + create Quotation |
| `add_activity.php` | POST — log call / meeting / note |
| `edit_activity.php` | POST — update activity |
| `delete_activity.php` | POST — soft-delete activity |
| `get_activities.php` | GET — fetch all activities for one lead |
| `get_pipeline_data.php` | GET — leads grouped by stage for Kanban board |
| `get_dashboard_data.php` | GET — all chart data + KPI stats |
| `get_lead_sources.php` | GET — source list for dropdowns |
| `manage_stage.php` | POST — add / edit / delete pipeline stage |
| `export_leads.php` | GET — Excel export |

### Navigation (edit one existing file)
| File | Change |
|---|---|
| `header.php` | Add CRM dropdown menu between Core and Sales |

---

## Database schema

### Table: `crm_leads`
```sql
CREATE TABLE crm_leads (
  lead_id          INT AUTO_INCREMENT PRIMARY KEY,
  lead_code        VARCHAR(20)  NOT NULL UNIQUE,          -- LEAD-00001
  first_name       VARCHAR(100) NOT NULL,
  last_name        VARCHAR(100) DEFAULT NULL,
  company_name     VARCHAR(200) DEFAULT NULL,
  email            VARCHAR(150) DEFAULT NULL,
  phone            VARCHAR(30)  DEFAULT NULL,
  mobile           VARCHAR(30)  DEFAULT NULL,
  website          VARCHAR(200) DEFAULT NULL,
  address          TEXT         DEFAULT NULL,
  city             VARCHAR(100) DEFAULT NULL,
  country          VARCHAR(100) DEFAULT 'Tanzania',
  lead_source      ENUM('website','referral','walk_in','phone_call','social_media',
                        'exhibition','cold_call','email_campaign','other')
                   DEFAULT 'other',
  pipeline_stage_id INT         DEFAULT NULL,             -- FK crm_pipeline_stages
  assigned_to      INT          DEFAULT NULL,             -- FK users
  lead_value       DECIMAL(15,2) DEFAULT 0.00,            -- estimated deal TZS
  probability      TINYINT      DEFAULT 20,               -- 0–100 %
  expected_close_date DATE      DEFAULT NULL,
  product_interest TEXT         DEFAULT NULL,             -- free text or product_id
  notes            TEXT         DEFAULT NULL,
  converted        TINYINT(1)   DEFAULT 0,                -- 1 = converted to customer
  customer_id      INT          DEFAULT NULL,             -- FK customers (after conversion)
  quotation_id     INT          DEFAULT NULL,             -- FK quotations (after conversion)
  lost_reason      TEXT         DEFAULT NULL,
  project_id       INT          DEFAULT NULL,             -- optional scope link
  status           ENUM('active','inactive','deleted') DEFAULT 'active',
  created_by       INT          NOT NULL,
  updated_by       INT          DEFAULT NULL,
  created_at       DATETIME     DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

### Table: `crm_pipeline_stages`
```sql
CREATE TABLE crm_pipeline_stages (
  stage_id    INT AUTO_INCREMENT PRIMARY KEY,
  stage_name  VARCHAR(100) NOT NULL,
  stage_order TINYINT      DEFAULT 0,
  color       VARCHAR(7)   DEFAULT '#6c757d',  -- hex colour for Kanban column header
  is_won      TINYINT(1)   DEFAULT 0,          -- only 1 "Won" stage allowed
  is_lost     TINYINT(1)   DEFAULT 0,          -- only 1 "Lost" stage allowed
  status      ENUM('active','deleted') DEFAULT 'active',
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

**Default seed rows:**
| order | name | color | is_won | is_lost |
|---|---|---|---|---|
| 1 | New Lead | #0d6efd | 0 | 0 |
| 2 | Contacted | #0dcaf0 | 0 | 0 |
| 3 | Qualified | #ffc107 | 0 | 0 |
| 4 | Proposal Sent | #fd7e14 | 0 | 0 |
| 5 | Negotiation | #6f42c1 | 0 | 0 |
| 6 | Won | #198754 | 1 | 0 |
| 7 | Lost | #dc3545 | 0 | 1 |

### Table: `crm_lead_activities`
```sql
CREATE TABLE crm_lead_activities (
  activity_id   INT AUTO_INCREMENT PRIMARY KEY,
  lead_id       INT NOT NULL,                              -- FK crm_leads
  activity_type ENUM('call','email','meeting','note','task','site_visit') DEFAULT 'note',
  subject       VARCHAR(200) NOT NULL,
  description   TEXT         DEFAULT NULL,
  activity_date DATETIME     DEFAULT CURRENT_TIMESTAMP,
  due_date      DATETIME     DEFAULT NULL,
  outcome       TEXT         DEFAULT NULL,
  status        ENUM('pending','done','overdue','deleted') DEFAULT 'pending',
  created_by    INT          NOT NULL,
  created_at    DATETIME     DEFAULT CURRENT_TIMESTAMP
);
```

### Table: `crm_labels`
```sql
CREATE TABLE crm_labels (
  label_id   INT AUTO_INCREMENT PRIMARY KEY,
  label_name VARCHAR(60)  NOT NULL,
  color      VARCHAR(7)   DEFAULT '#6c757d',
  status     ENUM('active','deleted') DEFAULT 'active',
  created_by INT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

### Table: `crm_lead_labels`  (many-to-many)
```sql
CREATE TABLE crm_lead_labels (
  id       INT AUTO_INCREMENT PRIMARY KEY,
  lead_id  INT NOT NULL,
  label_id INT NOT NULL,
  UNIQUE KEY uq_lead_label (lead_id, label_id)
);
```

---

## Permissions to seed

**Page keys:**
| page_key | Module |
|---|---|
| `crm_dashboard` | CRM Dashboard |
| `crm_leads` | Leads list, Add, Edit, Delete |
| `crm_pipeline` | Pipeline board + stage management |
| `crm_activities` | Activity log on leads |
| `crm_convert` | Convert lead → Customer + Quotation |

**Role grants (which roles get what by default):**
| Role | crm_dashboard | crm_leads | crm_pipeline | crm_activities | crm_convert |
|---|---|---|---|---|---|
| Super Admin | Full | Full | Full | Full | Full |
| Manager | view | Full | view+edit | Full | yes |
| Sales | view | Full | view+edit | Full | yes |
| Accountant | view | view | view | view | no |
| Procurement | — | — | — | — | — |
| Storekeeper | — | — | — | — | — |
| HR | — | — | — | — | — |
| Auditor | view | view | view | view | no |
| Field Officer | — | view | view | view | no |

---

## Phase breakdown

---

### PHASE 1 — Foundation (Database + Permissions)
**Goal:** All tables exist, permissions seeded, default stages seeded. Zero UI yet.  
**Risk:** None — pure database additions, no existing tables touched.

#### Sub-phase 1.1 — CRM Tables migration
**File:** `migrations/2026_06_11_crm_tables.php`

- CREATE TABLE `crm_leads` (full schema above)
- CREATE TABLE `crm_pipeline_stages`
- CREATE TABLE `crm_lead_activities`
- CREATE TABLE `crm_labels`
- CREATE TABLE `crm_lead_labels`
- Each wrapped in `IF NOT EXISTS` so it is idempotent
- Add indexes: `crm_leads(assigned_to)`, `crm_leads(pipeline_stage_id)`, `crm_lead_activities(lead_id)`, `crm_lead_activities(due_date)`, `crm_lead_labels(lead_id)`

#### Sub-phase 1.2 — Permission rows migration
**File:** `migrations/2026_06_11_crm_permissions_seed.php`

- INSERT INTO `permissions` for each of the 5 page_keys (skip if already exists — `INSERT IGNORE`)
- INSERT INTO `role_permissions` for every role × page_key combination per the role-grants table above
- Use `ON DUPLICATE KEY UPDATE` so re-running is safe

#### Sub-phase 1.3 — Default pipeline stages seed
**File:** `migrations/2026_06_11_crm_stages_seed.php`

- INSERT the 7 default stages (skip if table already has rows)
- Confirm `is_won=1` only on "Won" row and `is_lost=1` only on "Lost" row

**Phase 1 done when:** All 5 tables exist in the DB, 5 permission keys exist, 7 pipeline stages exist.

---

### PHASE 2 — Leads Core (List + CRUD)
**Goal:** Users can add, view, edit, and delete leads.  
**Depends on:** Phase 1

#### Sub-phase 2.1 — API: add_lead
**File:** `api/crm/add_lead.php`

- Auth + `canCreate('crm_leads')` + CSRF check
- Generate `lead_code`: `LEAD-` + zero-padded next ID
- Required: `first_name`, `pipeline_stage_id` (defaults to stage_order=1)
- Optional all other fields
- INSERT into `crm_leads`
- INSERT into `crm_lead_labels` for any labels chosen
- `logActivity()` — "Created lead: {full_name} ({lead_code})"
- Return `{ success, lead_id, lead_code }`

#### Sub-phase 2.2 — API: get_lead
**File:** `api/crm/get_lead.php`

- Auth + `canView('crm_leads')`
- GET `?id=`
- JOIN with `crm_pipeline_stages`, `users` (assigned_to), `crm_labels`
- Return full lead row + stage name + assigned user name + label IDs

#### Sub-phase 2.3 — API: edit_lead
**File:** `api/crm/edit_lead.php`

- Auth + `canEdit('crm_leads')` + CSRF
- Validate lead exists and is not deleted
- UPDATE `crm_leads`, update `crm_lead_labels` (delete old, insert new)
- `logActivity()` — "Updated lead: {lead_code}"

#### Sub-phase 2.4 — API: delete_lead
**File:** `api/crm/delete_lead.php`

- Auth + `canDelete('crm_leads')` + CSRF
- Soft-delete: `UPDATE crm_leads SET status = 'deleted'`
- Block if lead is already converted (`converted = 1`)
- `logActivity()`

#### Sub-phase 2.5 — Page: leads.php
**File:** `app/bms/crm/leads.php`

Follows §8 page template exactly:
- `ob_start()` → `header.php` → permission check
- Stats row (4 cards): Total Leads, Active, Converted, Pipeline Value (sum of lead_value)
- **Filter bar:** by stage, by source, by assigned user, by date range
- DataTable: columns → Code, Name, Company, Source, Stage (coloured badge), Value, Assigned To, Expected Close, Status, Actions
- Actions: Eye (lead_view), Pencil (edit modal), Trash (delete), Funnel (convert — only if stage is Won and not yet converted)
- **Add modal** fields: First Name*, Last Name, Company, Email, Phone, Source (select), Pipeline Stage (select), Assigned To (select — users), Lead Value, Expected Close Date, Product Interest (textarea), Notes (textarea), Labels (multi-select)
- **Edit modal**: mirrors Add modal, pre-fills from `get_lead.php`
- Mobile card view: Name + Company | Stage badge | Value | Assigned To | action buttons
- Export to Excel button (calls `export_leads.php`)

#### Sub-phase 2.6 — API: export_leads
**File:** `api/crm/export_leads.php`

- Auth + `canView('crm_leads')`
- Reads same filters as the list page (stage, source, date)
- Outputs Excel via PhpSpreadsheet (already in project) or CSV fallback
- Columns: Code, Name, Company, Email, Phone, Source, Stage, Value, Probability, Expected Close, Assigned To, Created Date

**Phase 2 done when:** A user can add a lead with all fields, see it in the list, edit it, delete it, and export the list to Excel.

---

### PHASE 3 — Pipeline Board (Kanban)
**Goal:** Visual drag-and-drop pipeline board matching WorkDo's Kanban view.  
**Depends on:** Phase 2

#### Sub-phase 3.1 — API: get_pipeline_data
**File:** `api/crm/get_pipeline_data.php`

- Auth + `canView('crm_pipeline')`
- Returns all active stages (ordered by `stage_order`) each with their array of active leads
- Each lead card carries: lead_id, lead_code, first_name, last_name, company_name, lead_value, probability, expected_close_date, assigned_user_name, days_in_stage (DATEDIFF from updated_at)
- Applies project scope filter if user is non-admin

#### Sub-phase 3.2 — API: move_lead_stage
**File:** `api/crm/move_lead_stage.php`

- Auth + `canEdit('crm_pipeline')` + CSRF
- POST: `lead_id`, `new_stage_id`
- Validate stage exists and is not deleted
- UPDATE `crm_leads SET pipeline_stage_id = ?, updated_at = NOW()`
- If new stage has `is_won = 1`: set probability to 100, do NOT auto-convert (user must click Convert separately)
- If new stage has `is_lost = 1`: optionally prompt for lost_reason (handled in frontend modal)
- `logActivity()` — "Lead {lead_code} moved to stage: {stage_name}"

#### Sub-phase 3.3 — Page: pipeline.php
**File:** `app/bms/crm/pipeline.php`

- Permission check: `canView('crm_pipeline')`
- On page load: fetch pipeline data via AJAX from `get_pipeline_data.php`
- Renders one column per stage; column header = stage name + total count + total value
- Each lead = a card showing: name, company, value badge, probability bar, assigned avatar initial, expected close date, days-in-stage chip
- **Drag behaviour:** use SortableJS (lightweight, CDN) — on `onEnd` event POST to `move_lead_stage.php`
- When card dropped into Lost column: show a small modal asking for `lost_reason` before confirming
- Click on a card → navigate to `lead_view.php?id={lead_id}`
- "Add Lead" floating button pre-selects the stage of the column clicked
- Mobile: collapses to accordion (one stage per collapsible section, cards inside)

#### Sub-phase 3.4 — API + Page: manage pipeline stages
**File (API):** `api/crm/manage_stage.php`  
**File (Page):** `app/bms/crm/pipeline_stages.php`

- Admin-only (or Manager) page under CRM settings
- List stages with drag handles to reorder (SortableJS, save order on drop)
- Add stage: name, colour picker, mark as Won/Lost (only one each)
- Edit stage: same fields
- Delete: only allowed if no leads are in that stage; else error
- "Won" and "Lost" stages cannot be deleted

**Phase 3 done when:** The full pipeline board renders, cards drag between columns, movements are saved, and stages are manageable.

---

### PHASE 4 — Lead Detail & Activity Log
**Goal:** A full-page view per lead showing all info, a timeline of activities, and a log-activity button.  
**Depends on:** Phase 2

#### Sub-phase 4.1 — Page: lead_view.php
**File:** `app/bms/crm/lead_view.php`

Layout (Bootstrap 5 two-column on desktop, stacked on mobile):

**Left column — Lead information card:**
- Lead code badge, stage badge, source badge, converted badge (if converted)
- Full name, company, email, phone, website links
- Lead value (formatted TZS), probability progress bar
- Expected close date (red if overdue)
- Assigned to (avatar + name)
- Notes
- Labels (colour-coded)
- Action buttons: Edit Lead | Move Stage | **Convert Lead** (only if won and not converted) | Delete

**Right column — Activity Timeline:**
- "Log Activity" button → opens Add Activity modal
- Timeline list (newest first): icon per type (phone=bi-telephone, email=bi-envelope, meeting=bi-people, note=bi-sticky, task=bi-check2-square, site_visit=bi-geo-alt)
- Each activity: type badge, subject, description, date, outcome, status (pending/done/overdue), Edit + Delete buttons
- Empty state message if no activities

**Bottom row — Related records (shown after conversion):**
- Link to the Customer record created
- Link to the Quotation created

#### Sub-phase 4.2 — API: add_activity
**File:** `api/crm/add_activity.php`

- Auth + `canCreate('crm_activities')` + CSRF
- POST: lead_id, activity_type, subject, description, activity_date, due_date, outcome, status
- INSERT into `crm_lead_activities`
- `logActivity()` — "Logged {activity_type} on lead {lead_code}: {subject}"

#### Sub-phase 4.3 — API: edit_activity
**File:** `api/crm/edit_activity.php`

- Auth + `canEdit('crm_activities')` + CSRF
- UPDATE `crm_lead_activities`

#### Sub-phase 4.4 — API: delete_activity
**File:** `api/crm/delete_activity.php`

- Auth + `canDelete('crm_activities')` + CSRF
- Soft delete: `UPDATE crm_lead_activities SET status = 'deleted'`

#### Sub-phase 4.5 — API: get_activities
**File:** `api/crm/get_activities.php`

- Auth + `canView('crm_activities')`
- GET `?lead_id=`
- Returns activities array ordered by `activity_date DESC`

**Phase 4 done when:** Lead detail page loads with all lead info, activities can be added/edited/deleted, and the timeline displays correctly.

---

### PHASE 5 — Lead Conversion (CRM → Sales)
**Goal:** One click converts a Won lead into a Customer record and an open Quotation.  
**Depends on:** Phase 4  
**This is the most important feature — it closes the loop between CRM and Sales.**

#### Sub-phase 5.1 — API: convert_lead.php
**File:** `api/crm/convert_lead.php`

- Auth + `canCreate('crm_convert')` + CSRF
- Validate: lead exists, not already converted, stage is Won (`is_won = 1`)
- **Step A — Create Customer:**
  - Generate CUST-xxxxx code (same pattern as `add_customer.php`)
  - INSERT into `customers` — map fields: first_name + last_name → customer_name, company_name, email, phone, mobile, address, city, country
  - Use defaults: currency='TZS', country='Tanzania', status='active', created_by
- **Step B — Create Quotation:**
  - Generate QUO-YYYY-xxxxx code (same pattern as quotation module)
  - INSERT into `quotations` with: customer_id=new customer, notes from lead's product_interest, status='draft', created_by
  - Do NOT add line items yet — user fills those in the quotation form
- **Step C — Update Lead:**
  - `UPDATE crm_leads SET converted=1, customer_id=?, quotation_id=?, updated_at=NOW()`
- **All 3 steps in a DB transaction** — rollback if any step fails
- `logActivity()` — "Lead {lead_code} converted → Customer {cust_code}, Quotation {quo_code}"
- Return: `{ success, customer_id, customer_code, quotation_id, quotation_code }`

#### Sub-phase 5.2 — UI: Convert button on lead_view.php
- "Convert Lead" button visible only when: stage is Won AND `converted = 0` AND user has `canCreate('crm_convert')`
- Button click → SweetAlert2 confirm: "This will create a Customer record and open a draft Quotation. Continue?"
- On confirm → POST to `convert_lead.php`
- On success → SweetAlert: "Lead converted! Customer {code} and Quotation {code} created." with two links
- Page refreshes to show the "Converted" badge and the related-records links at the bottom

**Phase 5 done when:** A Won lead can be converted, a real Customer row and Quotation row are created, and the lead shows as converted with working links to both.

---

### PHASE 6 — CRM Dashboard
**Goal:** A professional overview page with KPI cards and 4 charts.  
**Depends on:** Phases 2–5

#### Sub-phase 6.1 — API: get_dashboard_data.php
**File:** `api/crm/get_dashboard_data.php`

- Auth + `canView('crm_dashboard')`
- Accepts `?period=this_month|last_month|this_year|all` (default: this_month)
- Returns:

**KPI stats:**
- `total_leads` — all active leads in period
- `new_this_period` — leads created in period
- `pipeline_value` — SUM(lead_value) WHERE not lost and not deleted
- `conversion_rate` — (won_leads / total_leads) × 100
- `activities_today` — count of activities with due_date = today and status = pending
- `overdue_activities` — count with due_date < NOW() and status = pending

**Chart data:**
1. **Leads by Stage** (doughnut) — count per pipeline stage
2. **Leads by Source** (bar) — count per lead_source
3. **Monthly Pipeline** (line chart) — leads created per month, last 6 months
4. **Win/Loss trend** (grouped bar) — won vs lost per month, last 6 months

**Tables:**
- `recent_leads` — 5 most recently created leads (id, name, company, stage, value, created_at)
- `due_activities` — up to 10 activities due today or overdue (lead_name, subject, type, due_date)
- `top_assignees` — users with most Won leads this period

#### Sub-phase 6.2 — Page: crm_dashboard.php
**File:** `app/bms/crm/crm_dashboard.php`

Layout:
- **Row 1** — 6 KPI cards: Total Leads | New This Period | Pipeline Value | Conversion Rate | Activities Today | Overdue
- **Row 2** — 2 charts side by side: Leads by Stage (doughnut left) | Leads by Source (bar right)
- **Row 3** — 1 chart full-width: Monthly Pipeline (line — leads created + won per month)
- **Row 4** — 2 tables side by side: Recent Leads (left) | Due Activities (right — highlighted red if overdue)
- Period selector (dropdown: This Month / Last Month / This Year / All Time) triggers AJAX refresh of all data
- All charts use Chart.js (already in BMS)
- Mobile: all cards stack, charts stack full-width

**Phase 6 done when:** Dashboard loads with all 6 KPIs, 3 charts, and 2 tables. Period filter updates everything via AJAX.

---

### PHASE 7 — Navigation, Polish & Role Wiring
**Goal:** CRM is visible in the menu for authorised roles, mobile views work, and every role sees only what they should.  
**Depends on:** All previous phases

#### Sub-phase 7.1 — Add CRM to header.php
**File:** `header.php`

Add a new dropdown between "Core" and "Sales":

```php
<?php if(canView('crm_dashboard') || canView('crm_leads') || canView('crm_pipeline')): ?>
<li class="nav-item dropdown">
  <a class="nav-link dropdown-toggle" href="#" id="crmDropdown" role="button" data-bs-toggle="dropdown">
    <i class="bi bi-funnel"></i> CRM
  </a>
  <ul class="dropdown-menu" aria-labelledby="crmDropdown">
    <li><h6 class="dropdown-header">Customer Relations</h6></li>
    <?php if(canView('crm_dashboard')): ?>
    <li><a class="dropdown-item" href="<?= getUrl('crm/crm_dashboard') ?>">
      <i class="bi bi-speedometer2"></i> CRM Dashboard</a></li>
    <?php endif; ?>
    <?php if(canView('crm_leads')): ?>
    <li><a class="dropdown-item" href="<?= getUrl('crm/leads') ?>">
      <i class="bi bi-person-plus"></i> Leads</a></li>
    <?php endif; ?>
    <?php if(canView('crm_pipeline')): ?>
    <li><a class="dropdown-item" href="<?= getUrl('crm/pipeline') ?>">
      <i class="bi bi-kanban"></i> Pipeline Board</a></li>
    <?php endif; ?>
  </ul>
</li>
<?php endif; ?>
```

#### Sub-phase 7.2 — Verify mobile card views
- `leads.php`: confirm card renderer shows Name, Stage badge, Value, Assigned-to, action buttons
- `pipeline.php`: confirm accordion collapse works on < 768px
- `crm_dashboard.php`: confirm cards and charts stack correctly

#### Sub-phase 7.3 — Overdue activity badge on main dashboard
- On the main BMS dashboard (`app/constant/profile/`), add a small "CRM: X activities overdue" alert card that links to the CRM dashboard — visible only to users with `canView('crm_dashboard')`
- Fetches count via a lightweight AJAX call, shows only if count > 0

#### Sub-phase 7.4 — Final QA checklist
Run through every item before marking complete:
- [ ] Super Admin sees all leads regardless of project scope
- [ ] Sales role can Add / Edit / Convert but not Delete
- [ ] Auditor can View all CRM pages but has no Add / Edit / Delete buttons
- [ ] Converted lead cannot be deleted (blocked in `delete_lead.php`)
- [ ] Converting a lead twice is blocked (returns error)
- [ ] Dragging a lead to Won stage does NOT auto-convert — user must explicitly click Convert
- [ ] Lost reason modal appears when dragging to Lost column on pipeline board
- [ ] All CSRF tokens present on every POST form and AJAX request
- [ ] All activity log entries recorded
- [ ] All mobile card views render and action buttons work
- [ ] Export to Excel works and includes all filter options
- [ ] Dashboard period filter refreshes all charts and KPIs correctly

---

## Summary — Phase order and effort estimate

| Phase | What is delivered | Files created | Effort |
|---|---|---|---|
| **1 — Foundation** | DB tables + permissions + seeds | 3 migrations | Small |
| **2 — Leads Core** | Add / edit / delete / list / export | 7 files | Medium |
| **3 — Pipeline Board** | Kanban drag-and-drop + stage management | 5 files | Medium-Large |
| **4 — Lead Detail & Activities** | Full lead page + activity timeline | 6 files | Medium |
| **5 — Conversion** | CRM → Customer + Quotation in one click | 2 files | Small-Medium |
| **6 — CRM Dashboard** | KPIs + 4 charts + 2 tables | 2 files | Medium |
| **7 — Nav & Polish** | Menu, mobile QA, overdue badge | 1 edit + QA | Small |

**Total new files: ~26 (3 migrations + 5 pages + 14 APIs + 1 header edit + 3 minor touches)**  
**Zero changes to any existing table** — CRM is purely additive.

---

## Key rules for implementation

1. Every API file: auth check → permission check → CSRF check → validate → business logic → `logActivity()` → return JSON
2. Every page file: `ob_start()` → `header.php` → permission check → data fetch → HTML → `footer.php`
3. Code prefix for leads: `LEAD-` zero-padded to 5 digits (e.g. LEAD-00001)
4. Soft delete everywhere — never `DELETE FROM`
5. `scopeFilterSqlNullable('project', 'cl')` on all lead queries for non-admins
6. All charts: Chart.js only (already loaded in BMS)
7. Kanban drag: SortableJS from CDN (no npm, no build step needed)
8. All selects with > 10 options: Select2 (already loaded in BMS)
9. No direct URL paths — always `getUrl()` for links, `buildUrl()` for AJAX
10. Icons: Bootstrap Icons only (`bi bi-*`)
