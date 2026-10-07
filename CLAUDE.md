# Roumdoul Order: guide for Claude

QR ordering SaaS for restaurants and cafés in Cambodia, sold by Roumdoul. Customers scan a QR code on the table, order from their phone, the kitchen and waiters work from staff screens, and owners manage everything in a back office. Multi-tenant: many restaurants (companies) on one system, each with branches.

**Goal of Step 1:** a product good enough to sell to real cafés, even if not every feature is finished.

Read these before larger changes:
- `docs/architecture.md`: how the parts connect and how data flows (order, menu, money)
- `docs/database.md`: every table, column, index, plus the planned part B / Step 2 tables
- `docs/roadmap.md`: what is done, what is next, the exact scope of the next task
- `docs/step2-features.md`: how each Step 2 feature should work (upsell pop-up, combos, feedback, multi-language, kiosk, multi-branch, automatic KHQR, split bill)

## Stack

| Part | Tech | Where |
|---|---|---|
| Backend, API | PHP 8.2+, Laravel 12, Sanctum | `backend/` |
| Platform admin `/admin` | Filament 5 (no tenancy) | `backend/app/Filament/Admin` |
| Restaurant back office `/app/{company-slug}` | Filament 5 with tenancy (tenant = `Company`) | `backend/app/Filament/App` |
| Customer site `/t/{qr_token}` and staff screens `/staff/*` | Next.js 16 (App Router), React 19, TypeScript, Tailwind 4 | `frontend/` |
| Database | MySQL 8 / MariaDB 10.4 (XAMPP on the dev PC). Tests: SQLite in memory | |
| Cache / queue | `database` driver locally on Windows, Redis (predis) in production | |
| Live updates | Laravel Reverb (`App\Support\Live`, `frontend/src/lib/live.ts`); polling stays as fallback (30 s when connected, else customer 5 s, staff 3–4 s) | |

Not related to `coreos_roumdoul` (separate HR product). We only keep `coreos_company_id` / `coreos_branch_id` columns for a future link.

## Commands

```bat
:: backend
cd backend
composer install
php artisan migrate:fresh --seed   :: demo café + logins (prints QR links)
php artisan serve                  :: http://localhost:8000
php artisan test                   :: must stay green
php artisan schedule:work          :: Telegram day-end summary (start.bat opens it)
php artisan reverb:start           :: live updates on :8080 (start.bat opens it; screens poll without it)
php artisan reports:rebuild        :: recompute report tables (optionally a date)

:: frontend (second terminal)
cd frontend
npm install
npm run dev                        :: http://localhost:3000
npm run lint && npx tsc --noEmit   :: must stay clean
```

Demo logins (password `password`): `owner@roumdoul.test` (/app, manager PIN `1234`), `admin@roumdoul.test` (/admin), `kitchen@`, `waiter@`, `cashier@roumdoul.test` (/staff).

## How requests flow

- **Customer phone** → Next.js `/t/{token}` (server component fetches menu) and `/api/t/{token}/{session|orders|requests}` (route handler proxy) → Laravel `/api/public/tables/{token}/...`. The browser never talks to Laravel directly; `API_URL` is server-only.
- **Staff screens** → Next.js `/api/staff/*` proxy (forwards `Authorization: Bearer`) → Laravel `/api/staff/*` (Sanctum token from `POST /api/staff/login`).
- **Owners** → Filament on Laravel directly (`/app`, session auth).

## Rules that must never be broken

1. **Money = integers in minor units** (USD cents, or whole riel for KHR companies). Use `App\Support\Money` to convert/format. Never floats, never trust prices from the client: `OrderPlacer` recomputes everything.
2. **Snapshot history:** order items copy names/prices/options; bills/payments (part B) copy the riel rate.
3. **Tenant isolation:**
   - Back office: Filament tenancy scopes resources via each model's `company()` relation (trait `BelongsToCompany`). Relationship `Select` fields and filters must be scoped explicitly with `TenantScope::apply($query)`.
   - Public API: company/branch come **only** from the table token (`MenuBuilder::resolveTable`).
   - Staff API: every endpoint calls `StaffAccess::authorize($user, $branch, $roles)`.
   - **Branches:** owners see every branch; everyone else only the branches ticked on the Staff page (`branch_user`; a one-branch restaurant needs none). Back-office resources with `branch_id` override `getEloquentQuery()` with `BranchScope::apply()`, branch pickers use `BranchScope::branches()`, reports take `BranchScope::ids()`. Any new branch screen must do the same.
4. **Orders only from inside the shop:** when a branch has `require_location` and a map point, public orders and service requests must send `location` {lat, lng, accuracy}; `Services/Ordering/LocationCheck::enforce()` refuses with 422 + `code` (`location_required` / `location_too_far`). Staff (waiter) orders are never checked. Browsers only share location on https.
5. **Idempotency:** order (and later payment) creation takes an `idempotency_key`; a retry returns the existing record.
6. **Order status** changes only via `Order::moveTo()`; allowed moves live in `App\Enums\OrderStatus::canMoveTo()`. Cancel requires a reason and writes the audit log.
7. **Menu cache:** never cache a menu without the version in the key; models using `BumpsMenuVersion` bump it on change. If you change option groups of an item via a pivot, call `bumpMenuVersion()` yourself.
8. **Business date:** use `Branch::businessDate()`, not `now()->toDateString()`.
9. **Never hard-delete** orders or money rows; cancel / void / refund with a reason instead.

## Code layout (backend)

- `app/Models` – Eloquent models (incl. `FloorPlan`: drawn table layout per branch × area; `MenuItem::suggestions()` = "Goes well with"). Concerns: `BelongsToCompany`, `BumpsMenuVersion`, `Auditable`.
- `app/Services` – `MenuBuilder` (token → table, cached branch menu), `MenuSync` (branch × item rows), `CompanyProvisioner` (new restaurant), `AuditLogger`, `TelegramNotifier`, `Ordering/OrderPlacer|OrderPresenter|ServiceRequests`, `Billing/BillCalculator` (pure bill maths) `|BillService` (open, discount, pay, void, refund) `|BillPresenter`, `Billing/ShiftService` (cash drawer: open, cash in/out, close, expected cash), `Reports/DailySales` (rebuild a branch-day of the report tables) `|SalesReport` (dashboard numbers) `|CsvExport`.
- `app/Jobs/RebuildDailySales`, `app/Console/Commands` – `reports:rebuild`, `reports:daily-summary` (scheduled every 15 min in `routes/console.php`).
- `app/Support/Live` + `app/Events/BranchChanged|TableChanged` + `routes/channels.php` – live updates (no data in events; screens re-fetch).
- `app/Http/Middleware/UseCompanyTimezone` – back office shows times in the company time zone.
- `app/Support` – `Money` (incl. riel rounding/conversion), `QrCode`, `Tenant`, `TenantScope`, `StaffAccess`, `ManagerPin` (owner/manager PIN approval, rate-limited).
- `app/Enums` – `OrderStatus`, `StaffRole`, `CompanyStatus`, `Station`, `BillStatus`, `PaymentMethod`.
- `app/Http/Controllers/Api` – `PublicMenuController`, `PublicOrderController`, `StaffAuthController`, `StaffBoardController`, `StaffCashierController` (cashier tables/bills/payments + waiter orders), `StaffShiftController` (cash drawer), `StaffPrintController` (receipt / ticket data), `StaffFloorController` (floor plan: GET any staff, PUT owner/manager).
- `app/Filament/App/Resources` – Branches (+ Areas, Menu availability relation managers on the `ViewBranch` "Areas & menu" page), DiningTables (QR, add many), Categories, MenuItems, OptionGroups, Staff, Orders, Shifts (read-only), DailySales (read-only + CSV export actions). `app/Filament/App/Widgets` – SalesOverview, SalesChart, PaymentMethodsChart, BestSellers, SetupOverview.
- **Back office UI rules:** create / edit / view are **modals**, not pages (only list pages; the one exception is a branch's "Areas & menu" page, which holds two big tables). Put form/infolist `Grid`s in `->columnSpanFull()` or they fill only half the modal. Tables must fit a phone: hide secondary columns with `->visibleFrom('md'|'lg')`. Test modals with `callAction` / `callTableAction` / `mountTableAction` + `assertMountedActionModalSee`. Time-only fields (e.g. `day_ends_at`) use `->timezone(config('app.timezone'))` so the company time zone does not shift them.
- `routes/api.php` – public (`throttle:public-read|public-write`, limited per token) and staff (`auth:sanctum`).

## Code layout (frontend)

- `src/app/t/[token]/page.tsx` – customer menu (server) → `components/menu-app.tsx` (client: hero with the restaurant's cover/logo/name, search, cart, "Goes well with" pop-up after adding, send order, tracking, call waiter). Desktop: categories left, items grid, cart panel right (`CartPanel`); phones: chips + bottom cart bar.
- Design system: tokens + animation classes (`anim-fade-up` with `--i` stagger, `anim-pop`, `skeleton`, ...) in `src/app/globals.css`; shared `components/ui.tsx` (Button, Badge, Alert, Field, EmptyState, SuccessMark) and `components/sheet.tsx` (animated, `size` md/lg/xl). Icons: `lucide-react` only, **no emoji**. One font (Kantumruy Pro) for Khmer and Latin. Staff screens use `components/staff/chrome.tsx` (`PAGE` wide container, `BackLink`).
- Floor plan: `lib/floor.ts` (object kinds, floor materials, chair placement; units are cm), `components/floor/floor-stage.tsx` (CSS 3D diorama, 2D/3D, drag/resize/zoom/pan), `floor-editor.tsx` (`/staff/floor`, owners/managers), `components/staff/floor-view.tsx` (cashier's live plan).
- `src/app/staff/*` – sign-in + `kitchen` (columns New/Preparing/Ready, timers, chime) + `waiter` (calls, ready to serve, sold-out) + `waiter/order` (new order for a table, reuses `ItemSheet`) + `cashier` (tables grid → bill sheet → `pay-panel`, `pin-form`; `shift-sheet` for the drawer) + `print/receipt`, `print/ticket` (80 mm, `use-print` hook).
- `src/app/api/t/...` and `src/app/api/staff/...` – proxies to Laravel (`lib/backend.ts`).
- `src/lib` – `types.ts`, `money.ts`, `i18n.ts` (Khmer/English strings), `table-api.ts`, `staff.ts`.
- Next.js 16: `params`/`searchParams` are Promises; use `PageProps<'/route'>` / `RouteContext<'/route'>` types. Read `node_modules/next/dist/docs` when unsure (see `frontend/AGENTS.md`).

## Gotchas we already hit

- **Filament widgets are not tenant-scoped.** Dashboard widget queries must filter `company_id` themselves. Widgets load lazily, so test them with `Livewire::test(Widget::class)`, not by fetching the dashboard HTML.
- Report tables are derived: never update them directly; call `DailySales::queue()` / `rebuild()`.

- **Filament injects closure arguments by name.** In `->relationship('x', 'name', fn (Builder $query) => ...)` the parameter must be called `$query` (not `$q`), or you get a null model. Same for `$record`, `$data`, `$get`, `$set`, `$state`.
- Filament 5 API: forms use `Filament\Schemas\Schema`, layout components in `Filament\Schemas\Components\*`, actions in `Filament\Actions\*`, `->schema([...])` on actions, `->mutateDataUsing()`, `recordActions()` / `toolbarActions()`.
- Filament tenancy auto-associates new records with the current tenant: don't set a tenant in tests that create a *different* company (`Filament::setTenant(null, isQuiet: true)`).
- Staff API tests: call `$this->app['auth']->forgetGuards()` before switching tokens, or the previous user stays signed in.
- Next.js ESLint flags `setState` inside `useEffect`; schedule the first poll with `setTimeout(..., 0)` or disable the rule for storage reads with a comment.
- Dev PC is **Windows + XAMPP (MariaDB 10.4)**: no `pcntl` (no Horizon locally), no Redis by default. Keep things working with `CACHE_STORE=database` and `QUEUE_CONNECTION=database`. Use `dispatch(...)->afterResponse()` for small side jobs.
- XAMPP MySQL crashed once from damaged system tables; fix was replacing `C:\xampp\mysql\data` with `C:\xampp\mysql\backup` (keep project DB folders).

## Testing expectations

- Every feature ships with feature tests (`backend/tests/Feature`). Back-office screens are tested with Livewire (`BackOfficeFormsTest`), APIs with HTTP tests, money logic with exact minor-unit assertions.
- Seeded demo data (`DatabaseSeeder` → `PlanSeeder`, `DemoSeeder`) is used by tests: Demo Café, branch BKK1, tables T1–T6, coffee/tea/food menu with options, staff accounts.
- Before finishing: `php artisan test` green, `npm run lint`, `npx tsc --noEmit`, `npm run build`.

## Current status and next task

Step 0 and all of Step 1 (part A, and part B: bills & payments, cashier screen, waiter orders, shifts, printing, reports, live updates) are done; see `docs/roadmap.md`. **Next: Step 2** (`docs/step2-features.md`), once the owner decides the items below. Pending decisions from the owner:
- Discounts: built in B1/B2 as recommended (manager PIN + reason). The owner can still say no; then hide the Discount button on the cashier screen.
- Split bill by person (recommended: Step 2).
- **Translations:** today `categories` and `menu_items` have `name_km`, `name_en`, `name_zh`, but `option_groups` and `options` only have `name_km`, `name_en` (inconsistent). Recommended fix in B1: switch all four tables to one JSON `name` column per field (`{"km": "...", "en": "...", "zh": "..."}`, e.g. `spatie/laravel-translatable`), with `companies.languages` choosing which languages a restaurant uses and a fallback order en → km. Do not add more `name_xx` columns until the owner decides.

Write user-facing text in plain language; Khmer + English on customer screens.
