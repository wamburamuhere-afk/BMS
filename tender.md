# Tender Management — Professional Upgrade Plan

**Status:** DRAFT — not started. **Created:** 2026-09-05.
**How to resume:** Read this whole file first — §1/§2 are the *findings* (why), §4 is the
*build plan* (what/how), each phase has a checkbox. Update the checkbox and the
"Status tracker" at the bottom every time a phase finishes, so a cold session
(new terminal, no memory of this conversation) can see exactly what's done.

## 0. Where this comparison comes from

- **Reference system:** Facile-FMS demo (`https://facile-fms.com`), module
  "Bidding & Tenders", explored live on 2026-09-05 (demo account, read-only
  exploration — no BMS code copied, only the *feature shape* is referenced).
- **BMS side scouted:** `app/bms/tenders/{tenders,tender_create,tender_edit,tender_view}.php`,
  `api/tender_workflow.php`, `api/get_tenders.php`, table `tenders` + `tender_staff`
  in `schema/tenant_schema_template.sql` (lines ~7747–7825), permission wiring in
  `core/permissions.php` (~532, ~607), feature flag in `core/feature_registry.php` (~115).

**Bottom line up front:** BMS's tender module is *workflow*-strong (an 11-stage
PPRA-realistic pipeline that Facile doesn't come close to) but *document*-weak — it
has nowhere to price a bid item-by-item, nowhere to check the bid is compliant
before submission, and no way to generate the actual paperwork. Facile is the
reverse: thin workflow, but a genuinely useful pricing/paperwork toolkit. The
professional move is not to copy Facile — it's to bolt Facile's pricing/paperwork
layer onto BMS's already-superior workflow.

---

## 1. Completely missing in BMS (Facile has these, BMS has nothing like them)

| # | Feature | What Facile does | Why it matters |
|---|---|---|---|
| 1 | **Bills of Quantities (BOQ) engine** | Multiple named "Bills" (sections), each a line-item table (Item/Description/Unit/Qty/Rate/Amount) with a per-bill subtotal, rolling into a Collection/Summary with Contingency % and VAT 18% toggle → Grand Total | This is the actual pricing tool. Without it a bid amount is just a guess with no audit trail behind it. |
| 2 | **Materials Schedule** | Simple Material/Spec/Unit/Qty/Rate/Amount table, separate from the BOQ | Lets you cost the materials going into the bid before you price the works. |
| 3 | **Auto-drafted Form of Tender / covering letter** | A PPRA-boilerplate letter (addressed to the Tender Board, states tender no./title, BOQ grand total, validity period, non-debarment declaration) generated from the tender's own data, editable, re-draftable on demand | Saves real time and guarantees the figure quoted in the letter always matches the BOQ — no more "letter says one number, BOQ says another." |
| 4 | **PPRA compliance checklist with a readiness counter** | 19 fixed items (Form of Tender, POA, Certificate of Incorporation, Business Licence, TIN, VAT cert, Tax Clearance, NeST registration, Bid Security, priced BOQ, work programme, 3-yr audited financials, references, key-personnel CVs, equipment schedule, litigation history, anti-bribery declaration, JV agreement) with an "X / 19 ready" counter | The single highest-value item here — a missing document is the #1 cause of disqualification, and nothing in BMS today tells you *which* document you're missing before you submit. |
| 5 | **Print-to-letterhead + NeST portal shortcut** | Every generated document (Form of Tender, Materials Schedule, BOQ, Checklist) prints to PDF on the company letterhead; a button opens `nest.go.tz` directly | Turns the module into a one-stop prep station instead of a data-entry form you then have to re-type into Word. |

---

## 2. Half-there in BMS — works, but not professional yet

| # | Area | Current state (exact location) | The gap | Concrete fix |
|---|---|---|---|---|
| 1 | **Bid amount capture** | `app/bms/tenders/tenders.php` — "Financial & Technical Submission" modal (~line 486–540): one manual `<input name="sub_amount_tzs">` / `sub_amount_usd`, no line items behind it | The number you submit to the procuring entity is typed by hand with zero backing detail — can't be checked, can't be revised confidently, can't be reused for job-costing if you win | Build the BOQ engine (§1.1) and make it the *source* of `sub_amount_tzs`/`usd` — auto-fill the submission modal from the BOQ grand total, keep manual entry only as a fallback for RFQ-style tenders that don't need a BOQ |
| 2 | **Won tender → Project handoff** | `api/tender_workflow.php` lines 214–239 (`case 'DECISION'`, status `AWARDED`) — **this already runs automatically**, inserting a new `projects` row | Six separate, confirmed gaps — see the dedicated breakout in §2.1 below (traceability, a UI promise the code doesn't keep, invisible-to-its-own-team access, no idempotency guard, currency ambiguity, and no BOQ/materials carry-over) | See §2.1 and Phase E |
| 3 | **Document completeness** | Tender already has 8 real upload slots (`tender_document`, `participation_fee_document`, `opening_document`, `evaluation_document`, `post_qualification_document`, `award_letter_document`, `submission_document_tzs`, `submission_document_usd`), all shown in one place in `tender_view.php`'s "Uploaded Documents" card (~line 254) | Good storage, but nothing tells you *whether* the set is complete against the PPRA standard before you submit — the checklist in §1.4 doesn't exist, so completeness is only ever discovered by the procuring entity rejecting the bid | Build the checklist (§4 Phase C) and, where an item maps 1:1 to an existing upload slot (e.g. "Bid Security" → could link to a document), auto-tick it when that slot is filled; leave the rest as manual tick items since most (TIN, VAT cert, Tax Clearance, CVs…) have no existing slot at all |
| 4 | **Document/letterhead generation** | A genuinely strong, reusable engine already exists: `core/document_letter_render.php` + `core/document_letter_pdf.php` (TCPDF, company letterhead, logo, freely-positioned sender/recipient blocks, signature image or e-sign, "Printed by / Powered by" audit footer), driving `app/constant/document/create_document.php` | It is completely unwired from tenders — the Form of Tender letter in §1.3 has nowhere to render to yet | Reuse this engine rather than building a second one: the auto-drafted Form of Tender becomes a `body_html` fed into `renderLetterHtml()`, giving tenders e-signature and audit-footer support for free, which Facile doesn't even have |

### 2.1 The AWARDED → Project route, traced end to end — six confirmed gaps

The user asked specifically that this route be fully scouted before implementation
so nothing gets left behind. Traced the whole path — `tenders.php`'s Decision
modal → `api/tender_workflow.php`'s `DECISION` case → the `projects` INSERT →
what a user sees afterward → BMS's project-access-scope system
(`core/project_scope.php`). Six real gaps, all confirmed against actual code,
not guessed:

1. **No traceability.** `projects` has no `tender_id` column
   (`schema/tenant_schema_template.sql:5905`) — a project can never be traced
   back to the tender that spawned it. *(already noted above, repeated here for
   completeness of the route trace)*
2. **The UI makes a promise the code breaks.** `tenders.php:458` tells the user,
   in the Decision modal itself: *"This amount will be tracked as the project
   budget if awarded."* But the INSERT at `api/tender_workflow.php:223` only
   sets `contract_sum` — it never sets `budget`. Every tender-won project
   currently gets created with `budget = 0`, silently contradicting what the
   user was just told. This is a bug, not just a missing feature.
3. **The winning team can't see the project it won.** BMS enforces project-level
   row access via `user_projects` (`user_id`, `project_id`) — see
   `core/project_scope.php:67`, `.claude/security.md` §23. The auto-created
   project never inserts rows into `user_projects` for anyone. A non-admin
   `tender_staff` member who worked the whole bid will not see the resulting
   project in their own Projects list until an admin manually assigns them —
   the automation quietly hands the work back to a human anyway.
   - Mapping note: `tender_staff.employee_id` → `user_projects.user_id` is not
     direct — go through `users.employee_id` (`schema/tenant_schema_template.sql:8065`)
     to find the matching login, and simply skip staff who have no `users` row
     (e.g. a sub-contractor with no BMS login) rather than erroring.
4. **No idempotency guard.** The `DECISION` case never checks the tender's
   *current* status before running — nothing stops it from being called twice
   (double-submit, retried request) and creating two `projects` rows for the
   same tender. Once `tender_id` exists on `projects` (gap #1's fix), add a
   `UNIQUE KEY` on it *and* guard the PHP with `WHERE status != 'AWARDED'` on
   the tender lookup, so a repeat call is a harmless no-op, not a duplicate.
5. **Currency is dropped on the floor.** A tender can be priced in TZS, USD, or
   both (`tenders.currency_choice` / `sub_amount_tzs` / `sub_amount_usd`), but
   `$_POST['tender_sum']` at award time is a single unlabeled number, and
   `projects` has no currency column at all. A USD-priced win becomes an
   unlabeled figure in `contract_sum`/`budget` that every downstream TZS-based
   report (per `.claude/reporting-source.md`, the one ledger is TZS) will
   silently treat as TZS. At minimum record which currency the awarded sum is
   in; ideally require a recorded conversion rate before it feeds `budget`.
6. **No BOQ/Materials carry-over** *(depends on Phases A/B existing first)* —
   covered in Phase E below, plus the Materials→NIP linkage in §3.

`project_manager` is the one field that's simpler than it looks: it's a plain
free-text `<input>` on `projects` (`app/bms/operations/projects.php:401-402`),
not a foreign key — so seeding it from the tender's lead `tender_staff` row is
just string concatenation (`first_name . ' ' . last_name`), no `user_id`
resolution needed there specifically (contrast with gap #3, which does need it).

**What BMS already does *better* than Facile — do not regress these while building the above:**
an 11-stage PPRA-realistic status pipeline (`PENDING → APPROVED → INVITATION → SUBMISSION → OPENING → EVALUATION → POST-QUALIFICATION → NEGOTIATION → AWARDED/LOSS/END TENDER/cancelled`) vs Facile's 5 states; full institution master data with Tanzania region/district/council/ward; per-tender staff assignment (`tender_staff`); `technical_score` + `financial_rank` fields Facile has no equivalent of; a real audit trail + activity log on every tender; and the automatic AWARDED → Project creation Facile doesn't attempt at all.

---

## 3. Design decisions already made (so a future session doesn't re-litigate them)

- **New tables live under a `tender_` prefix**, isolated from HR's existing generic
  `checklist_templates` / `checklist_template_items` engine. That engine's
  `template_type` enum is hard-coded to `('onboarding','offboarding')` — extending
  it for tenders would couple two unrelated modules for no real benefit; a small
  dedicated `tender_checklist_items` table is simpler and safer. (HR's item shape —
  `item_text` + `sort_order` + a checked flag — is still the right pattern to copy.)
- **BOQ items are free-text**, not tied to `product_id` like `quotation_items` —
  tender works/goods usually aren't in the sales catalogue. `quotation_items`
  (`schema/tenant_schema_template.sql:6274`) is the closest existing pattern for
  column shape (qty/unit/unit_price/line_total) and is worth copying the *shape*
  of, not the table itself.
- **The tender's Materials Schedule is NOT the same thing as BMS's existing NIP
  Material Lists (`nip_material_lists`/`nip_material_list_nips`,
  `app/bms/purchase/nip_materials.php`) — they must be linked, not merged, and
  not built as a disconnected duplicate.** Scouted the existing concept fully:
  `nip_material_lists` is **project-scoped** (`project_id` FK) and drives actual
  procurement (RFQ/PO) for "Non-Inventory Products" — real rows in `products`
  (see `nip_material_component_status`, `nip_material_list_nips`) that just
  aren't warehouse-stocked. It only exists *after* a project exists. The
  tender's Materials Schedule is a **pre-award bid-costing estimate** — "what we
  think we'll need, to price the bid" — and no project exists yet at that
  point. They sit at different moments of the same lifecycle:
  `Tender Materials Schedule (pricing) --award--> Project NIP Material List (procurement)`.
  Two concrete rules follow, both required to avoid the duplication risk the
  user flagged:
  1. Each `tender_materials` line gets an **optional** `product_id` (nullable
     FK to `products`) — a Select2 lookup against the existing catalogue
     (including existing NIPs) so a material that's already known is referenced,
     not re-typed; free text stays allowed since bid-time materials are often
     not yet catalogued.
  2. **On AWARDED**, auto-generate the new project's `nip_material_lists` entry
     from the tender's `tender_materials` rows: any line with a `product_id`
     copies straight across into `nip_material_list_nips`; any line without one
     creates a new NIP `products` row first (type/flag it non-inventory, per
     the existing convention) and then references it. This is what actually
     prevents duplication — the person running the project never re-types the
     material list the bid was priced on.
- **The BOQ total is the source of truth for the bid amount**, flowing BOQ → Form
  of Tender → Financial Submission — never the other way round.
- **Reuse the existing letter/PDF engine** (`core/document_letter_render.php`) for
  the Form of Tender instead of building a second PDF pipeline.
- **New schema goes in both places**: the live migration (`migrations/YYYY_MM_DD_*.php`,
  per `.claude/migrations.md`) *and* `schema/tenant_schema_template.sql`, matching
  how the existing `tenders` / `tender_staff` tables are already present in both —
  BJP prod (Tenant #1) still runs off the plain `migrations/` path until
  multi-tenancy Phase 7 lands (see `ternant.md`), so don't route this through
  `migrations/tenant/` yet.

---

## 4. Build plan

Each phase is independently shippable and testable — do not start a phase until
the previous one's checkbox is ticked and its tests pass. Follow
`.claude/templates.md` (page/API skeleton), `.claude/security.md` (uploads, CSRF,
codes) and `.claude/migrations.md` (migration file rules) throughout.

### Phase A — Bills of Quantities engine ☑ DONE (2026-09-05)
Implemented on branch `feat/tender-professional-upgrade`. What actually shipped,
for a future session to verify against rather than re-derive:
- Migration `migrations/2026_09_05_tender_boq.php` (run and confirmed idempotent
  on the live `bms` DB) + mirrored into `schema/tenant_schema_template.sql`.
- `core/tender_boq.php` — `recomputeTenderBoqTotal()`, the one place the
  subtotal → +contingency% → +VAT%-on-(subtotal+contingency) math happens.
  Deliberately pulled out of the API file so it's unit-testable and reusable
  by Phase E's award carry-over later.
- `api/tender_boq.php` — `ADD_BILL`, `DELETE_BILL`, `ADD_ITEM`, `DELETE_ITEM`,
  `SAVE_BOQ` (validates every posted item's `bill_id` actually belongs to the
  posted `tender_id` before writing anything — a tampered request touching
  another tender's BOQ is silently skipped, not applied).
- `app/bms/tenders/tender_boq.php` — new page (`?id=<tender_id>`), routed at
  `tender_boq` in `roots.php`, gated by the existing `tenders` permission key.
- `app/bms/tenders/_tender_nav.php` — new shared tab bar (Details / Edit / BOQ),
  included from `tender_view.php` and `tender_edit.php` too, so this is also
  where Phase B/C/D/F add their own tab as they ship.
- **Wire-through, done the safe way**: did *not* auto-overwrite
  `tenders.tender_amount_tzs`/`tender_sum` from the BOQ (those represent what
  was actually submitted/awarded — an explicit user action). Instead,
  `tenders.php`'s `openSubmissionModal()` now pre-fills the Financial
  Submission modal's TZS amount from `boq_grand_total` when opening it, still
  fully editable — the BOQ feeds the submission, it doesn't silently replace it.
- Test: `tests/test_tender_boq_cli.php` — 24/24 assertions passing (lint of
  every touched file, schema presence, exact math on a 2-bill example,
  cascade-delete recompute, cross-tender write-guard). Verified over HTTP too:
  unauthenticated request to `tender_boq?id=9` returns a clean 302 → `/login`,
  no fatal — full browser/logged-in verification was consciously deferred (no
  BMS credentials available to this session; user opted for CLI-level
  verification across all phases rather than a login-and-screenshot loop).
- **Schema** (new migration `migrations/2026_MM_DD_tender_boq.php`, mirrored into
  `schema/tenant_schema_template.sql`):
  - `tender_boq_bills` (`bill_id` PK, `tender_id` FK → `tenders.tender_id`,
    `bill_title` varchar, `sort_order` int)
  - `tender_boq_items` (`item_id` PK, `bill_id` FK, `description` text, `unit`
    varchar, `qty` decimal(12,3), `rate` decimal(15,2), `amount` decimal(18,2)
    generated or computed on save, `sort_order` int)
  - `tenders` gets three new nullable columns: `boq_contingency_percent`
    decimal(5,2) default 0, `boq_vat_percent` decimal(5,2) default 18,
    `boq_grand_total` decimal(18,2) — cached, recomputed on every BOQ save.
- **Pages:** new tab/section inside `app/bms/tenders/tender_edit.php` (or a
  dedicated `app/bms/tenders/tender_boq.php` if `tender_edit.php` is judged too
  large already at 44KB — check file size before deciding).
- **API:** `api/tender_boq.php` — actions `ADD_BILL`, `ADD_ITEM`, `SAVE_BOQ`
  (recomputes bill subtotals + grand total server-side, never trusts client math),
  `DELETE_BILL`, `DELETE_ITEM`. Follow the §9 API template in `.claude/templates.md`.
- **Wire-through:** on `SAVE_BOQ`, also update `tenders.tender_sum` /
  `sub_amount_tzs`-equivalent so the Financial Submission modal can pre-fill from
  it (manual override still allowed).
- **Permission:** reuse the existing `tenders` page key — this is data on the same
  entity, not a new module.

### Phase B — Materials Schedule ☐
- **Schema:** `tender_materials` (`material_id` PK, `tender_id` FK, `product_id`
  int **nullable** FK → `products.product_id` (see §3's Materials/NIP linkage
  rule — this is the field that prevents duplicating the NIP concept), `material`
  varchar (free-text label, always populated even when `product_id` is set, so
  the schedule reads standalone), `specification`, `unit`, `qty` decimal(12,3),
  `rate` decimal(15,2), `amount` decimal(18,2), `sort_order`). Same migration
  file as Phase A is fine (both are small, both ship together) — or a
  follow-up same-day migration, whichever the session doing the work prefers.
- **Page/API:** same tab area as BOQ; `api/tender_materials.php` mirroring the
  BOQ API shape. The material-name field is a Select2 with free-text tagging
  enabled (`tags: true`) against a lookup of existing `products` (including
  existing NIPs) — picking an existing entry sets `product_id`; typing a new
  name leaves `product_id` null and just stores the text, per §3.

### Phase C — PPRA Compliance Checklist ☐
- **Schema:** `tender_checklist_items` (`item_id` PK, `tender_id` FK, `item_text`
  varchar(255), `is_ready` tinyint(1) default 0, `sort_order` int,
  `is_custom` tinyint(1) default 0 — distinguishes the 19 standard seeded items
  from anything a user adds with "+ Add").
- **Seed:** on tender creation (`tender_create.php`'s save handler), insert the
  19 standard items from Facile's list (§1 row 4) via `INSERT ... SELECT` or a
  static PHP array loop — this is seed data, not a migration concern.
- **UI:** checklist tab showing "`X / 19 ready`" (count dynamically, not hard-coded,
  since users can add custom items) with checkboxes; where an item has an obvious
  1:1 mapping to an existing upload slot (Bid Security, priced BOQ, Form of
  Tender), auto-tick it when that slot/table has data — see §2 row 3.
- **API:** `api/tender_checklist.php` — `TOGGLE_ITEM`, `ADD_ITEM`, `DELETE_ITEM`
  (only for `is_custom = 1` rows — the 19 standard items can't be deleted, only
  unchecked, so the count stays meaningful).

### Phase D — Form of Tender auto-draft ☐
- **Schema:** `tenders.form_of_tender_html` (mediumtext, nullable) — stores the
  user's edited version once they've touched it, same "drafted until edited"
  behavior as Facile.
- **Draft logic:** a PHP function `draftFormOfTenderHtml(array $tender): string`
  in `core/` (new file `core/tender_documents.php`) that fills the PPRA boilerplate
  from `tenders` + `tender_boq_bills`/`items` grand total — pure string building,
  no DB write, callable from both the "Re-draft" button and first-load.
  Genuinely fixed strings and dates only — no invented obligations. Model output
  on the actual boilerplate the Facile module used (already captured verbatim
  in the earlier chat transcript this plan was written from, if that context is
  still available; otherwise write to the standard PPRA Form of Tender wording).
- **Render/print:** feed the (possibly user-edited) `form_of_tender_html` into
  `renderLetterHtml()` (`core/document_letter_render.php`) for the PDF, reusing
  the existing letterhead/signature/audit-footer machinery — see §2 row 4 and §3.
- **API:** `api/tender_form_of_tender.php` — `SAVE_LETTER`, `REDRAFT`.

### Phase E — Tender → Project linkage hardening ☐
Closes all six gaps traced in §2.1. Every sub-item below maps 1:1 to a numbered
gap there — check them off individually, this phase should not be marked ☑
until all six are actually addressed, not just the schema change.

- **Schema:**
  - `projects.tender_id` int, nullable, **`UNIQUE KEY`** → `tenders.tender_id`
    (the UNIQUE is gap #4's actual enforcement, not just a suggestion — a
    second INSERT attempt for the same tender must fail at the DB level even
    if the PHP guard below is ever bypassed).
  - `projects.budget_currency` varchar(3) default `'TZS'` (gap #5).
  - No new table needed for gap #3 — reuse `user_projects` as-is.
- **Code change:** `api/tender_workflow.php`'s `DECISION`/`AWARDED` branch
  (~line 223 `INSERT INTO projects (...)`):
  1. **Gap #4 fix — guard first:** before doing anything else, re-fetch the
     tender's current `status` and abort with a clean "already awarded" message
     if it's already `AWARDED` — don't rely on the UNIQUE key alone to catch it,
     fail fast with a clear message instead of a raw constraint-violation error.
  2. **Gap #1 fix:** add `tender_id` to the INSERT.
  3. **Gap #2 fix:** add `budget = $t['tender_sum']` to the INSERT (matching
     the promise already made in `tenders.php:458`) and set `budget_currency`
     from whichever currency the submission actually used.
  4. **Gap #5 fix:** carry the resolved currency through explicitly — don't
     leave it implicit/assumed-TZS.
  5. **Gap #3 fix:** after the project INSERT, loop `tender_staff` for this
     `tender_id`, resolve each `employee_id` to a `users.user_id` via
     `SELECT user_id FROM users WHERE employee_id = ? AND user_id IS NOT NULL`,
     and INSERT into `user_projects (user_id, project_id, assigned_by,
     assigned_at)` for each match — silently skip staff with no `users` row
     (e.g. a sub-contractor with no login), don't error on them.
  6. **`project_manager` seeding:** look up the `tender_staff` row whose
     `role_position` matches the lead role (however that's flagged — check
     the actual seeded values in `tender_staff.role_position` before assuming
     a literal string) and set `project_manager` to that employee's
     `first_name . ' ' . last_name`. This is a plain string field, not an FK
     (`app/bms/operations/projects.php:401`) — no `user_id` resolution needed
     here, unlike gap #3 above.
- **Data carry-over (needs Phases A & B done first):**
  - Copy `tender_boq_bills`/`tender_boq_items` rows into new
    `project_boq_bills`/`project_boq_items` tables (same shape) so the won
    bid's pricing becomes the project's baseline budget breakdown — do **not**
    point the project at the tender's own BOQ rows by reference, the tender
    record should stay frozen as submitted evidence even if the project's
    costing changes later.
  - Per §3's Materials/NIP linkage rule: create (or reuse, if one's already
    manually started) a `nip_material_lists` row for the new project, and for
    each `tender_materials` line — if it has a `product_id`, insert directly
    into `nip_material_list_nips`; if not, first INSERT a new `products` row
    flagged non-inventory (matching the existing NIP convention in
    `nip_material_component_status`) then reference that new `product_id`.
- **UI:**
  - `tender_view.php` — once a tender is AWARDED, show a "→ Project created:
    [name]" link using the new `tender_id` back-reference.
  - The award success `Swal.fire()` in `tenders.php` (~line 1671) currently
    shows a generic message and just reloads the tenders list — change it to
    include the new project's name/link in the same success dialog, so the
    person who just won the tender isn't left to go hunt for it in Projects
    (compounds gap #3 if they can't even see it there without this fix too).

### Phase F — Print + NeST shortcut ☐
- **UI:** a "Preview & Print" tab/section on the tender record mirroring Facile's,
  with buttons for BOQ, Materials Schedule, Checklist, Form of Tender — each
  routes to a small print view that either reuses `renderLetterHtml()` (for the
  Form of Tender) or a plain letterhead-wrapped TCPDF table (for BOQ/Materials/
  Checklist — no existing engine for tabular letterhead docs, so this part is
  new, small, and self-contained).
- **Link:** a static "Open NeST Portal ↗" button linking to `https://nest.go.tz`
  in a new tab — trivial, no backend.

### Phase G — Permissions, feature registry, migration hygiene ☐
- Confirm every new API file follows the CSRF + `canView`/`canEdit`('tenders')
  pattern from `.claude/templates.md` §9 — no new permission keys needed, this
  is all sub-data of the existing `tenders` page key.
- `core/feature_registry.php`'s `tenders` entry (~line 115) — extend `paths` to
  include the new API files (`api/tender_boq.php`, `api/tender_materials.php`,
  `api/tender_checklist.php`, `api/tender_form_of_tender.php`) so the feature
  toggle continues to gate the whole module correctly.
- All new tables added to **both** `migrations/2026_MM_DD_tender_boq.php` (or
  split per phase, session's judgment) **and** `schema/tenant_schema_template.sql`,
  per §3.

### Phase H — Tests ☐
Per this project's working agreement: after each phase, write a CLI test for what
that phase touched; after all phases, one real end-to-end test.
- Phase A: `tests/test_tender_boq_cli.php` — create tender, add 2 bills, add items,
  assert grand total math (subtotal + contingency% + VAT%) is correct.
- Phase C: `tests/test_tender_checklist_cli.php` — assert 19 items seeded on
  create, assert ready-counter math, assert standard items can't be deleted.
- Phase E: `tests/test_tender_award_project_link_cli.php` — assert AWARDED
  creates a project with correct `tender_id`, `budget` (+ `budget_currency`),
  `project_manager`; assert every `tender_staff` member with a matching
  `users` row gets a `user_projects` row for the new project (gap #3); assert
  calling `DECISION` a second time on an already-AWARDED tender is rejected,
  not a second project (gap #4).
- Final: `tests/test_tender_end_to_end_cli.php` — create tender → add BOQ → tick
  checklist → draft Form of Tender → award → assert project exists and links back.

---

## 5. Status tracker

| Phase | Description | Status | Last touched |
|---|---|---|---|
| A | BOQ engine | ☑ Done | 2026-09-05 |
| B | Materials Schedule | ☐ Not started | — |
| C | Compliance checklist | ☐ Not started | — |
| D | Form of Tender auto-draft | ☐ Not started | — |
| E | Tender → Project linkage hardening | ☐ Not started | — |
| F | Print + NeST shortcut | ☐ Not started | — |
| G | Permissions/registry/migration hygiene | ☐ Not started | — |
| H | Tests | ☐ Not started | — |

**Next action when resuming:** start Phase B (Materials Schedule) — Phase A is
done and merged-to-branch; see its write-up above for exactly what exists to
build on (`core/tender_boq.php`, `_tender_nav.php`'s tab pattern, the
`tender_boq` route).
