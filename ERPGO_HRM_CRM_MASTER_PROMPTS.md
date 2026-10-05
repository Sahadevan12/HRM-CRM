# HRM + CRM (Lead) – Deep Dive + MASTER PROMPTS for Claude Pro

> Idhu `ERPGO_ANALYSIS_AND_REBUILD_GUIDE.md`-oda continuation. Core (Phase 0-4: auth, roles, settings, module engine, plans, `make:package`) already ready-nu assume panrom.
> Source analysed: `packages/workdo/Hrm` (43 models, 47 controllers, 41 migrations) and `packages/workdo/Lead` (24 models, 15 controllers, 26 migrations).

---------------------------------------------------------------------

## PART A – HRM module (Workdo\Hrm) – how it works

`module.json`: name Hrm, alias HRM, priority 30, package_name `hrm`. Provider loads `Routes/web.php`, `Routes/api.php` (mobile API: attendance, leave, holiday, dashboard), migrations; EventServiceProvider listens `DefaultData` + `GivePermissionToRole`.

### A1. Sub-areas (menu: HRM → …)
| Area | Entities |
|---|---|
| Org structure (System Setup) | Branch → Department → Designation, Employee Document Types, Shifts |
| Employee | `employees` (profile, bank, salary, shift, branch/dept/designation, `user_id` link → login user), Employee Documents |
| Attendance | Shifts, Attendance (clock in/out, break, total_hour, overtime_hours/amount, status present/half day/absent), IP Restrict, Working-days setting |
| Leave | Leave Types, Leave Applications (pending/approved/rejected + approver comment), Leave Balance |
| Payroll | Set Salary (Allowances, Deductions, Loans, Overtimes + their *Types*), Payroll (batch) → Payroll Entries (per employee) → Pay Salary |
| Employee lifecycle | Awards(+types), Promotions, Resignations, Terminations(+types), Warnings(+types), Complaints(+types), Employee Transfers |
| Company comms | Holidays(+types), Announcements(+categories, per department), Events(+types, per department), HRM Documents(+categories), Acknowledgments |
| Dashboard | `hrm.index` (KPIs, charts) |

### A2. Key design facts
- **Employee = users row + employees row.** `employees.user_id` → `users.id`; most child tables (`attendances.employee_id`, `leave_applications.employee_id`, `loans.employee_id`…) point to **users.id**, not employees.id.
- Every table: `creator_id`, `created_by` (tenant). Every "Type" is a tenant-scoped lookup table.
- Routes are named `hrm.<entity>.index/store/update/destroy`; permissions `manage-/create-/edit-/delete-<entity>` seeded by `PermissionTableSeeder` and handed to `company` role (and via `GivePermissionToRole` to staff role).
- 100+ Create/Update/Destroy **events** are dispatched from controllers (hook points for other modules, webhooks, notifications).
- **Payroll engine (`PayrollController::store → processEmployeePayroll`):**
  1. Create `payrolls` (title, frequency weekly/biweekly/monthly, period start/end, pay_date, status draft→processing→completed/cancelled, is_payroll_paid, bank_account_id (Account module)).
  2. Working days = days in period filtered by global `working_days` setting (minus holidays).
  3. Per employee: `per_day_salary = basic_salary / working_days`; from attendance → present/half/absent days; from approved leaves → paid vs unpaid days; deductions: `half_day_deduction`, `absent_day_deduction`, `unpaid_leave_deduction`.
  4. Add allowances (fixed/percentage of basic), deductions, manual overtime, **attendance overtime** (hours × `rate_per_hour`), loans (fixed/percentage instalments).
  5. `gross_pay`, `total_deductions`, `net_pay` stored on `payroll_entries` (+ JSON breakdowns for allowances/deductions/overtimes/loans); unique(payroll_id, employee_id); payroll totals aggregated.
  6. `paySalary` marks entry paid and fires `PaySalary` → **Account module listener** posts journal/bank transaction (loose coupling).
- Leave balance = leave type max days − approved days in year.
- IP restriction + shift rules validate clock-in.

### A3. HRM tech structure
`Http/Controllers/*Controller` (+`Api/*ApiController`), `Http/Requests`, `Models`, `Events`, `Listeners/{DataDefault,GiveRoleToPermission}`, `Database/Migrations`, `Database/Seeders/{PermissionTableSeeder, Demo*Seeder, HrmDatabaseSeeder}`, `Resources/js/Pages/{Dashboard,Employees,Attendances,LeaveApplications,LeaveTypes,LeaveBalance,Payrolls,SetSalary,Shifts,Holidays,Awards,Promotions,Resignations,Terminations,Warnings,Complaints,EmployeeTransfers,Announcements,Events,HrmDocuments,Acknowledgments,SystemSetup}`, `Resources/js/menus/company-menu.ts`.

---------------------------------------------------------------------

## PART B – CRM module (Workdo\Lead, alias "CRM") – how it works

`module.json`: name Lead, alias CRM, priority 40, package_name `lead`. Routes under `crm/*`, route names `lead.*`; middleware `PlanModuleCheck:Lead`. Mobile API controllers exist (leads, stages, pipelines, dashboard).

### B1. Entities
| Group | Tables |
|---|---|
| Config | `pipelines`, `lead_stages` (name, order, pipeline_id), `deal_stages`, `labels`, `sources` |
| Lead | `leads` (name, email, subject, phone, user_id(owner), pipeline_id, stage_id, sources, products, labels, order, is_active, is_converted, date, notes) + `user_leads` (assigned users), `lead_tasks`, `lead_calls`, `lead_emails`, `lead_discussions`, `lead_files`, `lead_activity_logs` |
| Deal | `deals` (name, price, pipeline_id, stage_id, sources/products/labels JSON, status Won/Loss/Active, order, phone) + `client_deals` (client users), `user_deals`, `deal_tasks`, `deal_calls`, `deal_emails`, `deal_discussions`, `deal_files`, `deal_activity_logs` |
| User tweak | `users.default_pipeline` (remembers last pipeline) |

### B2. Workflow
```
Pipeline ──┬─ Lead Stages (Kanban columns)  → Lead cards (drag/drop)
           └─ Deal Stages                   → Deal cards (drag/drop)

Lead created (manual / FormBuilder / API)
  → assign users, products (from ProductService), sources, labels
  → log calls, emails, discussions, tasks, files   (each writes lead_activity_logs)
  → drag between stages: POST crm/leads/order  {lead_id, stage_id, order[]}
        - writes ActivityLog("Move", old→new stage)
        - optional email template "Lead Move" (if company_setting('Lead Move')=='on')
        - dispatches LeadMoved
  → Convert to Deal: POST crm/leads/{lead}/convert-to-deal
        - choose existing client OR create new client user (type=client, role client,
          canCreateUser() plan-limit check, email verification, CreateUser event)
        - first DealStage of same pipeline becomes stage; copies name/price/phone/sources/products/labels/users
        - copies calls/emails/discussions/files/tasks, links client_deals, sets lead.is_converted
        - dispatches LeadConvertDeal
Deal → change-status (Won/Loss) → Reports (lead & deal reports, charts) → CRM Dashboard
```
Default pipeline per user, stage re-ordering endpoint (`update-order`), notification/email templates (`Lead Move`, `New Deal`…) seeded by the module's `EmailTemplatesSeeder` / `NotificationsTableSeeder`.

### B3. Pages
`Resources/js/Pages/{Dashboard, Leads (Kanban + list + detail drawer with tabs: general/users/products/sources/emails/discussions/calls/files/tasks/activity), Deals (same), Reports, SystemSetup (pipelines, stages, labels, sources)}`. Kanban uses `@hello-pangea/dnd`.

---------------------------------------------------------------------

## PART C – MASTER PROMPT (HRM + CRM) ← paste this into Claude Pro

> Usage: **one new chat per "TASK" line**. Always paste the whole block between the lines, then change only the `TASK:` and `PROGRESS:`. Best: put PART C-1 and C-2 in a Claude **Project's knowledge** so you only type the TASK.

### C-1. SYSTEM CONTEXT (static – paste every time or store in Project knowledge)

```
You are a senior Laravel 12 + Inertia + React/TypeScript architect and pair-programmer.
We are rebuilding a multi-tenant SaaS ERP. The core app (auth, spatie roles/permissions,
settings, plans, add-on module engine, make:package generator, Account/ProductService modules)
already exists. We now build two add-on modules: HRM and CRM(Lead).

══ ARCHITECTURE (must follow exactly) ══
• Module path: packages/workdo/<Module>/ ; namespace Workdo\<Module>\ → src/
  (Hrm, Lead). Auto-registered by PackageServiceProvider via composer.json
  extra.laravel.providers. module.json: {name, alias, priority, version, monthly_price,
  yearly_price, package_name}.
• src/ layout: Providers/{<Module>ServiceProvider,EventServiceProvider}.php,
  Routes/{web,api}.php, Http/{Controllers,Controllers/Api,Requests}, Models, Events,
  Listeners/{DataDefault,GiveRoleToPermission}, Database/{Migrations,Seeders},
  Resources/js/{Pages,menus/company-menu.ts}.
• ServiceProvider boot(): loadRoutesFrom(Routes/web.php [+api.php]), loadMigrationsFrom(Database/Migrations);
  register(): register EventServiceProvider (listens App\Events\DefaultData → DataDefault,
  App\Events\GivePermissionToRole → GiveRoleToPermission).
• Multi-tenancy: every table has nullable indexed creator_id (FK users, set null) and
  created_by (FK users, cascade). Helper creatorId() = tenant (company) id.
  EVERY query: ->where('created_by', creatorId()). On create set creator_id=Auth::id(), created_by=creatorId().
• Auth: if (Auth::user()->can('<permission>')) {...} else redirect/back with('error', __('Permission denied')).
  Permissions kebab-case: manage-x, create-x, edit-x, delete-x, view-x (+ special e.g. lead-move).
  Seeded in Database/Seeders/PermissionTableSeeder.php: Permission::firstOrCreate(['name','guard_name'=>'web'],
  ['module','label','add_on'=>'<Module>']) and give to Role 'company'. Run with: php artisan package:seed <Module>.
• Routes: Route::middleware(['web','auth','verified','PlanModuleCheck:<Module>'])->group(...).
  HRM names: hrm.<entity>.index|store|update|destroy (URL prefix hrm/…), dashboard hrm.index.
  CRM names: lead.<entity>.* (URL prefix crm/…), dashboard lead.index.
• Controllers: index() = filter(search, sort whitelist, per_page) + paginate()->withQueryString() →
  Inertia::render('<Module>/<Folder>/Index', [...]); store/update use FormRequest; after each
  state change dispatch an Event (Create<X>, Update<X>, Destroy<X>) passing ($request, $model).
• Frontend: React 18 + TS; pages at packages/workdo/<Module>/src/Resources/js/Pages/<Folder>/<Page>.tsx
  (Inertia name '<Module>/<Folder>/<Page>'); UI = shadcn/Radix components from '@/components/ui/*',
  lucide-react icons, sonner toasts, useTranslation t('…') for ALL strings, ziggy route().
  Menu file exports e.g. export const hrmCompanyMenu = (t) => [{title,href,permission,order,parent?,children?}].
  Dashboard item uses parent:'dashboard'. Sidebar is filtered by permission + activated module.
• Cross-module communication ONLY via events/listeners (never import another module's controller).
• Money = decimal(10,2); dates = date/datetime; statuses = enum or string constants; JSON for breakdowns.
• Demo data: Demo<Entity>Seeder per entity + <Module>DatabaseSeeder.
• Code style: PSR-12, typed request validation, no N+1 (use with()/withCount()), DB::transaction for
  multi-table writes, no raw SQL unless needed.

══ DOMAIN SPEC: HRM ══
Entities (tenant-scoped): Branch → Department → Designation; EmployeeDocumentType; Shift
(shift_name,start/end/break times,is_night_shift); Employee (employees table: employee_id, dob, gender,
shift FK, attendance_policy, date_of_joining, employment_type, address, emergency contact,
bank fields, tax_payer_id, basic_salary, hours_per_day, days_per_week, rate_per_hour, user_id→users,
branch_id, department_id, designation_id); EmployeeDocument; Attendance(employee_id→users, shift_id, date,
clock_in/out, break_hour, total_hour, overtime_hours, overtime_amount, status present|half day|absent, notes);
IpRestrict; LeaveType; LeaveApplication(employee_id→users, leave_type_id, start/end, total_days, reason,
status pending|approved|rejected, attachment, approver_comment, approved_by/at); LeaveBalance (derived);
AllowanceType/Allowance, DeductionType/Deduction, LoanType/Loan(type fixed|percentage), Overtime(hours,rate,status);
Payroll(title, frequency weekly|biweekly|monthly, period, pay_date, totals, status draft|processing|completed|cancelled,
is_payroll_paid, bank_account_id) → PayrollEntry(per employee, unique(payroll_id,employee_id), per_day_salary,
working_days, present/half/absent/paid-leave/unpaid-leave days & deductions, manual + attendance overtime,
gross/net, JSON breakdowns, status paid|unpaid); Award(+Type), Promotion, Resignation, Termination(+Type),
Warning(+Type), Complaint(+Type), EmployeeTransfer, Holiday(+Type), Announcement(+Category, departments),
Event(+Type, departments), HrmDocument(+Category), Acknowledgment.
Rules: employee create also creates a users row (type 'staff', role staff, canCreateUser() plan limit) and fires
CreateUser; payroll math = per_day_salary = basic/working_days; deductions for half/absent/unpaid leave; add
allowances/overtime; subtract deductions/loan instalments; paySalary fires PaySalary event (Account listens);
working days come from company setting 'working_days' minus holidays.

══ DOMAIN SPEC: CRM (Lead) ══
Entities: Pipeline; LeadStage(name,order,pipeline_id); DealStage; Label; Source; Lead(name,email,subject,phone,
user_id,pipeline_id,stage_id,sources,products,labels,order,is_active,is_converted,date,notes) with UserLead,
LeadTask, LeadCall, LeadEmail, LeadDiscussion, LeadFile, LeadActivityLog; Deal(name,price,pipeline_id,stage_id,
sources/products/labels JSON,status,order,phone,is_active) with ClientDeal, UserDeal, DealTask, DealCall, DealEmail,
DealDiscussion, DealFile, DealActivityLog; users.default_pipeline.
Rules: Kanban drag-drop endpoint POST crm/leads/order {lead_id, stage_id, order[]} (writes ActivityLog 'Move',
optional 'Lead Move' email template, dispatches LeadMoved); convert lead→deal (existing or new client user,
first DealStage of pipeline, copy related records, mark is_converted, dispatch LeadConvertDeal); deal status
Won/Loss; products come from ProductService; reports: lead & deal reports by stage/source/user/month.

══ OUTPUT FORMAT ══
1) Brief plan (max 8 bullets). 2) Every file with its exact path and FULL content, in creation order.
3) Terminal commands. 4) Test checklist (URLs + expected behaviour). 5) Updated PROGRESS.md.
Do not repeat unchanged files. If the task is too big for one answer, finish a clean sub-part and say
"CONTINUE FROM: <next file>".
```

### C-2. TASK blocks (give ONE per chat, in order)

**HRM**
```
PROGRESS: core ready; HRM not started.
TASK H1 – Generate module skeleton `Hrm` (composer.json, module.json, providers, empty Routes, menus file)
and the org-structure setup: Branch, Department, Designation, EmployeeDocumentType, Shift – migrations, models,
requests, controllers, routes, permissions, seeder, React "SystemSetup" tabbed page, menu entries.
```
```
TASK H2 – Employees: migrations (employees, employee_documents), Employee create/edit that also creates the
linked users row + role + plan limit check, list/search/filter by branch/dept, profile page with documents tab,
events, permissions, React pages.
```
```
TASK H3 – Attendance + Holidays + IP restrict + working-days setting: clock-in/out (self + admin), shift/break
rules, total_hour/overtime calculation, status rules, monthly grid view, filters, events, mobile API
(AttendanceApiController).
```
```
TASK H4 – Leave: LeaveType, LeaveApplication (approve/reject with comment, UpdateLeaveStatus event), LeaveBalance
page, attachment upload, notification email template, API endpoints.
```
```
TASK H5 – Set Salary + Payroll: Allowance/Deduction/Loan(+Types), Overtime, SetSalary page, Payroll batch create
(processEmployeePayroll algorithm exactly as in the spec incl. JSON breakdowns), entries table, paySalary + PaySalary
event, payslip print/PDF. Include unit tests for the calculation with 3 scenarios.
```
```
TASK H6 – Lifecycle: Award(+Type), Promotion (updates designation), Resignation, Termination, Warning, Complaint,
EmployeeTransfer (updates branch/dept) – all with Types where applicable, status workflows, events.
```
```
TASK H7 – Comms: Announcement(+Category, departments), Event(+Type, departments) with calendar view, HrmDocument,
Acknowledgment; HRM Dashboard with KPI cards + charts (recharts); DataDefault listener seeding default
types for a new company; Demo seeders.
```

**CRM**
```
PROGRESS: core + HRM done (see PROGRESS.md).
TASK C1 – Generate module skeleton `Lead` (alias CRM) and setup: Pipeline, LeadStage, DealStage (drag re-order
endpoint update-order), Label, Source – migrations, models, controllers, routes (crm/*), permissions, React
SystemSetup page, menu entries, DataDefault seeding a default pipeline with stages.
```
```
TASK C2 – Leads: migrations (leads + user_leads, lead_tasks, lead_calls, lead_emails, lead_discussions,
lead_files, lead_activity_logs), LeadController CRUD, Kanban board (@hello-pangea/dnd) + list view toggle,
pipeline switcher saving users.default_pipeline, order endpoint with activity log + 'Lead Move' email template.
```
```
TASK C3 – Lead detail drawer: assign users/products/sources/labels, add call/email/discussion/file/task,
activity timeline, permissions per action, events (LeadAddCall, LeadUploadFile …).
```
```
TASK C4 – Deals + Convert: Deal tables (+client_deals, user_deals, deal_* children), Deal Kanban, change-status
Won/Loss, ConvertToDealRequest + convertToDeal exactly as in the spec (existing/new client, copy children,
is_converted, LeadConvertDeal event).
```
```
TASK C5 – CRM Dashboard + Reports (lead report by stage/source/user/month, deal report with won/lost value),
notification + email templates seeding, Demo seeders, mobile API controllers (Lead, Stage, Pipeline, Dashboard).
```

**Integration (after both)**
```
TASK I1 – Wire modules through events only: (a) HRM PaySalary → Account journal (Dr Salary Expense / Cr Bank),
(b) CRM deal Won → optional Sales Proposal/Invoice draft via event listener in core, (c) FormBuilder/web-to-lead →
Lead creation endpoint, (d) company dashboard widgets for HRM + CRM. Then do a full tenant-isolation and
permission review of both modules and list any missing created_by filters.
```

### C-3. Follow-up helper prompts
- **Fix:** `Error below + file. Give 2-line cause and the corrected FULL file only.`
- **Resume:** `Context block + PROGRESS.md. Continue with TASK <id>, CONTINUE FROM: <file>.`
- **Review:** `Review packages/workdo/Hrm for tenant leaks (missing created_by), missing can() checks, N+1, missing events, and give a patch list.`
- **Tests:** `Write Pest/PHPUnit feature tests for <controller>: tenant isolation, permission denied, validation, happy path.`
- **Speed:** `Use the make:package generator output as base; do not regenerate files I already have.`

### C-4. Reality check
HRM alone ≈ 40 tables / 47 controllers; CRM ≈ 24 tables / 15 controllers. Per TASK expect 1-3 Claude Pro messages (or use Claude Code to write files directly). Don't skip H1/C1 – everything else depends on the skeleton and conventions above. Commit to git after each TASK.
