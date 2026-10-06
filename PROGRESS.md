# PROGRESS (paste this into Claude chats as "CURRENT STATE")

Project: ERPGo clone - Laravel 12 + Inertia v2 + React 18 + TypeScript (Option 1)
Location: `S:\kishro freelancer\erpgo-clone\main-app`
Docs: `ERPGO_ANALYSIS_AND_REBUILD_GUIDE.md`, `ERPGO_HRM_CRM_MASTER_PROMPTS.md` (copied from original project root)

## Tooling on this machine
- PHP 8.2.12 = `C:\xampp\php\php.exe` (NOT on PATH). MySQL/MariaDB = XAMPP (`C:\xampp\mysql`).
- Composer = `erpgo-clone\_tools\composer.phar` (+ `composer.bat` shim). Node 22.
- PowerShell session prefix: `$env:Path="C:\xampp\php;S:\kishro freelancer\erpgo-clone\_tools;$env:Path"`
- Dev: `php artisan serve --port=8001` + `npm run dev` (or `npm run build`)

## DONE
- [x] Phase 0: Laravel 12 skeleton, Breeze (Inertia React TS), Sanctum, spatie/laravel-permission, ziggy installed
- [x] `App\Providers\PackageServiceProvider` (auto-discovers `packages/workdo/*`, registered in bootstrap/providers.php)
- [x] `app/Helpers/Helper.php` autoloaded (`creatorId()` only for now)
- [x] `vite.config.js` globs module entries; `resources/js/app.tsx` resolves module pages
      (`Hello/Hello/Index` -> `packages/workdo/Hello/src/Resources/js/Pages/Hello/Index.tsx`)
- [x] `resources/views/app.blade.php` only preloads core page chunks (module pages load via app.tsx)
- [x] `tailwind.config.js` scans `packages/workdo/**`
- [x] Smoke-test module `packages/workdo/Hello` (route `/hello-module`) renders -> engine verified. Delete after Phase 4 generator.

- [x] Phase 0b: shadcn/ui (v2.3 for Tailwind 3) components in `resources/js/Components/ui`, `lib/utils.ts` (cn),
      CSS variables + Tailwind theme, lucide, sonner toasts (shown from `flash` in AuthenticatedLayout),
      i18next + react-i18next installed (NOT wired yet -> do in Phase 2)
- [x] Phase 1: MySQL DB `erpgo_clone` (XAMPP mysqld, root, no password; start: `C:\xampp\mysql\bin\mysqld.exe --defaults-file=C:\xampp\mysql\bin\my.ini --standalone`)
      - migration `add_tenancy_columns_to_users_roles_permissions` (users: type/slug/lang/plan fields/creator_id/created_by...;
        roles: label/description/creator_id/created_by; permissions: module/label/add_on)
      - `User` model (HasRoles, HasApiTokens, MustVerifyEmail, createdBy()), `creatorId()` helper
      - `PermissionRoleSeeder` (core permissions, roles superadmin/company/staff/client/vendor, 2 dev accounts:
        superadmin@example.com, company@example.com - dev password is in the seeder)
      - Self-registration => new company tenant (role company, created_by = superadmin)
      - `UserController` + `RoleController` (tenant scoped, permission checks, plan user-limit, role/permission whitelist)
      - Pages `Users/Index`, `Roles/Index` (table, search, dialog form, delete confirm, permission matrix), nav gated by permission
      - `HandleInertiaRequests` shares auth.user.permissions/roles + flash
      - Tests: `tests/Feature/TenancyTest.php` (8 tests: tenant isolation, perms, limit, role whitelist) -> `php artisan test` = 33 pass

- [x] Phase 2: settings + layout + i18n (41 tests pass)
      - `settings` table (key, value, is_public, created_by; unique key+tenant) + `Setting` model
      - Helpers (Helper.php): `setSetting`, `admin_setting`, `company_setting`, `getAdminAllSetting`, `getCompanyAllSetting`
        (cached per tenant, cache cleared on save; staff read their company's settings), `formatCurrency`, `availableLanguages`,
        `ActivatedModule` (PLACEHOLDER = all folders in packages/workdo with module.json -> replace in Phase 3)
      - `SettingController` (Brand / System / Currency, permissions manage-settings + edit-settings), `LanguageController`
      - i18n: `lang/{en,ta,languages}.json` (keys = English strings), server shares `translations` + `languages` + `auth.lang`;
        `resources/js/i18n.ts` `syncTranslations()` called in the layout. Add a language = drop `lang/<code>.json` + entry in languages.json
      - Shared props: adminAllSetting (public only for guests), companyAllSetting, auth.user.activatedPackages
      - Sidebar layout: `AppSidebar` + `AuthenticatedLayout` (mobile drawer, language switch, theme cycle light/dark/system, user menu)
      - Menu engine `utils/menu.ts`: core menu (`menus/core-menu.ts`) + add-on menus auto-loaded from
        `packages/workdo/<Module>/src/Resources/js/menus/company-menu.ts` (or superadmin-menu.ts) for activated packages,
        `parent` nesting, `order` sorting, permission filtering. Hello module proves it (nested under Dashboard)
      - Theme: company primary colour presets + per-user light/dark/system (`utils/theme.ts`, `hooks/useAppearance.ts`)
      - Tests: `SettingsTest` (8): tenant isolation, cache refresh, permissions, validation, currency, language, guest-public-only

- [x] Phase 3: SaaS engine (65 tests pass)
      - Tables: add_ons, user_active_modules, plans, coupons, user_coupons, orders, bank_transfer_payments
      - `App\Classes\Module`: installed() from module.json, sync() -> add_ons, allEnabled() (cached), setEnabled()
      - `App\Services\PlanService`: isExpired (no plan / plan_expire_date / trial_expire_date passed; free = no expiry),
        assign() (limits, expiry, replaces user_active_modules), activeModules(), validateCoupon(), quote(), completeOrder()
      - Helpers: companyOf, ActivatedModule (real), Module_is_active, assignPlan, canCreateUser
      - `PlanModuleCheck` middleware (alias) on the whole auth group: expired company -> only plans.*, bank-transfers.store, profile.*,
        languages.change, logout; expired company's sub-users are logged out; `PlanModuleCheck:Hello` (or `A-B` = any) gates module routes
      - Controllers: Plan (superadmin CRUD / company browse+subscribe+coupon+trial+free), Coupon, Order, BankTransferPayment
        (company submits -> pending order; superadmin approve/reject; proof download), Module (add-ons list/enable/disable)
      - Permissions: superadmin gets all; `PermissionRoleSeeder::ADMIN_ONLY` never goes to companies; company gets manage-plans,
        subscribe-plans, manage-orders. `PlanSeeder`: Free (3 users) + Pro (25 users, 14d trial, all modules); demo company on Free
      - Commands: `php artisan package:sync`, `php artisan package:seed <Module>` (runs Workdo\<Module>\Database\Seeders\PermissionTableSeeder)
      - Registration auto-assigns the free plan. Settings has a Payment tab (bankTransferDetails shown on Subscribe page)
      - Pages: Plans/Index (admin), Plans/Browse + Plans/Subscribe (company), Coupons, Orders, BankTransfers, AddOns
      - Tests: `SaasTest` (24) + `tests/TestCase.php` uses `withoutVite()`; UserFactory = company with plan 1, unlimited users

- [x] Phase 4: module generator (92 tests pass)
      - `php artisan make:package <Module> [--alias=] [--priority=] [--entity=Name ...]` -> composer.json, module.json, providers,
        Routes/web.php (PlanModuleCheck:<Module>), PermissionTableSeeder, menus/company-menu.ts (marker comments `// <use-statements>`,
        `// <crud-routes>`, `// <permissions>`, `// <menu-items>` - DO NOT delete them, make:crud inserts above them)
      - `php artisan make:crud <Module> <Entity> --fields="title:string,qty:integer,price:decimal?,due:date?,notes:text?,is_active:boolean"`
        (`?` = nullable; types string,text,integer,decimal,boolean,date) -> Model, Controller (tenant scope, can() checks, search/sort whitelist,
        pagination, Create/Update/Destroy events), SaveXRequest, migration, routes `<module>/<entities>` named `<module>.<entities>.*`,
        4 permissions (manage/create/edit/delete-<entities>) given to company role, menu item, React Pages/<Entities>/Index.tsx
        (table + search + create/edit dialog + delete confirm + i18n). No fields given => name:string, description:text?, is_active:boolean
      - After generating: `php artisan migrate && php artisan package:sync && php artisan package:seed <Module>`, add module to a plan, `npm run build`
      - Code: `App\Services\PackageGenerator`, stubs in `stubs/package/*.stub` (tokens look like %%Entity%%)
      - Sample modules: `Hello` (engine smoke test) and `Notes` (generated; Note fields above + Notebook defaults).
        `NotesSampleModuleTest` guards generator output (tenant isolation, validation, plan gating, events). Delete Notes + that test before shipping.
      - Tests: `PackageGeneratorTest` (21: files, lint, markers, invalid names/fields, no overwrite)

- [x] Phase 3.5: Companies page (superadmin): list/search, create (free plan), edit, assign plan (month/year/trial/lifetime),
      enable/disable login (blocks the company AND its staff via PlanModuleCheck; single users too via users.is_enable_login), delete (cascade).
      Permissions manage/create/edit/delete-companies are ADMIN_ONLY. `User::$attributes` mirrors DB defaults (needed for fresh models).
- [x] Phase 5: ProductService module (120 tests pass) - generated with make:crud, then customised
      - `PlanService::ALWAYS_ACTIVE = ['ProductService']`: every company gets it regardless of plan (hidden from the plan editor)
      - Entities (tables): ProductCategory(name,color #hex), ProductUnit, ProductTax(rate 0-100), Warehouse(address,city,phone,email,is_active),
        Product(name, sku unique per company, type product|service, sale/purchase price, category_id, unit_id, tax_ids json, is_active),
        ProductStock(product_id, warehouse_id, quantity>=0, unique pair), StockTransfer(product, from, to, quantity, date, notes)
      - Routes `product-service/*`, names `productservice.*` (products, product-categories, product-units, product-taxes, warehouses, stock-transfers;
        products/{product}/stock GET json + POST set quantity)
      - `Workdo\ProductService\Services\StockService` = the ONLY place stock changes: adjust(+/-), set, transfer (transaction + row lock,
        never below 0, throws InsufficientStockException). Sales/purchase/POS must call it.
      - Tenant safety: relation ids validated with Rule::exists(...)->where('created_by', tenant); unique sku per tenant
      - Transfers: atomic, services refused, same-warehouse refused, delete = reverse (refused if destination already spent the stock);
        warehouse with stock or transfer history cannot be deleted; deleting a product cascades its stock rows
      - Extra permissions: manage-product-stock, manage/create/delete-transfers. Events: Create/Update/DestroyProduct..., Create/DestroyStockTransfer
      - Pages: Products (filters, relation selects, tax checkboxes, per-warehouse stock dialog), StockTransfers, + generated master-data pages
      - BUG FIXED: re-running `PermissionRoleSeeder` used syncPermissions and wiped add-on permissions from the company role.
        Now it only gives/revokes core permissions; `php artisan db:seed` also runs every installed module's PermissionTableSeeder.

## TODO (next)
- [ ] Phase 6: Sales/Purchase documents (proposal -> invoice -> post -> returns) using StockService; item taxes; print pages
- [ ] Phase 7: Account module (chart of accounts, journal via events: PostSalesInvoice etc.), then POS, HRM (H1-H7), CRM (C1-C5)
- [ ] Online payment gateways (Stripe/Razorpay...) as modules; only bank transfer exists
- [ ] Then ProductService -> Sales/Purchase -> Account -> POS -> HRM (H1-H7) -> CRM (C1-C5)

## Gotchas learned
- Breeze `npm install` conflict: `@types/node` must be ^22 and `@tailwindcss/vite` removed (Tailwind 3 is used).
- Never write package.json with PowerShell `Set-Content -Encoding utf8` (BOM breaks Vite/PostCSS).
- Inertia test helper `->component('Mod/Folder/Page')` needs `, false` for module pages (it only looks in resources/js/Pages).
- Settings are cached forever per tenant: editing the `settings` table by hand needs `php artisan cache:clear`.
- Radix dialogs stay in the DOM (data-state=closed) while the Browser pane is hidden - animation never ends; not a bug.
- Claude Code preview tool is anchored to the ORIGINAL project's launch.json; run the clone's server manually.

