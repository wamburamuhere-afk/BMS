# Restaurant Order Flow — Waiter → Cashier → Kitchen → Waiter → Bill

Status: APPROVED by owner 2026-11-06 — ALL 5 PHASES BUILT 2026-11-07 on branch `feat/restaurant-order-flow`
(tests/test_restaurant_order_flow_cli.php 93/93; tenant migration verified; browser click-through as waiter/cashier/cook/owner).

## Why

Boss's rule: the **waiter** starts the order, the **cashier** confirms it, only then the **kitchen**
cooks, the kitchen hands the food to the waiter, and only food that reached the customer becomes a
sale (unless the customer cancels). A one-person restaurant must stay as simple as today.

Scout (2026-11-06) found the money side correct (sale = cashier closes the bill, paid or deni, one
ledger) but the people flow missing:

1. No cashier confirmation — "Send to Kitchen" goes straight to the kitchen.
2. No waiter/cashier split — saving, sending and paying all use `canCreate('pos')`.
3. Kitchen tickets are never linked to the bill: you can pay before cooking, paying/cancelling
   never closes the ticket (demo: 9 tickets stuck, oldest ≈ 3.7 days), a cancelled order keeps
   cooking, cooked-then-cancelled food leaves no record.
4. Bug: re-sending a table whose first round is already Ready/Served re-sends the WHOLE order
   (send_to_kitchen only updates tickets that are queued/preparing).
5. Bug: Move/Join table moves only queued/preparing/ready tickets, leaving served/other lines behind.

## Decisions (agreed with owner)

- **Sale counts when the cashier closes the bill** (paid or deni) — unchanged. Dine-in bill cannot
  be closed while food is still in the kitchen (team flow only).
- **Roles**: two new rights + reuse POS rights; four ready-made roles.
- **One setting**, "Cashier must confirm orders before the kitchen" — default OFF, so every live
  restaurant keeps working exactly as today. Steps skip by permission.
- **No account selection anywhere.** The only new posting is food waste for stock items, to the
  fixed Cost of Sales account (same account a recipe dish's cost already goes to). *Changed from the
  chat draft's "Kitchen waste" account: no new account needed, and spoilage belongs in cost of
  sales; the Waste report gives the detail.*
- **Retail is never changed** — everything sits behind `restaurantShopActive()`.

## Rights

| Right (page_key / verb) | Allows | Waiter | Cashier | Cook | Manager |
|---|---|---|---|---|---|
| `restaurant_orders` view/create/edit | take an order, add dishes, send, mark "Received" | ✅ | ✅ | – | ✅ |
| `restaurant_orders` delete | cancel items not yet started by the kitchen | – | ✅ | – | ✅ |
| `restaurant_orders` approve | confirm orders to the kitchen; approve cancelling food already cooking | – | ✅ | – | ✅ |
| `kitchen_display` view/edit | kitchen screen; Preparing → Ready | – | view | ✅ | ✅ |
| `pos` create (exists) | take payment, close the bill, give deni | – | ✅ | – | ✅ |
| `pos` delete (exists) | void a paid sale | – | – | – | ✅ |
| `restaurant_pos` view (exists) | module gate (tables, menu lists) | ✅ | ✅ | ✅ | ✅ |

Back-compat (migration): every existing role keeps what it can do today —
roles with `restaurant_pos` view get `kitchen_display` view+edit; roles with `pos` create get
`restaurant_orders` view/create/edit/delete/approve. So no live user loses a button.

Ready-made roles **Waiter, Cashier, Cook, Restaurant Manager** are created (if no role of that
name exists) when the setting is first turned ON, and by the migration for companies that already
have a restaurant shop. Admin keeps every right.

## The flow (setting ON, kitchen screen/printer)

| Step | Who | Ticket status |
|---|---|---|
| Waiter sends | `restaurant_orders` create | `pending` (Waiting for cashier) — unless the sender holds approve → straight to `queued` |
| Cashier confirms / returns | `restaurant_orders` approve | `queued` / `cancelled` (reason, waiter notified) |
| Kitchen | `kitchen_display` edit | `preparing` → `ready` (waiter notified) |
| Waiter "Received" | `restaurant_orders` edit | `served` |
| Cashier closes bill | `pos` create | sale; tickets linked (`sale_id`, `closed_at`) |

Skips: setting OFF → no `pending`; sender with approve → no `pending`; kitchen "By mouth" → no
tickets at all (setting has no effect); payer with kitchen right → "Mark all served and pay?"
instead of a hard block. Setting OFF: kitchen may still mark Ready → Served as today.

## Cancelling

- Not started (`pending`/`queued`): `restaurant_orders` delete (or the sender while pending) —
  ticket line cancelled, kitchen sees it gone. No money, no stock.
- Started (`preparing`/`ready`/`served`): needs `restaurant_orders` approve + a reason →
  `restaurant_order_cancellations` row (waste). Setting OFF: same record, any user who could
  remove it before (no new block), reason defaults to "Removed from the order".
- Removing a cooked dish from the cart (save to table / pay) goes through the same rule, so
  cooked food can never silently disappear from a bill.
- Waste posting: recipe dish → ingredients' stock out (`damaged`) + Dr Cost of Sales / Cr
  Inventory at ingredient cost; stock item → same at its cost; dish without recipe → record only
  (its ingredients were already costed when bought).
- Void of a paid sale → its tickets not yet served are cancelled (started ones recorded as waste).
- Online pickup "Cancel – not collected" → its tickets cancelled the same way.

## Clean-up

- Bill closed (not split) → current-order tickets get `sale_id` + `closed_at`; Ready → Served.
- Kitchen screen auto-closes tickets older than 12 h (pending → cancelled, others → served).
- Migration closes stale tickets of tables with no open order (criteria-based, idempotent).

## Phases

1. **Schema + rights** — migration (tenant + legacy): ticket statuses `pending`/`cancelled`,
   who/when columns, `closed_at`, `restaurant_order_cancellations`; permission rows + back-compat
   grants; feature registry + workflow keys; notification events; stale-ticket clean-up.
2. **Engine** — `core/restaurant_order_flow.php`: rights, current order, send (delta), confirm,
   return, advance/received, cancel (+ waste posting), bill guard + close, void, pickup cancel,
   ready-made roles.
3. **Endpoints** — `api/restaurant/order_flow.php` (pending/count/table status/confirm/return/
   received/cancel); send_to_kitchen, update_ticket_status, get_kitchen_tickets, hold_sale,
   process_sale, void_sale, online_orders, move/join, kitchen_slip, tables board wired to it.
4. **Screens** — till (waiter has no Pay; kitchen strip with Received/Cancel; Orders to confirm;
   pay guard prompts), kitchen screen, tables board badges, Selling Settings toggle, Waste &
   cancellations report.
5. **Tests** — `tests/test_restaurant_order_flow_cli.php` (every step, both modes, every role,
   retail unchanged, ledger balanced) + all existing restaurant/POS suites green.

After merge: republish the mobile API doc (hold_sale/process_sale gained optional fields + error
codes) — see project memory.
