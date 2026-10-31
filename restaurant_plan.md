# Smart POS — Restaurant Plan

Status: **DRAFT v2 — updated after reviewing apps.levanda.co.tz (2026-10-31). Awaiting approval.**

Decisions already confirmed by the owner:
1. Phase order OK.
2. Hybrid shop shows **Menu and Products** both.
3. Printer is **optional** — most small restaurants have none. No printer is the default.
4. Ingredients start with the **simple "Buy Ingredients"** (no ingredient stock); stock + recipes later.

---

## 0. The golden rule — retail stays exactly as today

Restaurant features are visible **only when all three are true**:

| # | Switch | Who controls it |
|---|---|---|
| 1 | **Restaurant POS** module ON | Superadmin only (Tenant → Point of Sale → More) |
| 2 | **Smart POS** (Simple Mode) ON | Superadmin only |
| 3 | Shop **POS Mode** = Restaurant or Hybrid | Tenant admin — the field exists **only** when #1 is ON |

One helper everywhere: `restaurantShopActive($warehouseId)` = #1 && #2 && #3.

**Restaurant POS OFF (default for every tenant):** every shop is Retail whatever is stored;
no POS Mode field (web + mobile ignore a posted one); no Restaurant menu, pages or till buttons;
till, products, expenses, reports = today's behaviour exactly. (Done in PR #2629.)

Out of scope: Advanced POS, LPO, delivery notes, quotations, projects — not touched.
Every phase adds a regression test that a **Retail** shop renders and sells exactly as before.

---

## What we learned from Levanda (apps.levanda.co.tz)

**Copy (they do it well):**
- Restaurant is its **own focused workspace**: Home, Weka Oda, Uendeshaji (Oda, Meza, Jikoni, Baa,
  Reservation, Menyu, Mapishi, Bidhaa, Manunuzi), Ripoti, Matumizi, Wateja, Wasambazaji.
- **Getting-started checklist** with ticks on the restaurant Home ("Mwongozo wa kuanza").
- Every menu item has a **preparation area** (Jikoni / Baa) and an **available** switch.
- **Free-text preparation notes** per order ("chumvi kidogo, pilipili zaidi").
- **Tables need no floor** — area is optional.
- **Order type** in the cart: Kulia Hapa / Take Away / Delivery; table picked in the cart.
- **Selling starts from the menu** (cards by category), not from the tables.
- Clear, natural Swahili wording.

**Avoid (their mistakes):**
- A dish saved "without recipe — sold without tracking ingredients" is then **refused** at
  order confirmation ("neither a recipe nor a stock item behind it"). A small owner gets stuck.
  → **Ours: a dish with no recipe always sells.**
- Too many steps: send → confirm → preparing → ready → served → pay.
  → **Ours: short path is the default (pick → pay); kitchen steps are optional.**
- Small bugs: table chosen in the cart didn't stick; seats field kept its "4" and became "44".
- Plan limits (1 kitchen area, 250 trial orders) — not relevant to us, noted only.

---

## Phase 0 — Safety finish + tables without floors (small)

| Item | What |
|---|---|
| 0.1 | **Superadmin warning** when switching Restaurant POS ON: "These shops are already Hybrid/Restaurant: …". No data changed. |
| 0.2 | **Tables without floors:** a default area "Eneo Kuu" is created automatically; Floor becomes optional in Add Table. |
| 0.3 | Fix the Floors page showing "Main Floor (0 tables)" and "No floors yet" together. |
| 0.4 | `restaurantShopActive()` helper + tests (false whenever Restaurant POS is off). |
| 0.5 | **Wording pass** on the existing restaurant screens using the glossary below. |

---

## Phase 1 — Restaurant workspace, Menu, getting-started checklist

### 1a. Focused workspace (restaurant shop only)
Top menu for a Restaurant shop: **Nyumbani · Weka Oda · Meza · Menyu · Jikoni · Matumizi · Ripoti**
(+ Wateja, Wasambazaji under "Zaidi"). Retail items that don't apply are hidden for that shop.
Hybrid shop keeps today's menu **plus** Menyu, Meza, Jikoni.

### 1b. Menu page (Menyu) + Add Item
```
┌ Menyu ──────────────────────────── [+ Ongeza Chakula] ┐
│ Kifungua kinywa  Chai ........ 500  Jikoni  ✔ kipo    │
│                  Mandazi ..... 200  Jikoni  ✔ kipo    │
│ Chakula kikuu    Lunch ..... 5,000  Jikoni  ✖ kimeisha│
│ Supu             Supu ya samaki 3,000 Jikoni ✔ kipo   │
│ Vinywaji         Maji ........ 500  —  stoo: 48       │
│                  Soda ........ 700  —  stoo: 120      │
└───────────────────────────────────────────────────────┘
```
**Ongeza Chakula** — first question *"Hiki ni nini?"*

| Choice | Fields | Behind the scenes |
|---|---|---|
| **Chakula kinachopikwa hapa** | Jina, Kundi, Bei ya kuuza, Kinaandaliwa wapi (Jikoni / Baa), Kinapatikana leo, Picha (hiari) | `is_service = 1` (existing no-stock flag) — **always sells, no recipe needed** |
| **Bidhaa iliyonunuliwa (ina stoo)** — maji, soda | Jina, Kundi, Bei ya kununua, Bei ya kuuza, Stoo ya mwanzo | Normal product; preparation area = "Haihitaji maandalizi" |

- **Kinapatikana leo** switch: off = hidden from Weka Oda until switched back on.
- Starter groups offered (not forced): Kifungua kinywa, Chakula kikuu, Supu, Vitafunio, Vinywaji.

### 1c. Getting-started checklist (Mwongozo wa kuanza) on the restaurant Home
```
✔ Umefungua duka la mgahawa
○ Ongeza meza            → (one screen: "Una meza ngapi?" creates Meza 1…N)
○ Ongeza vyakula/vinywaji → (tick ready items: chai, mandazi, lunch, supu, maji, soda + prices)
○ Pokea oda ya kwanza
```
Ticks itself as each step is done; can be closed. (Replaces the separate wizard — earlier and simpler.)

---

## Phase 2 — Weka Oda (sell) + Meza screen

### 2a. Weka Oda — menu first, short path by default
```
[Tafuta chakula...]   (Vyote) (Kifungua kinywa) (Supu) (Vinywaji)
[Chai 500] [Mandazi 200] [Supu ya kuku 3,000] [Soda 700] ...
                                    ┌ Kikapu ─────────────────────┐
                                    │ Aina: (Kulia Hapa) Take Away │
                                    │       Delivery               │
                                    │ Meza: [Meza 3 ▾]             │
                                    │ 2 × Chai ........... 1,000   │
                                    │ Maelekezo: chumvi kidogo     │
                                    │ Jumla ............. 1,000    │
                                    │ [ Lipa ]  [ Weka kwenye meza]│
                                    └──────────────────────────────┘
```
- **Lipa** = pay now (mama-lishe style, one step) — the default.
- **Weka kwenye meza** = keep the order open on the table (pay later), reuses `hold_sale.php` + table.
- "Tuma Jikoni" appears only when the shop turned **Jikoni** on (Phase 3).
- Free-text **Maelekezo** per item; existing modifier groups stay as an optional extra.
- Reuses `process_sale.php` sale types `dine_in` / `take_away` / `delivery`.

### 2b. Meza screen
Grid of tables: **Wazi / Ina oda (amount) / Inasubiri malipo**. Tap a table with an order →
**Ongeza** (another soda) · **Lipa** · **Hamisha meza**. Phone first.

---

## Phase 3 — Jikoni (optional, three ways)

Shop setting **"Oda zinafikaje jikoni?"**:

| Option | How it works |
|---|---|
| **Kwa mdomo** (default) | No kitchen step. Waiter tells the cook; the order stays on the table in the system. |
| **Skrini ya jikoni** | A phone/tablet in the kitchen shows orders: **Mpya → Inapikwa → Tayari**. (Existing kitchen display, reworded + simplified.) |
| **Printa ya risiti** | A small slip prints on "Tuma Jikoni" (table, items, maelekezo, time, waiter). Reuses `core/escpos_printer.php`. |

Items marked **Baa** go to the bar list; items with no preparation never go to the kitchen.

---

## Phase 4 — Bili: gawa, hamisha, unganisha
- **Gawa bili** — pay a table's order in parts (by item or amount).
- **Hamisha meza** — move an open order to another table.
- **Unganisha meza** — merge two open orders.
- Optional **ada ya huduma** % (POS setting, default off).

## Phase 5 — Nunua Viungo (simple)
- Entry **"Nunua Viungo"**: kitu, kiasi (TZS), umelipa kutoka (cash default).
- Posts to existing **5-2000 Purchases** (Cost of Sales) through the one ledger, so Ripoti shows
  Mauzo − Gharama ya viungo = Faida ya chakula, then − Matumizi = Faida halisi.
- No ingredient stock in this phase.

## Phase 6 — Ripoti za mgahawa
- Home tiles: oda za leo, meza zenye oda, vyakula vinavyouzwa zaidi, mauzo ya chakula vs vinywaji.
- Report: mauzo kwa kundi, kwa mhudumu, kwa aina ya oda (Kulia Hapa / Take Away / Delivery).

## Phase 7 — Mapishi + stoo ya viungo (later)
- "Lunch 1 = mchele kilo 0.2 + nyama kilo 0.15" in a simple form (engine exists, today Advanced only).
- Ingredients received as stock; selling a dish reduces them. Report: faida kwa kila chakula,
  viungo vinavyokaribia kuisha.
- A dish **without** a recipe keeps selling normally (never blocked — Levanda's mistake).

---

## Dashboard, Settings, Reports — follow what the company has (decided 2026-10-31)

| Company has | Dashboard · Settings · Reports |
|---|---|
| **Retail shops only** | **Exactly as today — no change.** |
| **Restaurant shops only** | **Full restaurant view.** Dashboard: quick actions Weka Oda, Meza, Ongeza Chakula, Nunua Viungo, Matumizi; cards Mauzo ya leo, Oda za leo, Meza zenye oda, Matumizi ya mwezi; lists Oda za karibuni, Vyakula vinavyouzwa zaidi; alerts = drinks low stock only; Mwongozo wa kuanza until done. Settings: a "Mgahawa" tab (jikoni: mdomo/skrini/printa, aina za oda, ada ya huduma); unrelated items hidden. Reports: Mauzo (kundi / mhudumu / aina ya oda), Faida, Vyakula vinavyouzwa zaidi, Stoo ya vinywaji, Matumizi & Viungo. |
| **Retail + restaurant** (or a Hybrid shop) | **Today's retail view unchanged + one small "Mgahawa leo" section** (oda za leo, meza zenye oda, Weka Oda button). Settings gets the "Mgahawa" tab; reports gain restaurant filters. Nothing removed. |

"Restaurant only" = every **active** shop is in Restaurant mode (and Restaurant POS + Smart POS are on).

**Reuse, don't rebuild:** Sell engine (`pos.php`, `pos_scripts_new.php`, `process_sale.php`,
`hold_sale.php`, shifts, receipts, split payment), restaurant tables/kitchen tickets,
`core/escpos_printer.php`, expense posting, `gl*` report functions, `product_analysis`,
`pos_profit_report`, sales report (+ `sale_type`).

**Retail protection:** single gate `restaurantShopActive()` / `restaurantOnlyCompany()`;
additive `if (restaurant) { new } else { existing, untouched }`; retail render + save tests for
dashboard, Sell, Add Product, Settings, Reports in every phase; English keys unchanged.

---

## Swahili wording glossary (natural words, checked against Levanda)

Applied in `lang/sw.php` (English keys unchanged, so retail screens are untouched).

| English key | Current sw.php | New (natural) |
|---|---|---|
| Restaurant | Mkahawa | **Mgahawa** |
| Floors & Tables | Ghorofa na Meza | **Meza** |
| Floor | Ghorofa | **Eneo** |
| Add Floor | Ongeza Ghorofa | **Ongeza Eneo** |
| Kitchen Display | Onyesho la Jikoni | **Jikoni** |
| Kitchen Stations | Vituo vya Jikoni | **Sehemu za Maandalizi** |
| Station | Kituo | **Sehemu ya Maandalizi** |
| Modifier Group | Kundi la Marekebisho | **Nyongeza** |
| Menu Type | Aina ya Menyu | **Makundi ya Menyu** |
| Ticket | Tiketi | **Oda** |
| Reservation(s) | Uhifadhi | **Reservation** (as Levanda) / **Kuhifadhi Meza** |
| Back to POS Hub | Rudi kwenye Kitovu cha POS | **Rudi Mauzo** |
| Occupied | Imechukuliwa | **Ina Oda** |
| Available (table) | — | **Wazi** |
| Preparing | Inaandaliwa | **Inapikwa** |
| Ready | — | **Tayari** |
| Dine-in | Kulia Ndani | **Kulia Hapa** |
| Take away | — | **Take Away** |
| Party Size | Idadi ya Watu | Idadi ya Watu (keep) |
| Send to Kitchen | Tuma jikoni | **Tuma Jikoni** |
| Seats | — | **Idadi ya Viti** |
| Table Number | Namba ya meza | **Namba ya Meza** |
| Menu item | Bidhaa za menyu | **Chakula** |
| Available today | — | **Kinapatikana leo** |
| Preparation notes | — | **Maelekezo ya maandalizi** |
| Cooking time | — | **Muda wa Kupika** |
| Buy Ingredients | — | **Nunua Viungo** |
| Getting started | — | **Mwongozo wa kuanza** |

Rules: short everyday words; avoid "ghala", "kituo", "tiketi", "marekebisho", "kitovu";
keep familiar loan words people already use (Oda, Take Away, Reservation, Soda).
Every new screen string goes through `t()` with an sw.php entry in the same phase.

---

## Testing (every phase)
- Real renders + real saves/sales (create item → sell → ledger balanced).
- **A dish with no recipe sells end-to-end** (the Levanda trap) — explicit test.
- **Retail regression:** Restaurant POS OFF, and a Retail shop with it ON — till, Add Product,
  Sell, Expenses render and save exactly as before.
- Existing suites stay green: `test_pos_simple_ux_cli`, `test_restaurant_pos_mode_setup_cli`,
  `test_restaurant_mode_gating_cli`, `test_warehouse_project_filter_cli`, i18n coverage.
- Browser click-through at phone size on local before each push.

## Delivery
Branch per phase off `develop`, commit per tested phase, push + PR into `develop`.
Mobile API doc republished after any phase touching `api/mobile/**` or `api/pos/**`.
