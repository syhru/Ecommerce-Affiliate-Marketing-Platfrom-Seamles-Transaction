# TDR Seamless Transaction

E-commerce platform for TDR HPZ with Midtrans payments, affiliate referrals, Telegram order notifications and a Filament admin panel.

## Repository layout

- `backend/`: Laravel 12 API, Sanctum authentication, Filament 3 admin, Composer dependencies and PHPUnit tests.
- `frontend/`: Next.js 16, React 19 and Tailwind CSS storefront, npm dependencies and Node tests.

API reference: https://documenter.getpostman.com/view/37270059/2sBXcGEfVr

## Runtime requirements

Backend: PHP >=8.3 (Composer supported range `^8.3`) and Composer 2. Typed class constants in current source require PHP 8.3; PHP 8.2 is not supported.

Enable extensions required by the locked dependencies: bcmath, ctype, curl, dom, fileinfo, filter, intl, mbstring, openssl, PDO, session, tokenizer, xml, xmlreader and xmlwriter. Deterministic tests also require **pdo_sqlite and sqlite3**. Zip supports Composer archive installation. `composer check-platform-reqs` is authoritative for the installed dependency set. Application development separately needs the appropriate driver for its configured database.

Frontend verification: Node.js 24 LTS and npm. Use the committed lockfiles, not dependency updates.

## Development setup

From the repository root:

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
```

Configure `backend/.env` for your own development database, `APP_URL`, `FRONTEND_URL` and provider settings. Run `php artisan migrate` only against that explicitly selected development database. Start the API with `php artisan serve` from `backend/`. Queue workers, when needed, use `php artisan queue:work` from `backend/`.

In another terminal from the repository root:

```bash
cd frontend
npm ci
npm run dev
```

Frontend `NEXT_PUBLIC_API_URL` defaults to `http://localhost:8000/api`. Backend `FRONTEND_URL` defaults to `http://localhost:3000`; web root intentionally redirects there. Configure provider credentials only for separately authorized development/provider integration, not deterministic tests.

Composer/npm/artisan are not root-level commands. The Laravel scaffold `composer setup`/`composer dev` scripts are not the split-app verification path; use the directory-specific commands documented here.

## Portable deterministic verification

Use a PHP CLI with the required extensions enabled (`php --ini` identifies its configuration). From `backend/`, preflight:

```bash
php -v
php -r 'foreach (["PDO", "pdo_sqlite", "sqlite3"] as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR, "Missing extension: $extension\n"); exit(1); } }'
composer validate --no-check-publish
composer check-platform-reqs
```

The backend suite uses **SQLite `:memory:`** and runs required migrations via existing tests. This does not prove PostgreSQL concurrency; that belongs to separate WS-07B verification. No project database, real mail or provider credentials are required.

Before both install and tests, use this isolated process environment (POSIX shell/Git Bash example; set equivalent process variables in other shells). It bypasses cached developer configuration and clears database URL overrides. Prefer a fresh checkout. Do not copy a developer `.env` into CI.

```bash
cd backend # from repository root
export APP_ENV=testing
export APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='
export APP_CONFIG_CACHE="$PWD/bootstrap/cache/verification-config.php"
export DB_CONNECTION=sqlite DB_DATABASE=':memory:' DB_URL='' DATABASE_URL=''
export CACHE_STORE=array MAIL_MAILER=array QUEUE_CONNECTION=sync SESSION_DRIVER=array
export BROADCAST_CONNECTION=null FRONTEND_URL=http://localhost:3000
export MIDTRANS_MERCHANT_ID='' MIDTRANS_SERVER_KEY='' MIDTRANS_CLIENT_KEY=''
export MIDTRANS_IS_PRODUCTION=false TELEGRAM_BOT_TOKEN='' TELEGRAM_BOT_USERNAME=''
# verification-config.php must not exist; do not cache configuration in this test shell.
composer install --no-interaction --prefer-dist --no-progress
composer check-platform-reqs
php vendor/bin/phpunit --colors=never --display-phpunit-deprecations --fail-on-phpunit-deprecation
```

The fixed APP_KEY is public test-only data, never a deployment key. Existing tests mock provider boundaries. Close this test shell before application development. If SQLite extensions are installed but disabled, an equivalent test invocation is `php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/phpunit`; enabling them in the selected CLI configuration is the portable default, not an absolute machine-specific PHP path.

From `frontend/` in a fresh shell:

```bash
npm ci
npm run typecheck
npm test
npm run build
npm run lint
```

`npm test` runs the existing 28 Node VM/module tests, not browser E2E. CI-equivalent builds use `NEXT_PUBLIC_API_URL=http://localhost:8000/api` and `NEXT_TELEMETRY_DISABLED=1`, without a developer `.env.local`. Next's Google font build may require network access.

## CI and lint policy

`.github/workflows/verification.yml` runs separate PHP 8.3 backend and Node 24 frontend jobs on push and pull requests. Locked installs, full PHPUnit suite, frontend typecheck, Node tests and production build are mandatory gates.

**Lint is visible but advisory/nonblocking** while existing lint debt remains (baseline 24 errors, 20 warnings, exit 1). CI preserves diagnostics and the real lint step outcome, publishes the outcome in the job summary and emits a warning on failure. No ESLint rules are disabled to make this pass; lint is not claimed green.

CI has no deployment, provider sandbox, browser farm, secret requirement or PostgreSQL service step. PostgreSQL concurrency, browser E2E, real provider delivery and the Next middleware-to-proxy warning are separate follow-ups.

## Issues and license

Report issues through the repository's GitHub Issues page. This project is licensed under the [MIT License](LICENSE).
