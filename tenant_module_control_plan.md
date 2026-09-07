# Tenant Module Control & Self-Service Upgrade — Plan

## 0. Revision note (owner feedback, incorporated)

Three corrections from the owner, applied throughout this revision:

1. **`loans` dropped from Phase A entirely.** BJP Technologies does not run a
   loan product through this system — gating a module that isn't real business
   is not "professional," it's noise. `loans`/`loan_documents` stay exactly as
   they are (ungated) until/unless that changes for a real reason.
2. **The "sell POS only" framing was too narrow.** Any single module — POS,
   Projects, Tenders, AI Assistant, Warehouses, whatever — should be sellable
   alone, or in combination. The system already supports this generically
   (it's one `plans.feature_keys` set); the fix below is about the modules
   that *cannot* actually stand alone, not about picking a favourite example.
3. **Module dependencies must be modelled, not assumed.** The owner's exact
   example — Sub-Contractors cannot be separated from Suppliers because a
   Project's own page shows Sub-Contractor and Supplier data inline — pointed
   at a real, verified gap in the *already-shipped* Phase 11 wiring (§4.2 below).
   This revision adds a proper dependency layer instead of shipping Phase A/B
   without it.

## 1. Why this plan exists

The owner asked: can BMS grant a specific company (tenant) only specific
modules and hide everything else — e.g. sell "POS only" — and can the company
see what else is available and request it, with a superadmin approving per
policy? The short answer is BMS already has a genuinely professional
entitlement engine for most of this (see `ternant.md` Phases 11/12 and
`core/feature_registry.php`, `core/plans.php`, `core/tenant_admin.php`,
`app/superadmin/{plans,features,tenant_view}.php`). This plan closes the three
real gaps found while auditing it, in increasing order of size:

- **Phase A** — several whole modules were never wired into the entitlement
  system at all (they run for every tenant regardless of plan). Fix the
  registry, not the enforcement.
- **Phase B** — tenant creation has no plan-selection step, so a brand-new
  company always starts with every module on and a superadmin must remember to
  apply a plan as a separate action afterward.
- **Phase C** — genuinely new: let a tenant's own admin see what modules exist
  beyond their current plan and request one, with a superadmin approval queue.

Nothing here replaces or duplicates the existing enforcement
(`bmsFeatureBlockingPath()`, `bmsFeatureGuardPath()`, the entitlement-before-
permission check order). Every phase is additive on top of it, exactly the
discipline `core/plans.php` itself documents: *"Deliberately NOT a new
enforcement mechanism."*

## 2. How the existing system works (for context — no changes here)

- **Two independent axes, checked in this order:** entitlement (does the
  company's subscription include this module — platform decides, lives in the
  control DB the tenant cannot reach even as `isAdmin()`) → permission (of
  what the company has, may *this* role touch it — `canView()` and friends).
- **Registry** (`core/feature_registry.php`): one entry per switchable module,
  each declaring its `page_keys` (drives `canView()`/nav) and `paths`
  (`api/`/`ajax/` prefixes the router never sees).
- **Enforcement, defense-in-depth:** the permission layer checks entitlement
  before its `isAdmin()` bypass; a separate path-based bootstrap guard catches
  direct `api/`/`ajax/` hits; a blocked module 404s (never 403 — never reveals
  the module exists); the whole system **fails open** if the control DB is
  briefly unreachable.
- **Per-tenant control:** `tenant_features` override rows
  (`setTenantFeatures()`, delta-only writes, audited); reusable named bundles
  (`plans` + `plan_features`, `applyPlanToTenant()` = one-click apply of a
  feature set *and* quotas).
- **Superadmin UI, already built:** `app/superadmin/tenant_view.php` (per-module
  toggle switches + "Apply plan" dropdown), `app/superadmin/plans.php` (CRUD
  plans), `app/superadmin/features.php` (platform-wide catalogue).

**This already lets you sell any single module today** — not only POS: a
plan with just `pos` checked, or just `tenders`, or just `ai_assistant`, or
`projects` + whatever it needs (§4.2). Create the plan, apply it to the
tenant. Every other switchable module 404s for every user in that company,
including their own admin.

## 3. Audit findings — Phase A's justification

Ran every distinct `page_key` in the live `permissions` table against
`featureForPageKey()`. Result, grouped by `permissions.module_name`:

| module_name (existing grouping — cosmetic only, not gating) | Gated today? |
|---|---|
| Sales, Procurement, Tenders, Human Resources, Operations (assets/projects), Inventory (warehouses/locations), Settings→pos_config_settings, Documents→e_signatures | ✅ yes — via `sales`/`procurement`/`tenders`/`hr`/`assets`/`projects`/`warehouses`/`pos`/`esignature` |
| Core, Customers, Inventory & Products→products, Finance, Reports, Settings (the rest), System Settings, Documents (the rest) | ✅ **deliberately** always-on — the documented base set a company must always keep (invoicing, own ledger, own staff access) |
| **CRM** (`crm_dashboard`, `crm_leads`, `crm_pipeline`, `crm_activities`, `crm_convert`) | ❌ **ungated** |
| **Marketing & CRM** (`campaign_management`, `crm_bulk`, `crm_import`, `crm_labels`, `crm_reports`, `customer_feedback`, `lead_generation`) | ❌ **ungated** |
| **Communication** (`message_center`, `notification_center`, `sms_alerts`, `payment_reminders`, `collection_letters`) | ❌ **ungated** |
| **Compliance** (`compliance`, `compliance_documents`, `compliance_report`) | ❌ **ungated** |

`loans`/`loan_documents` are deliberately **excluded** from this plan — not a
real product line for this business, so not worth gating (owner feedback).

None of the three groups above are in the existing "always-on base set" test
(`tests/test_feature_registry_cli.php` §2) either — confirming they were
overlooked when built as their own projects, not intentionally exempted.

## 4. Phase A — Register the missing modules + model real dependencies

### 4.1 New feature keys (safe, no behavior change until flipped)

| key | label | page_keys | notes |
|---|---|---|---|
| `crm` | CRM & Marketing | `crm_dashboard, crm_leads, crm_pipeline, crm_activities, crm_convert, campaign_management, crm_bulk, crm_import, crm_labels, crm_reports, customer_feedback, lead_generation` | folds Marketing & CRM in — one sellable unit, matching how `hr` already folds several `module_name` groups into one feature |
| `communication` | Messaging & Reminders | `message_center, notification_center, sms_alerts, payment_reminders, collection_letters` | `notification_center` is the in-app bell, not the always-on system alerts — confirm during build that disabling it degrades gracefully (hide the bell icon, not break header.php) |
| `compliance` | Compliance | `compliance, compliance_documents, compliance_report` | |

`'default' => true` on every new key, matching every existing key —
**existing tenants see zero behavior change** the moment this ships (every
control-DB row seeds `default_enabled = 1` via `INSERT IGNORE`, so nothing
currently running is switched off). This only makes these modules *available*
to switch off going forward, via the exact same superadmin UI that already
handles the other nine.

**Also add `paths` entries** for each (mirroring the `pos`/`tenders` pattern —
files, not mixed directories) so `api/crm/*`, the real communication-endpoint
prefix (confirm during build), and compliance endpoints are covered by the
bootstrap guard, not just the page router.

### 4.2 Module dependency graph — verified in code, not assumed

Went through the actual pages behind each *currently switchable* feature to
find real, hard functional couplings — same method the owner's own example
used (Sub-Contractors ↔ Suppliers). Confirmed by reading the code (not
inferred from naming):

| Dependent feature | Hard-depends on | Evidence |
|---|---|---|
| `projects` | `procurement` | `project_view.php` directly queries and displays `sub_contractors`/`suppliers` (joined via `sub_contractor_projects`/`supplier_projects`) as an inline, required part of a project's own page — not optional data |
| `sales` | `warehouses` | `sales_order_create.php`: `warehouse_id` is a `required` form field — a Sales Order cannot be created without one |
| `procurement` | `warehouses` | `grn_create.php`: `warehouse_id` is a `required` form field — a GRN cannot be created without one |
| `pos` | `warehouses` | `pos.php` filters sellable stock through `userCan('warehouse', ...)` — POS sells *from* a warehouse |
| `sub_contractors` (nav item, no own page_key) | `suppliers`/`procurement` | already true today — `sub_contractors.php` gates itself with `canView('suppliers')` directly, not its own permission. The bug: its **path** is currently owned by the `projects` feature in the registry, not `procurement` — so with Procurement off and Projects on, the page's request passes the path guard, then the page itself denies access via `canView('suppliers')`. Confusing half-broken state, not a clean 404. **Fix as part of 4.1**: move sub-contractor paths' feature ownership question to be resolved by the dependency graph, not left inconsistent. |

**Verified and ruled out, so not added above:** `tenders` → `projects` is a
soft, optional cross-link only (`tender_view.php` shows a "View Project" line
*if* the tender was awarded and a project happened to get created from it —
guarded, never required; a tender works fully standalone). `hr` →
`process_project_payroll.php` is an *optional sub-feature* of payroll (project-
cost-allocated payroll) — ordinary payroll works with zero Projects awareness;
without Projects the project picker is simply empty, not broken. Neither
degrades to a dead-end the way the four dependencies above do.

`warehouses` turns out to be a near-universal prerequisite (three of the
other switchable modules break without it). That is itself useful
information for how Plans get built (§4.3) — it does not need to become
part of the always-on baseline; the dependency mechanism below handles it
generically.

### 4.3 The mechanism — how professional platforms handle this (Odoo's `depends`
is the closest direct reference: an app manifest declares its prerequisites;
installing it auto-installs them; you cannot remove a module something else
still depends on)

1. **Registry format gets a `depends_on` key**, code-curated exactly like
   `page_keys`/`paths`:
   ```php
   'projects' => [
       ...
       'depends_on' => ['procurement'],
   ],
   ```
2. **`setTenantFeatures()`** (the one function every write path already goes
   through) enforces both directions in the same call:
   - Enabling a feature auto-includes everything in its `depends_on` chain
     (transitively) rather than silently leaving a half-working module —
     matches Odoo's "installing an app installs its dependencies" behaviour.
   - Disabling a feature that something *else* currently-enabled depends on
     is **rejected with a clear error** naming the dependent
     ("Cannot disable Warehouses — Sales and Procurement depend on it.
     Disable those first."), not silently allowed into a broken state.
3. **`createPlan()`/`updatePlan()`** validate the same way at save time — a
   plan can never be saved in a dependency-broken state, so a superadmin
   building "Projects Only" gets told at creation time that Procurement (and
   therefore Warehouses) come with it, rather than finding out from a support
   ticket later.
4. **`applyPlanToTenant()`** needs no change — it already just calls
   `setTenantFeatures()`, which now carries the enforcement.

This closes the exact gap the owner's Sub-Contractors example pointed at, and
does it as a general mechanism rather than a one-off patch for that one pair.

**Test:** extend `tests/test_feature_registry_cli.php` with (a) the reverse
coverage check this audit ran by hand — every `permissions.page_key` belongs
to a feature OR is in the documented always-on list, so this class of gap
can't silently reappear — and (b) the dependency enforcement itself: enabling
`projects` alone auto-includes `procurement` + `warehouses`; disabling
`warehouses` while `sales` is on is rejected with a message naming `sales`.

## 5. Phase B — Plan selection at tenant creation

`app/superadmin/tenant_new.php` currently has no plan/feature step — every new
tenant starts with everything on, and a superadmin must remember to apply a
plan as a *separate* action right after creation (a window where the company
briefly has every module, and an easy step to forget).

Add an optional "Starting plan" dropdown (same `listPlans(activeOnly: true)`
data `tenant_view.php` already uses) to the creation form. Any single-module
or multi-module plan works the same way here — "POS Only," "Projects +
Procurement," "Tenders Only," "Everything except HR," whatever the superadmin
built in Phase A's plan editor. If selected, call `applyPlanToTenant()`
immediately after the tenant row + admin user are created — same function
`tenant_view.php` already calls, no new write path, and it now carries the
§4.3 dependency enforcement, so an invalid plan can't even be applied here.
Leaving it blank keeps today's behavior exactly (everything on, apply a plan
later if needed).

**Test:** extend whatever CLI test covers tenant provisioning today (find and
confirm which one — likely under the multi-tenancy phase tests) with: creating
a tenant with a plan selected applies that plan's exact feature set
immediately (no gap where every module was briefly reachable).

### 5.1 Self-registration's default — a platform switch, not a forced choice

`register.php` → `registerTenant()` is the OTHER way a tenant gets created —
a prospective customer signing up themselves, with nobody at BJP Technologies
involved yet. Today it calls `provisionTenant()` with `status: 'active'` and
no plan at all, so a self-registered company gets exactly the same thing a
manually-created one used to: everything on.

Owner's decision: **both starting states stay available, chosen by the
superadmin as a platform-wide switch — not by the registering company, and
not hard-coded to one behavior:**

- **Switch OFF (default — today's behavior, unchanged):** self-registration
  gets everything on; a superadmin reduces afterward via `tenant_view.php`
  exactly as now.
- **Switch ON:** self-registration gets **nothing but the always-on
  baseline** (dashboard, customers, products, finance, reports, settings —
  the same base set §3 already documents); every switchable module starts
  off. Modules are then granted one at a time — either the superadmin adds
  them proactively from `tenant_view.php`, or the company asks via Phase C's
  request flow and the superadmin approves each one.

**Implementation — reuses the Phase A/B mechanism, adds no new enforcement:**
1. One new `platform_settings` row (control DB — the table already exists,
   used exactly like `get_setting()`/`save_setting()` on the tenant side),
   e.g. `tenant_default_provisioning = 'all' | 'none'`. A toggle in
   `app/superadmin/features.php` (or wherever platform settings already live)
   flips it.
2. A reserved **"Blank" plan** (`plan_key = 'blank'`, zero `plan_features`
   rows) — created once, likely via the same seed step that already
   `INSERT IGNORE`s the feature catalogue, so it always exists and can't be
   deleted (mirrors how `is_active`/soft-retire already protects a plan a
   tenant is currently on, per `core/plans.php`). Not shown as a
   superadmin-assignable option elsewhere — it exists to represent "nothing,"
   not to be picked from `tenant_view.php`'s dropdown for an existing company.
3. `registerTenant()` reads the switch: if `'none'`, calls the exact same
   `applyPlanToTenant()` with the Blank plan's id right after provisioning —
   same call Phase B already wires in elsewhere, no new write path. If
   `'all'` (default), does nothing further — today's behavior, byte-identical.

Nothing here changes `tenant_new.php` (Phase B) — a superadmin creating a
tenant by hand always explicitly picks a plan (or leaves it blank for "all"),
regardless of this switch. The switch only governs the *unattended* path,
where nobody at BJP is in the loop yet.

**Test:** extend the same provisioning test as Phase B — self-registration
with the switch on `'all'` behaves byte-identical to today; with it on
`'none'`, the new tenant has zero switchable modules and the always-on
baseline still works (can still log in, see dashboard, invoice); flipping the
switch mid-operation never touches any tenant that already exists.

## 6. Phase C — Tenant-facing module visibility + self-service request

Genuinely new. Three pieces, all reusing existing infrastructure:

**6.1 Schema (control DB, via `scripts/setup_control_db.php`, matching how
`plans`/`plan_features` were added):**
```sql
CREATE TABLE feature_upgrade_requests (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id     INT NOT NULL,
    feature_key   VARCHAR(64) NOT NULL,
    requested_by  INT NOT NULL,        -- the tenant user_id who asked
    note          VARCHAR(500) NULL,
    status        ENUM('pending','approved','declined') NOT NULL DEFAULT 'pending',
    decided_by    INT NULL,            -- superadmin user id
    decided_at    DATETIME NULL,
    decision_note VARCHAR(500) NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_tenant_status (tenant_id, status)
);
```
One open request per (tenant, feature_key) at a time — a second request while
one is pending updates the note rather than creating a duplicate row (same
"nothing invented, nothing duplicated" discipline as `setTenantFeatures()`).

**6.2 Tenant-facing page** (Settings area, e.g. `app/constant/settings/available_modules.php`,
gated only by "must be this tenant's admin" — it is inherently platform-facing
info, not a permission-table page_key): lists every registry entry with its
`label`/`description`, marked Active (they have it) or Available (they don't).
Each Available card also shows **"Requires: X, Y"** when the module has
entries in `depends_on` the tenant doesn't already have (§4.3) — so a company
sees up front that requesting Projects brings Procurement and Warehouses with
it, exactly the awareness the owner asked for, before they ever click Request.
The catalogue itself is not secret — only current entitlement is gated — so
this never needs `bmsFeatureGuardPath()` involvement. "Request this module"
button on each Available card records the FULL dependency-closed set (the
module plus anything unmet in its `depends_on` chain) in one request, posts to
a new `api/request_module_access.php` (standard template: auth check, CSRF,
insert-or-update-note into `feature_upgrade_requests`, `logActivity`).

**6.3 Superadmin requests inbox**: either its own
`app/superadmin/module_requests.php`, or a filtered section already surfaced
on `tenants.php`/`tenant_view.php` (simpler — no new nav entry, request count
badge on the tenant's row). Approve calls the *existing* `setTenantFeatures()`
for that one key (nothing new to enforce); Decline just records a reason.
Both logged via `logTenantAdminAction()`, matching every other action in this
system.

**6.4 Notifications — both directions are required, neither is optional**
(owner feedback: the tenant admin must be aware of what happened to their own
request, and the superadmin must be told something needs their attention —
this is not a "nice to have" add-on, it's the point of a self-service request
flow). Both reuse the existing `core/notify.php` `dispatchEvent()` engine —
the same one behind `notifyUnfamiliarLogin()`/`notifyConcurrentLogin()` in
`core/session_tracker.php` — at the same `'severity' => 'high'` level those
use, so a module request reads with the same urgency in the Notification
Center as any other thing that genuinely needs a human to look at it:
- **New request → superadmins get notified immediately**, not discovered by
  chance next time someone opens the requests inbox. Platform-level
  recipients, not tenant-scoped ones, since this request lives in the control
  DB outside any tenant database — confirm during build exactly how the
  existing engine resolves that recipient list, or add the platform-level
  path if it doesn't have one yet.
- **Decision made (approved OR declined) → the requesting tenant admin is
  notified**, with the decision note if one was given, via `dispatchEvent()`
  inside the TENANT's own database connection this time — a normal in-app
  notification + email, exactly like every other event that tenant's users
  already get.
- A request sitting **pending past a reasonable window** (confirm the right
  threshold with the owner — e.g. 48-72 hours) re-notifies superadmins once,
  the same "don't let it go silent" discipline `expireIdleSessions()` and the
  daily HR/document-expiry checks already apply elsewhere in this codebase.

**Test:** a new `tests/test_module_upgrade_requests_cli.php` — request
creation, duplicate-request-updates-note behavior, approve flips the real
feature switch (verified via `tenantFeatureEnabled()`), decline never touches
entitlement, both notification paths fire.

## 7. Rollout order & risk

1. **Phase A** — lowest risk, purely additive to a code array + one seed
   `INSERT IGNORE`. Ship first, alone.
2. **Phase B (+5.1)** — small, contained to tenant creation and self-
   registration; no risk to any existing tenant, and the provisioning switch
   defaults to today's exact behavior.
3. **Phase C** — the real feature work; naturally split into its own
   sub-commits (schema → tenant page → superadmin inbox → notifications), each
   independently testable, matching how this repo's other multi-phase projects
   (`ternant.md`, `tender.md`, `employee.md`) were built and tested end to end
   before merging.

Each phase gets its own branch off `develop`, its own test file, and — per
standing process — a go-ahead check-in before code, not after.
