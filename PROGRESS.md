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
- [x] Phase 9b: HRM H3 (attendance) + H4 (leave) - 255 tests (HrmAttendanceLeaveTest = 28, generator `time` test added)
      - Generator field type `time` (`$table->time`, `date_format:H:i`, `<Input type="time">`).
      - Generated CRUD: Shift (start/end/break/night), Holiday (end >= start), LeaveType (days_per_year, 0 = unlimited; cannot be deleted once used),
        IpRestriction (valid + unique per company). Employee got `shift_id` (same-company rule, Form dropdown).
      - Rules live in services: `WorkCalendar` (working weekdays from setting `hrmWorkingDays`, default Mon-Sat, + holidays),
        `AttendanceService` (clock in/out, manual entries, hours = out - in - break, overtime above the shift length, half day below half a shift,
        late = delay from shift start once beyond grace `hrmLateGraceMinutes` (default 10), night shifts end after midnight and belong to the start day,
        one record per employee per day, IP allow-list for self clocking, only active employees), `AttendanceSummary` (per working day:
        leave_paid/leave_unpaid > present/half_day/absent; past day without record = absent; future = upcoming; weekly offs/holidays never absent -
        payroll reuses it), `LeaveService` (working days only, no overlap, no cross-year, yearly allowance re-checked on approve, row lock on apply).
      - Times are stored in the app time zone and shown in the company time zone (`in_time`/`out_time` appended on Attendance).
      - Self service: staff role gets `clock-attendance` + `apply-leave` (seeded); an employee only sees/withdraws their OWN pending leave, owner without
        an employee profile gets a clear message. HR: manage/create/edit/delete-attendances, manage/approve/delete-leave-applications, manage-hrm-settings.
      - Pages: My Attendance, Attendance Records (filters + manual entry), Monthly Summary, Leave Applications (apply / approve / reject), Leave Balance, HRM Settings.
      - Verified in the browser: all new pages render without console errors.
- [x] Phase 9c: HRM H5 (payroll) - 280 tests (HrmPayrollTest = 25)
      - Setup: SalaryComponent (generated CRUD; allowance|deduction, fixed|percent of basic), Salary Setup page (basic, hourly rate, components per employee),
        Loans (instalment per month until repaid; cancel; delete only without repayments).
      - `PayrollService`: one payroll per company and month. draft (regenerate = recalculate) -> approved (loan instalments counted as repaid; "reopen" gives
        them back) -> paid (final). basic prorated by working days since joining, absence = daily rate x (absent + unpaid leave + half days/2) from the SAME
        `AttendanceSummary` as the attendance report, overtime = hours x hourly rate (or daily/8) x setting `hrmOvertimeMultiplier` (default 1.5),
        loans last and never push net below 0. Only active employees; payslip lines keep a readable breakdown.
      - Core event `App\Events\PaySalary` (plain numbers) -> Account listener `RecordSalaryPayment` books ONE entry: Dr Salaries & Wages (5300),
        Cr Cash/Bank, Cr Salary Deductions Payable (2300), Cr Employee Loans (1400); inside the pay transaction (failure => still approved).
        New default accounts 1400/2300/5300 (default chart is now 15 accounts).
      - Payslips: HR sees all; staff (`view-payslips`) only their own, only once the payroll is approved/paid; printable page.
      - An employee with payslips/loans cannot be deleted (terminate instead).
      - Known limits: loan disbursement itself is not booked (only the recovery credits 1400); a paid payroll cannot be reversed; no tax slabs / bonuses.
- [x] Phase 9d: HRM H6 (lifecycle) - 294 tests (HrmLifecycleTest = 13, generator test for two refs to one entity)
      - Generated CRUD: AwardType, Award, Warning (severity low|medium|high), Complaint (from / against, open|resolved, from != against).
        Generator fix: two ref fields to the SAME entity now share one import / options prop / argument (was a fatal duplicate `use`).
        Generated pages show employees as `EMP-0001 · Name` (Employee has no name column - the name lives on the user).
      - Promotions: applied at once (designation changes), previous designation kept, no delete.
      - Resignations / Terminations / Transfers: pending -> approved | rejected; one pending request per employee and kind; an approved request is
        APPLIED on its effective date (immediately when due, else by `php artisan hrm:apply-lifecycle`, scheduled daily 00:10): resigned/terminated
        status + login switched off, or branch/department changed. Approved requests are history (cannot be deleted). Resigned/terminated employees get no payslip.
      - One controller + one React page (`Hrm/Lifecycle/Index`) serve the four kinds: the server describes fields/columns (`LifecycleController`).
      - Menu: HRM -> Awards & Discipline (4 pages), Employee Lifecycle (4 pages). Self-service resignation by staff is NOT built (HR enters it).
- [x] Phase 9e: HRM H7 (communication + dashboard) - 308 tests (HrmCommsTest = 14). HRM module (H1-H7) is COMPLETE.
      - New npm dependency: recharts 2.15 (HRM dashboard charts).
      - Announcements (+ Category, generated): optional end date, department targeting (no department = everyone), optional acknowledgment with
        "x / y acknowledged" for HR; employees see only RUNNING announcements meant for their department and acknowledge them (idempotent, `acknowledgments` table).
      - Events (+ EventType with hex colour, generated): month calendar (Monday first, multi-day events span days, double click a day to add),
        department targeting like announcements; employees can view, HR create/edit/delete.
      - Documents: company files (policies...) on the PRIVATE disk, every employee can download, optional acknowledgment, HR upload/delete (pdf/jpg/png/doc/docx, 5 MB).
      - HRM Dashboard (`view-hrm-dashboard`): KPI cards, headcount by department, 7-day attendance, joiners per month, gender / employment type pies,
        upcoming events, birthdays (30 days), announcements, last payroll.
      - `Services\DefaultData`: starter leave types, shift, document / award / event types, announcement categories - seeded ONCE per company
        (setting `hrmDefaultsSeeded`) when the HRM dashboard is first opened; deleted defaults never come back. No separate demo seeder.
      - Staff role gets view-announcements / view-events / view-hrm-documents.
- [x] Phase 10a: CRM C1 (module `Lead`, alias CRM, menu "CRM") - 321 tests (CrmSetupTest = 13)
      - New npm dependency: @hello-pangea/dnd (stage drag and drop; also used by the lead / deal boards later).
      - Tables: pipelines (unique name per company), lead_stages + deal_stages (`order` per pipeline), labels (hex colour, per pipeline), sources.
      - ONE tabbed page `Lead/SystemSetup/Index` + ONE `SetupController` for the five kinds (`crm/setup/{kind}`): add / rename / delete, stages are
        re-ordered by drag and drop (`reorder` takes the full id list of one pipeline; stale / foreign / duplicate lists are refused). New stages go last,
        deleting a stage closes the gap, a stage or label never moves pipeline, the LAST pipeline cannot be deleted, a new pipeline starts with the standard stages.
      - `DefaultData` (once per company, flag `crmDefaultsSeeded`, on first visit): pipeline "Sales", lead stages New/Contacted/Qualified/Proposal Sent,
        deal stages Initial Contact/Qualification/Meeting/Proposal/Negotiation, labels Hot/Warm/Cold, six sources.
      - TODO in C2/C4: `SetupController::inUse()` is the hook that must refuse deleting a stage / source / label that leads or deals still use.
      - Verified in the browser incl. keyboard drag and drop (order persists after reload).
- [x] Phase 10b: CRM C2 (leads + board) - 332 tests (CrmLeadsTest = 11)
      - Tables: leads (pipeline + stage restrict FKs, `order` inside a column, is_active, is_converted for C4), user_leads (assignees),
        lead_activity_logs, crm_preferences (last pipeline of a user). The other lead children (calls, emails, discussions, files, tasks) come with C3.
      - `LeadService`: create (first stage unless one of THAT pipeline is given, appended last), update (assignee changes and edits are logged; the stage
        never changes here), move (stage of the lead's own pipeline only, converted leads are frozen, the posted id list must be the destination column
        plus the moved lead, positions rewritten, a stage change is logged as "Moved from X to Y").
      - Visibility: the company owner and users with `view-all-leads` see every lead, others only leads they created or are assigned to (also enforced
        on update / delete / move). Assignees must be the owner or staff of the same company.
      - Page `Lead/Leads/Index`: Kanban board (drag between and inside columns, optimistic update, reloads on error) / list view (search, stage filter,
        pagination), pipeline switcher that is remembered per user, add / edit / delete dialog with assignees.
      - Setup guards: a lead stage or pipeline that still holds leads cannot be deleted.
      - Not built (yet): the "Lead Move" e-mail template - there is no e-mail template system in the core yet.
      - Verified in the browser: created a lead through the form, dragged it to Contacted by keyboard, order survives a reload.## TODO (next)
- [x] Phase 10c: CRM C3 (lead drawer) - 345 tests (CrmLeadDetailTest = 13)
      - Click a card / subject -> drawer (`Leads/LeadDrawer.tsx`) with tabs Overview / Tasks / Calls / Emails / Discussion / Files / Activity. It talks to ONE JSON
        controller (`LeadDetailController`, routes `crm/leads/{lead}/...`): every mutation answers with the refreshed lead, 422 errors are shown inline.
      - Overview: sources, labels (only the labels of the lead's OWN pipeline) and products (ProductService) are assigned with checkboxes / search (needs edit-leads).
      - Tasks (due date, priority, complete / reopen), calls (in / out, minutes, result), e-mails (a RECORD only - nothing is sent), discussion, files
        (private disk `lead-files/<company>`, pdf/jpg/png/doc/docx/xls/xlsx/csv/txt, 5 MB, downloaded through the controller).
      - Permissions: manage-lead-tasks / -calls / -emails / -discussions / -files, one per action; the author removes their own entry, delete-leads any.
        Every call also checks company + lead visibility (403 JSON). An entry of another lead cannot be reached through this one (404).
      - Events (hooks for other modules): LeadCallAdded, LeadEmailAdded, LeadDiscussionAdded, LeadFileUploaded, LeadTaskAdded. Everything is written to the activity timeline.
      - A source / label that leads still use cannot be deleted (Setup guard).
      - Verified in the browser: open the drawer, tick a label, post a comment, timeline shows both.- [ ] Phase 9b: HRM H3 attendance (Shift, clock in/out, working days, holidays, IP restrict), H4 leave, H5 payroll (set salary, allowances, deductions, loans,
- [x] Phase 10d: CRM C4 (deals + lead conversion) - 364 tests (CrmDealsTest = 19)
      - Deal tables mirror the lead ones (deals, user_deals, client_deals, deal_activity_logs, deal_sources / labels / products, deal_tasks / calls / emails /
        discussions / files). The deal models, events and `DealDetailController` were GENERATED from the lead ones (same rules, `deal` instead of `lead`);
        NOTE the deal drawer endpoints answer with a `deal` key, the lead ones with `lead` (the drawer reads either).
      - Deal: name, price, phone, notes, stage (first deal stage first), status active | won | lost, clients (users of type client; the POS walk-in
        customer is excluded), staff. Board + list (search / stage / status), won / lost / reopen buttons (`change-deal-status`; event `DealStatusChanged`),
        a decided deal cannot be dragged until reopened. Same visibility rule as leads (`view-all-deals`). The drawer (`LeadDrawer entity="deal"`) is shared.
      - Convert (`LeadConversion`, button on the lead card, permission `convert-leads`): price, pipeline, client = none / existing / NEW (a `client` user without
        login, role client, counts against the plan seat limit, e-mail must be unused) and tick-boxes for what to copy (products, sources, labels - only into
        the same pipeline -, tasks, calls, e-mails, comments, files = real file copies). One transaction; copied files are removed again if it fails.
        The lead is marked converted (frozen on the board, cannot be converted twice); deleting the deal frees it again. Event `LeadConverted`.
      - Setup guards extended: deal stages / pipelines / sources / labels used by deals cannot be deleted.
      - Verified in the browser: converted the demo lead, marked the deal Won, opened the deal drawer, the lead shows "Converted".      overtime, payroll batch + payslip + PaySalary event -> Account), H6 lifecycle (awards, promotions, resignations, terminations, warnings, complaints,
- [x] Phase 10e: CRM C5 (dashboard, reports, demo data) - 375 tests (CrmReportsTest = 11). CRM module (C1-C5) is COMPLETE except the items below.
      - `deals.closed_at` (set when a deal becomes won / lost, cleared on reopen): won / lost value is reported in the month it was DECIDED.
      - `CrmReportService`: lead report (by stage / source / user / month, conversion rate; counted by creation date), deal report (won / lost count + value,
        win rate, open pipeline per stage = snapshot of today, won by user, won / lost per month). Everything is limited to the company AND to what the user may
        see (owner / `view-all-*` see all, other staff only their own leads and deals). Period is sanitised (bad dates -> this year, swapped, max 5 years).
      - Pages: `crm/dashboard` (KPIs of this month, charts, tasks due / overdue, latest activity; permission view-crm-dashboard) and `crm/reports` (pipeline +
        period filter, tables and recharts; permission view-crm-reports).
      - `php artisan crm:demo <company e-mail> [--force]`: 12 leads over all stages, sources, labels, tasks and 8 deals (open / won / lost over six months);
        refuses a company that already has leads or deals unless --force.
      - Bug found by the browser check: the activity logs had no `lead` / `deal` relation (fixed + test).
      - NOT built: the mobile API controllers (the core has no API token login yet) and notification / e-mail templates (no template system in the core).      transfers), H7 announcements/events/HR documents/dashboard. See ERPGO_HRM_CRM_MASTER_PROMPTS.md for the spec.
- [x] Phase 11: Integration I1 - 392 tests (IntegrationTest = 17). Modules talk through CORE events only.
      - Company dashboard widgets: core event `CollectDashboardWidgets` (App\Events); `DashboardController` collects, the `Dashboard` page renders one generic
        card per module. HRM and CRM add theirs (`AddHrmDashboardWidget`, `AddCrmDashboardWidget`) only when the module is active for the company AND the user may
        open that module's dashboard; numbers are the company's own (CRM ones respect lead / deal visibility).
      - Deal won -> draft proposal: core event `DealWon` (plain data), dispatched by `DealService::setStatus`; the SalesPurchase listener
        `DraftProposalForWonDeal` makes a DRAFT sales proposal (deal's client, deal's products qty 1 at list price, first warehouse, notes marker
        "CRM deal #id: name") - OPT-IN per company (CRM Setup > Automation, setting `crmDraftProposalOnWin`). It skips and says why (no client / no
        products / no warehouse / already drafted); the reason is written into the deal's activity trail. A failing listener never undoes the win.
      - Web to lead: PUBLIC `POST /crm/web-to-lead/{secret}` (no login, no CSRF, throttle 30/min, CORS open, OPTIONS answered, honeypot field `website_url`).
        The secret belongs to one company, is shown with a copy-paste HTML snippet in CRM Setup and can be renewed / switched off. Creates a lead in the first
        stage of the oldest pipeline with source "Website" and a "web" activity entry; needs an e-mail or phone; a company without the module gets 404.
      - REVIEW of HRM + CRM: every controller method has a permission check (only the two public web-form methods do not, on purpose); every query by id
        is tenant-scoped (validated ids, `created_by` checks, or children reached through a checked parent). FINDINGS FIXED: (1) SECURITY - `companyAllSetting`
        shared ALL company settings, including non-public ones (the web form secret, internal flags, POS walk-in id), with every logged-in user of the company
        (staff, clients): now only public settings are shared; (2) HRM documents list did one count query per document: now one grouped query.
      - Verified in the browser: dashboard cards, switched the web form on in CRM Setup and posted a lead to the real endpoint.- [ ] CRM (C1-C5)
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

