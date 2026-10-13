# Roadmap

Goal of **Step 1**: sellable to real cafés and restaurants. Step 2 adds features that beat competitors. Status as of 7 Oct 2026.

## Owner's feature list → where it lands

| Feature | Step | Status |
|---|---|---|
| Call waiter / request bill | 1A | ✅ done |
| Order tracking for customers | 1A | ✅ done (instant updates in B6) |
| Waiter app | 1A + B2 | ✅ calls, ready-to-serve, sold-out, waiter takes an order for a table |
| Sold-out toggle | 1A | ✅ done (waiter screen + back office) |
| Telegram alerts | 1A + B5 | ✅ new order · daily sales summary after each branch day ends |
| Payments (cash / KHQR marked paid) | B1 + B2 | ✅ cashier screen, cash USD/KHR with change, KHQR/card, discounts, void, refund |
| Shift close | B3 | ✅ start with counted cash ($ + ៛), cash in/out, blind count, difference; back-office Shifts page |
| Printing (receipt + kitchen ticket) | B4 | ✅ 80 mm bill/receipt (Khmer + English, riel, KHQR picture), kitchen/bar tickets, auto-print option |
| Export (Excel) + reports | B5 | ✅ dashboard (today, 7 days, methods, best sellers), Daily sales page, CSV exports for Excel (orders, payments, items) |
| Upsell suggestions | 2 | basic version done (Goes well with pop-up; special price + reports still planned) |
| Floor plan (drawn table layout on staff screens) | extra | ✅ backend done: `floor_plans`, `GET/PUT /api/staff/branches/{id}/floor-plan` (owner/manager edit) |
| Restaurant branding (logo, cover photo, tagline) | extra | ✅ logo required at sign-up; cover + tagline on the customer menu |
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

### B3 – shifts ✅ done (7 Oct 2026)
`shifts`, `cash_movements`; open shift with counted cash; payments require an open shift; close shift → expected vs counted, difference; back-office Shifts page.

### B4 – printing ✅ done (7 Oct 2026)
Browser print, 80 mm layout: customer receipt (company, branch, items, totals, riel, KHQR image from settings, footer) and kitchen/bar ticket per station. Branch print settings (header/footer text, auto-print kitchen tickets on new order).

### B5 – reports ✅ done (7 Oct 2026; exports are CSV files that open in Excel, no extra package)
`daily_branch_sales`, `daily_item_sales` updated by a queued job on payment / refund / void; `reports:rebuild`; back-office dashboard (today, 7 days, payment methods, best sellers); Excel export (orders, payments, items) ; Telegram daily summary at branch day end.

### B6 – live updates ✅ done (7 Oct 2026)
Laravel Reverb + Echo in Next.js: channels `branch.{id}` (staff, private) and `table.{token-hash}` (customer). Keep polling as a fallback.

## Plans: feature locking, then billing

### C1 – feature locking (next)
The list of plan features and whether each is built live in `App\Enums\Feature` (start page shows unbuilt ones as "Coming soon"); names/order in `/admin > Plan features`, which plan has which in `/admin > Plans`. Today nothing checks them: every restaurant can use every built feature. To do:
- `Company::hasFeature(Feature)` reads the current plan (via the subscription), so ticking a feature on a plan applies to every restaurant on it at once.
- **Owner back office:** show a missing feature **locked** with "Available on <plan>. Call us to upgrade".
- **Staff screens and customer pages:** **hide** it (staff can't upgrade; guests never see upgrade messages).
- **Server refuses too:** staff/public APIs answer 403 + `code: feature_not_in_plan`, like `StaffAccess` does for roles.
- Built features: printing (hide Print buttons; lock printer settings), export (lock CSV actions), telegram (lock settings, skip the day-end summary), upsell (lock the suggestions field, no "Goes well with" pop-up). Printing stays a plan feature (owner's decision, 13 Oct 2026).
- Moving to a smaller plan deletes nothing: data is kept, just not shown, and comes back on upgrade.
- A test fails if a feature is used in the code but not marked built, or marked built but never checked.

### C2 – billing restaurants (when there are ~5–10 paying restaurants; until then by hand)
Rules decided with the owner (13 Oct 2026):
- **Upgrade** (e.g. Starter → Pro): starts **at once**; pay only the difference for the days left in the period: `(new price − old price) × days left ÷ days in period`, in cents, rounded down. Starter $19 → Pro $39 on day 16 of 30: pay $10 now, $39 at the next renewal. The renewal date does not change. Yearly plans: same with days in the year.
- **Downgrade** (e.g. Pro → Starter): starts at the **next renewal**; **no refund**. They keep Pro until the end of the period already paid.
- **Snapshot the price** on the subscription when it starts, so later price changes in `/admin > Plans` don't change what existing restaurants pay.
- **Keep history:** a plan change ends the old subscription and starts a new one (today `CompanyResource::changePlan` overwrites `plan_id`; fix this). Every charge is an invoice row, never deleted (void with a reason).
- Payment: first **manual** (ABA / KHQR transfer, platform admin confirms in `/admin`), automatic check later. Optional grace: upgrade now, pay within 3 days, else back to the old plan.
- Still to decide: renewal reminders and what happens when a restaurant doesn't pay (grace days, then read-only?).

**Until C2 is built:** note payments by hand; on an upgrade, work out the difference with the rule above and use **Change plan** in `/admin > Restaurants`.

## Step 2 (after Step 1 is selling)
Upsell, combos, feedback, multi-language, kiosk, multi-branch comparison, automatic KHQR, split bill by person. Design for each: `docs/step2-features.md`.
