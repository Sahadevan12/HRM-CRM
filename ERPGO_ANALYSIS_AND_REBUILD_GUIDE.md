# ERPGo SaaS – Full Analysis + Rebuild Guide (Claude Pro Prompts)

## PART 1 – Project Overview

**Type:** Multi-tenant SaaS ERP (CodeCanyon "ERPGo SaaS" by WorkDo), **modular / add-on architecture**.

| Layer | Tech |
|---|---|
| Backend | Laravel 12, PHP 8.2+ |
| Frontend | React 18 + TypeScript + **Inertia.js** (no separate API – server-driven SPA) |
| UI | Tailwind 3, Radix UI (shadcn-style `components/ui`), lucide icons, sonner toasts, recharts, FullCalendar, TipTap editor |
| Auth/RBAC | Laravel Breeze-style auth, **spatie/laravel-permission** (roles + permissions), Sanctum (API), 2FA (google2fa), Socialite |
| Realtime | Pusher + Laravel Echo (Messenger chat, online presence) |
| Files | spatie/medialibrary + Local / S3 / Wasabi (`DynamicStorageService`) |
| i18n | i18next (frontend) + `resources/lang/*.json` (per-language + per-package) |
| Payments | 30+ gateway modules (Stripe, Paypal, Razorpay, Paystack, Flutterwave, Mollie…) |
| Integrations | Google Calendar, Zoom, Slack, Telegram, Twilio, Mailchimp, MS Graph, IMAP, ZATCA |

### Folder structure
```
main-file/
├─ app/                         ← CORE (shell) application
│  ├─ Classes/Module.php        ← module registry (AddOn table + module.json + cache)
│  ├─ Providers/PackageServiceProvider.php  ← auto-discovers packages/workdo/*
│  ├─ Http/Controllers/         ← core: Users, Roles, Plans, Coupons, Orders, Settings,
│  │                              Warehouses, Transfers, Purchase/Sales Invoice/Return/Proposal,
│  │                              Helpdesk, Messenger, Media, Translation, Module(add-ons), Installer, Updater
│  ├─ Http/Middleware/          ← CheckInstallation, HandleInertiaRequests, PlanModuleCheck,
│  │                              DemoMode, UpdateUserActiveStatus, ApiForceJson
│  ├─ Events/ (≈50)             ← domain events (PostSalesInvoice, CreateUser, GivePermissionToRole…)
│  ├─ Helpers/Helper.php        ← global helpers (creatorId, company_setting, Module_is_active, assignPlan…)
│  ├─ Services/                 ← Mail/Storage config services
│  └─ Console/Commands/         ← package:seed, make-crudly, create-react-package, install…
├─ packages/workdo/<Module>/    ← 54 ADD-ON MODULES (each = mini Laravel + React app)
├─ resources/js/                ← core React: app.tsx, pages/, layouts/, components/ui, utils/menu.ts, hooks/
├─ routes/                      ← web.php (core), auth.php, installer.php, updater.php, api.php
├─ database/{migrations,seeders}
├─ config/ stubs/ public/ tests/
```

### The 54 modules (packages/workdo)
- **Business:** ProductService (items/categories/taxes/units/warehouse stock), Account (accounting, ~27 models), DoubleEntry, BudgetPlanner, Goal, Pos, Quotation, Contract
- **HR:** Hrm (43 models: employees, attendance, leave, payroll, loans, awards…), Recruitment, Performance, Training, Timesheet
- **CRM/Projects:** Lead (leads+deals+pipelines), Taskly (projects/tasks/bugs), SupportTicket, FormBuilder, Calendar
- **Platform:** LandingPage, Webhook, AIAssistant, GoogleCaptcha, ZoomMeeting, Slack, Telegram, Twilio
- **Payment gateways (≈30):** each tiny module (controller + settings + payment page + events)

---

## PART 2 – How it works (Workflow)

### 2.1 Roles & tenancy
- 3 user kinds: **superadmin** (platform owner) → **company** (tenant/customer who buys a plan) → **staff/client/vendor sub-users** (`created_by` = company id).
- **Multi-tenancy = column based.** Every business table has `creator_id` (who made it) and `created_by` (tenant/company id). `creatorId()` helper returns company id for any logged-in user, and *every query* does `->where('created_by', creatorId())`. No separate DB per tenant.

### 2.2 Request lifecycle
```
Browser → Laravel route (web.php or package Routes/web.php)
  → middleware: CheckInstallation → auth → verified → PlanModuleCheck[:ModuleName]
  → Controller: Auth::user()->can('permission-name')  (spatie)
  → Eloquent query scoped by created_by = creatorId()
  → Inertia::render('Pos/PosOrder/Index', props)   ← React page
  → HandleInertiaRequests shares: auth.user (+permissions, roles, activatedPackages),
    settings, packages, locale, flash
```

### 2.3 Module (package) system – the heart of the project
1. `PackageServiceProvider` (registered in `bootstrap/providers.php`) scans `packages/workdo/*`, reads each `composer.json`, adds PSR-4 namespace at runtime and registers the module's ServiceProvider. **No `composer dump-autoload` needed per module.**
2. Each module `ServiceProvider` → `loadRoutesFrom(Routes/web.php)`, `loadMigrationsFrom(Database/Migrations)`, registers its `EventServiceProvider`.
3. `module.json` = metadata (name, alias, priority, monthly/yearly price, child_module, package_name).
4. `add_ons` table = which modules are installed/enabled globally; `user_active_modules` = which modules each company bought. `App\Classes\Module` reads both and caches.
5. `PlanModuleCheck:Pos` route middleware blocks routes if module isn't active for that company; also blocks expired-plan companies (redirects to plans page).
6. `php artisan package:seed <Name>` runs the module's `PermissionTableSeeder` → creates permissions (`manage-pos`, `create-pos`…) tagged with `add_on` and gives them to the `company` role.
7. **Frontend auto-wiring:**
   - `vite.config.js` globs `packages/workdo/*/src/Resources/js/app.tsx`.
   - `app.tsx` `resolve()` : page name `Pos/PosOrder/Index` → first tries `resources/js/pages/…`, else `packages/workdo/Pos/src/Resources/js/Pages/PosOrder/Index.tsx`.
   - `utils/menu.ts` globs `packages/workdo/*/src/Resources/js/menus/company-menu.ts` (or `superadmin-menu.ts`) **only for `activatedPackages`**, merges into sidebar using `parent` (e.g. `parent:'dashboard'`) + `order`, then filters by user permissions.
   - Settings screens for gateways similarly come from `Resources/js/settings/*.ts`.

### 2.4 Cross-module communication = Events/Listeners (loose coupling)
- Core fires events: `PostSalesInvoice`, `PostPurchaseInvoice`, `CreateTransfer`, `ApproveSalesReturn`, `CreateUser`, `DefaultData`, `GivePermissionToRole`…
- Modules subscribe in their `EventServiceProvider`. Example: **Account** listens to `PostSalesInvoice` → `JournalService::createSalesInvoiceJournal()` → Dr Accounts Receivable (1100) / Cr Sales (4100) / Cr Tax Payable (2210) with balance validation. `Pos` fires `CreatePos` → Account's `CreatePosListener` posts the journal.
- So Pos/HRM/etc. work without Account installed; if Account is active it auto-posts accounting entries.
- `GivePermissionToRole` / `DefaultData` events let each module seed its default data and permissions when a company is created or a role gets updated.

### 2.4b Business flow (example: Sales)
`Sales Proposal → sent → accepted → convertToInvoice → Sales Invoice (draft) → post (stock out of Warehouse, journal entry) → payment (CustomerPayment, Stripe/…) → Sales Return → approve → Credit Note`
Purchase mirrors it (Purchase Invoice → post → Vendor Payment → Purchase Return → Debit Note). Stock lives in `warehouse_stocks` (ProductService module) per warehouse; Transfers move stock between warehouses.

### 2.5 SaaS / billing flow
`Plan` (price, duration, user limit, module list, trial) → company subscribes (`plans.subscribe`) → payment via gateway modules / bank transfer (admin approves) → `assignPlan()` sets `active_plan`, `plan_expire_date`, and creates `user_active_modules` rows → `Coupon` discounts via `applyCouponDiscount()` → `Order` recorded. Expiry = `PlanModuleCheck` forces renewal; sub-users are logged out if their company's plan expired.
Superadmin can also **upload add-on zips** (`ModuleController::install`), enable/disable modules, set per-module prices (buy modules à-la-carte).

### 2.6 Settings & white-label
`settings` table key/value per `created_by` (superadmin = global, company = own). Helpers `admin_setting('key')` / `company_setting('key')` (cached forever, cleared on `setSetting`). Brand, currency, SMTP (`SetConfigEmail` swaps mail config at runtime), storage (local/S3/Wasabi), cookie, SEO, Pusher, theme (color, sidebar, RTL) – all DB-driven. Email/notification templates are multi-language tables.

### 2.7 Other core features
Installer wizard (`/install`, writes `storage/installed`), Updater, Impersonation ("login as"), login history, Messenger (Pusher), Helpdesk, Media Library (directories), Translation manager (edit JSON per language & per package), demo mode, Larabug error reporting.

### 2.8 Typical module anatomy (copy this pattern for any new feature)
```
packages/workdo/Pos/
├─ composer.json        (psr-4 Workdo\Pos\ → src/, extra.laravel.providers)
├─ module.json
├─ favicon.png
└─ src/
   ├─ Providers/{Pos,Event}ServiceProvider.php
   ├─ Routes/web.php               (middleware: web,auth,verified,PlanModuleCheck:Pos)
   ├─ Http/Controllers, Http/Requests
   ├─ Models, Events, Listeners, Services
   ├─ Database/{Migrations,Seeders/PermissionTableSeeder.php, DemoSeeder}
   └─ Resources/js/{Pages/**.tsx, menus/company-menu.ts, settings/*, app.tsx?}
```
Controller convention: `if (Auth::user()->can('x')) {…} else redirect()->with('error')`, query `where('created_by', creatorId())`, `Inertia::render('<Module>/<Folder>/<Page>', props)`, fire an Event after state changes.

### 2.9 Observations / things to know
- `Account` EventServiceProvider imports events from modules **not in this package set** (Retainer, Fleet, Commission, SalesAgent, PropertyManagement…) – they're other WorkDo add-ons; class imports of missing classes are harmless until fired but be careful.
- Some leftovers: `tax_ids_debug` in `PosController::getProducts`; permission-denied redirect in `PosController::index` goes to `warehouses.index`.
- Money fields & journals use account codes hard-coded (1100, 4100, 2210) → chart of accounts must be seeded (`DataDefault` listener).
- Seeders create default `superadmin@…` and `company@…` demo accounts – change before production.
- `.env` is present – never share it.

---

## PART 3 – How to recreate this project with Claude Pro

### 3.0 Golden rules (important – Claude Pro has context/usage limits)
1. **Never say "build the whole ERP".** Build in **small phases**, one module per chat/message set.
2. Start each new chat with a **"Project Context Block"** (below) because Claude forgets between chats. Better: create a **Claude Project** (Projects → add knowledge) and paste the context block + conventions + this guide as project knowledge.
3. Ask for **complete files with exact paths**, not snippets. Ask it to list "files created/changed" at the end.
4. After every phase: run it, copy the **error text** back to Claude, fix, then continue.
5. Use **Claude Code (terminal)** if you can – it can write files straight into the folder. With Claude Pro the web chat is fine too, just paste files manually.
6. Keep a `PROGRESS.md` – ask Claude to update it each phase so the next chat can continue.

### 3.1 Project Context Block (paste at the start of EVERY new chat)
```
You are a senior Laravel + React architect. We are building a multi-tenant SaaS ERP
("ERPGo clone") step by step.

STACK: Laravel 12, PHP 8.2, MySQL, Inertia.js v2 + React 18 + TypeScript, Vite,
Tailwind + shadcn/ui (Radix), spatie/laravel-permission, Sanctum, Pusher/Echo, i18next.

ARCHITECTURE RULES
1. Core app in /app; features are add-on modules in /packages/workdo/<Module> with
   namespace Workdo\<Module>\ (psr-4 → src/), auto-registered by App\Providers\PackageServiceProvider
   reading each module's composer.json (extra.laravel.providers).
2. Each module has module.json, ServiceProvider (loads Routes/web.php + Database/Migrations),
   EventServiceProvider, Models, Http/Controllers, Http/Requests, Events, Listeners,
   Database/Seeders/PermissionTableSeeder.php, Resources/js/{Pages,menus/company-menu.ts}.
3. Multi-tenancy by columns: every table has creator_id and created_by; helper creatorId()
   returns the tenant (company) id; ALL queries filter created_by = creatorId().
4. Roles: superadmin > company > sub-users(staff/client/vendor). Permissions are
   kebab-case (manage-x, create-x, edit-x, delete-x, view-x) checked with Auth::user()->can().
5. Routes use middleware ['web','auth','verified','PlanModuleCheck:<Module>'].
6. Controllers return Inertia::render('<Module>/<Folder>/<Page>') ; React page files live at
   packages/workdo/<Module>/src/Resources/js/Pages/<Folder>/<Page>.tsx
7. Modules talk through Events/Listeners, never direct dependency on other modules.
8. Frontend menu per module: Resources/js/menus/company-menu.ts (title, href, permission, order, children, parent).
9. Settings are key/value in `settings` table (admin_setting()/company_setting() helpers).
10. All UI strings via t('...') (i18n).

CURRENT STATE: <paste PROGRESS.md here>
TASK: <what you want now>
OUTPUT FORMAT: Give full file contents with exact file paths, in the order I should create them,
then list terminal commands to run, then a short test checklist. Do not skip files.
```

### 3.2 Phase-by-phase prompts (give one at a time)

**Phase 0 – Setup**
> Using the Context Block: give me exact commands + files to create a new Laravel 12 project with Inertia v2 + React + TypeScript + Vite + Tailwind + shadcn/ui, spatie/laravel-permission, Sanctum. Configure `vite.config.js` to also glob `packages/workdo/*/src/Resources/js/app.tsx`, and `resources/js/app.tsx` `resolve()` to load pages from `resources/js/pages` first and then from `packages/workdo/{Package}/src/Resources/js/Pages`. Add `"Workdo\\"` psr-4 handling through a `PackageServiceProvider` that scans `packages/workdo/*/composer.json` at runtime.

**Phase 1 – Auth, roles, tenancy**
> Create the `users` migration with columns: type (superadmin/company/staff/client/vendor), creator_id, created_by, lang, active_plan, plan_expire_date, trial_expire_date, total_user, slug, active_status, last_seen_at. Create `PermissionRoleSeeder` (superadmin, company, staff, client, vendor roles; base permissions for users/roles/settings/plans/dashboard), Breeze-style login/register/forgot-password in Inertia React, `creatorId()` helper in `app/Helpers/Helper.php` (autoloaded), and CRUD for Users and Roles (permission matrix UI) scoped by `created_by`.

**Phase 2 – Settings + helpers + layout**
> Build `settings` table + `Setting` model + helpers `setSetting`, `admin_setting`, `company_setting` (cached, cache cleared on save). Build the authenticated layout (sidebar, topbar, theme/dark mode/RTL, language switcher) and `utils/menu.ts` that merges core menu + package menus from `packages/workdo/*/src/Resources/js/menus/company-menu.ts` for `activatedPackages`, supports `parent` + `order`, filters by permission. Share props in `HandleInertiaRequests` (auth user with permissions/roles/activatedPackages, settings, flash, locale).

**Phase 3 – Module engine + Plans (SaaS)**
> Create `add_ons` and `user_active_modules` tables, `App\Classes\Module` (find/all/allEnabled/isEnabled/enable/disable, cached), `Module_is_active()` and `ActivatedModule()` helpers, `PlanModuleCheck` middleware (superadmin skip; company plan-expiry redirect to plans.index; sub-user logout if creator plan expired; optional module-name param). Create Plans, Coupons, Orders CRUD, `assignPlan()` helper, subscribe flow with bank-transfer approval. Add `php artisan package:seed {name}` command that runs the module's PermissionTableSeeder. Add module enable/disable page for superadmin.

**Phase 4 – Scaffolding generator (saves LOTS of Pro usage)**
> Write an artisan command `make:package {Name}` that generates the full module skeleton (composer.json, module.json, service providers, Routes, Models, Controller, Request, migration, PermissionTableSeeder, Pages/Index.tsx, menus/company-menu.ts) from stubs in `/stubs`. Use it for every future module.

**Phase 5 – ProductService module** (items, categories, taxes, units, `warehouse_stocks`) + core **Warehouses & Transfers**.

**Phase 6 – Core sales/purchase documents**
> Build Sales Proposal → Invoice (draft/post), Purchase Invoice, Sales/Purchase Returns with item + item-tax tables, stock decrement/increment on post, and fire events `PostSalesInvoice`, `PostPurchaseInvoice`, `ApproveSalesReturn`… Add print pages.

**Phase 7 – Account module**
> Chart of accounts, account types, bank accounts, customers/vendors, revenue/expense, customer/vendor payments with allocations, credit/debit notes, `JournalService` (balanced double-entry validation, account codes 1100 AR, 4100 Sales, 2210 Tax, etc.), listeners for PostSalesInvoice/PostPurchaseInvoice/ApproveSalesReturn, and reports (trial balance, P&L, balance sheet, ledger).

**Phase 8+ – One module per chat** (use the generator): `Pos` → `Hrm` (split into sub-phases: org structure → employees → attendance/leave → payroll) → `Lead` (pipelines/stages, Kanban with @hello-pangea/dnd) → `Taskly` (projects/tasks Kanban/bugs/timesheet) → `Recruitment` → `SupportTicket` → `Contract` → `Quotation` → payment gateways (copy Stripe module pattern: settings page + payment controller + status event) → `LandingPage` → Messenger (Pusher) → Translation manager → Installer/Updater.

### 3.3 Handy "mini prompts" while building
- **New CRUD page:** "In module X add CRUD for `<entity>` with fields …; give migration, model, FormRequest, controller (permission checks + created_by scoping + search/sort/paginate like PosController::index), routes, PermissionTableSeeder entries, menu entry, and React Index page with table, filters, create/edit dialog, delete confirm."
- **Add integration event:** "When `<action>` happens fire `<Event>`; write the listener in module Account that creates the journal entry via JournalService."
- **Debug:** "Here is the error + the file. Explain the cause in 2 lines and give the corrected full file."
- **Review:** "Review this controller for tenant-isolation leaks (missing created_by filter), permission checks, N+1 queries, and validation gaps."
- **Resume next day:** paste Context Block + PROGRESS.md + "Continue with Phase N".
- **Token saver:** "Don't repeat unchanged files; give only new/changed files, full content."

### 3.4 Realistic expectations
- The original has ~54 modules / thousands of files. With Claude Pro you can realistically rebuild **core + 4-6 modules** quickly; do the rest gradually.
- Build order that gives a sellable MVP fastest: Phases 0-3 → ProductService → Sales/Purchase → Account → POS → HRM.
- Always commit to git after each phase.

### 3.5 Running the original project (reference)
```
composer install
npm install
copy .env.example .env ; php artisan key:generate
(create DB) → open /install wizard  OR  php artisan migrate --seed
php artisan package:seed <ModuleName>   # per module permissions
npm run dev   (or npm run build)
php artisan serve
```
