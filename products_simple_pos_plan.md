# Products — Simple POS Simplification + Batch Manufacturing/Expiry Dates

Status: DRAFT — awaiting your confirmation before any code changes.

Gate: the simplified form applies only when **Simple POS is ON** and the new **"Advanced Product" superadmin override is OFF**. Normal tenants, and any Simple POS tenant a superadmin has explicitly switched "Advanced Product" on for, see the full form unchanged.

---

## 1. What already exists (so you know what's NOT being built from scratch)

- **Batches already exist**: `product_batches` (per-warehouse, `expiry_date`, `unit_cost`/`wholesale_price`/`selling_price`, `quantity_received`/`remaining`). Restock already writes one via `receiveProductBatch()` (`core/stock_intake.php`).
- **Product View already shows a Batches/Lots table** (Stock Information tab) with expiry countdown and Active/Expired/Exhausted badges, already using "Shop" terminology.
- **Expiry notifications already work**: `cron/run_notification_checks.php` scans `product_batches.expiry_date`, fires at 30/14/7/1 days remaining, dedupes so nobody gets the same alert twice, and delivers via the same in-app+email engine every other BMS alert uses — recipients are resolved automatically, scoped to that batch's shop.
- **Gap found**: Product *creation* doesn't create a batch at all today — "opening stock" just bumps a plain stock counter (`api/create_product.php`). Only Restock creates real batch rows. So a product's very first stock is currently invisible to the batch/expiry system.
- **Gap found**: no `manufacturing_date` column exists anywhere yet — genuinely new.

## 2. Schema changes (tenant migration)

- `product_batches.manufacturing_date DATE NULL` — new column, mirrors `expiry_date`'s nullability (not every product needs one).
- `core/stock_intake.php::receiveProductBatch()` — accepts and stores `manufacturing_date` alongside the `expiry_date` it already handles.
- **`api/create_product.php`'s initial-stock handling changes from a plain `product_stocks` insert to calling `receiveProductBatch()`** (same helper Restock already uses) — so a product's very first stock becomes a real, trackable batch, in **both** normal and Simple POS mode. This is the one structural change needed to make "Manufacturing Date & Expiry Date will be present in all environments" (your words) actually mean something — otherwise there's no batch for those dates to belong to.

## 3. Superadmin "Advanced Product" toggle

New 5th row in the existing Tenant → Point of Sale → More dialog (`app/superadmin/tenant_view.php`), built the same way "Simple Mode" already is:
- Tenant `system_settings` key `pos_advanced_product` + control-DB lock column `tenants.pos_advanced_product_locked` (superadmin-only, tenant admin never self-manages it — same as Simple Mode).
- New `core/tenant_admin.php` functions `tenantAdvancedProductStatus()` / `setTenantAdvancedProduct()`, new `actions/superadmin_tenant_advanced_product.php` — direct copies of the Simple Mode pair.
- New page helper `advancedProductEnabled()` in `core/pos_nav.php`, alongside `posSimpleModeEnabled()`.
- Every product page computes one flag: `$simpleProductForm = posSimpleModeEnabled() && !advancedProductEnabled();` — that's the single switch controlling everything below.

## 4. Product Create — field-by-field, Simple POS

Collapsing the current 4 tabs (General Info / Pricing & Profit / Inventory & Stock / Advanced Details) down to **one screen, no tabs**, when `$simpleProductForm` is true:

| Field | Simple POS | Why |
|---|---|---|
| Product Name | shown, required | — |
| Category | shown | still useful for a shop's own organization |
| Product Image | shown | visual matters even for a small shop |
| SKU | **hidden** — still auto-generated (same `generate_sku()` call, sent as a hidden input) | you asked to hide it; nothing downstream breaks since it's still populated |
| Barcode | **hidden** — same treatment as SKU (auto EAN-13, hidden input) | same |
| Description | **removed entirely** — not collected, stays empty | you asked to drop it |
| Buying Price (was "Cost Price") | shown, required, **relabeled** | your exact request |
| Selling Price | shown, required | — |
| Wholesale Price | **hidden** | not relevant to a small shop's normal workflow |
| Discount Rate | **hidden** | same |
| Tax Configuration | **hidden**, defaults to no tax | your exact request |
| Unit (pcs/kg/etc.) | shown, required | a shop still needs this |
| Shop (Warehouse) | shown **only if the user has access to more than one shop**; auto-assigned silently if exactly one | matches the pattern we already used for Expenses |
| Opening Stock (quantity) | shown | — |
| **Manufacturing Date** (new) | shown, optional | your request |
| **Expiry Date** (new) | shown, optional | your request — not forced required, since not every product expires |
| Track Inventory / reorder / min / max stock levels | **hidden**, tracking forced on, thresholds left unset | advanced inventory planning, not a small-shop concern |
| Weight / Dimensions | **hidden** | not relevant |
| Brand, Manufacturer, Model, Serial Number, Warranty, Shelf-Life-in-Days, Is Service, Is Taxable (the whole "Advanced Details" tab) | **hidden entirely** | this is exactly the tab your "Maelezo"/4th-section removal was describing |

Normal tenants, or a Simple POS tenant with "Advanced Product" switched on: unchanged, full 4-tab form exactly as today.

## 5. Product Edit — same rules, applied consistently

- Same hide-list as Create, applied identically (SKU/Barcode hidden but still editable-if-you-know-the-URL... no — genuinely not rendered, matching "make sure it's also not shown on Edit").
- The Phase 15/23/26/30/31 advanced blocks (Variants, Combos, Selling Units, Serials, Kitchen Station, Modifier Groups, Promotional Pricing) — already gated by `pos_advanced`/`restaurant_pos` entitlements a Simple POS shop is unlikely to hold anyway, but now **also explicitly gated** behind `!$simpleProductForm` so it's certain, not incidental.
- Per-warehouse stock-editing grid simplifies to a single quantity field when the user only has one shop; a short list (not the full grid) when they have more than one.
- Manufacturing/Expiry Date are **not** added to Edit — those belong to a specific batch (created at registration or at each Restock), not to the product record itself. Editing an existing batch's dates after the fact isn't part of this request; flag if you want that too.

## 6. Product View — lead with batches, simplify the rest

- Already shows the Batches/Lots table — kept as-is (it's already good, already "Shop"-labeled).
- In Simple POS: Sales Performance / Stock Movements / Additional Details tabs — collapsed down or hidden where they'd just show empty advanced fields (brand/manufacturer/etc. that Simple POS never collected). Stock Information (with its batch table) becomes the primary, default view.

## 7. Products list page + its own "Quick Add" modal

- SKU column hidden from the list in Simple POS (the table is hand-built `<th>`/`<td>`, not config-driven like the Expenses table — needs direct edits, care taken to keep DataTables' column indexes aligned).
- `products.php` has its **own separate, third product-creation modal** ("Quick Add Product", with its own SKU field) — gets the identical Simple-POS field treatment as the main Create form, for consistency.

## 8. POS Restock modal — add the two date fields

- `app/bms/pos/pos_modals_new.php`'s Restock modal gains **Manufacturing Date** and **Expiry Date** (both optional — forcing expiry on every restock would break for non-perishable goods).
- `api/pos/quick_restock.php` passes both through to `receiveProductBatch()` (it already calls this with `write_batch=true`; just adding the two new args).
- The already-built notification cron picks these up automatically — no notification code changes needed, just real dates flowing into `product_batches.expiry_date`.

## 9. Terminology fix (the stray "Ghala"/Warehouse text you spotted)

Two lines found, both the same bug — a translated string with "Warehouse" baked into it instead of going through the Shop-swap helper:
- `app/bms/product/product_edit.php:1139`
- `app/bms/product/products.php:2080`

Both: `t('Store / Warehouse Name')` → `wLabel('Store / Warehouse Name', 'Store / Shop Name')`.

## 10. Explicitly NOT changing
- Services (`services.php`) — separate page, not touched here (that's next, after Expenses and Products, per your own stated order).
- The existing product-level "Shelf Life (Days)" field (`expiry_days`) stays as-is for normal/advanced mode — it's a different, older concept (a generic day-count) from the new batch-level actual calendar dates; the two aren't merged.
- No new notification configuration UI — the existing cron's fixed milestones (30/14/7/1 days) and automatic recipient resolution are reused unchanged, since that's exactly what you described needing.

## 11. Suggested delivery order (given "slow but sure")

Given the size, I'd rather ship and verify this in phases rather than one giant change:
1. Schema + `receiveProductBatch()` + Create's initial-stock now writes a real batch (foundation everything else depends on).
2. Superadmin "Advanced Product" toggle (needed before the simplified form can be gated correctly).
3. Product Create simplification.
4. Product Edit simplification.
5. Product View simplification.
6. Products list + Quick Add modal.
7. POS Restock date fields.
8. Terminology fix (quick, can go anywhere, maybe bundled with #3).

Each phase gets its own test pass before moving to the next, same as the Expenses work.

---

**Please check this against what you meant — especially §4's field-by-field table (the exact hide/keep list) and §8 (dates optional, not required, on Restock) — and tell me if anything needs to change before I start.**
