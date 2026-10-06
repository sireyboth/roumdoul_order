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
| `khqr_image_path` | varchar | yes |  | the shop's static KHQR picture, printed on unpaid bills (B4) |
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
| `receipt_header` / `receipt_footer` | varchar(500) | yes |  | printed on bills and receipts (B4) |
| `auto_print_kitchen` | tinyint |  | 0 | kitchen/bar screens print new orders (B4) |
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
| `company_id` | integer |  |  | companies.id (restrict) |
| `branch_id` | integer |  |  | branches.id (restrict) |
| `dining_table_id` | integer |  |  | dining_tables.id (cascade) |
| `status` | varchar |  | open |  |
| `opened_at` | datetime |  |  |  |
| `bill_requested_at` | datetime | yes |  |  |
| `closed_at` | datetime | yes |  |  |
| `created_at` | datetime | yes |  |  |
| `updated_at` | datetime | yes |  |  |

Indexes: `(branch_id, status)`, `(dining_table_id, status)`. MySQL only: generated column `open_table_id = IF(status <> 'closed', dining_table_id, NULL)` with `UNIQUE (open_table_id)`, so a table can never have two open visits.

### `orders`

One 'Send order' tap. `number` restarts daily per branch; `business_date` follows the branch cutoff; `idempotency_key` makes retries safe; `subtotal` is computed on the server.

| Column | Type | Null | Default | References |
|---|---|---|---|---|
| `id` | integer |  |  |  |
| `company_id` | integer |  |  | companies.id (restrict) |
| `branch_id` | integer |  |  | branches.id (restrict) |
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

Indexes: `UNIQUE (branch_id, business_date, number)`, `(branch_id, status, created_at)`, `(company_id, business_date)`, `UNIQUE (branch_id, idempotency_key)` (a key reused from another table is refused)

### `order_items`

Snapshot of what was ordered: names, station, unit price incl. options, quantity, chosen options (JSON), note.

| Column | Type | Null | Default | References |
|---|---|---|---|---|
| `id` | integer |  |  |  |
| `company_id` | integer |  |  | companies.id (restrict) |
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
2. **Snapshots:** `order_items` copies names, prices and options; bills copy the riel rate, VAT and service charge %, and payments copy the riel rate. Editing the menu or the rate never changes history.
3. **Tenant isolation:** every query in the back office is scoped to the Filament tenant through each model's `company()` relation. Public endpoints find company and branch **only** from the table's `qr_token`. Staff API checks membership with `App\Support\StaffAccess`.
4. **Idempotency:** a retried "Send order" or payment with the same key returns the existing record.
5. **Locks:** always in the order branch → table → bill. Placing an order locks the branch (daily numbering) and the table (one open session per table); a payment locks the table and the bill, so a new order can never join a visit that is being closed.
6. **Business date:** `Branch::businessDate()` uses the company timezone and the branch `day_ends_at` cutoff.
7. **Menu cache:** key `menu:{branch}:{company.menu_version}.{branch.menu_version}`; any menu change bumps a version (`BumpsMenuVersion`).
8. **Status changes:** only through `Order::moveTo()`, which enforces `OrderStatus::canMoveTo()`, stamps the time and writes the audit log. Cancelling needs a reason.

## Billing (Step 1 part B, B1)

All changes go through `App\Services\Billing\BillService`; the arithmetic is the pure `BillCalculator` (subtotal → discounts → service charge → VAT → riel total rounded to 100៛). Discounts, voids and refunds need a manager PIN (`App\Support\ManagerPin`, 5 wrong tries per minute).

B1 also changed existing tables: `orders`, `order_items`, `table_sessions` now use `restrictOnDelete` for company / branch, `orders` retry keys are unique per branch, and MySQL has the one-open-visit-per-table index (see `table_sessions`).

### `bills`

One per table visit. Amounts are recomputed while `open` (new or cancelled orders, discounts) and frozen once `paid` or `void`. Rates are copied from the company when the bill opens.

| Column | Type | Null | Default | References |
|---|---|---|---|---|
| `id` | integer |  |  |  |
| `company_id` | integer |  |  | companies.id (restrict) |
| `branch_id` | integer |  |  | branches.id (restrict) |
| `table_session_id` | integer |  |  | table_sessions.id (restrict), **unique** |
| `number` | integer |  |  | receipt number, restarts daily per branch |
| `business_date` | date |  |  |  |
| `currency` | char(3) |  |  |  |
| `khr_per_usd` | integer |  |  | snapshot |
| `service_charge_bp` | integer |  | 0 | snapshot |
| `vat_bp` | integer |  | 0 | snapshot |
| `prices_include_vat` | tinyint |  | 1 | snapshot |
| `subtotal` | integer |  | 0 | non-cancelled orders of the visit |
| `discount_total` | integer |  | 0 | from active `bill_adjustments` |
| `service_charge` | integer |  | 0 |  |
| `vat` | integer |  | 0 | 0 when prices include VAT |
| `total` | integer |  | 0 |  |
| `total_khr` | integer |  | 0 | rounded to the nearest 100៛ |
| `paid_total` | integer |  | 0 | sum of confirmed payments |
| `status` | varchar |  | open | open → paid, or void |
| `opened_by_user_id` | integer | yes |  | users.id (set null) |
| `paid_at` | datetime | yes |  |  |
| `voided_at` | datetime | yes |  |  |
| `void_reason` | varchar | yes |  |  |
| `voided_by_user_id` | integer | yes |  | users.id (set null) |
| `created_at` / `updated_at` | datetime | yes |  |  |

Indexes: `UNIQUE (table_session_id)`, `UNIQUE (branch_id, business_date, number)`, `(branch_id, status)`, `(company_id, business_date)`

Full payment → bill `paid`, visit `closed` (table free), served orders → completed, open calls for the visit marked done. Void (PIN + reason) is only allowed with no confirmed payments and also closes the visit.

### `bill_adjustments`

Discounts, applied in the order they were added. Removing one sets `removed_at` (kept for the audit trail).

| Column | Type | Null | Default | References |
|---|---|---|---|---|
| `id` | integer |  |  |  |
| `company_id` | integer |  |  | companies.id (restrict) |
| `bill_id` | integer |  |  | bills.id (restrict) |
| `type` | varchar |  |  | percent, fixed |
| `value` | integer |  |  | percent: basis points (1000 = 10%); fixed: minor units |
| `amount` | integer |  | 0 | what it took off, set by the calculator |
| `reason` | varchar |  |  |  |
| `created_by_user_id` | integer | yes |  | users.id (set null) |
| `approved_by_user_id` | integer | yes |  | users.id (set null), the PIN owner |
| `removed_at` | datetime | yes |  |  |
| `removed_by_user_id` | integer | yes |  | users.id (set null) |
| `created_at` / `updated_at` | datetime | yes |  |  |

Indexes: `(bill_id, removed_at)`

### `payments`

A bill can have several (part cash, part KHQR). Never deleted; a mistake is refunded (PIN + reason). Refunding on an open bill makes the amount due again; on a paid bill the sale stays closed and reports subtract the refund.

| Column | Type | Null | Default | References |
|---|---|---|---|---|
| `id` | integer |  |  |  |
| `company_id` | integer |  |  | companies.id (restrict) |
| `branch_id` | integer |  |  | branches.id (restrict) |
| `bill_id` | integer |  |  | bills.id (restrict) |
| `shift_id` | integer | yes |  | shifts.id (restrict), set by the server: the open shift |
| `idempotency_key` | varchar |  |  | retry returns the same payment |
| `method` | varchar |  |  | cash, khqr, card, other |
| `amount` | integer |  |  | applied to the bill, bill currency |
| `tendered_amount` | integer | yes |  | cash handed over (cents or riel) |
| `tendered_currency` | char(3) | yes |  | USD, KHR |
| `change_amount` | integer |  | 0 | in `change_currency` |
| `change_currency` | char(3) | yes |  | USD, KHR |
| `khr_per_usd` | integer |  |  | snapshot |
| `reference` | varchar | yes |  | KHQR transaction id |
| `status` | varchar |  | confirmed | confirmed, refunded |
| `received_by_user_id` | integer | yes |  | users.id (set null) |
| `paid_at` | datetime |  |  |  |
| `business_date` | date |  |  |  |
| `refund_reason` | varchar | yes |  |  |
| `refunded_by_user_id` | integer | yes |  | users.id (set null) |
| `refunded_at` | datetime | yes |  |  |
| `refunded_in_shift_id` | integer | yes |  | shifts.id (restrict): the drawer a cash refund came out of |
| `created_at` / `updated_at` | datetime | yes |  |  |

Indexes: `UNIQUE (branch_id, idempotency_key)`, `UNIQUE (branch_id, reference)`, `(bill_id)`, `(shift_id, method)`, `(branch_id, business_date)`

Cash rules: riel handed over is compared with the due amount rounded to 100៛, so paying exactly the riel total settles the bill; a smaller amount credits only what was given (rounded down). KHQR / card / other can never pay more than is due; only cash gives change.

## Shifts (Step 1 part B, B3)

All changes go through `App\Services\Billing\ShiftService`. Every payment needs an open shift at its branch (the server sets `payments.shift_id`; the screen never sends it). Cash is counted separately in dollars and riel.

Expected cash, per currency = opening + cash handed over − change given (payments of this shift) + cash in − cash out − cash refunded in this shift (handed back, net of the change given then).

### `shifts`

One cash drawer session at a branch. MySQL only: generated column `open_branch_id = IF(status = open, branch_id, NULL)` with a unique index, so a branch can never have two open shifts (the app also locks the branch row).

| Column | Type | Null | Default | References |
|---|---|---|---|---|
| `id` | integer |  |  |  |
| `company_id` | integer |  |  | companies.id (restrict) |
| `branch_id` | integer |  |  | branches.id (restrict) |
| `status` | varchar |  | open | open, closed |
| `opened_by_user_id` | integer | yes |  | users.id (set null) |
| `opened_at` | datetime |  |  |  |
| `opening_cash_usd` | integer |  | 0 | cents |
| `opening_cash_khr` | integer |  | 0 | riel |
| `closed_by_user_id` | integer | yes |  | users.id (set null) |
| `closed_at` | datetime | yes |  |  |
| `expected_cash_usd` / `expected_cash_khr` | integer | yes |  | worked out at close |
| `counted_cash_usd` / `counted_cash_khr` | integer | yes |  | typed by the cashier (blind count) |
| `difference_usd` / `difference_khr` | integer | yes |  | counted − expected (negative = short) |
| `note` | varchar | yes |  |  |
| `created_at` / `updated_at` | datetime | yes |  |  |

Indexes: `(branch_id, status)`, `(company_id, opened_at)`, MySQL `UNIQUE (open_branch_id)`

### `cash_movements`

Cash put into or taken out of the drawer that is not a sale (change float, paying a supplier). Reason required.

| Column | Type | Null | Default | References |
|---|---|---|---|---|
| `id` | integer |  |  |  |
| `company_id` | integer |  |  | companies.id (restrict) |
| `shift_id` | integer |  |  | shifts.id (restrict) |
| `type` | varchar |  |  | in, out |
| `amount` | integer |  |  | cents or riel, see `currency` |
| `currency` | char(3) |  |  | USD, KHR |
| `reason` | varchar |  |  |  |
| `user_id` | integer | yes |  | users.id (set null) |
| `created_at` / `updated_at` | datetime | yes |  |  |

Indexes: `(shift_id)`

## Reports (Step 1 part B, B5)

Report copies, never edited by hand: `App\Services\Reports\DailySales::rebuild(branch, date)` recomputes a whole branch-day from paid bills, their payments and orders, so it can always be rebuilt (`php artisan reports:rebuild [date] [--from= --to= --branch=]`). A rebuild runs after the response of every payment that settles a bill, refund and void (`RebuildDailySales`, no queue worker needed). Sales are paid bills, counted on the bill's business date; a later refund is counted on the day of the sale it gives back. Foreign keys cascade (derived data, not money of record).

### `daily_branch_sales`

UNIQUE `(branch_id, business_date)`, index `(company_id, business_date)`. Columns: `company_id, branch_id, business_date, currency, orders_count` (not cancelled), `cancelled_count, bills_count` (paid), `items_count, gross` (subtotal of paid bills), `discounts, service_charge, vat, net` (total of paid bills), `refunds, cash, khqr, card, other` (confirmed payments by method), `summary_sent_at` (Telegram day-end summary sent).

### `daily_item_sales`

UNIQUE `(branch_id, business_date, menu_item_id)`, index `(company_id, business_date)`. Columns: `company_id, branch_id, business_date, menu_item_id` (null if the item was deleted), `name_en, name_km, quantity, amount`. Best sellers and the items export.

## Still awaiting the owner

**Translatable names (proposed fix #6):** replace `name_km` / `name_en` / `name_zh` (and `description_*`) on `categories`, `menu_items`, `option_groups`, `options` with JSON `name` / `description` (`{"km","en","zh",...}`) and add `companies.languages` JSON. Chinese is missing on options today; JSON lets a restaurant add any language without a schema change. Order item snapshots would then store the JSON too. Not built until the owner decides.

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
