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

- [x] Phase 6: SalesPurchase module (145 tests pass), ALWAYS_ACTIVE like ProductService
      - ONE table family for all 5 trade documents: `documents` (type sales_invoice|purchase_invoice|sales_proposal|sales_return|purchase_return,
        number unique per company+type e.g. SI-00001, party_id = users of type client/vendor, warehouse_id, parent_id, status, subtotal,
        discount_amount, tax_amount, total_amount, paid_amount (Account module will maintain), posted_at), `document_items`
        (name snapshot, qty, unit_price, discount_amount, tax_amount, total_amount, source_item_id for return lines), `document_item_taxes` (snapshot)
      - `Support\DocumentType` registry (labels, prefix, slug, party role, stock direction, statuses). Routes `sales-purchase/<slug>` named
        `salespurchase.<slug>.<action>` are generated in a loop with the route default `type` (ONE `DocumentController` for all types)
      - `Services\DocumentCalculator` = all money maths (gross=qty*price, net=gross-discount, tax per tax rounded to 2dp, totals) - client totals are ignored;
        returns are priced pro rata from the invoice line; `returnableQuantities()` adds up across returns
      - `Services\DocumentService` lifecycle: invoice draft --post--> posted (stock out for sales / in for purchase, via StockService, in a transaction,
        immutable afterwards); proposal draft->sent->accepted->converted (creates draft invoice, deleting that draft gives the proposal back) | rejected;
        return draft --approve--> approved (stock back in / out again) --complete--> completed. Only drafts can be edited/deleted.
      - Core events (App\Events, so Account/POS can listen without importing the module): PostSalesInvoice, PostPurchaseInvoice, ApproveSalesReturn,
        ApprovePurchaseReturn, CompleteSalesReturn, CompletePurchaseReturn, ConvertSalesProposal (payload: Document model) + CompanyDeleting
      - Permissions per type: manage/create/edit/delete (+post for invoices, +approve for returns, returns have no edit). Customers/vendors are Users of
        type client/vendor created on the Users page.
      - Deleting a product/warehouse/customer that is used by a document is refused (restrictive FKs); deleting a company clears its documents first
        (SalesPurchase listens to CompanyDeleting).
      - Pages: Documents/Index, Form (live line-item editor with tax checkboxes; return mode with per-line returnable qty), Show (status-dependent
        buttons from server `can`, print via window.print + `print:hidden` in the layout, related documents), `useMoney()` hook (company currency)
      - Verified in browser: invoice 5 x 25 - 10 discount + 18% GST = $135.70 -> post (stock 30->25) -> return 2 pro rata $54.28 -> approve (stock 27)
      - Tests: `SalesPurchaseTest` (25): maths, numbering, tenant isolation, role mismatch, atomic posting, proposals, returns (cumulative qty), permissions, guards

- [x] Phase 7: Account module "Accounting" (175 tests pass; NOT always-active: it is part of the Pro plan)
      - Tables: chart_of_accounts (code unique per company, type asset|liability|equity|revenue|expense, is_bank, is_system, is_active),
        journal_entries (+items; number JE-00001, entry_type manual|automatic, reference_type/id UNIQUE per company = one entry per source document),
        account_payments (kind customer|vendor, one invoice per payment, bank/cash account)
      - Default chart (12 system accounts: 1000 Cash, 1010 Bank, 1100 AR, 1200 Inventory, 1300 Tax Receivable, 2000 AP, 2210 Tax Payable,
        3000 Equity, 4100 Sales, 4200 Sales Returns, 5000 COGS, 5200 Services/Other) is created lazily per company (`AccountService::ensureDefaults`);
        system accounts can be renamed only (no delete / code / type / deactivate); accounts with bookings cannot be deleted or change type
      - `JournalService::record()` = the only way to write the books: >= 2 lines, debit == credit (0.005), active accounts of the company, idempotent per reference.
        Balances are NEVER stored, always summed from journal lines.
      - Automatic bookings (listener `PostDocumentToLedger` on PostSalesInvoice / PostPurchaseInvoice / ApproveSalesReturn / ApprovePurchaseReturn, dated on the
        DOCUMENT date): sales invoice Dr AR / Cr Sales(net) + Cr Tax Payable, plus Dr COGS / Cr Inventory at the product's purchase price; purchase invoice
        Dr Inventory (products) + Dr Services expense + Dr Tax Receivable / Cr AP; returns reverse pro rata.
        The events are now dispatched INSIDE the posting transaction in DocumentService, so a failing booking rolls back stock + status too.
        Companies without the Account module are skipped; `php artisan account:backfill [company]` books older documents later (idempotent).
      - Payments (`PaymentService`): customer Dr Bank / Cr AR, vendor Dr AP / Cr Bank; amount <= outstanding (= total - paid - approved returns, row lock);
        updates documents.paid_amount; delete reverses booking + paid_amount
      - Manual journal entries (balanced form, delete only manual), reports: trial balance, profit & loss, balance sheet (with "current earnings", always balances),
        general ledger (opening + running balance), all per company
      - Pages: ChartOfAccounts, JournalEntries (Index/Form/Show), Payments (customer + vendor, one controller with route default `kind`), Reports (4 tabs, print)
      - KNOWN LIMITS: manual stock counts / warehouse transfers are not booked (opening stock must come from a purchase invoice or a manual journal
        Dr Inventory / Cr Equity, otherwise Inventory can go negative); no bank reconciliation, no credit/debit note documents, no period closing / locking,
        one invoice per payment, no multi-currency
      - Tests: `AccountingTest` (30). Test infra: `tests/TestCase.php` migrates + seeds ONCE per run (file sqlite `database/testing.sqlite`); never `use RefreshDatabase`
        in a test class; module PermissionTableSeeders grant permissions in ONE givePermissionTo call (per-permission calls were very slow)

- [x] Phase 8: Pos module "Point of Sale" (199 tests pass; part of the Pro plan, needs ProductService + SalesPurchase, Account optional)
      - A POS sale IS a posted sales invoice in `documents` (+ one `pos_sales` row: cashier, payment_method cash|card|bank_transfer|credit,
        amount_paid, amount_tendered, change_due). So stock, taxes, numbering (SI-xxxxx), returns (SalesPurchase "Create return" on the invoice) and the
        ledger all work unchanged.
      - `Services\PosService::checkout` = ONE transaction: price the cart from the DB (cashier only chooses qty + line discount; client prices/taxes ignored),
        create invoice, post it (stock out + PostSalesInvoice -> ledger), take payment, rollback on ANY failure (stock, ledger...).
      - Payment: core event `App\Events\PosPaymentReceived`; Account's `RecordPosPayment` listener books a customer payment (cash -> 1000, card/transfer -> 1010).
        If nobody handles it (company has no Accounting) the invoice's paid_amount is set directly. `credit` = sale on account (needs a real customer).
      - Walk-in customer: `Support\WalkInCustomer` creates ONE reserved client user per company (no login, setting `walkInCustomerId`); hidden from the Users list,
        excluded from the plan seat count (`canCreateUser`)
      - Routes `pos/*`, names `pos.*`: terminal, products (JSON search by text/category/exact SKU + stock of the chosen warehouse), checkout, receipts.show,
        orders.index (filters + total), reports.index (summary net of returns, by method / cashier / day, top products)
      - Permissions: manage-pos (look), create-pos (sell), manage-pos-orders, manage-pos-reports
      - UI: Terminal (product grid, barcode/SKU Enter, cart with qty/discount, stock limit, customer, payment buttons, quick cash, change), 80mm-style Receipt (print),
        Orders, Reports. Verified in the browser: 2 x 25 + 18% GST = $59.00, cash $100, change $41, stock 27 -> 25, JE for invoice + payment.
      - Tests: `PosTest` (24)
      - NOT built: held/parked carts, shift open/close + cash drawer count, refunds directly from the POS screen (use the invoice's "Create return"), barcode printing,
        customer-facing display, offline mode

- [x] Phase 9a: HRM H1 (organisation) + H2 (employees) - module `Hrm`, 226 tests pass (HrmEmployeesTest = 25)
      - Generator got a new field type: `branch_id:ref=Branch?` (foreign key to another entity of the SAME module): FK migration (nullOnDelete /
        restrictOnDelete), model relation, tenant-scoped `Rule::exists`, controller eager load + `<entity>Options` prop, Select in the React form
        ('none' sentinel -> null). New migrations are always stamped AFTER the module's existing ones (parents first).
      - Org entities (generated): Branch, Department(branch_id?), Designation(department_id?), EmployeeDocumentType(is_required)
      - Employee = login `users` row (type staff, role staff or a company role, email verified) + `employees` HR profile, created/updated/deleted
        together in one transaction (`Services\EmployeeService`): auto code EMP-0001 per company (unique per company), plan seat limit enforced,
        role whitelist (no company/superadmin escalation), tenant-scoped branch/department/designation, status never reset by accident on update,
        `is_enable_login` off blocks the employee. Bank/tax fields are `$hidden` in lists and only returned on the profile/edit pages.
      - Employee documents: PRIVATE `local` disk (`employee-documents/<tenant>/...`), downloaded only through the controller (tenant + employee match),
        pdf/jpg/png/doc/docx max 5 MB, expiry date, required-document-type warning; deleting an employee removes the files.
      - Pages: Employees Index (filters), Form (sections; department/designation lists follow the branch/department), Show (profile + documents)
      - Permissions: manage/create/edit/delete-employees, manage-employee-documents (+ generated ones for the 4 org entities)
      - Menu: HRM -> Employees, Organization -> Branches / Departments / Designations / Document Types
      - Verified in the browser: hired Meena (EMP-0001) through the real form; profile shows branch, department and salary.

## TODO (next)
- [ ] Phase 9b: HRM H3 attendance (Shift, clock in/out, working days, holidays, IP restrict), H4 leave, H5 payroll (set salary, allowances, deductions, loans,
      overtime, payroll batch + payslip + PaySalary event -> Account), H6 lifecycle (awards, promotions, resignations, terminations, warnings, complaints,
      transfers), H7 announcements/events/HR documents/dashboard. See ERPGO_HRM_CRM_MASTER_PROMPTS.md for the spec.
- [ ] CRM (C1-C5)
- [ ] (old) Phase 7: Account module (chart of accounts, journal via events: PostSalesInvoice etc.), then POS, HRM (H1-H7), CRM (C1-C5)
- [ ] Online payment gateways (Stripe/Razorpay...) as modules; only bank transfer exists
- [ ] Then ProductService -> Sales/Purchase -> Account -> POS -> HRM (H1-H7) -> CRM (C1-C5)

## Gotchas learned
- Breeze `npm install` conflict: `@types/node` must be ^22 and `@tailwindcss/vite` removed (Tailwind 3 is used).
- Never write package.json with PowerShell `Set-Content -Encoding utf8` (BOM breaks Vite/PostCSS).
- Inertia test helper `->component('Mod/Folder/Page')` needs `, false` for module pages (it only looks in resources/js/Pages).
- Settings are cached forever per tenant: editing the `settings` table by hand needs `php artisan cache:clear`.
- Radix dialogs stay in the DOM (data-state=closed) while the Browser pane is hidden - animation never ends; not a bug.
- Laravel passes route parameters to controller methods IN URL ORDER (route defaults come last): with `{document}` in the URL and a `type` default,
  the method must be `(Document $document, string $type)`, not the other way round (otherwise "Argument must be of type Document, string given").
- Model instances built with `User::create()` do not have DB column defaults loaded (e.g. is_enable_login null) -> keep `$attributes` in sync.
- Browser pane: Inertia XHR calls need `Accept: application/json` (no X-Inertia header) when scripting axios from the console.
- Claude Code preview tool is anchored to the ORIGINAL project's launch.json; run the clone's server manually.

