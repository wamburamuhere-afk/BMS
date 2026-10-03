# POS detail pages — clean-up plan (Customers · Suppliers · Products · Services)

**Date:** 2026-10-03 · **Branch:** `feat/pos-detail-pages-cleanup` (off `develop`) · **PR → develop**

## Why
Live check on shop.demo (modules ON: POS + Warehouse only; Simple Mode) found links that open
"Not found" (404), sections that belong to modules that are off, a wrong stock sign, wrong
Swahili, and missing POS information. Every item below was verified on the live site or in code.

**Out of scope (user decision):** how codes display (`Bsx-Sup-0001`, `Bsx-Cust-0018`) — unchanged.
**Correction:** the customer "Historia ya Mauzo" tab works (it loads by AJAX; my first read was
too early). It is NOT changed.

## Root cause & the one rule
Links were drawn without asking whether the target page's module is on. The router already
404s such pages (`roots.php` handleRoute: `tenantModuleAllowsPage($route)` + `bmsFeatureGuardPath($file)`).

**Phase 0 adds `bmsRouteAvailable(string $route): bool`** (core/feature_registry.php) — the SAME two
checks the router does, plus "route is mapped and the file exists". Every link to another module's
page is drawn only when `bmsRouteAvailable()` is true. With all modules on, nothing changes
(regression-safe); with a module off, its links disappear instead of 404-ing.

---

## Phase 0 — foundation
| # | File | Change |
|---|---|---|
| 0.1 | `core/feature_registry.php` | `bmsRouteAvailable($route)`: false if route unmapped / file missing / `tenantModuleAllowsPage($route)` false (only when a feature map is primed) / `bmsFeatureBlockingPath($file)` not null. |
| 0.2 | `tests/helpers/page_request.php` | Generic runner: render a page/API as a forged user, with an optional feature map + setting overrides (Simple Mode). |

## Phase A — dead links + real bugs
| # | Page | Change |
|---|---|---|
| A1 | `suppliers.php` menu | "View Account" (vendor_statement) only if `bmsRouteAvailable('vendor_statement')`; "New Order" also requires `bmsRouteAvailable('purchase_order_create')`. |
| A2 | `products.php` menu | "Create Purchase Order" only if route available. **Add "Receive Stock / Pokea Mzigo"** (when `canView('pos') && canView('pos_restock')`, stock-tracked product) → `pos?restock=1&product_id=N`. |
| A3 | `pos_scripts_new.php` | `?restock=1&product_id=N` opens the restock modal with that product pre-selected (name looked up server-side; invalid/service/untracked id → modal opens empty, as today). |
| A4 | `product_view.php` | 3× "View All Purchase Orders", the Recent Purchase Orders block, "Generate Sales Report" (product_analysis) → only if their routes are available. |
| A5 | `product_view.php` movements | Sign from the movement's direction: `stock_after − stock_before` when both set, else `stockMovementIsInbound()`. A sale shows **−3.000** (red), stock-in **+**. |
| A6 | `product_view.php` movements | "Adjusted By" = person's name (first + last), username only as fallback. |
| A8 | `product_view.php` Recent Sales | Receipt number linked to `sales_order_view?id=<POS sale_id>` — the wrong record in every mode (found by the Phase A link test). Now opens the POS receipt (`api/pos/print_receipt.php?id=`), same as the customer page. |
| A7 | `lang/sw.php` + `customer_details.php` | 'Nobody owes you anything right now' → "Hakuna mteja anayedaiwa kwa sasa" (also correct on POS credit list). Customer page uses new key 'This customer owes nothing right now' → "Mteja huyu hadaiwi chochote kwa sasa". |

## Phase B — remove what doesn't belong + language
| # | Page | Change |
|---|---|---|
| B1 | `suppliers.php` | Simple Supplier mode: menu offers only Deactivate/Activate (Activate also for a supplier already Suspended/Blacklisted, so none gets stuck). Suspended/Blacklisted stat cards + filter options hidden unless such suppliers exist. |
| B2 | `supplier_details.php` | "Projects Linked" row only when `projectsModuleActive()`. Header/buttons/tabs/System-Info labels through `t()`. |
| B3 | `product_view.php` | "Reserved" (card, progress bar, per-shop column) hidden when Projects is off **and** reserved = 0 (real data never hidden). "Source GRN" column only when Procurement route available or a batch has a GRN. "No reason provided" → "—". Visible labels through `t()`. |
| B4 | `services.php` | Subtitle per modules (POS only → "Services you sell at the POS"); stats/columns/search say Services (`wLabel`); category filter lists only categories used by services (hidden if none); no trailing/double dividers in the menu. |
| B5 | `service_view.php` | "Assembly Information" (Contract Item No / Base Assembly Qty) hidden with the materials (same `$hideMaterialComponents`). Title/breadcrumb/print title say Service; SKU hidden in Simple mode; `TZS` → `format_currency`; Cost/Margin use the service's cost and are hidden when cost is 0; labels through `t()`. |
| B6 | `customer_details.php` | Header/Quick-Info/System-Info labels through `t()`. "Available Credit" card/counter shown only when the customer has a credit limit > 0. |

## Phase C — add what a POS shop needs
| # | Page | Change |
|---|---|---|
| C1 | `supplier_details.php` | New tab **"Stock Received" (Mizigo Iliyopokelewa)** from `product_batches.supplier_id` (written by Pokea Mzigo): S/No, date, product, shop, qty, unit cost, total; summary = total bought, deliveries, last delivery. Warehouse-scoped for non-admins. Default tab in Simple mode. |
| C2 | `customer_details.php` | Purchase summary: total bought (net of returns, same recognition rules as the POS dashboard), number of purchases, last purchase, average, top 5 products. |
| C3 | `product_view.php` | Simple mode Basic Info: **Barcode** (read-only, when set — it is what "Print Barcode" prints), **wholesale price from the Wholesale price group** (what POS really charges), **last delivery** (supplier, unit cost, date), **days of stock left** (available ÷ average daily sales, last 30 days). Batches: Supplier column where Source GRN is hidden. |
| C4 | `service_view.php` + `services.php` | **Edit** button (opens the service's edit form via `services?edit=N`); **sales summary**: times sold, quantity, revenue, last sold + last 10 sales. |

---

## Safety rules for every phase
- Gate with existing helpers only (`bmsRouteAvailable`, `tenantFeatureEnabled`, `projectsModuleActive`, `posSimpleModeEnabled`, `canView`). Advanced / all-modules-on tenants keep today's links.
- Never hide non-zero data (Reserved, Suspended suppliers, GRN refs).
- No schema change, no write-path change except the read-only restock pre-select.
- New SQL: prepared, `status`/void filters, warehouse scope for non-admins.

## Tests (each phase, then all together)
`tests/test_pos_detail_pages_cli.php` renders the real pages as a forged admin **twice**:
(1) POS + Warehouse only + Simple Mode, (2) all modules on. It asserts:
- every rendered internal link passes `bmsRouteAvailable()` (no 404 links) in (1); PO / vendor-statement links still present in (2);
- the A5 sign on a fixture sale / stock-in; names not emails; Swahili strings;
- B/C sections present/absent per mode; numbers match direct SQL on fixtures;
- no PHP warnings/notices in any render; lint on every touched file.
Plus existing suites: feature registry/gating, pos_simple_ux, products/suppliers/customers tests that exist.
