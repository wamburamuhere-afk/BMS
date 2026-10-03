# Ziara za Wateja (Customer Visits) — usability + accuracy plan

**Date:** 2026-10-03 · **Branch:** `feat/customer-visits-ux` (off `develop`) · **PR → develop**
Module key stays `field_reports`, page key `field_visits`, routes `field_reports*` (no URL/permission
change, so nothing that links or grants access breaks). Only what people SEE is renamed.

Found by filling it in on shop.demo as a marketer on a phone (2026-10-03).

## Phase 1 — real bugs
| # | Change |
|---|---|
| 1.1 | `api/field_reports/_common.php` loads the user's language (like every other API): business types, interest, validation errors, "saved" messages were English for Swahili users. |
| 1.2 | Phone card: the three Yes/No badges get their names (Card / Trial link / Training). |
| 1.3 | One clock: the page takes date AND time from the server (EAT); `save.php` rejects a time later than now (+5 min) for today. (A visit was stored 03/10 23:39 at 00:23.) |

## Phase 2 — easier to fill
| # | Change |
|---|---|
| 2.1 | Name: **"Ziara za Wateja" / "Customer Visits"** everywhere people see it (nav, phone More sheet, page, print, Excel, roles, superadmin modules). Registry gains `previous_labels`; `syncFeatureCatalogue()` renames a catalogue label only while it still equals a label we shipped (an operator's own label is never touched). Permission `page_name` updated the same way. |
| 2.2 | Page: 3 big cards on top — **Ziara za leo · Wamejiunga · Wa kufuatilia**; the other stats under "Takwimu zaidi" (open on desktop, closed on phone). Admin staff summary = list on phone, table on desktop. |
| 2.3 | Form starts with **Phone**. A known number (own visits; admin: anyone's) fills name, business, place and says "Ziara ya N". Date/time = "now", hidden behind "Badilisha tarehe/muda" (shown when editing). |
| 2.4 | GPS also suggests the place: the nearest of the user's OWN earlier visits within 300 m ("Karibu na: Kariakoo") fills an empty Place. No external map service (no location leaves the system). |
| 2.5 | Card / Trial link / Training = big tap buttons. |
| 2.6 | Interest = three coloured buttons (Anavutiwa · Atafikiria · Hana nia), tap again to clear. |

## Phase 3 — follow-up + accuracy
| # | Change |
|---|---|
| 3.1 | **Follow-up date** per visit (optional, ≥ visit date, ≤ 1 year). "Wa kufuatilia" list (due today/overdue, not joined, not done) at the top with Call / Done; done is recorded. Columns `follow_up_date`, `follow_up_done_at` added idempotently (tenant + legacy migration; schema helper for new tenants). Same scope rules as visits. |
| 3.2 | **"✓ Amejiunga"** button directly on each card / row (not only in ⚙). |
| 3.3 | Admin viewing "all staff" can still submit / see **their own** day (button + banner for the caller's own day). |
| 3.4 | GPS-confirmed visits (accuracy ≤ 100 m) marked **📍** on the page, print and Excel ("GPS" column); report gains a Follow-up column. |

## Safety
- Ownership rules unchanged: every new query goes through `frScopeUserId()` / `frCanTouch()`.
- No DDL in transactions; migrations idempotent; old rows keep working (new columns NULL).
- All strings via `t()`, Swahili added, `sw.php` duplicate check.

## Tests
`tests/test_field_reports_cli.php` extended (real endpoints as forged users): language of API output,
future-time rejection, phone lookup scope, nearby-place scope, follow-up validation/list/done + scope,
joined button, admin own-day, print/Excel columns sw+en, rename. jsdom run of the page (form order,
toggles, interest buttons, follow-up list). Existing related suites compared with `develop`.
