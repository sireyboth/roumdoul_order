# Database

MySQL 8 / MariaDB 10.4+ in production (XAMPP locally), SQLite in-memory for tests. All money columns are **integers in minor units** (USD cents, or whole riel when the company currency is KHR). Every restaurant-owned table has `company_id`.

This file lists the tables as they exist now (generated from the migrations; types are shown as SQLite reports them, see `backend/database/migrations` for exact MySQL types), then the tables planned for Step 1 part B. Laravel's own tables (cache, jobs, sessions, personal_access_tokens, password_reset_tokens) are left out.

## Relationship map

```
plans ─< subscriptions >─ companies ─< company_user >─ users
                              │
     ┌────────────────────────┼──────────────────────────────┐
  branches                 categories ─< menu_items        audit_logs
     │                     option_groups ─< options
     │                     menu_items >─< option_groups (menu_item_option_group)
     ├─< branch_user >─ users
     ├─< table_areas ─< dining_tables (qr_token)
     ├─< branch_menu_items >─ menu_items
     ├─< table_sessions ─< orders ─< order_items >─ menu_items
     │        │
     │        └─< service_requests
     └─ (part B) table_sessions ─1:1─ bills ─< payments >─ shifts ─< cash_movements
                                        └─< bill_adjustments
        (part B) branches ─< daily_branch_sales, daily_item_sales
```

## Platform & accounts

### `users`

Everyone who signs in: platform admins (`is_platform_admin`), owners, managers and staff. One user can work at several companies.

| Column | Type | Null | Default | References |
|---|---|---|---|---|
| `id` | integer |  |  |  |
| `name` | varchar |  |  |  |
| `email` | varchar |  |  |  |
| `phone` | varchar | yes |  |  |
| `email_verified_at` | datetime | yes |  |  |
| `password` | varchar |  |  |  |
| `is_platform_admin` | tinyint |  | 0 |  |
| `is_active` | tinyint |  | 1 |  |
| `locale` | varchar |  | km |  |
| `remember_token` | varchar | yes |  |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |

Indexes: `UNIQUE (email)`

### `plans`

Price list and limits (branches, tables, staff) plus feature keys. Edited in `/admin > Plans`.

| Column | Type | Null | Default | References |
|---|---|---|---|---|
| `id` | integer |  |  |  |
| `code` | varchar |  |  |  |
| `name` | varchar |  |  |  |
| `description` | varchar | yes |  |  |
| `max_branches` | integer | yes |  |  |
| `max_tables` | integer | yes |  |  |
| `max_staff` | integer | yes |  |  |
| `price_monthly_cents` | integer |  | 0 |  |
| `price_yearly_cents` | integer |  | 0 |  |
| `currency` | varchar |  | USD |  |
| `features` | text | yes |  |  |
| `is_active` | tinyint |  | 1 |  |
| `sort_order` | integer |  | 0 |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |

Indexes: `UNIQUE (code)`

### `companies`

A restaurant business = Filament tenant. Holds currency, riel rate, VAT/service %, status/trial, `menu_version`, Telegram chat id, link to coreos.

| Column | Type | Null | Default | References |
|---|---|---|---|---|
| `id` | integer |  |  |  |
| `name` | varchar |  |  |  |
| `slug` | varchar |  |  |  |
| `email` | varchar | yes |  |  |
| `phone` | varchar | yes |  |  |
| `logo_path` | varchar | yes |  |  |
| `timezone` | varchar |  | Asia/Phnom_Penh |  |
| `currency` | varchar |  | USD |  |
| `khr_per_usd` | integer |  | 4100 |  |
| `vat_bp` | integer |  | 0 |  |
| `service_charge_bp` | integer |  | 0 |  |
| `prices_include_vat` | tinyint |  | 1 |  |
| `status` | varchar |  | trial |  |
| `trial_ends_at` | datetime | yes |  |  |
| `menu_version` | integer |  | 1 |  |
| `telegram_chat_id` | varchar | yes |  |  |
| `coreos_company_id` | integer | yes |  |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |
| `deleted_at` | datetime | yes |  |  |

Indexes: `(coreos_company_id)`, `UNIQUE (slug)`, `(status)`

### `subscriptions`

Which plan a company is on. `Company::subscription()` = latest row.

| Column | Type | Null | Default | References |
|---|---|---|---|---|
| `id` | integer |  |  |  |
| `company_id` | integer |  |  | companies.id (cascade) |
| `plan_id` | integer |  |  | plans.id (restrict) |
| `status` | varchar |  | active |  |
| `interval` | varchar |  | monthly |  |
| `starts_at` | datetime | yes |  |  |
| `ends_at` | datetime | yes |  |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |

Indexes: `(company_id, status)`

### `company_user`

Membership: a user's role (owner, manager, cashier, kitchen, waiter) at a company, manager PIN hash, active flag. Model: `Membership` (Pivot).

| Column | Type | Null | Default | References |
|---|---|---|---|---|
| `id` | integer |  |  |  |
| `company_id` | integer |  |  | companies.id (cascade) |
| `user_id` | integer |  |  | users.id (cascade) |
| `role` | varchar |  |  |  |
| `pin_hash` | varchar | yes |  |  |
| `is_active` | tinyint |  | 1 |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |

Indexes: `UNIQUE (company_id, user_id)`

### `audit_logs`

Who changed what: every create/update/delete of audited models, order status changes, cancellations (with reason).

| Column | Type | Null | Default | References |
|---|---|---|---|---|
| `id` | integer |  |  |  |
| `company_id` | integer | yes |  | companies.id (set null) |
| `user_id` | integer | yes |  | users.id (set null) |
| `event` | varchar |  |  |  |
| `subject_type` | varchar | yes |  |  |
| `subject_id` | integer | yes |  |  |
| `reason` | varchar | yes |  |  |
| `before_data` | text | yes |  |  |
| `after_data` | text | yes |  |  |
| `ip_address` | varchar | yes |  |  |
| `user_agent` | varchar | yes |  |  |
| `created_at` | datetime |  | CURRENT_TIMESTAMP |  |

Indexes: `(company_id, created_at)`, `(subject_type, subject_id)`

## Branches & tables

### `branches`

A physical shop. `day_ends_at` sets the business-day cutoff; `menu_version` is bumped when this branch's menu settings change.

| Column | Type | Null | Default | References |
|---|---|---|---|---|
| `id` | integer |  |  |  |
| `company_id` | integer |  |  | companies.id (cascade) |
| `name` | varchar |  |  |  |
| `code` | varchar | yes |  |  |
| `address` | varchar | yes |  |  |
| `phone` | varchar | yes |  |  |
| `latitude` | numeric | yes |  |  |
| `longitude` | numeric | yes |  |  |
| `day_ends_at` | time |  | 04:00:00 |  |
| `opening_hours` | text | yes |  |  |
| `is_active` | tinyint |  | 1 |  |
| `menu_version` | integer |  | 1 |  |
| `sort_order` | integer |  | 0 |  |
| `coreos_branch_id` | integer | yes |  |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |
| `deleted_at` | datetime | yes |  |  |

Indexes: `UNIQUE (company_id, code)`, `(coreos_branch_id)`

### `branch_user`

Optional: limits a staff member to some branches. No rows = all branches.

| Column | Type | Null | Default | References |
|---|---|---|---|---|
| `branch_id` | integer |  |  | branches.id (cascade) |
| `user_id` | integer |  |  | users.id (cascade) |

### `table_areas`

Indoor, Terrace, VIP… groups tables for waiters.

| Column | Type | Null | Default | References |
|---|---|---|---|---|
| `id` | integer |  |  |  |
| `company_id` | integer |  |  | companies.id (cascade) |
| `branch_id` | integer |  |  | branches.id (cascade) |
| `name` | varchar |  |  |  |
| `sort_order` | integer |  | 0 |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |

### `dining_tables`

A table and its random `qr_token` (the only thing a customer's phone knows). Regenerating the token disables old printed QR codes.

| Column | Type | Null | Default | References |
|---|---|---|---|---|
| `id` | integer |  |  |  |
| `company_id` | integer |  |  | companies.id (cascade) |
| `branch_id` | integer |  |  | branches.id (cascade) |
| `table_area_id` | integer | yes |  | table_areas.id (set null) |
| `name` | varchar |  |  |  |
| `seats` | integer | yes |  |  |
| `qr_token` | varchar |  |  |  |
| `is_active` | tinyint |  | 1 |  |
| `sort_order` | integer |  | 0 |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |

Indexes: `UNIQUE (branch_id, name)`, `UNIQUE (qr_token)`

## Menu

### `categories`

Menu sections (Khmer / English / Chinese names).

| Column | Type | Null | Default | References |
|---|---|---|---|---|
| `id` | integer |  |  |  |
| `company_id` | integer |  |  | companies.id (cascade) |
| `name_km` | varchar |  |  |  |
| `name_en` | varchar |  |  |  |
| `name_zh` | varchar | yes |  |  |
| `image_path` | varchar | yes |  |  |
| `is_active` | tinyint |  | 1 |  |
| `sort_order` | integer |  | 0 |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |

Indexes: `(company_id, is_active, sort_order)`

### `menu_items`

The company's master menu. `price` in minor units; `station` = kitchen or bar.

| Column | Type | Null | Default | References |
|---|---|---|---|---|
| `id` | integer |  |  |  |
| `company_id` | integer |  |  | companies.id (cascade) |
| `category_id` | integer |  |  | categories.id (restrict) |
| `name_km` | varchar |  |  |  |
| `name_en` | varchar |  |  |  |
| `name_zh` | varchar | yes |  |  |
| `description_km` | text | yes |  |  |
| `description_en` | text | yes |  |  |
| `image_path` | varchar | yes |  |  |
| `price` | integer |  |  |  |
| `sku` | varchar | yes |  |  |
| `station` | varchar |  | kitchen |  |
| `is_active` | tinyint |  | 1 |  |
| `sort_order` | integer |  | 0 |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |
| `deleted_at` | datetime | yes |  |  |

Indexes: `(company_id, category_id, is_active)`

### `option_groups`

Size, Sugar, Ice, Extras… `min_select`/`max_select` define required / optional / multi-pick.

| Column | Type | Null | Default | References |
|---|---|---|---|---|
| `id` | integer |  |  |  |
| `company_id` | integer |  |  | companies.id (cascade) |
| `name_km` | varchar |  |  |  |
| `name_en` | varchar |  |  |  |
| `min_select` | integer |  | 0 |  |
| `max_select` | integer |  | 1 |  |
| `sort_order` | integer |  | 0 |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |

### `options`

Choices inside a group, each with `price_delta` (minor units).

| Column | Type | Null | Default | References |
|---|---|---|---|---|
| `id` | integer |  |  |  |
| `company_id` | integer |  |  | companies.id (cascade) |
| `option_group_id` | integer |  |  | option_groups.id (cascade) |
| `name_km` | varchar |  |  |  |
| `name_en` | varchar |  |  |  |
| `price_delta` | integer |  | 0 |  |
| `is_default` | tinyint |  | 0 |  |
| `is_active` | tinyint |  | 1 |  |
| `sort_order` | integer |  | 0 |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |

### `menu_item_option_group`

Which option groups an item uses, in order.

| Column | Type | Null | Default | References |
|---|---|---|---|---|
| `menu_item_id` | integer |  |  | menu_items.id (cascade) |
| `option_group_id` | integer |  |  | option_groups.id (cascade) |
| `sort_order` | integer |  | 0 |  |

### `branch_menu_items`

Per-branch differences: hidden (`is_available`), branch price, `sold_out_until`. One row per branch × item, kept in sync by `MenuSync`.

| Column | Type | Null | Default | References |
|---|---|---|---|---|
| `id` | integer |  |  |  |
| `company_id` | integer |  |  | companies.id (cascade) |
| `branch_id` | integer |  |  | branches.id (cascade) |
| `menu_item_id` | integer |  |  | menu_items.id (cascade) |
| `is_available` | tinyint |  | 1 |  |
| `price` | integer | yes |  |  |
| `sold_out_until` | datetime | yes |  |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |

Indexes: `UNIQUE (branch_id, menu_item_id)`

## Ordering (Step 1 part A)

### `table_sessions`

One visit to a table: opened by the first order, `bill_requested` when the customer asks for the bill, closed when paid (part B).

| Column | Type | Null | Default | References |
|---|---|---|---|---|
| `id` | integer |  |  |  |
| `company_id` | integer |  |  | companies.id (cascade) |
| `branch_id` | integer |  |  | branches.id (cascade) |
| `dining_table_id` | integer |  |  | dining_tables.id (cascade) |
| `status` | varchar |  | open |  |
| `opened_at` | datetime |  |  |  |
| `bill_requested_at` | datetime | yes |  |  |
| `closed_at` | datetime | yes |  |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |

Indexes: `(branch_id, status)`, `(dining_table_id, status)`

### `orders`

One 'Send order' tap. `number` restarts daily per branch; `business_date` follows the branch cutoff; `idempotency_key` makes retries safe; `subtotal` is computed on the server.

| Column | Type | Null | Default | References |
|---|---|---|---|---|
| `id` | integer |  |  |  |
| `company_id` | integer |  |  | companies.id (cascade) |
| `branch_id` | integer |  |  | branches.id (cascade) |
| `dining_table_id` | integer | yes |  | dining_tables.id (set null) |
| `table_session_id` | integer | yes |  | table_sessions.id (set null) |
| `number` | integer |  |  |  |
| `business_date` | date |  |  |  |
| `status` | varchar |  | placed |  |
| `source` | varchar |  | qr |  |
| `placed_by_user_id` | integer | yes |  | users.id (set null) |
| `idempotency_key` | varchar | yes |  |  |
| `note` | varchar | yes |  |  |
| `currency` | varchar |  |  |  |
| `subtotal` | integer |  |  |  |
| `accepted_at` | datetime | yes |  |  |
| `preparing_at` | datetime | yes |  |  |
| `ready_at` | datetime | yes |  |  |
| `served_at` | datetime | yes |  |  |
| `completed_at` | datetime | yes |  |  |
| `cancelled_at` | datetime | yes |  |  |
| `cancel_reason` | varchar | yes |  |  |
| `cancelled_by_user_id` | integer | yes |  | users.id (set null) |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |

Indexes: `UNIQUE (branch_id, business_date, number)`, `(branch_id, status, created_at)`, `(company_id, business_date)`, `UNIQUE (dining_table_id, idempotency_key)`

### `order_items`

Snapshot of what was ordered: names, station, unit price incl. options, quantity, chosen options (JSON), note.

| Column | Type | Null | Default | References |
|---|---|---|---|---|
| `id` | integer |  |  |  |
| `company_id` | integer |  |  | companies.id (cascade) |
| `order_id` | integer |  |  | orders.id (cascade) |
| `menu_item_id` | integer | yes |  | menu_items.id (set null) |
| `name_km` | varchar |  |  |  |
| `name_en` | varchar |  |  |  |
| `station` | varchar |  |  |  |
| `unit_price` | integer |  |  |  |
| `quantity` | integer |  |  |  |
| `line_total` | integer |  |  |  |
| `options` | text | yes |  |  |
| `note` | varchar | yes |  |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |

### `service_requests`

Call waiter / request bill from a table, until a waiter marks it done.

| Column | Type | Null | Default | References |
|---|---|---|---|---|
| `id` | integer |  |  |  |
| `company_id` | integer |  |  | companies.id (cascade) |
| `branch_id` | integer |  |  | branches.id (cascade) |
| `dining_table_id` | integer |  |  | dining_tables.id (cascade) |
| `table_session_id` | integer | yes |  | table_sessions.id (set null) |
| `type` | varchar |  |  |  |
| `status` | varchar |  | open |  |
| `handled_by_user_id` | integer | yes |  | users.id (set null) |
| `handled_at` | datetime | yes |  |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |

Indexes: `(branch_id, status)`

## Rules the schema relies on

1. **Money:** integers in minor units only. Totals are always recomputed on the server.
2. **Snapshots:** `order_items` copies names, prices and options; bills and payments (part B) copy the riel rate. Editing the menu or the rate never changes history.
3. **Tenant isolation:** every query in the back office is scoped to the Filament tenant through each model's `company()` relation. Public endpoints find company and branch **only** from the table's `qr_token`. Staff API checks membership with `App\Support\StaffAccess`.
4. **Idempotency:** a retried "Send order" with the same key returns the existing order.
5. **Locks:** placing an order locks the branch row (daily numbering) and the table row (one open session per table).
6. **Business date:** `Branch::businessDate()` uses the company timezone and the branch `day_ends_at` cutoff.
7. **Menu cache:** key `menu:{branch}:{company.menu_version}.{branch.menu_version}`; any menu change bumps a version (`BumpsMenuVersion`).
8. **Status changes:** only through `Order::moveTo()`, which enforces `OrderStatus::canMoveTo()`, stamps the time and writes the audit log. Cancelling needs a reason.

## Planned for Step 1 part B

### Fixes to existing tables (B1)

| # | Change | Why |
|---|---|---|
| 1 | `orders`, `order_items`, `table_sessions` and the new money tables: `restrictOnDelete` instead of `cascadeOnDelete` on company / branch | Deleting a branch must never erase sales history (companies and branches are soft-deleted anyway) |
| 2 | `orders` unique `(dining_table_id, idempotency_key)` → `(branch_id, idempotency_key)` | Keeps retry protection for takeaway / pickup orders with no table |
| 3 | MySQL only: generated column `table_sessions.open_table_id = IF(status <> 'closed', dining_table_id, NULL)` + unique index | Database-level guarantee of one open visit per table |
| 4 | Billing amounts live on `bills`, not on `orders` | Orders keep `subtotal`; VAT, service charge and discounts belong to the bill |
| 5 | Copy `khr_per_usd` onto every bill and payment | Old receipts stay correct when the rate changes |
| 6 | **Proposed, awaiting owner:** replace `name_km` / `name_en` / `name_zh` (and `description_*`) on `categories`, `menu_items`, `option_groups`, `options` with JSON `name` / `description` (`{"km","en","zh",...}`); add `companies.languages` JSON | Chinese is missing on options today; JSON lets a restaurant add any language without a schema change. Order item snapshots then store the JSON too. |

### `bills`
One per table visit.

| Column | Type | Notes |
|---|---|---|
| id, company_id, branch_id | FK | restrictOnDelete |
| table_session_id | FK, **unique** | one bill per visit |
| number | int | receipt number, restarts daily per branch |
| business_date | date | |
| currency | char(3) | |
| khr_per_usd | int | snapshot |
| subtotal | int | sum of non-cancelled orders |
| discount_total | int | from `bill_adjustments` |
| service_charge | int | company % at billing time |
| vat | int | company % at billing time (0 if prices include VAT) |
| total | int | |
| total_khr | int | rounded to nearest 100៛ |
| paid_total | int | sum of confirmed payments |
| status | string | `open` → `paid`, or `void` |
| paid_at, voided_at, void_reason, voided_by_user_id | | void needs manager PIN |

Indexes: UNIQUE `(branch_id, business_date, number)`, `(branch_id, status)`

### `bill_adjustments`
Discounts. `bill_id, type (percent|fixed), value, amount, reason, approved_by_user_id`. Manager PIN required.

### `payments`
A bill can have several (part cash, part KHQR).

| Column | Type | Notes |
|---|---|---|
| id, company_id, branch_id, bill_id, shift_id | FK | |
| idempotency_key | string | UNIQUE `(branch_id, idempotency_key)` |
| method | string | cash, khqr, card, other |
| amount | int | bill currency |
| tendered_amount, tendered_currency | int, char(3) | e.g. 50,000 KHR handed over |
| change_amount, change_currency | int, char(3) | change given in USD or KHR |
| khr_per_usd | int | snapshot |
| reference | string null | KHQR transaction id, UNIQUE `(branch_id, reference)` |
| status | string | confirmed, refunded |
| received_by_user_id, paid_at, business_date | | |
| refund_reason, refunded_by_user_id, refunded_at | | manager PIN |

Indexes: `(bill_id)`, `(shift_id, method)`, `(branch_id, business_date)`

### `shifts`
Cash drawer per branch. `branch_id, opened_by_user_id, opened_at, opening_cash_usd, opening_cash_khr, closed_by_user_id, closed_at, expected_cash_usd, expected_cash_khr, counted_cash_usd, counted_cash_khr, difference_usd, difference_khr, note, status (open|closed)`. Only one open shift per branch (MySQL generated-column unique like sessions; app lock elsewhere).

### `cash_movements`
`shift_id, type (in|out), amount, currency, reason, user_id`. Paying a supplier from the drawer, adding change.

### `daily_branch_sales` (report copy)
UNIQUE `(branch_id, business_date)`: `orders_count, bills_count, items_count, cancelled_count, gross, discounts, service_charge, vat, net, refunds, cash, khqr, card, other`. Updated by a queued job after payment / refund / void; `php artisan reports:rebuild {date}` recomputes from raw rows.

### `daily_item_sales` (report copy)
UNIQUE `(branch_id, business_date, menu_item_id)`: `name_en, name_km, quantity, amount`. Best sellers.

## Planned for Step 2 (not built)

| Feature | Tables |
|---|---|
| Upsell suggestions | `menu_item_suggestions (menu_item_id, suggested_item_id, sort_order)` |
| Combo / set menus | `menu_items.type` (`single`/`combo`) + `combo_components (combo_item_id, menu_item_id, quantity, group, extra_price)` |
| Customer feedback | `feedbacks (bill_id unique, rating 1-5, comment, created_at)` |
| Multi-language | `name_zh` on `option_groups`/`options`; `companies.languages` JSON |
| Kiosk mode | `dining_tables.kind` (`table`/`kiosk`/`counter`); `orders.source = kiosk` |
| Multi-branch comparison | none: reads `daily_branch_sales` |
| Automatic KHQR | `payments.reference` + webhook log table `payment_events` |
