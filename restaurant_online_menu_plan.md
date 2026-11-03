# Restaurant Online Menu & Ordering — Plan

Status: **BUILT 2026-11-03** — all 6 phases done (owner: "yes go ahead … hackers should not get any weakness").
Look: Preline "Storefront" listing-grid style (rebuilt ourselves; their templates are a paid licence).
Branch `feat/restaurant-online-menu` (worktree `bms-onlinemenu`) → PR into `develop`.

## Goal
A restaurant shop can share **a link + QR codes**. Anyone who opens it sees the dishes and
drinks available now (Storefront-style, phone first, no login) and can **send an order**.
Staff **accept** it → it joins the table's order / the kitchen exactly like a waiter's order.
Payment stays with the waiter/cashier, so accounting is untouched.

## Hard rules (security)
1. **The customer only asks; staff decide; the server prices.** A public order is a *request*
   (`pending`). Nothing reaches the kitchen, a table, stock or the ledger until a logged-in
   staff member with `canCreate('pos')` and warehouse scope accepts it.
2. **Server-side pricing only.** The browser sends `product_id`, `qty`, `option_ids`, a note.
   Every price, name, modifier rule (required / min / max / belongs to this dish) and
   availability is re-read from the database. Client totals are never read.
3. **Unguessable links.** Shop token and per-table token = 128-bit random (`random_bytes(16)`),
   unique-indexed. Unknown, revoked, disabled-shop, module-off → the *same* generic
   "This menu is not available" page (no oracle). Owner can make new links at any time
   (old ones die instantly). Tokens are stored as-is (unlike the shop-catalog link) because a
   printed table QR must be re-printable; a token only opens the public menu and lets
   someone *request* an order — it is never a staff credential.
4. **No data leak.** Public pages/APIs return only: shop name, logo, dish name, photo,
   category, price, modifier names/prices, availability. Never cost, stock numbers, staff,
   other orders, customers, ids of other shops. The order status page shows only that order.
5. **Isolation.** Public files never read `$_SESSION` for identity (a staff member opening
   the link in their own browser gets the same public view), never call `includeHeader()`.
   Tenant comes from the host (existing resolver); each tenant DB is separate.
6. **Abuse limits.** Per device+IP: max 3 open orders, 6 orders/hour; per table max 3 open;
   per shop max 40 open; orders blocked when the shop's online ordering is off. Signed
   form token (HMAC, per-tenant secret in settings) with min 2 s / max 12 h age + honeypot
   field → stops scripted floods and cross-site form posts. JSON-only POST (no CORS
   headers sent → other sites' scripts cannot post). IPs stored only as salted hashes.
7. **Input limits.** ≤ 30 lines, qty 1–20, note ≤ 200 chars, name ≤ 60, phone digits
   7–15. All output escaped (`htmlspecialchars` server side, `textContent` client side).
8. **Headers on public pages.** `Content-Security-Policy` (self + jsdelivr, `frame-ancestors
   'none'`), `Referrer-Policy: no-referrer` (the token in the URL must never leak to a CDN
   or a clicked link), `X-Content-Type-Options: nosniff`, `X-Robots-Tag: noindex`,
   `Cache-Control: no-store` on APIs.
9. **Gate.** Works only while: tenant has `restaurant_pos`, Smart POS on, schema present,
   shop active and in Restaurant/Hybrid mode, owner switched the menu on. Re-checked on
   every request (turning any of these off kills every link immediately).

## Data (tenant migration + `_legacy_db` twin, idempotent, additive)
- `warehouses`: `online_menu_token CHAR(32) NULL UNIQUE`, `online_menu_enabled TINYINT(1) 0`,
  `online_order_table TINYINT(1) 0`, `online_order_pickup TINYINT(1) 0`.
- `restaurant_tables`: `qr_token CHAR(32) NULL UNIQUE`.
- `restaurant_online_orders`: id, warehouse_id, table_id NULL, order_type
  ENUM('table','pickup'), order_code (short, shown to customer & staff, e.g. `W-0042`),
  public_ref CHAR(32) UNIQUE (status page key), customer_name, customer_phone, note,
  items_json (server-built), subtotal, status ENUM('pending','accepted','ready','completed',
  'rejected','cancelled'), reject_reason, hold_id, handled_by, client_hash CHAR(64),
  created_at, updated_at, accepted_at. Indexes on (warehouse_id,status), (client_hash,created_at).

## Phases
1. **Foundation** — migrations; `core/restaurant_online.php` (gate, token create/rotate/
   resolve, public menu query, order build+price+validate, limits, form token, IP hash).
2. **Public menu page** `/m/<token>` (Storefront look): header with logo + name + "Table 5",
   EN/SW, search, category chips, 2-col photo grid (3–4 on desktop), "Popular"-style
   badges where data exists, sold-out greyed. Standalone CSS (Bootstrap 5 + own tokens).
3. **Ordering** — dish bottom-sheet (photo, modifiers obeying required/min/max, qty, note),
   sticky basket bar, review sheet, pickup name+phone, send → `/m/<token>/o/<ref>` status
   page (Received → Accepted → Ready, or Rejected with reason), polling.
4. **Staff: Share menu** — button on Restaurant Menu page → modal: switch menu on/off,
   table ordering on/off, pickup on/off, link (copy / WhatsApp / open), QR download,
   "Print table QR codes" sheet (one card per table), "Make new links".
5. **Staff: Online orders** — Restaurant → Online orders page (polls, sound on new),
   Accept / Reject (reason) / Ready / Done. Accept table order → merged into that table's
   open held order (same `items_data` cart shape the till uses, transaction + row lock),
   table occupied, then sent to the kitchen when the kitchen mode ≠ none. Accept pickup →
   held order for the accepting staff member ("ONLINE W-0042 · Name"), then kitchen.
   Small pending-count badge + toast on restaurant staff screens.
6. **Tests** — security (forged prices, foreign product/option, unavailable dish, bad/
   rotated token, module off, limits, honeypot/timing, XSS in name/note, no cost/stock in
   output, headers) + real end-to-end over HTTP: open menu → order → staff accept → table
   held order + kitchen ticket → status page shows Accepted.

## Out of scope (v1)
Online payment, delivery addresses, customer accounts, SMS/OTP (can be added if abuse appears).
