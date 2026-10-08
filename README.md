# ERP Clone

A modular, multi-tenant SaaS ERP: **Laravel 12 + Inertia v2 + React 18 + TypeScript** (Tailwind 3, shadcn/ui).
One platform owner (super admin) sells **plans**; every **company** is a tenant with its own staff, clients and data.
Business features ship as **add-on modules** that a plan switches on.

The app lives in [`main-app/`](main-app). `PROGRESS.md` is the detailed build log (every phase, every rule, every known limit).

## Modules

| Module | What it does |
|---|---|
| **Core** | Companies, plans & coupons, orders / bank transfers, users, roles & permissions, settings (brand, currency), languages (en / ta), add-on switches, dashboard |
| **ProductService** | Products & services, categories, units, taxes, warehouses, stock, stock transfers |
| **SalesPurchase** | Sales proposals, sales & purchase invoices, returns (one `documents` engine) |
| **Account** | Chart of accounts, journal, customer / vendor payments, reports; books every posted document automatically |
| **POS** | Counter sales on top of sales invoices, receipts |
| **HRM** | Organisation, employees & documents, attendance (clock in/out, shifts, IP limits), leave, payroll & payslips, loans, promotions / resignations / terminations / transfers, awards / warnings / complaints, announcements, events, documents, HR dashboard |
| **CRM** (`Lead`) | Pipelines & stages (drag and drop), leads and deals boards, lead drawer (tasks, calls, e-mails, comments, files, activity), lead to deal conversion, won / lost, dashboard, reports, web-to-lead form |

Modules talk to each other through **core events only** (`app/Events`): e.g. a posted invoice books the ledger, a paid payroll
books the salary expense, a won deal can draft a sales proposal, every module adds a card to the company dashboard.

## Requirements

PHP 8.2+ (with `pdo_mysql`, `mbstring`, `intl`, `gd`/`fileinfo`), Composer, Node 20+, MySQL / MariaDB 10.4+ (SQLite is enough for the tests).

## Install (local)

```bash
cd main-app
composer install
cp .env.example .env            # then set DB_* (the database must exist)
php artisan key:generate
php artisan migrate
php artisan db:seed             # permissions, roles, plans, add-ons + demo logins
npm install
npm run build                   # or: npm run dev
php artisan serve
```

Local demo logins (created only outside production): `superadmin@example.com` and `company@example.com`, password `password`.
Optional: `php artisan crm:demo company@example.com` fills the demo company with sample leads and deals.

Plans decide which modules a company sees. Log in as the super admin, open **Plans**, and put the modules you want on a plan
(the seeded *Pro* plan contains every installed module); then assign the plan to a company.

## Tests

```bash
php artisan test            # ~390 feature tests, SQLite, a few minutes
npx tsc --noEmit            # type check
```

## Production checklist

1. `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://...`, MySQL credentials, a real mail server.
2. **First login:** set `SEED_PASSWORD` (and optionally `SEED_ADMIN_EMAIL`) *before* `php artisan db:seed --force`.
   In production only the super admin is created, never the demo company. Leave `SEED_PASSWORD` empty and a random password is printed **once**.
   Change it after the first login. Re-running the seeder never resets an existing password.
3. `composer install --no-dev --optimize-autoloader`, `npm ci && npm run build`.
4. `php artisan migrate --force && php artisan db:seed --force && php artisan config:cache && php artisan route:cache`
5. **Scheduler** (needed for `hrm:apply-lifecycle`, which applies approved resignations / terminations / transfers on their date):
   `* * * * * cd /path/to/main-app && php artisan schedule:run >> /dev/null 2>&1`
6. Web server document root = `main-app/public`. Make `storage/` and `bootstrap/cache/` writable.
7. Uploaded files (employee documents, HR documents, lead / deal files) are on the **private** `local` disk and are only served through
   permission-checked controllers. Back up `storage/app/private` together with the database.
8. HTTPS everywhere. The public web-to-lead endpoint (`/crm/web-to-lead/{secret}`) is rate limited and protected by a per-company secret that
   a company admin can renew or switch off in *CRM Setup*.

## Useful commands

| Command | Purpose |
|---|---|
| `php artisan package:sync` | Register / refresh add-ons from `packages/workdo/*/module.json`. **A module folder that was deleted is uninstalled**: its add-on row, plan entries, company grants and permissions are removed (its tables are kept) |
| `php artisan package:seed <Module>` | Re-run one module's permission seeder |
| `php artisan make:package <Name>` | New module skeleton (provider, routes, permissions, menu, React page) |
| `php artisan make:crud <Module> <Entity> --fields="name:string,price:decimal,branch_id:ref=Branch?"` | Full CRUD layer (migration, model, request, controller, events, React page, permissions, menu, routes) |
| `php artisan account:backfill` | Book old documents into the ledger |
| `php artisan hrm:apply-lifecycle` | Apply due resignations / terminations / transfers (scheduled daily) |
| `php artisan crm:demo <company e-mail> [--force]` | Sample CRM data for demos |

## How tenancy works

Every business table has `creator_id` and `created_by`; `created_by` is the **company (tenant) id**, and `creatorId()` returns it for the logged-in user.
Every query is filtered by it, and every id coming from a request is validated against it (`Rule::exists(...)->where('created_by', creatorId())`).
Roles: `superadmin` > `company` > `staff` / `client` / `vendor`. Permissions are per action (`manage-`, `create-`, `edit-`, `delete-` ...), checked in the controllers.

## Not built (by design or not yet)

Mobile API (there is no token login yet), e-mail / notification templates, online payment gateways (bank transfer only),
staff self-service resignation, booking a loan's disbursement in the ledger. See `PROGRESS.md` for every known limit.
