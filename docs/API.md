# Mobile API (v1)

JSON in, JSON out. Base URL: `https://your-domain/api/v1`. Every call except login needs `Authorization: Bearer <token>`
(and `Accept: application/json`, although the API answers in JSON without it).

## Rules that apply to every call

* Only **company owners** and their **staff** can log in (clients and vendors have no mobile features).
* The account and its company must be enabled and the company's **plan must not be expired**; module endpoints need the module in the company's plan.
  Otherwise `403` with a `code`: `account_disabled`, `plan_expired`, `module_inactive`, `not_allowed`.
* **Same permissions as the website** (the controllers share the rules); users only see the leads and deals they created or work on, unless they may "view all".
* Errors: `401` no / bad token, `403` not allowed, `404` not found, `422` validation (`{"message": "...", "errors": {"field": ["..."]}}`), `429` too many attempts.
* Tokens last 60 days, belong to a `device_name` and are revoked on logout (or when the account is disabled). Rate limits: login 20 / minute (5 wrong passwords per e-mail and address, then a minute's wait), everything else 120 / minute.
* A browser session cookie is **not** accepted: only tokens.

## Auth

| Call | Body | Answer |
|---|---|---|
| `POST /auth/login` | `email`, `password`, `device_name` | `{token, expires_at, user: {id, name, email, type, company, permissions[], modules[]}}` |
| `GET /auth/me` | | `{user: {...}}` (use `permissions` / `modules` to decide which screens to show) |
| `POST /auth/logout` | | `{ok: true}` (revokes this token only) |

## CRM  (`/crm/...`, module **Lead**)

| Call | Permission | Notes |
|---|---|---|
| `GET /crm/pipelines` | manage-leads or manage-deals | pipelines with `lead_stages` and `deal_stages` in order |
| `GET /crm/dashboard?pipeline=` | view-crm-dashboard | the same numbers as the website dashboard |
| `GET /crm/leads?pipeline=&stage=&search=&per_page=` | manage-leads | paginated (`per_page` max 50) |
| `POST /crm/leads` | create-leads | `subject`, `name`, `pipeline_id`, optional `email`, `phone`, `notes`, `follow_up_date`, `lead_stage_id`, `user_ids[]` |
| `POST /crm/leads/{id}/move` | move-leads | `lead_stage_id`; the lead lands at the bottom of that stage |
| `GET /crm/leads/{id}` | manage-leads | the lead with sources, labels, products, tasks, calls, e-mails, comments, files, activity (key `lead`) |
| `PUT /crm/leads/{id}/sync` | edit-leads | `source_ids[]`, `label_ids[]`, `product_ids[]` |
| `POST /crm/leads/{id}/items/{task\|call\|email\|discussion}` | manage-lead-* | add a task / call / e-mail note / comment |
| `POST /crm/leads/{id}/tasks/{task}/toggle` | manage-lead-tasks | complete / reopen |
| `DELETE /crm/leads/{id}/items/{kind}/{itemId}` | manage-lead-* | `kind` also `file` |
| `POST /crm/leads/{id}/files` · `GET /crm/leads/{id}/files/{file}` | manage-lead-files / manage-leads | multipart upload (`file`, 5 MB) · download |
| `GET /crm/deals?pipeline=&status=&search=&per_page=` | manage-deals | paginated |
| `GET /crm/deals/{id}` and the same `sync`, `items`, `tasks`, `files` calls as leads | manage-deals / manage-deal-* | the record is under the key `deal` |
| `POST /crm/deals/{id}/status` | change-deal-status | `status` = `won`, `lost` or `active` |

## HRM  (`/hrm/...`, module **Hrm**, employee self service)

| Call | Permission | Notes |
|---|---|---|
| `GET /hrm/me` | | profile: code, branch, department, designation, shift (`404 no_employee_profile` when HR has not created one) |
| `GET /hrm/attendance` | clock-attendance | `clocked_in`, `since`, last 14 days |
| `POST /hrm/attendance/clock-in` · `/clock-out` | clock-attendance | answers like `GET /hrm/attendance`; `422` with the reason (already clocked in, outside the office network, ...) |
| `GET /hrm/leaves?year=` | apply-leave | `types`, `balances`, own `applications` |
| `POST /hrm/leaves` | apply-leave | `leave_type_id`, `start_date`, `end_date`, `reason`; `422` for overlap / balance / no working day |
| `DELETE /hrm/leaves/{id}` | | withdraw your own **pending** application |
| `GET /hrm/payslips` · `GET /hrm/payslips/{id}` | view-payslips | only payrolls that are approved or paid, only your own |
| `GET /hrm/payslips/{id}/pdf` | view-payslips | the payslip as a PDF file |
| `GET /hrm/announcements` · `POST /hrm/announcements/{id}/acknowledge` | view-announcements | running announcements for your department |

## Quick try

```bash
curl -s -X POST https://your-domain/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"asha@company.com","password":"...","device_name":"my phone"}'

curl -s https://your-domain/api/v1/hrm/attendance -H 'Authorization: Bearer 1|abc...'
```

New modules add their own routes in `packages/workdo/<Module>/src/Routes/api.php` behind `auth:sanctum` and `api.access:<Module>`.
