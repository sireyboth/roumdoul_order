# Roadmap

Goal of **Step 1**: sellable to real cafés and restaurants. Step 2 adds features that beat competitors. Status as of 7 Oct 2026.

## Owner's feature list → where it lands

| Feature | Step | Status |
|---|---|---|
| Call waiter / request bill | 1A | ✅ done |
| Order tracking for customers | 1A | ✅ done (instant updates in B6) |
| Waiter app | 1A + B2 | ✅ calls, ready-to-serve, sold-out, waiter takes an order for a table |
| Sold-out toggle | 1A | ✅ done (waiter screen + back office) |
| Telegram alerts | 1A + B5 | ✅ new order · ⏳ daily summary (B5) |
| Payments (cash / KHQR marked paid) | B1 + B2 | ✅ cashier screen, cash USD/KHR with change, KHQR/card, discounts, void, refund |
| Shift close | B3 | ⏳ |
| Printing (receipt + kitchen ticket) | B4 | ⏳ |
| Export (Excel) + reports | B5 | ⏳ |
| Upsell suggestions | 2 | planned |
| Combo / set menus | 2 | planned |
| Customer feedback after paying | 2 | planned |
| Multi-language menu (Chinese etc.) | 2 | planned. Chinese names exist on categories/items only; translatable JSON names proposed for B1 |
| Ordering kiosk mode | 2 | planned |
| Multi-branch comparison | 2 | planned (reads `daily_branch_sales`) |
| Automatic KHQR confirmation | 2 | planned (needs bank merchant approval – start the application early) |
| Split bill by person | 2 | recommended for Step 2 |
| AI menu import (photo → menu) | 3 | future |
| Pre-order / pickup | ? | owner still deciding (B1 prepares orders without a table) |
| Delivery integration | ? | owner still deciding |

## Done

### Step 0 – foundation
Multi-tenant Filament back office, platform admin, plans & limits, self sign-up with 14-day trial, branches, table areas, tables with QR (show/download/regenerate, add many), menu with Khmer/English names, option groups with price deltas, per-branch availability/price/sold-out, staff with roles and manager PIN, audit log, cached public menu API, Next.js customer menu with cart (USD + riel).

### Step 1 part A – ordering
`table_sessions`, `orders`, `order_items`, `service_requests`; `OrderPlacer` (server-side pricing, option validation, sold-out/hidden checks, idempotency, daily numbering, business date); customer send order / tracking / call waiter / request bill; staff login (Sanctum); kitchen & bar board; waiter board with sold-out toggle; Filament Orders page with cancel + reason; Telegram new-order alert. 45 backend tests.

## Next: Step 1 part B

Build in this order, each with tests and a browser run.

### B1 – database fixes + bills & payments ✅ done (7 Oct 2026; translatable names still wait for the owner)
- Migration: change company/branch FKs on `orders`, `order_items`, `table_sessions` to `restrictOnDelete`.
- Migration: `orders` unique `(dining_table_id, idempotency_key)` → `(branch_id, idempotency_key)`.
- MySQL only: generated column + unique index for one open session per table (skip on SQLite).
- New tables `bills`, `bill_adjustments`, `payments` (see `docs/database.md`).
- If the owner approves: translatable JSON names on categories, menu items, option groups, options + `companies.languages` (fix #6 in `docs/database.md`).
- `App\Services\Billing\BillCalculator`: subtotal from non-cancelled orders → discounts → service charge → VAT (respect `prices_include_vat`) → total, `total_khr` rounded to 100៛. Pure function, unit-tested.
- `BillService::openFor(TableSession)` (idempotent, one bill per session, daily receipt number), `addPayment()` (transaction + lock, cannot overpay beyond change, closes bill and session when fully paid), `void()`, `refund()` (manager PIN + reason → audit log).
- Tests: totals with VAT inclusive/exclusive, service charge, discount %, KHR rounding, split cash + KHQR, overpay → change, duplicate payment key, closing the session, PIN required.

### B2 – cashier screen + waiter takes orders ✅ done (7 Oct 2026)
- Staff API: list open sessions with bill preview, open bill, add discount (PIN), take payment (cash with tendered/change in USD or KHR, KHQR with optional reference), void/refund (PIN).
- Next.js `/staff/cashier`: tables grid with amounts and "bill requested" badge → bill detail → pay.
- Waiter "New order for table" using the same menu components and `OrderPlacer` (`placed_by_user_id`, source `waiter`).
- Customer: after paying, tracking shows "Paid, thank you".

### B3 – shifts
`shifts`, `cash_movements`; open shift with counted cash; payments require an open shift; close shift → expected vs counted, difference; back-office Shifts page.

### B4 – printing
Browser print, 80 mm layout: customer receipt (company, branch, items, totals, riel, KHQR image from settings, footer) and kitchen/bar ticket per station. Branch print settings (header/footer text, auto-print kitchen tickets on new order).

### B5 – reports
`daily_branch_sales`, `daily_item_sales` updated by a queued job on payment / refund / void; `reports:rebuild`; back-office dashboard (today, 7 days, payment methods, best sellers); Excel export (orders, payments, items) ; Telegram daily summary at branch day end.

### B6 – live updates
Laravel Reverb + Echo in Next.js: channels `branch.{id}` (staff, private) and `table.{token-hash}` (customer). Keep polling as a fallback.

## Step 2 (after Step 1 is selling)
Upsell, combos, feedback, multi-language, kiosk, multi-branch comparison, automatic KHQR, split bill by person. Design for each: `docs/step2-features.md`.
