# PROGRESS (paste this into Claude chats as "CURRENT STATE")

Project: ERPGo clone – Laravel 12 + Inertia v2 + React 18 + TypeScript (Option 1)
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

## TODO (next)
- [ ] Phase 0b: shadcn/ui setup (`@/components/ui`), tsconfig alias for packages, i18next
- [ ] Phase 1: users migration columns (type, creator_id, created_by, lang, active_plan, plan_expire_date, ...),
      MySQL DB `erpgo_clone`, roles/permission seeder, Users + Roles CRUD scoped by created_by
- [ ] Phase 2: settings table + helpers + authenticated layout + menu engine
- [ ] Phase 3: add_ons / user_active_modules, Module class, PlanModuleCheck, plans/coupons/orders
- [ ] Phase 4: `make:package` generator
- [ ] Then ProductService -> Sales/Purchase -> Account -> POS -> HRM (H1-H7) -> CRM (C1-C5)

## Gotchas learned
- Breeze `npm install` conflict: `@types/node` must be ^22 and `@tailwindcss/vite` removed (Tailwind 3 is used).
- Never write package.json with PowerShell `Set-Content -Encoding utf8` (BOM breaks Vite/PostCSS).
- Claude Code preview tool is anchored to the ORIGINAL project's launch.json; run the clone's server manually.
