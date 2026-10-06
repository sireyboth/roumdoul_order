# Architecture and data flow

Diagrams: the "Roumdoul Order System" page, https://claude.ai/artifact/XFXdQJczECUWxJoNUX5Rvi (private to the owner). This file is the text version; keep both in sync when the design changes.

## Parts

```
 Customer phone ──► Next.js (frontend/)  ──► Laravel API (backend/) ──► MySQL
 Staff tablet   ──►   /t/{token}             /api/public/...            (cache: DB/Redis)
                      /staff/*  + /api/* proxies  /api/staff/* (Sanctum)
 Owner browser  ─────────────────────────► Filament /app/{company}
 Roumdoul team  ─────────────────────────► Filament /admin
                                             │
                                             └─► Telegram (after response)
```

- Only Laravel writes to the database.
- Next.js holds no data; it renders and proxies. `API_URL` is server-only.
- Rate limits are per QR token (`public-read` 240/min, `public-write` 20/min), because all customer traffic reaches Laravel from the Next.js server's IP.

## Who uses what

| Person | Screen | Auth |
|---|---|---|
| Customer | `/t/{qr_token}` | none – the token is the identity |
| Kitchen / bar | `/staff/kitchen?branch=&station=` | staff login → Sanctum token (localStorage) |
| Waiter | `/staff/waiter?branch=` | same |
| Cashier (B2) | `/staff/cashier?branch=` | same |
| Owner / manager | `/app/{slug}` Filament | session; only owner/manager roles |
| Roumdoul team | `/admin` Filament | `users.is_platform_admin` |

## Flow 1 – scan and order

1. Phone opens `/t/{token}`. Next.js server calls `GET /api/public/tables/{token}`.
2. `MenuBuilder::resolveTable()` finds the table (token lookup cached 10 min, forgotten when the table changes), loads company and branch fresh, checks: table active, branch active, company active or in trial.
3. Branch menu comes from cache key `menu:{branch}:{company.menu_version}.{branch.menu_version}`; on a miss it is built from `menu_items` + `branch_menu_items` (hidden items removed, branch price applied, `sold_out_until` passed through so the phone can grey items out).
4. Customer builds a cart (localStorage, per token + menu version) and taps Send. Phone → `POST /api/t/{token}/orders` → Laravel `POST /api/public/tables/{token}/orders` with `idempotency_key`, item ids, option ids, quantities, notes.
5. `OrderPlacer`: re-reads items/options/branch settings, rejects hidden/sold-out/invalid choices, computes prices, then in one transaction: lock branch + table → find or open `table_sessions` → next daily `number` → insert `orders` + `order_items` → audit log.
6. After the response: Telegram alert to the company chat.
7. Phone polls `GET .../session` every 5 s for status (Received → Preparing → Ready → Served).

## Flow 2 – kitchen and waiter

1. Staff log in (`POST /api/staff/login`) → token + branches they may work at (`StaffAccess`).
2. Kitchen polls `GET /api/staff/branches/{id}/board?station=kitchen|bar` every 3 s: active orders (placed/accepted/preparing/ready, plus served in the last 10 min) with items filtered by station, and open service requests.
3. Buttons call `POST /api/staff/orders/{id}/status` → `Order::moveTo()` (allowed moves only, timestamps, audit log). Cancel: owner/manager/cashier with reason.
4. Waiter screen: calls (`POST /api/staff/requests/{id}/done`), ready orders → Served, sold-out toggle (`POST /api/staff/branches/{id}/menu/{item}/sold-out` → `branch_menu_items.sold_out_until` → branch menu version bump → customers see it on next load).

## Flow 3 – menu changes

Owner edits in Filament → model saved → `BumpsMenuVersion` increments `companies.menu_version` (company-wide changes) or `branches.menu_version` (branch settings) → next menu request uses a new cache key. New items/branches get `branch_menu_items` rows via `MenuSync`.

## Flow 4 – money (part B, planned)

1. Customer requests bill or cashier opens the table → `bills` row (one per session): subtotal from non-cancelled orders, discounts, service charge, VAT, total, riel total, rate snapshot.
2. Cashier takes one or more `payments` (cash with tendered + change in USD/KHR, KHQR with reference) inside an open `shifts` row.
3. When `paid_total >= total`: bill `paid`, session `closed` (table free; old QR session ends).
4. Queued job updates `daily_branch_sales` / `daily_item_sales` for the bill's business date.
5. Shift close: expected cash (opening + cash payments − change ± movements) vs counted.

## Status tracks

- Order: placed → accepted → preparing → ready → served → completed; cancel from placed/accepted/preparing (reason). ready → preparing allowed (sent back).
- Table session: open → bill_requested → closed (closed only by full payment, part B).
- Service request: open → done.
- Bill (B): open → paid | void. Payment (B): confirmed → refunded.
