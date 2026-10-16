# ERP Suite: main app

Laravel 12 + Inertia v2 + React 18 + TypeScript. Everything you need to install, run, test and deploy it is in the
**[repository README](../README.md)**; the full build log is in [`../docs/DEVELOPMENT_LOG.md`](../docs/DEVELOPMENT_LOG.md).

```bash
composer install && cp .env.example .env && php artisan key:generate
php artisan migrate && php artisan db:seed
npm install && npm run build
php artisan serve
```
