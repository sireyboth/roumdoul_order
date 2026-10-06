# Roumdoul Order

QR ordering for restaurants and cafés in Cambodia.

```
roumdoul_order/
├── backend/    Laravel 12 + Filament 5 + MySQL
│               /admin  platform team (plans, restaurants)
│               /app    restaurant back office (menu, tables & QR, staff)
│               /api    public menu API for the customer site
└── frontend/   Next.js 16 customer site
                /t/{qr-token}  the menu a customer sees after scanning
                /staff         kitchen, waiter, cashier screens (Step 1)
```

## Docs

- `CLAUDE.md` – guide for Claude in VS Code (rules, layout, gotchas, next task)
- `docs/architecture.md` – how the parts connect and how data flows
- `docs/database.md` – every table and column, plus planned tables
- `docs/roadmap.md` – what is done and what comes next
- `docs/step2-features.md` – how each Step 2 feature will work
- Online diagrams: https://claude.ai/artifact/XFXdQJczECUWxJoNUX5Rvi (private to the owner)

## What Step 0 includes

- Restaurants are **companies** (tenants). Each has branches, table areas, tables, a menu, staff and a plan.
- Sign-up: anyone can register at `/app/register`, then "Register your restaurant" creates the company, a 14-day trial, and the first branch.
- **Menu**: categories, items (Khmer / English / Chinese names), option groups (size, sugar, ice, extras) with extra prices.
- **Per-branch menu**: on each branch page, switch items off, set a branch-only price, or mark sold out today.
- **Tables & QR codes**: add one table or many at once (T1–T20), show/download QR, make a new QR if one was copied.
- **Staff**: owners, managers, cashiers, kitchen, waiters, with a manager PIN for voids/refunds later.
- **Plans** with limits on branches, tables and staff (edit prices in `/admin > Plans`).
- **Audit log** of every change to menus, tables, staff and settings.
- **Customer menu** (Next.js): Khmer/English, categories, item options, cart with USD + riel total. Sending the order to the kitchen arrives in Step 1.

## Data rules built in from day one

- Money is stored as whole numbers (USD cents, or whole riel). No floats.
- Customers are identified only by the table's random QR token; company and branch always come from that token.
- The branch menu is cached and has a version number that changes on every menu edit, so a stale menu is never shown.
- Every restaurant row has `company_id`; the back office only ever shows the signed-in restaurant's data.

## Run it on Windows

You need PHP 8.2+ with the `intl`, `zip`, `gd`, `pdo_mysql` and `fileinfo` extensions, Composer, MySQL 8 (XAMPP / Laragon is fine) and Node.js 20+.

In XAMPP, open `php.ini` and make sure these lines have no `;` in front: `extension=intl`, `extension=zip`, `extension=gd`.

### 1. Create the database

In phpMyAdmin or the MySQL console:

```sql
CREATE DATABASE roumdoul_order CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 2. Backend

```bat
cd D:\Leng\Leng\System\roumdoul_order\backend
copy .env.example .env
composer install
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
php artisan serve
```

Set `DB_USERNAME` / `DB_PASSWORD` in `.env` first if your MySQL root user has a password.

The seed step prints the demo logins and one customer link per table.

### 3. Frontend (second terminal)

```bat
cd D:\Leng\Leng\System\roumdoul_order\frontend
copy .env.example .env.local
npm install
npm run dev
```

### 4. Try it

| What | Where | Login |
|---|---|---|
| Restaurant back office | http://localhost:8000/app | owner@roumdoul.test / password |
| Platform admin | http://localhost:8000/admin | admin@roumdoul.test / password |
| Customer menu | Back office > Tables & QR codes > ⋯ > Open customer menu | none |
| Kitchen / bar / waiter screens | http://localhost:3000/staff | kitchen@, waiter@ or cashier@roumdoul.test / password |
| Cashier screen (bills, payments, cash drawer) | http://localhost:3000/staff → 💵 Cashier | cashier@roumdoul.test / password; manager PIN for discounts, voids, refunds: **1234** |

`start.bat` also opens a **scheduler** window (`php artisan schedule:work`) that sends the Telegram sales summary after each business day.

If you set up the database before Step 1, add the new tables and demo staff with:

```bat
php artisan migrate:fresh --seed
```

(This wipes the demo data and creates it again; QR links change, so use the new ones it prints.)

### Tests

```bat
cd backend
php artisan test
```

## Step 1 part A (done)

- Customers send orders from their phone, track them (Received, Preparing, Ready, Served), call a waiter and request the bill.
- Kitchen / bar screen at `/staff/kitchen`: new orders with a timer (amber after 10 min, red after 15) and a chime; tap Start, then Ready.
- Waiter screen at `/staff/waiter`: table calls, orders ready to serve, and a sold-out switch per item.
- Back office: Orders page with cancel (reason required, saved to the audit log).
- Telegram alert for every new order (set `TELEGRAM_BOT_TOKEN` in `.env` and the chat ID in Restaurant settings).
- Prices are always recalculated on the server; the same "Send order" tap arriving twice creates one order.
- Screens refresh every few seconds. Instant updates (Laravel Reverb) come next.

## Step 1 part B (B1–B5 done)

- **Bills and payments:** one bill per table visit; discounts, service charge and VAT; total in riel rounded to 100៛; cash in dollars or riel with change in either; KHQR / card; void and refund with a manager PIN and a reason.
- **Cashier screen** (`/staff/cashier`): every table with what it owes and a "wants the bill" badge; tap a table to see the bill and take payment.
- **Waiters take orders** for a table (`/staff/waiter/order`); the customer sees them on their phone too, and "Paid, thank you" after paying.
- **Shifts:** start with the cash counted in the drawer (dollars and riel); payments need an open shift; cash in / out; close with a blind count; the difference shows in the back office (Restaurant → Shifts).
- **Printing:** 80 mm bill / receipt (Khmer + English, riel, the shop's KHQR picture) and kitchen / bar tickets; optional automatic kitchen tickets (Branch → Printing). For printing without a dialog, start Chrome with `--kiosk-printing` on that device.
- **Reports:** dashboard (sales today, last 7 days, payment methods, best sellers), Restaurant → Daily sales with spreadsheet exports (orders, payments, items sold) that open in Excel, and a Telegram summary after each business day.

If your database was set up before part B, run `php artisan migrate` in `backend` (keeps your data), then `php artisan reports:rebuild` to fill the reports.

## Live updates (B6, done)

Kitchen, waiter and cashier screens and the customer's phone update instantly through Laravel Reverb (`php artisan reverb:start`, port 8080; `start.bat` opens it). If it is not running, every screen still works by checking every few seconds. Settings: `REVERB_*` in `backend/.env` and `NEXT_PUBLIC_REVERB_*` in `frontend/.env.local` (the keys must match). On the shop Wi-Fi, phones connect to the same PC address they opened the menu on; allow port 8080 in Windows Firewall.

Step 1 is complete. Next: Step 2 (see `docs/step2-features.md`).
