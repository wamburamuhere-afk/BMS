# Retail Online Shop (Smart POS) + Online-Order Record Strengthening — Plan

Status: **IN PROGRESS** — owner approved 2026-11-05 ("yes go ahead", incl. the 3 extra suggestions). Branch `feat/retail-online-shop` (worktree `bms-online-shop`).
Phase 1 DONE (schema, engine, customer rule, alerts registered; 51 checks).
Scope A: **Smart POS retail shops only** (`posSimpleModeEnabled()`); not Smart BMS.
Scope B: **Restaurant online orders** — strengthen the record (owner asked), no change to how
the restaurant menu looks or works for customers.
Reference: `restaurant_online_menu_plan.md` — same look, same security rules, same order engine.

## Goal (owner's words)
"A Smart POS customer can post a link / QR code of the products they registered, so a buyer
can order online. The buyer must not see shop quantities — if at least one is in stock they
can order. I get the order in the system and also by email."

## Agreed decisions
1. **Every option is a switch the owner sets per shop before sharing** (all off by default):
   - Show my shop online
   - Let customers order
   - Pickup (on/off) · Delivery (on/off) — when ordering is on, at least one must be on
     (save is refused otherwise). Delivery asks for address/area; **fee agreed by phone** (no
     fee field for now).
   - Also show wholesale prices (off by default — see §Prices)
   - Show our payment numbers (on/off — numbers come from `payment_settings.php`)
2. **One shop** → the Share window opens straight on it (like one restaurant).
   **Several shops** → shop picker at the top; each shop has its own link, stock, orders.
3. **Alerts:** bell + email to admins by default; anyone else is added in
   `notification_rules.php` (same as restaurant alerts).
4. **Payment:** no payment inside the system. If the owner switches it on, the order
   confirmation + status page show the company's bank / mobile-money numbers from
   `payment_settings.php` (`getPaymentAccounts()`), with "Use your order number S-0012 as the
   reference". The system **never** marks an order paid by itself. Staff open the order in
   **Held orders** on the till and choose the payment method exactly as today — cash, mobile
   money, bank, or **Credit** (customer required, credit limit checked, deposit optional).
5. **Customers from online orders (no duplicates):** on Accept, match an existing customer
   **only if BOTH phone and name match**; otherwise create a new customer. Phone-only or
   name-only match = new customer. Matching is tidy, not exact-text: phone compared as digits
   with `0…`/`255…`/`+255…` treated the same; name compared ignoring case and extra spaces.
   The customer is created **on Accept, never when the buyer sends** (spam orders must not
   fill the customer list). The held sale is linked to that customer so Credit works at once.

## Prices (research result — tell the owner)
Today a product has **one price for all shops**: restock writes `products.selling_price`
(`api/pos/quick_restock.php:240`) and one Wholesale price-group price — restocking in Shop B
changes Shop A's till price too. So each shop page shows **exactly what that shop's till
charges** (today: the same everywhere). Per-shop prices would be a separate project (till,
restock, reports); the online page would follow it automatically.
Wholesale switch ON → each product shows both prices and the buyer picks Retail or Wholesale;
the order clearly says which. Staff decide at Accept (anyone online can claim wholesale);
the cashier can still change price at the till.

## Hard rules (security — from the restaurant plan, non-negotiable)
1. **Buyer only asks, staff decide, server prices.** Nothing touches stock, a sale, a customer
   record or the ledger until a logged-in user (`canCreate('pos')` + warehouse scope) accepts.
   Stock + ledger move only when the cashier completes the sale (existing POS posting).
2. **Server-side pricing + stock.** Browser sends `product_id`, `qty`, price type, note.
   Price, name, visible flag, "qty ≤ available" re-read from DB. Over-stock → "Only a few
   left — reduce the quantity" (never the number).
3. **Unguessable link** (128-bit, unique index). Unknown / off / shop inactive / Smart POS
   off → the same generic "This shop is not available" page.
4. **No data leak.** Public output: shop name, logo, phone, product name, photo, category,
   price(s), Available/Sold out, and payment numbers only if switched on.
5. Reuse restaurant helpers for form token + honeypot, abuse limits, input limits, security
   headers (`roFormToken`, `roCheckLimits`, `roClientHash`, `roSendSecurityHeaders`,
   `roCleanText`). Decimal qty allowed for kg/litre units (`core/pos_quantity.php`).

## Online order → receipt (the record, both restaurant and retail)
```
Buyer sends ──► Waiting (request only: no receipt, stock, customer, accounts)
   Staff Accept ──► customer matched/created ─► Held order "ONLINE S-0012 · Name" (linked to customer)
       Cashier completes on till (cash / mobile / bank / Credit) ──► Receipt (pos_sales)
            ├─ stock + ledger posted (existing)
            ├─ receipt stores online_order_id  ─► receipt shows "Online order S-0012"
            └─ online order auto-set Collected/Done (no manual button needed)
   Staff Reject (reason) ──► buyer sees reason
   Not collected ──► "Cancel – not collected" (reason) removes the held order; reminder after N hours
```
Fixes over today's restaurant flow:
1. **Auto-close:** completing the sale marks the online order Done/Collected (`process_sale.php`
   reads `online_order_id` from the held sale's lines). Manual Done stays only for edge cases.
2. **Customer link** per decision 5 (pickup/delivery orders; table orders keep the table).
3. **Receipt ↔ order link:** `restaurant_online_orders.sale_id` (set when the till completes the
   sale) — one sale can carry several online orders (a table bill) and a table bill paid in parts
   stays traceable, which a single `pos_sales.online_order_id` could not do. Shown on the receipt
   and in Sales history; filter "Online orders" in Sales history.
4. **Not collected:** Cancel action (reason, logged) deletes/cancels the held order; reminder
   alert `*.online_order_uncollected` after N hours accepted-but-unpaid.
5. **Void safety:** voiding a receipt that came from an online order puts the order back to
   Accepted (not Done) and logs it.
6. **Audit:** every transition (accept, reject, cancel, collected, linked customer) →
   `logActivity` + `logAudit`.

## Design decisions
- **One engine, not two.** Reuse `restaurant_online_orders` + `core/restaurant_online.php`.
  Add `order_type` values `'shop_pickup'`, `'shop_delivery'`; new columns `price_type`,
  `delivery_address`, `customer_id`, `sale_id`, `cancel_reason`. Status enum gains
  `'not_collected'`. Table name stays (rename = churn).
- **Own link per shop**, route `/s/<token>` (restaurant keeps `/m/<token>`).
- **Warehouse columns:** `online_shop_token`, `online_shop_enabled`, `online_shop_ordering`,
  `online_shop_pickup`, `online_shop_delivery`, `online_shop_wholesale`,
  `online_show_payment` (restaurant gets `online_show_payment` too). Product `online_hidden`.
- **Old shared links keep working:** `shop_catalog.php?token=…` → look up by old hash → if that
  shop is online, 301 to `/s/<token>`; else the generic page.
- Order code prefix **S-** (restaurant W-).

## Phases
### Phase 1 — Schema + shared engine
- Migration `migrations/tenant/2026_11_05_online_shop_and_order_record.php` (idempotent,
  criteria-based): all columns above, `pos_held_sales.online_order_id` (any staff of that shop
  can load an accepted online order, not only who accepted it).
- `core/online_customer.php`: `ocNormalisePhone`, `ocNormaliseName`, `ocFindOrCreate`
  (both-match rule, created on Accept only).
- `core/retail_online.php`: `rsShopLinks`, `rsEnsureLink`, `rsResolveToken`, `rsPublicCatalog`
  (Available/Sold out only), `rsBuildOrder`, `rsPlaceOrder`.
- Rebuild tenant template (`scripts/build_tenant_template.php`).

### Phase 2 — Restaurant record strengthening (Scope B)
- Accept links/creates customer (pickup orders), held sale gets `customer_id`.
- `process_sale.php`: store `online_order_id`, auto-mark order Done; receipt + Sales history
  show the online order; void puts it back to Accepted.
- "Cancel – not collected" + uncollected reminder. Payment-numbers switch on Share menu and
  shown on the restaurant order status page.
- Restaurant behaviour otherwise unchanged; existing restaurant tests must still pass.

### Phase 3 — Share shop window (retail staff)
- `includes/retail/online_shop_share.php`, same look as "Share menu": shop picker (only when
  >1 shop), all switches from decision 1 with one-line explanations, link + Copy (toast
  "Link copied"), WhatsApp, Open, Download QR, Make new link.
- `api/stock/online_shop.php` (GET / save / rotate) — `canEdit`, warehouse scope, CSRF.
- Buttons on Products, Online orders, shop detail page (replaces old Generate/Revoke card).
- Products page: "Show online" toggle per product.

### Phase 4 — Public shop page (buyer)
- `public_shop.php` on the `public_menu.php` design: logo, shop name, phone, EN/SW, search,
  category chips, product cards (photo, name, price(s), Available/Sold out), cart bar, cart
  sheet (+ / −, note), checkout: name, phone, Pickup/Delivery choice (only the ones on),
  address if Delivery. Confirmation + status page `/s/<token>/o/<ref>` (polling) with payment
  numbers + reference when switched on.

### Phase 5 — Retail Online orders inbox
- Page in the Smart POS menu, per shop, scoped: Waiting / In progress / Done today.
  Accept (stock re-check, customer match, held order), Reject (reason), Ready,
  Cancel – not collected. Nav badge. Held orders on the till show the online order.

### Phase 6 — Alerts
- `retail.online_order_new` (instant), `retail.online_order_waiting` (not accepted after N min),
  `retail.online_order_uncollected` (accepted, unpaid after N hours) via `core/notify.php`:
  bell + email, admins by default, others via `notification_rules.php`. Tests capture mail.

### Phase 7 — Tests + click-through
- CLI tests: gates, no stock numbers in any public response, price tampering ignored,
  over-stock refused, hidden product refused, switches enforced (pickup/delivery/ordering),
  customer rule (both match → reuse; phone-only / name-only → new; created only on Accept),
  accept → held → complete (paid + credit) → receipt has online_order_id, order auto-Done,
  stock + ledger correct; void → order back to Accepted; not-collected cancel; old catalog link
  redirect; alerts + email captured; restaurant suite unchanged.
- Browser at 390 px + desktop: share → order on phone → bell → accept → pay at till → receipt.
- Changelog, PR into `develop`.

## Not in scope
Payment inside the system (gateway), delivery fees, per-shop prices, customer login, Smart BMS
shops, mobile API (add later + republish the API doc).

## Open suggestions (owner to confirm)
- **Staff hint on Accept:** if the phone already belongs to a customer with a different name,
  show "Phone already used by *Juma Ali* — link to this customer instead?" Default stays the
  agreed rule (new customer); staff can choose to link. Prevents duplicates from typos.
- **Buyer can cancel** their own order while it is still Waiting (from the status page).
- **Order expiry:** Waiting orders not accepted within 24 h auto-expire (buyer sees "expired").
