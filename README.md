# RaktSethu — Thalassemia Blood Support System

A web platform connecting thalassemia patients with compatible blood donors, doctors, and diagnostic labs. Built with plain PHP (PDO) and MySQL.

## Features

- **Patient side** — create blood requests, track match status, view verified donors
- **Donor side** — availability toggle, eligibility countdown (90 days between donations), report upload, match accept/decline with expiry timer, donation certificates
- **Doctor / Lab side** — request creation on behalf of patients, donor report verification, NMC license verification (SurePass API)
- **Matching engine** — blood-group compatibility matrix (server-side), automatic donor matching, 12-hour response deadline with expiry
- **Admin panel** — user management, report verification, license approval, dashboard analytics
- **Security** — email verification on signup, security-question password recovery, CSRF tokens on all forms, brute-force login lockout, prepared statements everywhere, XSS escaping, upload protection via .htaccess

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8 (no framework, plain PDO) |
| Database | MySQL (MariaDB compatible) |
| Frontend | HTML, CSS, vanilla JS |
| Email | EmailJS (client-side delivery — works on hosts that block outbound email) |
| Hosting tested | InfinityFree (Apache), XAMPP (local) |

## Project Structure

```
thalassemia/
├── admpanel/        Admin dashboard & user management
├── auth/            Login, register, email verification, password recovery
├── config/          db.php.example (copy to db.php, add your credentials)
├── cron/            Match expiry job (call via cron-job.org)
├── database/        schema.sql + migrations + setup_admin.php (delete after use!)
├── doctor_lab/      Doctor/lab portal
├── donor/           Donor portal
├── engine/           Matching engine
├── includes/         Shared functions (sessions, CSRF, helpers)
├── patient/          Patient portal
└── uploads/reports/  Donor report files (.htaccess protected)
```

## Quick Start (Local — XAMPP)

1. Copy the `thalassemia` folder into `C:\xampp\htdocs\`
2. Create a database in phpMyAdmin named `thalassemia`
3. Import `database/schema.sql`, then each `database/migration_*.sql` in order
4. Copy `config/db.php.example` → `config/db.php`, set host `localhost`, user `root`, empty password
5. Visit `http://localhost/thalassemia/`

## Deployment (InfinityFree)

See `BEGINNER-GUIDE.txt` in this repo for a complete step-by-step guide (GitHub upload + hosting + cron + email setup).

## Security Notes

- `config/db.php` is git-ignored — credentials never enter version control
- `uploads/reports/` is served only through authenticated PHP (`doctor_lab/view_report.php`), direct access is denied by `.htaccess`
- `database/` folder is web-blocked via `.htaccess`
- Cron endpoint is token-protected; the token lives in `config/cron_token.php` (git-ignored, see `config/cron_token.php.example`)
- One-time admin setup is token-protected via `config/setup_token.php` (git-ignored) — and **delete `database/setup_admin.php` from the server after creating your admin account**
- All internal links/redirects use an auto-detected `BASE_URL`, so the app works deployed at the domain root or in any subfolder — no hardcoded paths to edit

## Credits

Academic capstone project. Licensed for educational use.