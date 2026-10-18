# ERP Suite – Feature list

## Platform
- Multi-tenant SaaS: super admin → company → staff / client / vendor, each company's data isolated
- Companies, users, roles and permissions editor
- Subscription plans that switch modules on/off per company; plan orders, bank-transfer approval, coupons
- Add-on modules auto-discovered; module generator (`make:package`, `make:crud`) for your own modules
- Stripe and Razorpay payments (server-side price, signed webhooks)
- Editable e-mail templates (per-company overrides)
- Settings: brand, title, logo, theme colour (6 presets), light/dark mode
- English and Tamil interface
- Mobile REST API with Sanctum tokens (`docs/API.md`)
- Demo data command: `php artisan app:demo <company email>`
- 456 automated tests

## HRM
- Dashboard: headcount by department, 7-day attendance, new joiners, gender / employment-type charts, pending approvals
- Employees (profile, bank, emergency contact, documents), branches, departments, designations, document types
- Shifts, holidays, IP restrictions
- Attendance: my attendance (clock in/out), records, monthly summary
- Leave: types, applications, approvals, my leave, leave balance
- Payroll: salary setup and components, loans with ledger, monthly payrolls, PDF payslips, my payslips
- Awards & discipline: award types, awards, warnings, complaints
- Employee lifecycle: promotions, transfers, resignations (self-service), terminations
- Communication: announcements, my announcements, events, announcement categories, event types

## CRM
- Dashboard and reports
- Leads: list, details, notes and files, convert lead to deal
- Deals: drag-and-drop kanban or list, won / lost, owner, value
- Pipelines, lead stages, deal stages, labels, sources (CRM Setup)
- Web-to-lead form for your website (secret-protected)

## Point of Sale
- Terminal: search or scan barcode/SKU, warehouse and category filter, customer or walk-in
- Payments: cash (change due), card, bank transfer, on account
- Stock out and ledger entries posted automatically
- Orders and POS reports

## Products & Inventory
- Products with SKU, prices, categories, units, taxes
- Warehouses, stock per warehouse, stock transfers

## Sales & Purchases
- Sales proposals, sales invoices, sales returns
- Purchase invoices, purchase returns
- Totals, discounts, taxes calculated on the server

## Accounting
- Chart of accounts, journal entries
- Customer payments, vendor payments
- Reports

## Not included
GST filing / e-invoicing, WhatsApp integration, biometric device integration.
