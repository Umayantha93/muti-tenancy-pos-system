# Garage POS SaaS

Multi-tenant point-of-sale and operations platform for garages, supermarkets, and shops. Laravel provides the Sanctum API and Next.js provides separate tenant and platform consoles.

## Architecture

- One database with a shared schema and required `tenant_id` columns on business records.
- Eloquent global scopes isolate tenant models automatically. Super administrators bypass tenant scopes.
- Tenant feature assignments cap available modules. Business owners can further restrict each staff account.
- Active user and tenant checks run on every authenticated request. Deactivation revokes existing tokens.
- Platform lifecycle and feature-plan changes are written to `audit_logs`.
- Tenants are soft deleted. Tenant users are also soft deleted so historical transaction references remain intact.

## Applications

- Backend: `garage-pos-backend`, Laravel 13 and Sanctum
- Frontend: `../garage-pos-frontend`, Next.js 16 and React 19
- Default API URL: `http://localhost:8000/api`
- Default frontend URL: `http://localhost:3000`

## Local Setup

```bash
cd garage-pos-backend
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan db:seed
php artisan storage:link
php artisan serve
```

In another terminal:

```bash
cd garage-pos-frontend
npm install
npm run dev
```

Set `NEXT_PUBLIC_API_URL` when the API is not available at `http://localhost:8000/api` (must include the `/api` suffix, e.g. `https://api.iubits.cc/api`).

On the API host, set `FRONTEND_URL=https://iubits.cc`, then run `php artisan config:clear`.

## Seed Accounts

| Console | Email | Password | Role |
| --- | --- | --- | --- |
| Platform | `superadmin@bay06.lk` | `password` | Super administrator |
| Tenant | `admin@garage.lk` | `password` | Business owner |
| Tenant | `owner2@garage.lk` | `password` | Business owner |
| Tenant | `cashier@garage.lk` | `password` | Staff |
| Tenant | `owner@supermarket.lk` | `password` | Supermarket owner |

The demo tenants are **Bay 06 Garage** and **Bay 06 Supermarket**. Garage owners have all garage features, while the supermarket owner has billing, inventory, reports, and finance. Demo garage staff has admission, billing, and inventory permissions.

## Feature Keys

- `admit_vehicle`
- `billing`
- `parts_inventory`
- `employees_management`
- `payroll`
- `balance_sheet`
- `reports`

An owner can use every enabled tenant feature. Staff access requires both an enabled tenant feature and an enabled user permission. The API middleware is authoritative; frontend navigation uses the same effective feature list for usability.

## Fingerprint Attendance

Fingerprint ingestion is public to devices and protected by the configured `X-Device-Key`. Because fingerprint IDs can repeat across tenants, every payload must include `tenant_id`:

```json
{
  "tenant_id": 1,
  "fingerprint_id": "FP-001",
  "timestamp": "2026-07-06 08:00:00",
  "event": "check_in"
}
```

Send the payload to `POST /api/attendance/ingest`. The tenant must be active and have employee management enabled.

## Validation

```bash
php artisan test
php artisan route:list --path=api --except-vendor
cd ../garage-pos-frontend
npm run lint
npm run build
```
