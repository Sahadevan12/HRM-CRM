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

## TODO (next)
- [ ] Phase 3: add_ons / user_active_modules, Module class, PlanModuleCheck, plans/coupons/orders
- [ ] Phase 4: `make:package` generator
- [ ] Then ProductService -> Sales/Purchase -> Account -> POS -> HRM (H1-H7) -> CRM (C1-C5)

## Gotchas learned
- Breeze `npm install` conflict: `@types/node` must be ^22 and `@tailwindcss/vite` removed (Tailwind 3 is used).
- Never write package.json with PowerShell `Set-Content -Encoding utf8` (BOM breaks Vite/PostCSS).
- Claude Code preview tool is anchored to the ORIGINAL project's launch.json; run the clone's server manually.

