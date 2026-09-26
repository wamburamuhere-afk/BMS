# BMS — Mobile Money Agent Management Module — Implementation Plan

**Feature key:** `mobile_money`
**Location:** `app/bms/mobile_money/` (peer to `pos/`, `crm/`, `sales/`)
**Superadmin toggle:** off by default — enabled per tenant from `superadmin/features.php`
**Grounded in environment inspection (2026-09-26).** Every helper, table, hook,
and file referenced below was verified to exist in the current repo before this
plan was written.

---

## Terminology — What Every Piece Is Called

This section is **mandatory reading before writing a single UI label**.
The DB column names and BMS generic terms are internal — the MM module has its
own language that must be used consistently across every page, modal, button,
report heading, receipt, notification, and error message.

### Core naming rules

| BMS Generic / DB Name | MM UI Label (what the user sees) | Notes |
|---|---|---|
| Warehouse | **Outlet** or **Agent Outlet** | Never show the word "Warehouse" inside any MM page |
| `mm_agents` row | **Agent** / **Outlet** (Wakala) | The physical business location |
| `mm_tills` row | **Till** | One SIM/line per network per outlet |
| `mm_networks` row | **Network** | M-Pesa, Airtel Money, Tigo Pesa, HaloPesa, T-Pesa |
| `mm_shifts` row | **Teller Shift** | Never "cash register shift" or "POS shift" |
| Z-Report (POS term) | **Teller Shift Report** | The shift summary printed at close |
| `users` (operating a till) | **Teller** | The person recording transactions |
| `mm_float_movements` | **Float Movement** | Top-up, withdrawal, opening balance, adjustment |
| Float top-up | **Float Top-Up** | Agent deposits cash → bank credits e-float |
| Float withdrawal | **Float Withdrawal** | Agent returns e-float → gets cash from bank |
| E-float balance | **Network Float Balance** (e.g. "M-Pesa Float") | Per network, per till |
| Cash position | **Cash on Hand** | Physical cash in the till |
| `mm_reconciliations` | **Daily Reconciliation** | End-of-day float + cash check |
| Bank reconciliation (Finance term) | **Network Reconciliation** | The MM equivalent — never reuse Finance's term |
| Network CSV export | **Network Statement** | The downloaded file from M-Pesa/Airtel portal |
| `mm_commissions_received` | **Commission Received** | When network credits the agent |
| `commission_earned` column | **Commission Earned** | Computed per transaction |
| Walk-in customer | **Customer** | Phone number only — no customer account needed |
| `mm_kyc_records` | **KYC Record** | Identity verification for large transactions |
| BOT threshold | **Large Transaction Limit** (TZS 1,000,000) | Used in all user-facing messages |
| Suspicious flag | **Flagged Transaction** | Never "suspicious" in the customer-facing receipt |

### Label rule: Outlet ≠ Warehouse

The word **"warehouse"** must **never appear** on any MM-facing screen, label,
placeholder, tooltip, or error message — even when the Agent is optionally linked
to a `warehouses` row in the DB. The link is internal plumbing only.

Correct: `Select Outlet`, `Outlet: Kariakoo Branch`, `Add New Outlet`
Wrong: `Select Warehouse`, `Warehouse: Kariakoo Branch`

### Status badges (follow `.claude/ui-constants.md` blue scale)

| MM Status | Badge label | Color |
|---|---|---|
| Transaction recorded but not yet posted | `Pending` | `#e9ecef / #495057` |
| Transaction posted to GL | `Posted` | `#052c65 / #fff` |
| Transaction voided | `Void` | `#dc3545 / #fff` |
| Shift open | `Open` | `#0d6efd / #fff` |
| Shift closed | `Closed` | `#052c65 / #fff` |
| Reconciliation open | `Open` | `#0d6efd / #fff` |
| Reconciliation resolved | `Resolved` | `#052c65 / #fff` |
| Reconciliation disputed | `Disputed` | `#dc3545 / #fff` |
| Float OK | `OK` | `#0d6efd / #fff` |
| Float low | `Low Float` | `#dc3545 / #fff` |

---

## Receipt Specification

### Receipt Type 1 — Customer Transaction Receipt

Printed (TCPDF) or displayed on screen after each transaction.
**Rule: commission is NEVER shown to the customer.**

Fields to include:

```
┌─────────────────────────────────────────┐
│  [Company Logo if set]                  │
│  [Agent Name]                           │
│  [Agent Address / Location]             │
│  Network: [Network Name]                │
│  Till:    [Till Number / MSISDN]        │
├─────────────────────────────────────────┤
│  TRANSACTION RECEIPT                    │
├─────────────────────────────────────────┤
│  Type:        [Transaction Type]        │
│  Amount:      TZS [principal_amount]    │
│  Customer:    [customer_phone]          │
│  Customer:    [customer_name if given]  │
│  Ref No:      [reference_no]            │
│  Date:        [txn_date]                │
│  Time:        [txn_time]                │
├─────────────────────────────────────────┤
│  Teller:      [teller name]             │
│  Receipt No:  [txn_code]               │
├─────────────────────────────────────────┤
│  Asante kwa kutumia huduma yetu!        │
│  Thank you for using our service!       │
└─────────────────────────────────────────┘
```

Fields **NOT** on customer receipt:
- `commission_earned` — internal only
- `cash_effect` / `float_effect` — internal only
- `journal_entry_id` — internal only
- Any GL account names or codes

### Receipt Type 2 — Teller Shift Report (internal)

Printed at shift close. Equivalent to POS Z-report.
Accessible only to the teller (own shift) or a user with `canEdit('mm_transactions')`.

Sections:

```
┌─────────────────────────────────────────┐
│  TELLER SHIFT REPORT                    │
│  [Agent Name] — [Till Number]           │
│  Network: [Network Name]                │
├─────────────────────────────────────────┤
│  Teller:       [name]                   │
│  Shift Code:   [shift_code]             │
│  Opened:       [opened_at]              │
│  Closed:       [closed_at]              │
├─────────────────────────────────────────┤
│  CASH SUMMARY                           │
│  Opening Cash:      TZS [opening_cash]  │
│  + Cash In txns:    TZS [sum]           │
│  - Cash Out txns:   TZS [sum]           │
│  = Expected Cash:   TZS [expected_cash] │
│  Actual Cash:       TZS [closing_cash]  │
│  Variance:          TZS [cash_variance] │
├─────────────────────────────────────────┤
│  FLOAT SUMMARY                          │
│  Opening Float:     TZS [opening_float] │
│  - Float given out: TZS [sum]           │
│  + Float received:  TZS [sum]           │
│  = Expected Float:  TZS [expected_float]│
│  Actual Float:      TZS [closing_float] │
│  Variance:          TZS [float_variance]│
├─────────────────────────────────────────┤
│  TRANSACTION BREAKDOWN                  │
│  Cash In:    [count]  TZS [total]       │
│  Cash Out:   [count]  TZS [total]       │
│  Send Money: [count]  TZS [total]       │
│  Bill Pay:   [count]  TZS [total]       │
│  Airtime:    [count]  TZS [total]       │
│  Other:      [count]  TZS [total]       │
│  ─────────────────────────────────────  │
│  TOTAL:      [count]  TZS [total]       │
├─────────────────────────────────────────┤
│  COMMISSION EARNED THIS SHIFT           │
│  TZS [sum of commission_earned]         │
├─────────────────────────────────────────┤
│  VOIDS THIS SHIFT: [count]              │
└─────────────────────────────────────────┘
```

### Receipt Type 3 — Float Movement Receipt

Printed when recording a float top-up or withdrawal.

```
┌─────────────────────────────────────────┐
│  FLOAT MOVEMENT                         │
│  [Agent Name] — [Till Number]           │
├─────────────────────────────────────────┤
│  Type:        [Float Top-Up / Withdrawal]
│  Network:     [Network Name]            │
│  Amount:      TZS [amount]              │
│  Bank:        [bank_account_name]       │
│  Ref No:      [reference_no]            │
│  Movement No: [movement_code]           │
│  Date:        [movement_date]           │
│  Recorded by: [user name]               │
└─────────────────────────────────────────┘
```

---

## Zero-Break Guarantee — Why Nothing Else Breaks

This module is **entirely additive**. It ships in its own directory, its own
tables (`mm_*`), its own permission keys (`mm_*`), and its own feature-registry
entry. Nothing existing is edited except **six precisely-scoped, additive edits**
listed in §0.5 below (a new feature-registry entry, a new `MOBILE_MONEY_DIR`
constant, new route slugs, a new `gl_source.php` route entry, six new nav `<li>`
blocks in `header.php`, and a new `journal_mappings` seed). Every one of those
six additions is an INSERT / new array entry — never a modification of existing
logic.

The four proven guardrails that keep this safe:

| Guardrail | Where | What it protects |
|---|---|---|
| **Entitlement layer runs BEFORE `isAdmin()` bypass** | `core/feature_registry.php` → `tenantModuleAllowsPage()` | A tenant with MM off never sees MM even as admin — no leak into other tenants |
| **Ledger posting is idempotent on (entity_type, entity_id)** | `core/ledger_post.php` `postLedgerEntry()` | Reposting a MM transaction can never double-count |
| **`postLedgerEntry()` throws on unbalanced Dr/Cr** | same file | A malformed MM posting is refused, never a half-write |
| **Pre-push hook enforces scope helpers** | `.git/hooks/pre-push` | Every new MM file that queries a scoped table is checked or carries `// scope-audit: skip` with a written reason |

---

## Self-Contained Module Guarantee

The tenant may enable Mobile Money **without** enabling any other optional
feature. To make that real:

- `depends_on: []` — no dependency on `warehouses`, `pos`, `finance`, `hr`.
- MM's own dashboard, transactions, float, commissions, reconciliation, reports
  live under `mm_*` permission keys — no borrowing from other modules' keys.
- The GL posting resolves its accounts via the existing three-step pattern
  (`system_settings` → `journal_mappings` → sub-type/code fallback) and, if
  none of those resolve, uses **auto-provisioned** MM accounts seeded on
  Phase 1 install — so a fresh tenant can post an MM transaction on Day 1
  without having to configure the chart of accounts.
- Every report on the MM dashboard is computed **from `mm_transactions` + the
  posted ledger**, never from any table owned by another module. No sales
  invoice, no purchase order, no HR record is ever read.

---

## Verified Integration Points (from the environment)

| Piece | File | How MM uses it |
|---|---|---|
| Feature registry | `core/feature_registry.php` | Add `mobile_money` entry (§0.5.1) |
| Superadmin toggle UI | `app/superadmin/features.php` | Reads from control DB; `syncFeatureCatalogue()` picks up the new entry automatically on deploy |
| Nav guarding | `header.php` (1773 lines) — pattern `<?php if(canView('key') && tenantFeatureEnabled('mobile_money')): ?>` | Add MM top-level menu (§10.1) |
| URL routing | `roots.php` line 604+ — associative slug → file map | Add `MOBILE_MONEY_DIR` constant (line ~59) + MM slug entries (§0.5.2) |
| Permission page_keys | `permissions` table | 9 new page_key rows via tenant migration (§0.3) |
| Ledger posting | `core/ledger_post.php` — `postLedgerEntry($pdo, $desc, $lines, $project_id, $entity_id, $entity_type, $date, $user_id, $warehouse_id): int` | Every MM txn calls this with `entity_type = 'mm_transaction'` (§2.1) |
| Source drill-down | `core/gl_source.php` — `gl_source_routes()` | Add `'mm_transaction' => ['mm_transaction_view', 'MM Transaction']` (§0.5.3) |
| GL account resolution | `core/gl_accounts.php` — `gl_setting_account()`, `gl_mapping_account()`, `gl_first_leaf_by_subtype()` | Wrapped in new `mm_resolve_account()` (§2.1) |
| Code generation | `core/code_generator.php` — `nextCode($pdo, 'TYPE')` | `MM-TXN`, `MM-AGT`, `MM-TOP`, `MM-REC` (§0.3) |
| Notifications | `core/notify.php` — `dispatchEvent()` | Low-float alerts, reconciliation-due alerts (§4.4) |
| Warehouse scope | `journal_entries.warehouse_id` populated by `postLedgerEntry()` | MM entries default to `NULL` (company-wide); optional link if tenant maps agent→warehouse (§2.1) |
| UI standards | `.claude/ui-constants.md` | Blue-only palette, DataTable + Select2 patterns, mobile card view |
| Security scoping | `.claude/security.md §23` — `scopeFilterSqlNullable('project', 'je')` | Applied on every MM report query (§8) |
| Migrations | `migrations/` (legacy) + `migrations/tenant/` (tenant DBs) | Dual-file pattern — every DDL file has two copies (§0.1) |

---

## Database Design

Every table uses `InnoDB`, `utf8mb4_unicode_ci`, singular English column names,
`created_at` / `updated_at`, `created_by` (FK to `users`) — matching the rest
of BMS.

### Tables (11 total)

#### 1. `mm_networks` — the mobile-money providers
```
network_id       INT PK AI
network_code     VARCHAR(20) UNIQUE   -- 'MPESA','AIRTEL','TIGO','HALOTEL','TPESA'
network_name     VARCHAR(80)          -- 'M-Pesa (Vodacom Tanzania)'
provider         VARCHAR(80)          -- Vodacom, Airtel, ...
short_code       VARCHAR(20)          -- USSD short code (*150*00#)
color_hex        VARCHAR(7)           -- brand color for dashboard chips
sort_order       INT
status           ENUM('active','inactive')
created_at, created_by
```
Seed on install with the 5 Tanzanian networks above.

#### 2. `mm_agents` — the outlets (mawakala)
```
agent_id         INT PK AI
agent_code       VARCHAR(40) UNIQUE   -- PREFIX-MM-AGT-0001
agent_name       VARCHAR(120)
outlet_type      ENUM('main','sub','kiosk','shop_in_shop')
warehouse_id     INT NULL FK          -- optional link if tenant uses warehouses
bot_license      VARCHAR(80) NULL     -- BOT Wakala licence
region, district, ward, street
gps_lat, gps_lng DECIMAL(10,7) NULL
manager_user_id  INT NULL FK -> users
phone_primary, phone_alt VARCHAR(20)
opening_date     DATE
low_float_alert_pct  TINYINT DEFAULT 20   -- % of ceiling that triggers alert
status           ENUM('active','suspended','closed')
created_at, updated_at, created_by
```

#### 3. `mm_tills` — one SIM/till per network per agent
```
till_id          INT PK AI
agent_id         INT FK -> mm_agents
network_id       INT FK -> mm_networks
till_number      VARCHAR(40)          -- the paybill/till number
sim_msisdn       VARCHAR(20)          -- SIM phone number
float_ceiling    DECIMAL(15,2)        -- max e-float this till can hold
cash_ceiling     DECIMAL(15,2)        -- max cash before deposit trigger
status           ENUM('active','suspended','closed')
UNIQUE (agent_id, network_id, till_number)
created_at, updated_at, created_by
```

#### 4. `mm_commission_rates` — tariff schedule
```
rate_id          INT PK AI
network_id       INT FK -> mm_networks
txn_type         ENUM('cash_in','cash_out','send','bill_pay','airtime','bank_to_wallet','wallet_to_bank','international')
amount_from      DECIMAL(15,2)
amount_to        DECIMAL(15,2)
rate_type        ENUM('flat','percent')
rate_value       DECIMAL(12,4)        -- flat TZS OR percent (e.g. 0.5 = 0.5%)
min_commission   DECIMAL(12,2) DEFAULT 0
max_commission   DECIMAL(12,2) NULL
effective_from   DATE
effective_to     DATE NULL
status           ENUM('active','superseded')
INDEX (network_id, txn_type, amount_from, effective_from)
```

#### 5. `mm_transactions` — the transaction register (append-only)
```
mm_txn_id        BIGINT PK AI
txn_code         VARCHAR(40) UNIQUE      -- PREFIX-MM-TXN-0000001
till_id          INT FK -> mm_tills
network_id       INT FK -> mm_networks   -- denormalised for reporting speed
agent_id         INT FK -> mm_agents     -- denormalised for reporting speed
txn_type         ENUM(... same as commission_rates)
txn_date         DATE
txn_time         TIME
customer_phone   VARCHAR(20)
customer_name    VARCHAR(80) NULL
counterparty_phone VARCHAR(20) NULL      -- for send-money
reference_no     VARCHAR(60)             -- the network's own txn ref
principal_amount DECIMAL(15,2)           -- what the customer moves
customer_fee     DECIMAL(12,2) DEFAULT 0 -- what customer paid on top
commission_earned DECIMAL(12,2)          -- our commission
cash_effect      DECIMAL(15,2)           -- +cash in, -cash out
float_effect     DECIMAL(15,2)           -- +float in, -float out
teller_user_id   INT FK -> users
shift_id         INT FK -> mm_shifts NULL
kyc_required     TINYINT(1) DEFAULT 0
kyc_document_id  INT NULL FK -> mm_kyc_records
suspicious_flag  TINYINT(1) DEFAULT 0
notes            TEXT NULL
journal_entry_id INT NULL FK -> journal_entries    -- filled by mm_posting.php
status           ENUM('recorded','posted','void','reversed')
void_reason      TEXT NULL
voided_by, voided_at
created_at, created_by
INDEX (txn_date), INDEX (till_id, txn_date), INDEX (agent_id, txn_date),
INDEX (network_id, txn_type, txn_date)
```

#### 6. `mm_shifts` — teller shift open/close (mirrors POS `cash_register_shifts`)
```
shift_id         INT PK AI
shift_code       VARCHAR(40) UNIQUE      -- PREFIX-MM-SFT-0001
till_id          INT FK -> mm_tills
teller_user_id   INT FK -> users
opened_at        DATETIME
closed_at        DATETIME NULL
opening_cash     DECIMAL(15,2)
opening_float    DECIMAL(15,2)
closing_cash     DECIMAL(15,2) NULL
closing_float    DECIMAL(15,2) NULL
expected_cash    DECIMAL(15,2) NULL      -- computed on close
expected_float   DECIMAL(15,2) NULL      -- computed on close
cash_variance    DECIMAL(15,2) NULL
float_variance   DECIMAL(15,2) NULL
status           ENUM('open','closed','forced_close')
close_notes      TEXT NULL
closed_by        INT NULL FK -> users
created_at
```

#### 7. `mm_float_movements` — top-ups and withdrawals of e-float
```
movement_id      INT PK AI
movement_code    VARCHAR(40) UNIQUE      -- PREFIX-MM-TOP-0001
till_id          INT FK -> mm_tills
movement_type    ENUM('float_topup','float_withdrawal','opening_balance','adjustment')
movement_date    DATE
amount           DECIMAL(15,2)           -- positive
bank_account_id  INT NULL FK -> accounts -- source/destination bank
reference_no     VARCHAR(60)
notes            TEXT NULL
journal_entry_id INT NULL FK -> journal_entries
status           ENUM('draft','posted','void')
created_at, created_by, posted_at, posted_by
```

#### 8. `mm_commissions_received` — periodic commission credits from networks
```
credit_id        INT PK AI
network_id       INT FK -> mm_networks
period_from, period_to DATE
amount_received  DECIMAL(15,2)
bank_account_id  INT FK -> accounts      -- where the money landed
reference_no     VARCHAR(60)
notes            TEXT NULL
journal_entry_id INT NULL FK -> journal_entries
status           ENUM('draft','posted','void')
created_at, created_by
```

#### 9. `mm_reconciliations` — daily EOD reconciliation sessions
```
recon_id         INT PK AI
recon_code       VARCHAR(40) UNIQUE      -- PREFIX-MM-REC-0001
till_id          INT FK -> mm_tills
recon_date       DATE
opening_cash, opening_float DECIMAL(15,2)
computed_cash, computed_float DECIMAL(15,2)   -- system-derived
actual_cash, actual_float DECIMAL(15,2)       -- entered by user
cash_variance, float_variance DECIMAL(15,2)
status           ENUM('open','resolved','disputed','closed')
resolved_notes   TEXT NULL
created_at, created_by, closed_at, closed_by
UNIQUE (till_id, recon_date)
```

#### 10. `mm_recon_items` — line items matched or unmatched to network statement
```
item_id          INT PK AI
recon_id         INT FK -> mm_reconciliations
mm_txn_id        BIGINT NULL FK -> mm_transactions
statement_ref    VARCHAR(60) NULL        -- network's ref for this line
statement_amount DECIMAL(15,2) NULL
match_status     ENUM('matched','unmatched_book','unmatched_stmt','disputed')
notes            TEXT NULL
```

#### 11. `mm_kyc_records` — compliance for large transactions
```
kyc_id           INT PK AI
mm_txn_id        BIGINT FK -> mm_transactions
customer_phone   VARCHAR(20)
customer_name    VARCHAR(120)
id_type          ENUM('nida','voters','passport','driving_licence')
id_number        VARCHAR(40)
id_photo_path    VARCHAR(255) NULL       -- uploads/mm_kyc/ (with .htaccess)
captured_by      INT FK -> users
captured_at      DATETIME
```

### Scope-audit posture

Every MM query is by `agent_id` / `till_id`, which are MM-owned scopes. The
pre-push hook does not know about them, so **every new MM file gets**
`// scope-audit: skip — MM tables are agent-scoped via mm_user_agent_grants (see §3.2)`
at the top. Reports that join to `journal_entries` also apply
`scopeFilterSqlNullable('project', 'je')` from §23.

---

## Phase-by-Phase Plan

Each phase ends with a working, testable increment. Later phases build on
earlier ones; nothing is left half-wired.

---

### PHASE 0 — Foundation (Feature Registration + Migrations + Routing)

**Goal:** After Phase 0 the module exists in the registry, tables exist, routes
resolve, permissions are seeded, and the superadmin toggle appears in the
Modules catalogue. Nothing is user-visible in the nav yet.

#### 0.1 — Migrations (dual: legacy + tenant)

Create six paired files, all named `2026_MM_DD_mm_*.php` and
`2026_MM_DD_mm_*_legacy_db.php`:

| Migration | Content |
|---|---|
| `mm_core_tables` | Creates tables 1–5 above (`mm_networks`, `mm_agents`, `mm_tills`, `mm_commission_rates`, `mm_transactions`) |
| `mm_shift_tables` | Creates `mm_shifts` |
| `mm_float_and_commission_tables` | Creates `mm_float_movements`, `mm_commissions_received` |
| `mm_reconciliation_tables` | Creates `mm_reconciliations`, `mm_recon_items` |
| `mm_compliance_tables` | Creates `mm_kyc_records` |
| `mm_seed_networks_and_rates` | Seeds 5 Tanzanian networks + a starter commission tariff (editable per tenant) |

Every file:
- CLI-only guard (`if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }`)
- Idempotent (`CREATE TABLE IF NOT EXISTS`, `INSERT IGNORE`)
- Legacy variant checks `SHOW TABLES LIKE 'customers'` and skips silently on tenant-only installs (following the `2026_09_23_master_data_offline_sync_legacy_db.php` pattern)

#### 0.2 — Permission page_keys

Migration `mm_permissions.php` (+ legacy variant) — INSERT IGNORE nine rows into
`permissions`:

| page_key | page_name | module_name |
|---|---|---|
| `mm_dashboard` | MM Dashboard | Mobile Money |
| `mm_agents` | MM Agents | Mobile Money |
| `mm_networks` | MM Networks | Mobile Money |
| `mm_transactions` | MM Transactions | Mobile Money |
| `mm_float` | MM Float Management | Mobile Money |
| `mm_commissions` | MM Commissions | Mobile Money |
| `mm_reconciliation` | MM Reconciliation | Mobile Money |
| `mm_reports` | MM Reports | Mobile Money |
| `mm_compliance` | MM Compliance / KYC | Mobile Money |

#### 0.3 — Code sequences

No migration needed — `code_sequences` is created on-demand by
`nextCode()`. But document the four new type tags used:
- `MM-TXN` — transactions
- `MM-AGT` — agents
- `MM-TOP` — float movements (top-ups, withdrawals, opening, adjustments — one shared series is intentional so a single ledger of float events reads sequentially)
- `MM-REC` — reconciliations
- `MM-SFT` — shifts

#### 0.4 — GL journal_mappings seed

Migration `mm_gl_mappings.php` — INSERT IGNORE seven rows into `journal_mappings`
so posting resolves automatically on Day 1:

| event_type | debit_leaf | credit_leaf |
|---|---|---|
| `mm_cash_in` | Cash on Hand (1-1000 first leaf) | E-Float per network (auto-provisioned in §1.5) |
| `mm_cash_out` | E-Float per network | Cash on Hand |
| `mm_bill_payment` | Cash on Hand | E-Float per network |
| `mm_airtime` | Cash on Hand | E-Float per network |
| `mm_float_topup` | E-Float per network | Bank Account (chosen at time of top-up) |
| `mm_float_withdrawal` | Bank Account | E-Float per network |
| `mm_commission_received` | Bank Account | Commission Income - Mobile Money (auto-provisioned) |

Because the mapping is one-per-event but E-Float and Commission Income differ
per network, the actual posting resolver (`core/mm_posting.php`, §2.1) uses
`mm_networks.float_account_id` and `mm_networks.commission_account_id` (added
in §1.5) — the `journal_mappings` seed is a fallback only.

#### 0.5 — The six additive-only edits to existing files

Every one of these is a *new* line/entry — no existing behaviour touched.

**0.5.1** — `core/feature_registry.php`, inside `bmsFeatureRegistry()`:

```php
'mobile_money' => [
    'label'       => 'Mobile Money',
    'description' => 'Manage mobile money agent operations: transactions, float, commissions, teller shifts, daily reconciliation and BOT compliance across M-Pesa, Airtel Money, Tigo Pesa, HaloPesa and T-Pesa.',
    'default'     => false,                   // opt-in, superadmin enables
    'sort_order'  => 25,                      // between 'pos_advanced' (21) and 'restaurant_pos' (22)? -> actually 25 places it after both
    'page_keys'   => [
        'mm_dashboard', 'mm_agents', 'mm_networks', 'mm_transactions',
        'mm_float', 'mm_commissions', 'mm_reconciliation', 'mm_reports',
        'mm_compliance',
    ],
    'depends_on'  => [],                       // fully self-contained
    'paths'       => [
        'app/bms/mobile_money/',
        'api/mobile_money/',
        'core/mm_posting.php',
        'core/mm_float_service.php',
    ],
],
```

**0.5.2** — `roots.php`, alongside other `_DIR` constants (line ~59):
```php
define('MOBILE_MONEY_DIR', BMS_DIR . '/mobile_money');
```
And in the URL slug map (line 604+), add:
```php
'mm_dashboard'          => MOBILE_MONEY_DIR . '/mm_dashboard.php',
'mm_agents'             => MOBILE_MONEY_DIR . '/mm_agents.php',
'mm_agent_view'         => MOBILE_MONEY_DIR . '/mm_agent_view.php',
'mm_networks'           => MOBILE_MONEY_DIR . '/mm_networks.php',
'mm_transactions'       => MOBILE_MONEY_DIR . '/mm_transactions.php',
'mm_transaction_view'   => MOBILE_MONEY_DIR . '/mm_transaction_view.php',
'mm_float'              => MOBILE_MONEY_DIR . '/mm_float.php',
'mm_commissions'        => MOBILE_MONEY_DIR . '/mm_commissions.php',
'mm_commission_rates'   => MOBILE_MONEY_DIR . '/mm_commission_rates.php',
'mm_reconciliation'     => MOBILE_MONEY_DIR . '/mm_reconciliation.php',
'mm_recon_view'         => MOBILE_MONEY_DIR . '/mm_recon_view.php',
'mm_shifts'             => MOBILE_MONEY_DIR . '/mm_shifts.php',
'mm_shift_report'       => MOBILE_MONEY_DIR . '/mm_shift_report.php',
'mm_reports'            => MOBILE_MONEY_DIR . '/mm_reports.php',
'mm_compliance'         => MOBILE_MONEY_DIR . '/mm_compliance.php',
```

**0.5.3** — `core/gl_source.php`, inside `gl_source_routes()`:
```php
'mm_transaction'  => ['mm_transaction_view',  'MM Transaction'],
'mm_float_move'   => ['mm_recon_view',        'MM Float Movement'],
'mm_commission'   => ['mm_commissions',       'MM Commission Received'],
```

**0.5.4** — `header.php` — new top-level menu (nav `<li>` block), inserted
after the CRM block (§10.1 lists the exact HTML).

**0.5.5** — `mm_networks` migration seed adds two nullable FK columns to
`mm_networks` — `float_account_id INT NULL FK -> accounts`,
`commission_account_id INT NULL FK -> accounts` — populated at install-time
provisioning (§1.5).

**0.5.6** — Nothing else. No edit to `postLedgerEntry()`, `financial_reports.php`,
`gl_accounts.php`, `notify.php`, `code_generator.php`, or any other file.

#### 0.6 — Acceptance for Phase 0

- `php migrations/runner.php` runs green on both legacy and tenant DBs.
- `syncFeatureCatalogue()` (already runs on every deploy) picks up the new
  `mobile_money` entry — visible on superadmin `features.php`.
- With MM off (default): every nav item and API path Phase 0.5.4 introduces
  returns 403 / redirects to unauthorized.php for every user, admin included.
- With MM on: every page returns 200 (empty shells for now).
- `assertLedgerBalanced($pdo, date('Y-m-d'))` still passes — no ledger touched.
- **Regression check:** run the full CLI test suite; no new failures compared
  to baseline.

---

### PHASE 1 — Setup / Master Data

**Goal:** A tenant with MM freshly enabled can register networks, agents,
tills and commission rates in under 10 minutes and start recording.

#### 1.1 — Networks page (`mm_networks.php`)

- DataTable of the 5 seeded networks (editable brand fields only — `network_code` is locked once used).
- "Configure GL accounts" modal per network — sets `float_account_id` and
  `commission_account_id` from AJAX Select2 backed by `api/search_accounts.php`
  (already exists — Chart-of-Accounts wide search, "CODE — Name" left-code format per user memory `feedback_account_code_left.md`).
- Per-network status toggle (active/inactive).

#### 1.2 — Agents / outlets (`mm_agents.php`, `mm_agent_view.php`)

- List + create/edit modal for outlets.
- `agent_code` auto-generated with `nextCode($pdo, 'MM-AGT')` on save.
- Warehouse link is optional; the Select2 uses `warehousesForSelect()` (exists) if `tenantFeatureEnabled('warehouses')`; hidden otherwise.
- Manager assignment via user Select2 (existing pattern).
- Detail page shows: outlet header, tills tab, teller assignments tab, shift history, transaction summary, reconciliation history, KYC log.

#### 1.3 — Tills (nested tab on Agent detail)

- Add till modal: network Select2, till number, SIM MSISDN, ceilings.
- Deactivating a till hides it from Transactions dropdown but does not delete
  historical rows.

#### 1.4 — Commission rates (`mm_commission_rates.php`)

- One row per (network, txn_type, amount_from–amount_to, effective_from).
- Bulk import CSV button — a starter TZS tariff is included in the seed migration; user overrides per tenant.
- Rate change creates a new row with `effective_from = today` and marks the previous row `superseded` (`effective_to = today - 1`) — never edits in place. This preserves historical commission accuracy.

#### 1.5 — Auto-provisioning of MM GL accounts on first enable

A one-time bootstrap runs when a tenant first enables MM (called from the
superadmin toggle handler OR lazily on first visit to `mm_dashboard.php`,
whichever fires first — idempotent). It:

1. Reads Chart of Accounts.
2. For each active network, if `network.float_account_id` is NULL:
   - Creates leaf account `1-4100 + N` "M-Pesa Float" (etc.) under the "Cash and Cash Equivalents" sub-type; skips if same-named leaf already exists.
   - Stores its id in `mm_networks.float_account_id`.
3. Ensures one "Commission Income - Mobile Money" leaf exists under "Other Operating Income" and stores its id in every network's `commission_account_id` (defaulting to a shared account; the user may split per network from §1.1).
4. Records the provisioning in `activity_log`.

**Why this matters:** it guarantees a fresh tenant can post an MM transaction
on Day 1 without touching Chart of Accounts — the "self-dependent module"
requirement.

#### 1.6 — Acceptance for Phase 1

- Create an agent → confirm code = `<PREFIX>-MM-AGT-0001`.
- Add a till → confirm it appears in transaction till dropdown (empty state
  otherwise says "Add a till on the agent's page first").
- Verify all 5 networks have a `float_account_id` set (either auto-provisioned
  or user-set).
- Every list uses DataTable + mobile card view (per `.claude/ui-constants.md`).

---

### PHASE 2 — Transaction Engine (the core)

**Goal:** A teller can record any of the 8 transaction types; each record posts
a balanced double-entry to `journal_entries`; float balance updates
in real time.

#### 2.1 — `core/mm_posting.php` — the posting engine

Public API (all functions idempotent on `(entity_type='mm_transaction', entity_id)`
because `postLedgerEntry()` itself is):

```php
mm_resolve_account(PDO $pdo, string $eventType, int $networkId, string $side): int
// three-step resolver: mm_networks column -> journal_mappings -> gl_first_leaf_by_subtype -> throws if unresolved

mm_compute_commission(PDO $pdo, int $networkId, string $txnType, float $amount, string $onDate): float
// looks up the active mm_commission_rate row and applies flat/percent + min/max caps

postMMTransaction(PDO $pdo, int $mmTxnId, int $userId): int
// reads mm_transactions row; validates cash_effect + float_effect + commission math;
// composes 2-3 legs (principal + commission on same entry when non-zero);
// calls postLedgerEntry() with entity_type='mm_transaction';
// writes back journal_entry_id and status='posted'.
// warehouse_id = the agent's warehouse if linked, else NULL.

voidMMTransaction(PDO $pdo, int $mmTxnId, int $userId, string $reason): int
// posts a REVERSAL entry (contra) — never DELETE; sets status='void',
// links reverses_entry_id to the original journal_entries.entry_id.
```

The four-leg shape for a cash-in of TZS 100,000 with TZS 800 commission:
```
Dr  Cash on Hand              100,000     (agent gains cash)
Cr  M-Pesa Float              100,000     (agent gives up e-money)
Dr  Cash on Hand                  800     (agent gains cash for fee)
Cr  Commission Income – Mobile Money 800  (agent earns commission)
```
Balanced: Dr 100,800 = Cr 100,800. Two entries or one 4-leg entry — the plan
uses **one 4-leg entry per MM transaction** for a clean audit trail.

#### 2.2 — `core/mm_float_service.php` — real-time float tracker

```php
mm_current_float(PDO $pdo, int $tillId): float
// = opening_balance movement + sum(float_effect on posted mm_transactions)
//   + sum(amount for posted mm_float_movements matching this till, signed by type)

mm_current_cash(PDO $pdo, int $tillId): float
// = opening_cash on active shift + sum(cash_effect on posted mm_transactions in shift)

mm_check_low_float(PDO $pdo, int $tillId): bool
// true if current_float < ceiling * (low_float_alert_pct / 100) — fires notification
```

Both functions cache their result per request in a static array (matching the
`companyCodePrefix()` cache pattern).

#### 2.3 — Transaction form (`mm_transactions.php`)

- Top: filter bar (date range, till, network, type, teller) + summary tiles
  (Today: count / volume / commission).
- Main table: DataTable of `mm_transactions` for the visible scope.
- "New Transaction" modal:
  1. Till Select2 (only tills the user is granted — §3.2 will add scoping).
  2. Type radios with icons (Cash In / Cash Out / Send / Bill / Airtime / …).
  3. Principal amount — commission auto-computes on blur via
     `api/mobile_money/compute_commission.php`.
  4. Customer phone (validated: 10 digits, starts with `0` or `+255`).
  5. Reference no. (network's txn ID — required, unique per till).
  6. Notes.
- Save button: POSTs to `api/mobile_money/save_transaction.php`.

#### 2.4 — `api/mobile_money/save_transaction.php`

- CSRF check (per `§21`), `autoEnforcePermission('mm_transactions')`.
- Server-recomputes commission (never trusts the client value).
- Validates the till has enough float (for cash-out) or is under ceiling (for cash-in).
- All-or-nothing DB transaction:
  1. Insert `mm_transactions` row (status='recorded').
  2. Call `postMMTransaction()` (posts to GL, updates status='posted').
  3. Return `{success, mm_txn_id, txn_code, journal_entry_id}`.
- On any exception: rollback + JSON error.

#### 2.5 — Transaction detail view (`mm_transaction_view.php`)

- Header: txn_code, status, teller, timestamp.
- Facts panel: principal, fee, commission, cash effect, float effect.
- GL panel: link to the linked `journal_entries` (via `gl_source_link()` — works
  because we registered `mm_transaction` in §0.5.3).
- KYC card if flagged.
- Actions: Print receipt (TCPDF), Void (with reason).

#### 2.6 — Acceptance for Phase 2

- Record 5 transactions of different types on the same till → verify all 5 are
  in `journal_entries` with `status='posted'` and `entity_type='mm_transaction'`.
- `assertLedgerBalanced($pdo, today)` returns true.
- `mm_current_float(till)` matches the sum of manual math.
- Void one transaction → verify contra entry created and cash/float
  balance rebounds.

---

### PHASE 3 — Teller Shift Management

**Goal:** A teller opens a shift, records transactions against it, closes with
counted cash + float, sees variance. Mirrors the POS `zreport.php` /
`cash_register_shifts` pattern.

#### 3.1 — `mm_shifts.php` (list + Open/Close modals)

- Open Shift modal: till Select2 (only unassigned tills the user is granted),
  opening cash and opening float counts.
- Only one open shift per till at any time (enforced by unique partial index).
- Close Shift modal: closing cash + float counts → server computes expected +
  variance and asks for close_notes if variance > 0.

#### 3.2 — Teller → till grants (`mm_user_agent_grants` table — created here)

```
grant_id, user_id, agent_id, till_id (NULL = all tills of agent),
can_open_shift, can_record_transactions, can_close_shift, can_reconcile,
created_at, granted_by
UNIQUE (user_id, agent_id, till_id)
```
This is the MM equivalent of `user_scope_overrides` for warehouses (Phase 6 of
`pos_upgrade_plan.md`). Admins bypass; every helper in `mm_posting.php` /
`mm_transactions.php` checks it.

#### 3.3 — `mm_shift_report.php` (mirrors POS Z-report)

- Printable summary: shift header, opening/closing cash + float, computed
  expected, variance, transaction breakdown by type, commission earned this
  shift, list of voids.
- `// scope-audit: skip — one shift the user is authorised for` marker at top,
  matching `zreport.php`.

#### 3.4 — Acceptance for Phase 3

- Open shift → record 3 txns → close shift with over/under cash → variance
  computes correctly and prints on the report.
- Second Open Shift attempt on the same till while one is open → refused.
- Non-granted user cannot open a shift on a till.

---

### PHASE 4 — Float Management

**Goal:** A user can record float top-ups (bank → e-float) and float
withdrawals (e-float → bank); the operation posts to the GL and updates the
till's live float.

#### 4.1 — `mm_float.php`

- DataTable of `mm_float_movements`.
- "New Float Top-up" modal:
  - Till Select2 → amount → source bank account Select2 (accounts of type 'bank').
  - On save: creates `mm_float_movements` row → `postLedgerEntry()` with
    `entity_type='mm_float_move'` → Dr E-Float, Cr Bank.
- "New Float Withdrawal" modal: mirror (Dr Bank, Cr E-Float).
- Opening balance form (visible only on tills with no movements yet).

#### 4.2 — Low-float alert

- After every `postMMTransaction()`, `mm_check_low_float()` fires.
- If threshold crossed, `dispatchEvent()` (from `core/notify.php`) with
  event `mm_low_float`.
- Recipients: users with `mm_float` view permission + the outlet's `manager_user_id`.
- Deduped once per till per day via `notifClaimDedupe()`.

#### 4.3 — Acceptance for Phase 4

- Post a top-up of TZS 500k on M-Pesa till → verify float balance rose 500k,
  bank account balance dropped 500k on Trial Balance.
- Drive a till's float below its `low_float_alert_pct` threshold → notification
  appears in-app for granted users.

---

### PHASE 5 — Commission Tracking

**Goal:** Track commission earned per transaction; record commissions received
from networks; reconcile expected vs received.

#### 5.1 — `mm_commissions.php`

- Two tabs: **Earned** (query `mm_transactions` for a period) and **Received**
  (`mm_commissions_received`).
- Summary tile: "Unreceived commission = earned − received per network".

#### 5.2 — Recording a received commission

- New Commission Received modal: network, period, amount, destination bank,
  reference.
- On save: post `Dr Bank, Cr Commission Receivable` (or directly credit income
  if the tenant hasn't accrued receivables — a per-tenant setting on the
  network row).

#### 5.3 — Acceptance for Phase 5

- Earned tab totals match SUM(commission_earned) from posted transactions.
- Received tab total exists in Trial Balance under "Cash at Bank".
- Difference visible on the summary tile — never negative.

---

### PHASE 6 — Daily Reconciliation

**Goal:** End-of-day, per till, compute expected vs actual cash + float,
optionally match against a network statement.

#### 6.1 — `mm_reconciliation.php`

- List of `mm_reconciliations` with status chips.
- "New Reconciliation" modal — auto-fills opening balances from previous day's
  close.

#### 6.2 — `mm_recon_view.php`

- Two-column display: **Book side** (from `mm_transactions` + `mm_float_movements`)
  vs **Actual** (entered by user or imported from network statement CSV).
- Import Statement button — CSV upload; parser stubs one per network (start with
  M-Pesa's export format).
- Match/unmatch controls; save writes `mm_recon_items`.
- Resolve variance workflow — user records reason and, if agreed to write off,
  posts a small journal (Dr Cash Short / Cr Cash — or vice versa).

#### 6.3 — Acceptance for Phase 6

- Create a recon for a till → expected computes correctly.
- Match all book lines to statement lines → variance drops to 0 → status = resolved.
- A resolved recon locks the till for that date (a second recon for the same
  `(till_id, recon_date)` is refused by the unique constraint).

---

### PHASE 7 — Dashboard

**Goal:** A single-glance view of MM operations, per user's granted agents.

#### 7.1 — `mm_dashboard.php`

Widgets:
1. **Float status grid** — per active till: current float, ceiling, % used,
   low-float chip.
2. **Today at a glance** — count, volume, commission (across all granted tills).
3. **7-day trend chart** — daily transaction volume by network (line chart,
   blue palette per `.claude/ui-constants.md`).
4. **Top transaction types** — bar chart.
5. **Pending reconciliations** — count of tills without a recon for yesterday.
6. **Alerts feed** — low-float, high-variance, KYC-required, suspicious flags.

Every widget respects `mm_user_agent_grants`.

#### 7.2 — Acceptance for Phase 7

- Dashboard loads in <2s with 10k transactions in the DB.
- Every number ties back to a source query the user can drill into.

---

### PHASE 8 — Reports (self-contained, use one ledger)

**Goal:** Six reports, all pulling from `journal_entries` + `mm_transactions`
(per `.claude/reporting-source.md`), never bypassing the ledger.

Each report is a page under `app/bms/mobile_money/reports/` and gets one
permission key `mm_reports`.

| Report | Data source | Key metric |
|---|---|---|
| Transaction Summary | `mm_transactions` grouped by date/network/type | Volume, count, commission |
| Commission Report | `mm_transactions.commission_earned` vs `mm_commissions_received.amount_received` | Earned vs received per network per period |
| Float Utilisation | `mm_float_service` snapshots hourly (light log table `mm_float_snapshots` — added in this phase) | Average float used, idle % |
| Agent Performance | `mm_transactions` grouped by agent | Revenue, volume, uptime |
| Cash Flow | `mm_transactions` — cash_effect > 0 (in) vs cash_effect < 0 (out) by day | Cash in vs cash out timing per outlet |
| Discrepancy Log | `mm_reconciliations` where cash_variance ≠ 0 OR float_variance ≠ 0 | Unresolved variance |
| Network Statement Match | `mm_recon_items` where match_status IN ('unmatched_book','unmatched_stmt') | Ageing of unmatched lines |

Every report:
- Applies `scopeFilterSqlNullable('project', 'je')` on any join to `journal_entries`.
- Print + Excel export (existing DataTables Buttons pattern).
- Reads MM's own tables — never opens `sales_orders`, `invoices`, `pos_sales`, etc.

#### 8.1 — Acceptance for Phase 8

- Transaction Summary totals match GL: `SUM(commission_earned)` for period =
  Commission Income – Mobile Money movement on the P&L for the same period.
- Every report loads in <2s with 100k transactions in the DB.

---

### PHASE 9 — Compliance (BOT)

**Goal:** Meet BOT Wakala regulatory requirements: KYC on large transactions,
suspicious-transaction flagging, audit-ready export.

#### 9.1 — KYC capture at transaction time

- Config setting `mm_kyc_threshold` (default TZS 1,000,000).
- If principal ≥ threshold: transaction modal expands to require ID type, ID
  number, ID photo upload.
- Upload goes to `uploads/mm_kyc/` with `.htaccess` (per `§19`), served via
  gatekeeper `api/mobile_money/kyc_download.php`.

#### 9.2 — Suspicious flag

- Manual: user checks "Flag for review" on the modal.
- Automatic: multiple sub-threshold transactions from the same phone within 24h
  totalling ≥ threshold (structuring detection) — flagged by a nightly cron.

#### 9.3 — `mm_compliance.php`

- KYC records list + document viewer (gatekeeper URL).
- Flagged transactions list with resolution workflow.
- BOT export button (Excel format — one row per flagged transaction with all
  required BOT fields).

#### 9.4 — Acceptance for Phase 9

- File a TZS 1.5M transaction → KYC required → save requires photo → KYC record
  linked to transaction.
- Nightly cron flags a 3×TZS 400k pattern from one phone within 24h.

---

### PHASE 10 — Nav + UI Integration

**Goal:** MM appears in the header nav, gated by `tenantFeatureEnabled('mobile_money')`.
Every list is DataTable + mobile card view + Select2 + blue palette.

#### 10.1 — `header.php` — new top-level menu

Insert after the CRM block (roughly line 1160), following the exact pattern
from CRM/Restaurant/HR:

```php
<?php if (tenantFeatureEnabled('mobile_money') &&
          (canView('mm_dashboard') || canView('mm_transactions') || canView('mm_agents'))): ?>
<li class="nav-item dropdown">
    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
        <i class="bi bi-phone-vibrate"></i> <?= t('Mobile Money') ?>
    </a>
    <ul class="dropdown-menu">
        <?php if (canView('mm_dashboard')): ?>
        <li><a class="dropdown-item" href="<?= getUrl('mm_dashboard') ?>"><i class="bi bi-speedometer2"></i> <?= t('Dashboard') ?></a></li>
        <?php endif; ?>
        <?php if (canView('mm_transactions')): ?>
        <li><a class="dropdown-item" href="<?= getUrl('mm_transactions') ?>"><i class="bi bi-arrow-left-right"></i> <?= t('Transactions') ?></a></li>
        <?php endif; ?>
        <?php if (canView('mm_float')): ?>
        <li><a class="dropdown-item" href="<?= getUrl('mm_float') ?>"><i class="bi bi-cash-stack"></i> <?= t('Float Management') ?></a></li>
        <?php endif; ?>
        <?php if (canView('mm_commissions')): ?>
        <li><a class="dropdown-item" href="<?= getUrl('mm_commissions') ?>"><i class="bi bi-coin"></i> <?= t('Commissions') ?></a></li>
        <?php endif; ?>
        <?php if (canView('mm_reconciliation')): ?>
        <li><a class="dropdown-item" href="<?= getUrl('mm_reconciliation') ?>"><i class="bi bi-check2-square"></i> <?= t('Reconciliation') ?></a></li>
        <?php endif; ?>
        <li><hr class="dropdown-divider"></li>
        <li><h6 class="dropdown-header"><?= t('Setup') ?></h6></li>
        <?php if (canView('mm_agents')): ?>
        <li><a class="dropdown-item" href="<?= getUrl('mm_agents') ?>"><i class="bi bi-shop-window"></i> <?= t('Agents / Outlets') ?></a></li>
        <?php endif; ?>
        <?php if (canView('mm_networks')): ?>
        <li><a class="dropdown-item" href="<?= getUrl('mm_networks') ?>"><i class="bi bi-broadcast"></i> <?= t('Networks') ?></a></li>
        <?php endif; ?>
        <?php if (canView('mm_commission_rates')): ?>
        <li><a class="dropdown-item" href="<?= getUrl('mm_commission_rates') ?>"><i class="bi bi-percent"></i> <?= t('Commission Rates') ?></a></li>
        <?php endif; ?>
        <li><hr class="dropdown-divider"></li>
        <?php if (canView('mm_reports')): ?>
        <li><a class="dropdown-item" href="<?= getUrl('mm_reports') ?>"><i class="bi bi-bar-chart"></i> <?= t('Reports') ?></a></li>
        <?php endif; ?>
        <?php if (canView('mm_compliance')): ?>
        <li><a class="dropdown-item" href="<?= getUrl('mm_compliance') ?>"><i class="bi bi-shield-check"></i> <?= t('Compliance / KYC') ?></a></li>
        <?php endif; ?>
    </ul>
</li>
<?php endif; ?>
```

#### 10.2 — UI polish

- **Terminology enforcement** — before opening any MM file for UI work, re-read
  the `## Terminology` section at the top of this plan. Every `<label>`,
  `placeholder`, modal title, table heading, toast message, and error string
  must use the MM-specific names (Outlet, Teller, Teller Shift, Network Float
  Balance…). Run a final grep for the words `"warehouse"`, `"Z-Report"`,
  `"cashier"` inside `app/bms/mobile_money/` — none should appear.
- Every list has a `<div id="cardView">` mobile alternative (per user memory
  `project_mobile_card_progress.md`).
- Every DB-backed `<select>` uses Select2 with account labels in "CODE — Name"
  format on the left (per `feedback_account_code_left.md`).
- Every state-changing form includes `<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">`.
- Every write path calls `logActivity()` and sensitive ops call `logAudit()`.

#### 10.3 — Acceptance for Phase 10

- Menu appears when MM enabled; disappears cleanly when disabled.
- All pages pass browser test at 400px width (no horizontal scroll).

---

### PHASE 11 — Test Suite

**Goal:** CLI-runnable tests cover the money math and the posting integrity.
Follows the `tests/` layout of the rest of BMS.

#### 11.1 — Test files

| File | Assertions |
|---|---|
| `tests/test_mm_commission_calc_cli.php` | Flat + percent + min + max caps across amount bands; effective-from windowing |
| `tests/test_mm_posting_cli.php` | Every txn_type posts a balanced entry; cash_effect + float_effect signs; commission line included; entity linkage correct; idempotency on retry |
| `tests/test_mm_float_service_cli.php` | current_float after N transactions matches manual math; opening_balance movement respected; void reverses |
| `tests/test_mm_shift_cli.php` | One open shift per till; variance math on close |
| `tests/test_mm_reconciliation_cli.php` | Expected vs actual math; matched/unmatched item accounting; unique (till_id, recon_date) |
| `tests/test_mm_end_to_end_cli.php` | Enable MM → create agent → create till → open shift → record 3 txns → close shift → reconcile → verify Trial Balance still balances → verify P&L shows correct commission income |

#### 11.2 — Acceptance for Phase 11

- All new tests pass locally.
- The pre-push hook runs them (per user memory `project_prepush_hook_hangs.md`,
  known-hanging suites are pre-existing; MM's new suites must not hang).
- `assertLedgerBalanced()` after every test run returns true.

---

## Rollout Sequence (per user memory `feedback_branch_pr_workflow.md`)

Each phase is one dedicated branch off `develop`, one PR into `develop`.
Final phase releases from `develop` → `main` via `.github/workflows/deploy.yml`.

| Branch | PR title | Merges to |
|---|---|---|
| `feat/mm-phase-0-foundation` | feat(mobile_money): registry, migrations, routing | develop |
| `feat/mm-phase-1-master-data` | feat(mobile_money): networks, agents, tills, tariffs | develop |
| `feat/mm-phase-2-txn-engine` | feat(mobile_money): posting engine + transaction UI | develop |
| `feat/mm-phase-3-shifts` | feat(mobile_money): teller shifts + Z-report | develop |
| `feat/mm-phase-4-float` | feat(mobile_money): float top-up / withdrawal + low-float alerts | develop |
| `feat/mm-phase-5-commissions` | feat(mobile_money): commission tracker | develop |
| `feat/mm-phase-6-recon` | feat(mobile_money): daily reconciliation | develop |
| `feat/mm-phase-7-dashboard` | feat(mobile_money): dashboard | develop |
| `feat/mm-phase-8-reports` | feat(mobile_money): six reports | develop |
| `feat/mm-phase-9-compliance` | feat(mobile_money): KYC + suspicious-flag | develop |
| `feat/mm-phase-10-nav` | feat(mobile_money): header nav + UI polish | develop |
| `feat/mm-phase-11-tests` | test(mobile_money): CLI test suite | develop |

Every PR: `changelog.md` updated per `feedback_change_tracking.md`; commits
attributed per session reminder.

---

## Zero-Gap Checklist (verified before implementation starts)

- [x] Feature-registry entry has `default: false`, `depends_on: []`, `paths[]`
      covering both PHP directories and the two core service files.
- [x] All 9 permission keys defined.
- [x] All 15 URL slugs mapped in `roots.php`.
- [x] `MOBILE_MONEY_DIR` constant defined.
- [x] `gl_source.php` extended with 3 MM route entries so GL drill-down works.
- [x] Both `mm_networks` FK columns (`float_account_id`, `commission_account_id`) present so posting resolver always finds an account.
- [x] Auto-provisioning of MM GL accounts on first-enable — no manual setup required.
- [x] Every table has `created_at` / `created_by`, `status` where relevant.
- [x] Every posting call uses `postLedgerEntry()` — never direct SQL to
      `journal_entries`.
- [x] Every list page uses DataTable + mobile card view + Select2.
- [x] Every state-changing form uses CSRF token.
- [x] Every scoped-table query uses `scopeFilterSqlNullable('project', 'je')` or
      carries `// scope-audit: skip` with a written reason.
- [x] Nav appears only when `tenantFeatureEnabled('mobile_money')` — a tenant
      without MM sees nothing.
- [x] Ledger guardrail (`assertLedgerBalanced`) called at test time.
- [x] KYC uploads follow all five §19 rules (extension + MIME + size +
      random filename + `.htaccess`).
- [x] Every write path calls `logActivity()`; sensitive ops call `logAudit()`.
- [x] No other module's tables ever read (fully self-dependent).
- [x] Void = contra entry, never DELETE.
- [x] Migration files paired (tenant + legacy) with idempotent guards.
- [x] All 11 new tables added to the pre-push hook's scope-audit list OR every
      MM file carries the skip marker with reason.

---

## Post-Implementation Follow-ups (out of scope, tracked for later)

- Bank-account integration: some tenants have their bank's mobile-money mirror
  (NMB Mkononi, CRDB Tembo). A future phase could add these as `mm_networks`
  entries with a different `provider` value.
- Direct API integration to M-Pesa/Airtel Money — pull transactions
  automatically instead of manual entry. Requires each provider's B2B/C2B
  agreement; scoped as a Phase 12+ enhancement.
- Multi-currency (USD, EUR remittances) — the tables use TZS-native
  `DECIMAL(15,2)`; multi-currency would need a `currency` column on
  `mm_transactions` and a rate service.

---

**End of plan. Ready to start Phase 0 on branch `feat/mm-phase-0-foundation`
once you say go.**
