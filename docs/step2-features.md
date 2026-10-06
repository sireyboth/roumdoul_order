# Step 2 features: design

Not built yet. Same content as the "Step 2 in detail" section of the online page. Build only after Step 1 is selling.

## Upsell: "Goes well with" pop-up
- **Customer:** after "Add to order", a bottom sheet shows 1–3 suggestions (photo, price, + Add). Optional deal price ("croissant $1.00 with any coffee"). Also a "You might also like" row in the cart. Shown once per added item; "No thanks" closes it.
- **Owner:** on each menu item, "Suggest with" (pick items, order them), optional special price. Report: shown / added / extra revenue.
- **Data:** `menu_item_suggestions (menu_item_id, suggested_item_id, sort_order, special_price null)`. Never suggest sold-out, hidden or already-in-cart items. `order_items.source = upsell` and `upsell_events (shown/added)` to measure. The server re-checks the special price when the order is sent (only valid if the trigger item is in the same order).

## Combo / set menus
- **Customer:** item with a "Set" badge; steps (pick a drink → pick a pastry), each with its own options; savings shown.
- **Owner:** create a combo item, add steps, allowed items per step, optional extra charge for premium choices.
- **Data:** `menu_items.type` (`single`/`combo`) + `combo_components (combo_item_id, step, menu_item_id, extra_price)`. Order = parent line + child lines; children go to their own station. Reports count the combo and its parts.

## Customer feedback after paying
- **Customer:** after the bill is paid: 1–5 stars + comment. 4–5 → "Review us on Google"; 1–3 → private to the manager.
- **Owner:** Google review link in settings; Telegram alert for 1–3 stars; average rating per branch/week.
- **Data:** `feedbacks (bill_id unique, rating, comment, tags)`. Only after payment (no fake reviews).

## Multi-language menu
- **Customer:** picker ខ្មែរ / EN / 中文 (more later), remembered on the phone; fallback en → km. Staff screens keep km + en.
- **Owner:** choose languages in settings; one input per language on categories, items, option groups, options. Later: AI suggests missing translations.
- **Data:** proposed JSON names `{"km","en","zh"}` on the 4 menu tables + `companies.languages` (see fix #6 in `database.md`). Order item snapshots keep all languages.

## Ordering kiosk mode
- **Customer:** full-screen counter tablet, order → big number "#23"; resets after 60 s idle.
- **Owner:** add a kiosk like a table and open its link on the tablet; choose pay-first or pay-later.
- **Data:** `dining_tables.kind` (`table`/`kiosk`/`counter`), `orders.source = kiosk`; kiosk orders have no table session and are paid per order. Same `OrderPlacer`.

## Multi-branch comparison
- **Owner:** side-by-side sales, orders, average bill, cancellations, best sellers per branch, busy-hours heat map, week vs last week. Chain plan.
- **Data:** reads `daily_branch_sales` / `daily_item_sales` (B5). No new tables.

## Automatic KHQR
- **Customer:** "Pay now" shows a KHQR with the exact amount; paid instantly; receipt on the phone.
- **Owner:** connect the bank merchant account (ABA / ACLEDA / Bakong). Apply early.
- **Data:** bank webhook → `payments` (method khqr, unique reference); `payment_events` log; verified + idempotent.

## Split bill by person
- **Cashier:** assign items to person 1, 2, 3… or split evenly; each part paid by any method.
- **Data:** `bill_splits` with their own payments; parts must sum to the bill; rounding goes to the last part.

## AI menu import (Step 3)
- **Owner:** upload 1–5 menu photos → review screen → Publish. Limited scans on the free plan.
- **Data:** AI output is a draft only; nothing goes live until published. Cost: a few cents per page.
