# User guide: how to use Roumdoul Order

Step by step for every person who uses the system. Same content as the "How to use" section of the online docs page. Keep both in sync when screens change.

Step by step for every person who touches Roumdoul Order, from starting the computer to closing the cash drawer.

| Who | Where | Demo login (password: password) |
| --- | --- | --- |
| Owner | `localhost:8000/app` | owner@roumdoul.test · manager PIN 1234 |
| Roumdoul team | `localhost:8000/admin` | admin@roumdoul.test |
| Kitchen / bar | `localhost:3000/staff` | kitchen@roumdoul.test |
| Waiter | `localhost:3000/staff` | waiter@roumdoul.test |
| Cashier | `localhost:3000/staff` | cashier@roumdoul.test |
| Customer | table QR (`localhost:3000/t/…`) | no login |

## Start the system

*Windows PC · start.bat*

Everything runs on one computer for now. Phones and tablets in the shop connect to it over the shop Wi-Fi.

1. **First time only: install**: PHP 8.2+ with intl, zip, gd (XAMPP), Composer, MySQL / MariaDB, Node.js 20+.
2. **First time only: create the database**: In phpMyAdmin → SQL: `CREATE DATABASE roumdoul_order CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`
3. **First time only: set up**: In `backend`: copy `.env.example` to `.env`, `composer install`, `php artisan key:generate`, `php artisan migrate --seed`, `php artisan storage:link`. In `frontend`: copy `.env.example` to `.env.local`, `npm install`.
4. **Every day: start XAMPP MySQL, then double-click start.bat**: It opens four windows: backend (port 8000), customer site (3000), live updates (8080) and the scheduler for Telegram summaries. Keep them open.
5. **Open the back office**: start.bat opens `localhost:8000/app`. Staff screens are at `localhost:3000/staff`.

For phones to reach the system, open it with the computer's Wi-Fi address (e.g. `192.168.1.20:3000`) and set `FRONTEND_URL` to that address before printing QR codes. The location check only works on https (a real server).

## Roumdoul team (you)

*/admin*

Your own panel to run the business: plans and every restaurant on the platform.

1. **Sign in**: `admin@roumdoul.test` / password on the demo.
2. **Set your prices**: Plans: monthly / yearly price, how many branches, tables and staff, which features. The demo prices ($19 / $39 / $99) are placeholders.
3. **Add a restaurant yourself (after a sales visit)**: Restaurants → Add restaurant: name, first branch, currency, plan, owner name, email and password. They can then sign in at /app.
4. **Or let them sign up**: Owners can register themselves at `/app/register` and get a 14-day trial.
5. **Manage restaurants**: Change status (trial, active, suspended, cancelled) or the trial end date. Suspended restaurants' QR codes stop working.

## Owner: first-day setup

*/app/{restaurant}*

About 30 minutes for a small café. The dashboard shows what is still missing.

1. **Register**: Sign up, then “Register your restaurant”: name, **logo** (required), short description, cover photo, phone, first branch name, currency ($ or ៛).
2. **Restaurant settings**: Menu (top left) → Restaurant settings:
   - Logo, cover photo, short description shown on the customer menu
   - **KHQR code picture** from your bank: printed on bills so customers can scan and pay
   - Exchange rate (៛ per $1), VAT %, service charge %, whether prices already include VAT
   - Telegram chat ID for alerts
3. **Branch**: Restaurant → Branches → edit:
   - **Business day ends at** (default 04:00): late sales count toward the evening before
   - Printing: receipt header and footer, print kitchen tickets automatically
   - Location: paste the Google Maps link of the shop (or tap “Use my current location” standing inside), then turn on **Only accept QR orders from inside the shop** with an allowed distance (150 m suits most shops)
4. **Areas**: Branches → Areas & menu → add Indoor, Terrace, VIP…
5. **Tables and QR codes**: Restaurant → Tables & QR codes → **Add many tables** (e.g. T1–T12). For each table: QR → download (SVG) → print and stick on the table. “Make new QR code” if one was copied.
6. **Menu**: In this order:
   - Menu → Categories (Coffee, Tea, Food…)
   - Menu → Option groups (Size, Sugar level, Ice, Extras, Spice level) with extra prices
   - Menu → Menu items: Khmer and English name, price, photo, category, which screen the ticket goes to (kitchen or bar), option groups, and **Goes well with** (up to 3 suggestions)
7. **What each branch sells**: Branches → Areas & menu → Menu in this branch: switch items off, set a branch-only price, or mark sold out.
8. **Staff**: Team → Staff → Add staff: name, email, password, role (owner, manager, cashier, kitchen, waiter), **manager PIN** for managers, and which **branches** they work at.
9. **Floor plan (optional)**: On a tablet: `/staff` → Floor plan → draw the room, place each QR table, chairs, counter, walls. The cashier then sees busy / free tables on this plan.
10. **Test before opening**: Scan a QR with your phone, order, watch it on the kitchen screen, serve it, pay it on the cashier screen, print the receipt, and check the dashboard.

## Customer

*scans the QR on the table*

No app and no login. Works on any phone browser, in Khmer or English.

1. **Scan the QR**: The menu opens with the restaurant's cover photo, logo and the table name. Tap ខ្មែរ / EN to switch language.
2. **Choose**: Browse categories or search. Tap an item, pick options (size, sugar…), add a note like “no onion”, set the quantity, Add to order.
3. **Goes well with**: After adding, a small pop-up may suggest a pastry or side. Tap Add or No thanks.
4. **Send the order**: Open the cart, check the total in $ and ៛, add a note for the whole order, Send order. If the shop checks location, the phone asks once to confirm the guest is inside.
5. **Follow it**: “My orders” shows Received → Preparing → Ready → Served. More rounds can be ordered any time; they join the same table visit.
6. **Call waiter / request bill**: Buttons at the top. The waiter screen shows the call until it is handled.
7. **Pay**: Pay the cashier (cash, KHQR from the printed bill, or card). The phone then shows “Paid, thank you!”.

## Kitchen and bar

*/staff → Kitchen*

A tablet or TV by the kitchen and another at the bar. Food goes to Kitchen, drinks to Bar (set on each menu item).

1. **Sign in**: `/staff` with the email and password from the manager, then pick Kitchen (or Bar, or All).
2. **Turn on sound**: Tap “Turn on sound” once so new orders chime.
3. **New order arrives**: Card shows table, order number, items with options and notes, and a timer (amber after 10 min, red after 15).
4. **Start → Ready**: Tap Start when cooking begins and Ready when it is done. The waiter and the customer see it straight away.
5. **Print a ticket**: Printer button on a card, or turn on automatic kitchen tickets in the branch settings.

## Waiter

*/staff → Waiter · New order*

A phone in the waiter's pocket.

1. **Tables tab**: Three lists: **Calls** (calling waiter / wants the bill) → Done; **Ready to serve** → Served; **Being prepared** to answer “where is my food?”.
2. **Take an order for a guest**: `/staff` → New order: choose the table, add items with options, send. It goes to the kitchen like a QR order (no location check).
3. **Sold out**: Sold out tab: tap an item to mark it sold out for today; customers can't order it anymore. Tap again when it's back.

## Cashier

*/staff → Cashier*

A tablet or laptop at the counter, with an 80 mm receipt printer.

1. **Start shift**: Count the drawer in dollars and riel and enter it. Payments are only possible while a shift is open.
2. **Tables**: Grid or floor plan: free / busy tables, amount so far, and a badge when a table wants the bill.
3. **Open the bill**: Tap a table: orders, subtotal, service, VAT, total in $ and ៛. **Print bill for the table** (includes your KHQR picture).
4. **Discount (if needed)**: Apply discount (% or $). A manager types their PIN and a reason; it's saved in the history.
5. **Take payment**: Cash: enter what the customer gave in $ or ៛; change is calculated. KHQR or card: enter the amount (and the bank reference if you like). Part cash, part KHQR is fine.
6. **Done**: When fully paid, the table becomes free and the customer's phone says thank you. Print receipt.
7. **Mistakes**: Void bill (nothing paid yet) or Refund a payment: manager PIN and reason required.
8. **Cash in / out**: Shift → Cash in or out with a reason (e.g. ice delivery, more small notes).
9. **Close shift**: Count the drawer without seeing the expected amount (blind count). The difference is saved and shown to the owner.

## Owner: every day

*/app dashboard · phone Telegram*

What to check and where.

1. **Telegram**: Every new order arrives in your chat; a sales summary arrives after each branch's business day ends.
2. **Dashboard**: Sales today, last 7 days, payment methods, best sellers, and what is still missing in setup.
3. **Restaurant → Orders**: Every order with status and times. Cancel with a reason if needed.
4. **Restaurant → Daily sales**: One row per branch per business day. **Export** orders, payments or items sold as a CSV file that opens in Excel (Khmer included).
5. **Restaurant → Shifts**: Who opened and closed the drawer, expected vs counted cash, differences, cash in / out.
6. **Menu changes**: Edit prices, photos and availability any time; customers see changes on their next scan.

## When something goes wrong

### The QR shows “This QR code is not active”

The table, branch or restaurant is switched off, the trial ended, or the QR was replaced with a new one. Check Tables & QR codes and the restaurant status.

### A customer can't order: “Outside the shop” or location blocked

The branch only takes orders from inside. The guest must allow location for the page. GPS inside buildings can be off by 50 m, so keep the allowed distance at 150 m or more. A waiter can always type the order on New order.

### Screens don't update by themselves

The live updates window (reverb) isn't running. Screens still refresh every few seconds; restart start.bat to get instant updates.

### The print dialog appears every time

Start Chrome on the kitchen / cashier computer with `--kiosk-printing` and set the 80 mm printer as default.

### Telegram messages don't arrive

Set `TELEGRAM_BOT_TOKEN` in backend/.env, add the bot to your group, put the group chat ID in Restaurant settings, and keep the scheduler window open for day-end summaries.

### A manager forgot their PIN

Owner: Team → Staff → edit that person → type a new Manager PIN.

### XAMPP MySQL stops with “shutdown unexpectedly”

Stop XAMPP, rename `C:\xampp\mysql\data` to `data_old`, create a new `data` folder, copy everything from `C:\xampp\mysql\backup` into it, then copy your `roumdoul_order` database folder back from data\_old.

### Numbers in a report look wrong

Reports are copies. Run `php artisan reports:rebuild` (optionally with a date) to recompute them from the bills and payments.

### First login on a real server

Do not use `--seed` on a server: it adds the demo café and logins with the password `password`. Instead run:

1. `php artisan migrate --force`
2. `php artisan db:seed --class=PlanSeeder --force` (the plans only)
3. `php artisan admin:create`: asks for your email, name and password and makes you a platform admin (`/admin`).

Forgot the password? Run `php artisan admin:create` again with the same email to set a new one.
