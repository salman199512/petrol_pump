# Banas Pump Manager

A Laravel 12 application foundation for station operations. Laravel 12 is used because the supplied XAMPP PHP runtime is PHP 8.2; Laravel 13 requires a newer PHP runtime.

## Local installation

1. Install PHP 8.2+, Composer, MySQL 8+, and Node.js.
2. Create a MySQL database named `pump_manager`.
3. Copy `.env.example` to `.env`, then set `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD`.
4. Install PHP packages and prepare the application:

   ```sh
   composer install
   php artisan key:generate
   php artisan migrate --seed
   php artisan serve
   ```

5. Open `http://127.0.0.1:8000/login`.

Seeded local accounts (change these passwords before deployment):

| Role | Email | Password |
|---|---|---|
| Super Admin | `admin@banaspump.local` | `ChangeMe123!` |
| Operator | `operator@banaspump.local` | `ChangeMe123!` |

The seeder creates the Banas CNG Pump station, CNG/Petrol/Diesel fuel types, four CNG nozzles, three shift types, today's CNG rate, and an open operator shift. The seed operator opening readings start at zero; replace them with actual meter readings before use.

### Optional 20 day demo data

To fill the admin screens with clearly labeled training activity, run:

```sh
php artisan db:seed --class=DemoDataSeeder
```

This adds or confirms fictional `DEMO` customers, fuel nozzles, daily rates, approved shifts and readings, invoices, receipts, expenses, and audit entries for the last 20 calendar days. It does not overwrite existing station, user, role, or rate values. The action is idempotent and should only be used in a development or demonstration database. Demo transactions affect dashboard totals and reports.

## Implemented in this project iteration

- Laravel authentication using email or mobile, active-account check, session regeneration, and sign out.
- Role-aware station visibility for Super Admin, Owner, Manager, and Operator accounts.
- Responsive day summary with station/date filters, fuel sales, collection, expenses, net income, and shift status from database records.
- Shift list, operator shift closing with server-side reading/payment validation, decimal-safe sales calculations, reconciliation, and audit events.
- Manager approval/rejection for submitted shifts with station scope checks.
- Daily rate entry and date-wise rate history; closing readings store the rate applied at that time.
- Invoice register and multiple fuel-line invoice creation with tax calculation, customer credit limit checks, ledger entries, payment records, audit logging, and browser print / Save as PDF.
- Customer creation, opening balances, outstanding balances, and receipt recording.
- Expense recording and deactivation with station scope and audit entries.
- Twelve station-scoped reports with date/station filters, fuel filtering for nozzle sales, paginated tables, CSV export, and browser print / Save as PDF.
- Poppins-only typography, emerald navigation and actions based on the attached dashboard, Toastify success/error messages, and a rebuilt responsive sign-in screen.
- Super Admin user workflow with account creation/edit/deactivation, role selection (Super Admin, Owner, Manager, Operator), active station assignment, and first-login credentials setup.
- Profile editing, password change with current-password verification, and a top-right account menu.
- Relational migrations for stations, fuel types, dispensers, nozzles, shifts, readings, rates, invoices/items, payments, customers/ledgers, expenses, employees/salary records, and audit logs.

## Configuration and limits

- Configure `APP_URL`, database credentials, mail, and backup storage for deployment. Never use seeded passwords in production.
- Notification credentials/provider integration is not configured, so no WhatsApp/SMS delivery is claimed.
- Salary, native XLSX/PDF file exports, backup/restore, and full system setup screens/workflows remain to be implemented. Station/user administration, shift creation, automated notifications, and provider-backed WhatsApp/SMS also need implementation. This iteration is not yet production-ready for financial operations.
- `php artisan migrate:fresh --seed` deletes existing tables. Use it only for a disposable local database. On a live installation use reviewed forward migrations and a verified backup.

## Checks

Run `php artisan route:list` to review registered endpoints and `php artisan test` to run the suite after the local PHP/PHPUnit path configuration is working.
