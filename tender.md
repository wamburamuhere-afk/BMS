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

### Phase B — Materials Schedule ☑ DONE (2026-09-05)
Implemented on branch `feat/tender-professional-upgrade`. What shipped:
- Migration `migrations/2026_09_05_tender_materials.php` (run + idempotent) +
  mirrored into `schema/tenant_schema_template.sql`, right after the BOQ tables.
- `tender_materials` table with the §3-mandated nullable `product_id` FK
  (`ON DELETE SET NULL` — deliberately not CASCADE, so the tender's pricing
  record survives even if the catalogue product behind it is later deleted).
- `api/tender_materials.php` — `ADD_ITEM`, `DELETE_ITEM`, `SAVE_MATERIALS`
  (same cross-tender ownership guard pattern as Phase A's `SAVE_BOQ`), plus a
  `SEARCH_PRODUCTS` read action powering the Select2 lookup.
- `app/bms/tenders/tender_materials.php` — new page, added as a tab in
  `_tender_nav.php`. The material-name field is a Select2 with `tags: true` +
  AJAX search against `products` (including existing NIPs) — picking a real
  catalogue hit sets a hidden `product_id`; typing a new name leaves it null
  and just stores the text. This is the actual mechanism behind §3's
  Materials/NIP linkage rule; Phase E's award carry-over reads `product_id`
  off these rows to decide "reference the existing product" vs "create a new
  NIP product first."
- Test: `tests/test_tender_materials_cli.php` — 17/17 assertions (lint, schema
  + FK presence, linked-vs-free-text math, the SET NULL behavior, cross-tender
  guard). Phase A's test re-run clean alongside it (24/24, no regression).
  Verified over HTTP: clean 302 → `/login` on the new page, unauthenticated.
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

### Phase C — PPRA Compliance Checklist ☑ DONE (2026-09-05)
Implemented on branch `feat/tender-professional-upgrade`. What shipped:
- Migration `migrations/2026_09_05_tender_checklist.php` — creates
  `tender_checklist_items` **and backfills the 19 standard items for every
  pre-existing tender** (criteria-based `LEFT JOIN ... WHERE item_id IS NULL`,
  not hard-coded ids, so it's safe to re-run and doesn't touch tenders that
  already have a checklist). Ran live: backfilled 17 existing tenders.
  Mirrored into `schema/tenant_schema_template.sql`.
- `core/tender_checklist.php` — `tenderChecklistStandardItems()` (the 19
  strings, single source of truth) and `seedTenderChecklist()`. Hooked into
  `tender_create.php` right after the INSERT, so every new tender gets the
  checklist automatically.
- `api/tender_checklist.php` — `TOGGLE_ITEM`, `ADD_ITEM` (custom only),
  `DELETE_ITEM` (refuses anything but `is_custom = 1` — the 19 standard items
  can be unticked, never removed, so the ready-counter always measures
  against the real standard, not a shrinking list).
- `app/bms/tenders/tender_checklist.php` — new page, added as the 4th tab.
  Counter is computed live (`COUNT(*)` / `SUM(is_ready)`), not hard-coded to
  19, so it grows correctly when a custom item is added.
- **Auto-tick, deliberately scoped down**: the original idea (auto-tick a box
  when a matching document/BOQ/materials record exists) was simplified to a
  **"View →" hint link**, not silent auto-toggling — shown only for "Priced
  Bills of Quantities" (links to the BOQ tab, shows the current grand total)
  and "Materials Schedule & delivery plan" (links to Materials, shows the line
  count). Reasoning: silently flipping a checkbox the user already unticked
  themselves would be worse than not automating it at all; a small handful of
  the 19 items (TIN, VAT cert, Tax Clearance, CVs, litigation history, etc.)
  have no existing BMS field to map to regardless, so full auto-detection was
  never going to cover the list — a future phase could extend the hint map,
  but should keep it as hints, never silent writes to `is_ready`.
- Test: `tests/test_tender_checklist_cli.php` — 20/20 assertions (lint,
  seeding produces exactly 19 unticked/non-custom rows, counter math, counter
  denominator growing with a custom item, the standard-item delete guard,
  cascade delete). Phases A (24/24) and B (17/17) re-run clean. Clean 302 →
  `/login` over HTTP, unauthenticated.
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

### Phase D — Form of Tender auto-draft ☑ DONE (2026-09-05)
Implemented on branch `feat/tender-professional-upgrade`. What shipped:
- Migration `migrations/2026_09_05_tender_form_of_tender.php` adds
  `tenders.form_of_tender_html` (mediumtext, null until first drafted/saved),
  `form_of_tender_date`, and **`bid_validity_days` (int, default 90)** — this
  last one wasn't in the original plan; discovered mid-implementation that
  BMS's `tenders` table had no equivalent of Facile's "Bid Validity (days)"
  field at all, and the letter genuinely needs it for the validity paragraph.
  Mirrored into `schema/tenant_schema_template.sql`.
- `core/tender_documents.php` — `draftFormOfTenderBodyHtml()` (the editable
  body paragraphs only), `tenderFormOfTenderRecipientHtml()` and
  `tenderFormOfTenderSubject()` (always deterministic from tender_no/
  tender_description/procuring_entity_name — deliberately NOT part of the
  editable draft, since Tender Details is already their canonical source;
  cleaner than Facile's single flat textarea covering everything).
- `api/tender_form_of_tender.php` — `SAVE_LETTER`, `REDRAFT` (regenerates and
  overwrites, matching Facile's "Re-draft From Details"), and `PRINT` (a
  read-only GET, no CSRF needed) which **reuses the existing
  `core/document_letter_render.php` / `core/document_letter_pdf.php` engine**
  per `tender.md` §2 row 4 and §3 — the Form of Tender gets company
  letterhead, e-signature support and the audited "Printed by" footer for
  free, none of which Facile's own print view has.
- `app/bms/tenders/tender_form_of_tender.php` — new page (5th tab), Summernote
  rich-text editor for the body, "Print / Save PDF" opens the PDF in a new tab.
- Test: `tests/test_tender_form_of_tender_cli.php` — 22/22 assertions,
  including an actual **end-to-end PDF generation** (not just string checks):
  drafts a letter from a test tender + its BOQ total, feeds it through the
  real `generateLetterPdf()`, and asserts a valid, non-trivial PDF file comes
  out (`%PDF-` header, plausible size). This is the closest this session could
  get to "does it actually work" without a logged-in browser session — the
  PDF pipeline was proven end to end even though nobody looked at the
  rendered page. Also asserts the "not yet priced" case is honest (says so
  rather than claiming a fake TZS 0 figure). Phases A/B/C re-run clean
  (24/17/20). Clean 302 → `/login` on the page and clean 401 on the PDF
  endpoint, both unauthenticated.
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

### Phase E — Tender → Project linkage hardening ☑ DONE (2026-09-05)
Implemented on branch `feat/tender-professional-upgrade`. All six §2.1 gaps
closed and proven by a single end-to-end test running the real function —
not a re-derivation of its logic:
- Migration `migrations/2026_09_05_tender_award_project_link.php` — adds
  `projects.tender_id` (nullable, **UNIQUE** — the actual DB-level
  enforcement of gap #4, proven in the test by a raw bypass-the-PHP-guard
  duplicate INSERT that the database itself rejects), `projects.budget_currency`,
  and the `project_boq_bills`/`project_boq_items` carry-over tables (real
  copies, not references — the tender's BOQ stays frozen as submitted
  evidence). Mirrored into `schema/tenant_schema_template.sql`.
- `core/tender_award.php` — `awardTenderToProject()`, pulled out of
  `api/tender_workflow.php`'s `DECISION` case so all six gaps live in one
  testable place. Follows the same `$ownTxn` convention as
  `core/code_generator.php::nextCode()` (checks `$pdo->inTransaction()`
  before managing its own transaction) — deliberate, so a caller already
  inside a transaction (this phase's own CLI test) can compose it cleanly.
  - **Gap #1** (traceability): `tender_id` set on the new project.
  - **Gap #2** (budget promise): `budget` actually set from `tender_sum` —
    `tenders.php:458`'s promise to the user is now true.
  - **Gap #3** (team access): loops `tender_staff`, resolves each
    `employee_id` to a `users.user_id` via `users.employee_id`, inserts
    `user_projects` rows — silently skips staff with no login rather than
    erroring. `project_manager` (a plain string field, no FK) is seeded from
    whichever staff member's `role_position` reads like a lead
    (`LIKE '%lead%'/'%manager%'/'%coordinator%'`), falling back to the first
    staff member assigned.
  - **Gap #4** (idempotency): checks `tenders.status` isn't already `AWARDED`
    before doing anything, PLUS the UNIQUE key backstops it at the DB level.
  - **Gap #5** (currency): `budget_currency` set from the tender's actual
    `currency` field (`'USD'` if that's what was submitted, else `'TZS'`) —
    not assumed.
  - **Gap #6** (BOQ/Materials carry-over): copies `tender_boq_bills`/`items`
    into the new `project_boq_*` tables; per §3's linkage rule, seeds a
    `nip_material_lists` row from `tender_materials` — a line with a
    `product_id` references that product directly, a free-text line creates a
    new NIP `products` row first (`is_service=1`, `track_inventory=0`,
    `contract_item_no` via `nextCode($pdo,'NIP')` — the exact convention
    `api/create_nip_product.php` already uses).
- `api/tender_workflow.php`'s `DECISION` case is now a thin wrapper: handles
  the award-letter file upload (upload-specific, stays inline), then calls
  `awardTenderToProject()` and returns its `project_id`/`project_name`.
- UI: `tenders.php`'s award success dialog now shows a **"View Project"**
  button linking straight to `project_view?id=`, not just a toast (closes the
  "no redirect/visibility after award" note from §2.1); `tender_view.php`
  shows a green "AWARDED — Project created: [name] → View Project" banner
  once a linked project exists, via the new `projects.tender_id` back-reference.
- Test: `tests/test_tender_award_project_link_cli.php` — **35/35 assertions**,
  built a real tender with BOQ, Materials (one linked product, one free-text),
  and two `tender_staff` (one with a login, one without), then ran the actual
  `awardTenderToProject()` and checked every gap's real output — including
  attempting a raw duplicate `INSERT` after the fact to prove the UNIQUE key
  itself rejects it, not just the PHP-level guard. Phases A–D re-run clean
  (24/17/20/22). Clean 302 → `/login` on both modified pages, unauthenticated.
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

### Phase F — Print + NeST shortcut ☑ DONE (2026-09-05)
Implemented on branch `feat/tender-professional-upgrade`. What shipped:
- `core/tender_documents.php` extended with `buildTenderBoqPrintHtml()`,
  `buildTenderMaterialsPrintHtml()`, `buildTenderChecklistPrintHtml()` — plain
  HTML tables (TCPDF's `writeHTML()` constraint, same reason
  `document_letter_render.php` uses tables, not flexbox/grid).
- `api/tender_print.php` — `PRINT_BOQ`, `PRINT_MATERIALS`, `PRINT_CHECKLIST`
  (read-only GET, `canView('tenders')` gated). All three reuse
  `generateLetterPdf()` — **no second PDF pipeline**, per §3's reuse-the-engine
  decision; Form of Tender's own print action (Phase D) already did the same.
- `app/bms/tenders/tender_print.php` — new page, the 7th and final tab
  ("Preview & Print"), matching Facile's own last tab: buttons for all four
  documents plus a static "Open NeST Portal" link to `nest.go.tz`.
- Test: `tests/test_tender_print_cli.php` — 24/24 assertions: the three HTML
  builders contain the real data passed in (item descriptions, totals, ready
  counts), and — same proof style as Phase D — each of the three actually
  produces a real, valid PDF end-to-end (`%PDF-` header, plausible size), not
  just a string-content check. Phases A–E re-run clean (24/17/20/22/35 — 118
  assertions total across the whole plan so far, zero regressions). Clean
  302 → `/login` on the page, clean 401 on the print API, both unauthenticated.

**All seven build phases (A–F, plus this makes the 6th delivered tab) are now
done.** Only Phase G (permission/registry/migration hygiene — already mostly
folded into each phase as it shipped) and Phase H (the final consolidated
re-scout + end-to-end test) remain.
- **UI:** a "Preview & Print" tab/section on the tender record mirroring Facile's,
  with buttons for BOQ, Materials Schedule, Checklist, Form of Tender — each
  routes to a small print view that either reuses `renderLetterHtml()` (for the
  Form of Tender) or a plain letterhead-wrapped TCPDF table (for BOQ/Materials/
  Checklist — no existing engine for tabular letterhead docs, so this part is
  new, small, and self-contained).
- **Link:** a static "Open NeST Portal ↗" button linking to `https://nest.go.tz`
  in a new tab — trivial, no backend.

### Phase G — Permissions, feature registry, migration hygiene ☑ DONE (folded into A–F)
Done incrementally as each phase shipped, not as a separate pass — verify by
checking any single phase's write-up above, they all follow the same
discipline: every new API checked `isAuthenticated()` + `canView`/`canEdit`/
`canCreate('tenders')` + (for state-changing POSTs) `csrf_check()`;
`core/feature_registry.php`'s `tenders` entry's `paths` array got the new
API file appended in the same commit as that API was added; every schema
change landed in both `migrations/2026_09_05_*.php` (run against the live
`bms` DB) and `schema/tenant_schema_template.sql` in the same commit. No
separate hygiene pass was needed because none of the six phases skipped it.
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

### Phase H — Final re-scout + consolidated end-to-end test ☑ DONE (2026-09-05)
Per this project's working agreement (re-scout before/after phases, write a
real end-to-end test after all phases). Two genuine gaps were caught here
that no individual phase's isolated test could have caught, because both
involve a page nobody had touched since before Phase A started:

1. **Critical — the AWARDED bypass.** `tender_edit.php`'s plain "Current
   Status" dropdown listed `AWARDED` as a normal option, saved via a raw
   `UPDATE tenders SET status = ?`. Selecting it there would silently skip
   every single Phase E guarantee — no project, no budget, no team access, no
   BOQ/Materials carry-over — AND leave the tender stuck: a later proper
   award attempt via the Decision workflow would refuse it as "already
   awarded" even though no project was ever created, with no recovery path in
   the UI. Fixed both directions (entering AND leaving AWARDED are blocked
   outside the guarded workflow) with a server-side guard in
   `tender_edit.php`'s POST handler plus a client-side UX fix (the dropdown
   excludes AWARDED entirely for a non-awarded tender; once awarded, the
   field is shown disabled with an explanatory note and a hidden input so the
   value still round-trips).
2. **`bid_validity_days` had no UI.** Phase D added the column and used it in
   the drafted letter, but never exposed a field to actually change it from
   the DB default of 90 — added "Bid Validity (days)" to both
   `tender_create.php` and `tender_edit.php`'s Tender Details section.

Also swept for (and found none of): other code paths that INSERT INTO
`tenders` bypassing the Phase C checklist seed (only `tender_create.php`
does); a dangerous pre-existing `AWARD_RECORDS` case in
`api/tender_workflow.php` that falls through into `DELETE` with no `break` —
confirmed dead code (nothing calls that action anywhere), left alone as
out-of-scope for this plan rather than fixed opportunistically.

- Test: `tests/test_tender_end_to_end_cli.php` — **24/24 assertions**, the
  full real lifecycle in one script (not each phase's slice of it): create
  tender exactly the way `tender_create.php` does (checklist auto-seeds) ->
  price a BOQ -> add a materials line -> tick 5 checklist items -> draft the
  Form of Tender and confirm it reflects *this* tender's live BOQ total and
  its own `bid_validity_days` (not stale/default values) -> print all four
  documents to real valid PDFs -> award -> verify the project has the
  correct `tender_id`, `budget`, `project_manager`, carried-over BOQ amount,
  carried-over NIP material list, and `user_projects` access, AND that the
  tender's own checklist is untouched afterward -> confirm re-awarding is
  refused. Also re-lints all 25 tender-module files in one sweep and asserts
  the AWARDED-bypass fix is actually present in the file.
- **Full regression, all seven test files, one final run:** 24+17+20+22+35+24+24
  = **166 assertions, 0 failures**, across `test_tender_{boq,materials,
  checklist,form_of_tender,award_project_link,print,end_to_end}_cli.php`.
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
| B | Materials Schedule | ☑ Done | 2026-09-05 |
| C | Compliance checklist | ☑ Done | 2026-09-05 |
| D | Form of Tender auto-draft | ☑ Done | 2026-09-05 |
| E | Tender → Project linkage hardening | ☑ Done | 2026-09-05 |
| F | Print + NeST shortcut | ☑ Done | 2026-09-05 |
| G | Permissions/registry/migration hygiene | ☑ Done (folded into A–F) | 2026-09-05 |
| H | Final re-scout + end-to-end test | ☑ Done (found & fixed 2 real gaps) | 2026-09-05 |

**All phases (A–H) are done.** 166 assertions passing across 7 test files,
zero regressions, two real cross-cutting gaps found and fixed during the
Phase H re-scout (see above). The `feat/tender-professional-upgrade` branch
is ready to push and PR into `develop`. Nothing left to resume — if a future
session lands here, the next tender-module work is a genuinely new
feature/fix, not a continuation of this plan.
