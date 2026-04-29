# Development Setup

This document covers how to run the extension locally for development and testing.
The production target is TastyIgniter on MySQL — that's what this setup uses.

---

## Prerequisites

| Tool | Version | Install |
|---|---|---|
| PHP | 8.3+ | `brew install php@8.3` |
| Composer | 2.x | `brew install composer` or [getcomposer.org](https://getcomposer.org) |
| MySQL | 8+ | `brew install mysql` |
| Git | any | `brew install git` |

---

## First-time setup

### 1. MySQL

```bash
brew services start mysql

mysql -u root <<'SQL'
CREATE DATABASE tastyigniter CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'tastyigniter'@'localhost' IDENTIFIED BY 'secret';
GRANT ALL PRIVILEGES ON tastyigniter.* TO 'tastyigniter'@'localhost';
FLUSH PRIVILEGES;
SQL
```

### 2. TastyIgniter app

The dev TastyIgniter installation lives at `../tastyigniter-app/` (a sibling of this
directory). It is **not** committed to git — you create it once and it lives on your
machine.

```bash
export PATH="/opt/homebrew/opt/php@8.3/bin:/opt/homebrew/bin:$PATH"

cd /path/to/Square          # parent of both this repo and tastyigniter-app
composer create-project tastyigniter/tastyigniter tastyigniter-app --no-interaction
```

### 3. Configure the app

Edit `tastyigniter-app/.env`:

```dotenv
APP_NAME="Square Catalog Sync Dev"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_DATABASE=tastyigniter
DB_HOST=127.0.0.1
DB_PORT=3306
DB_USERNAME=tastyigniter
DB_PASSWORD=secret
DB_PREFIX=
```

### 4. Link this extension into TastyIgniter

```bash
mkdir -p tastyigniter-app/extensions/kirbygo
ln -s "$(pwd)/tastyigniter-square-sync" tastyigniter-app/extensions/kirbygo/squarecatalogsync
```

### 5. Register the extension with Composer

TastyIgniter discovers extensions via `vendor/composer/installed.json`. Add this
extension as a path repository so Composer can include it:

```bash
cd tastyigniter-app

# Relax payregister's square/square pin so v42 can install alongside it.
# (payregister ships with square/square 26.x pinned exactly; we need 42.x.
#  The lock file entry for payregister is patched — see note below.)
composer config repositories.squarecatalogsync path ../tastyigniter-square-sync
composer require kirbygo/ti-ext-square-catalog-sync:@dev --no-interaction
```

> **Note — payregister SDK conflict:** `tastyigniter/ti-ext-payregister` pins
> `square/square` to `26.0.0.20230419` exactly. Our extension requires `^42.0`.
> The `composer.lock` entry for payregister must be patched to `^26.0 || ^42.0`
> before the `composer require` above will resolve. Run this once:
>
> ```bash
> sed -i '' \
>   's/"square\/square": "26.0.0.20230419"/"square\/square": "^26.0 || ^42.0"/' \
>   composer.lock
> ```
>
> This is a dev-environment workaround. When this extension is published to the
> marketplace, TastyIgniter will need to update payregister's constraint upstream,
> or we will need to coordinate the SDK version with them.

### 6. Migrate and seed

```bash
# Still inside tastyigniter-app/
php artisan igniter:up --force
php artisan db:seed --class="Igniter\System\Database\Seeds\DatabaseSeeder" --force
php artisan igniter:package-discover
php artisan igniter:theme-vendor-publish
php artisan storage:link
```

### 7. Create an admin user

```bash
php -r "
define('LARAVEL_START', microtime(true));
require 'vendor/autoload.php';
\$app = require 'bootstrap/app.php';
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
\Igniter\User\Models\User::firstOrCreate(
    ['email' => 'admin@example.com'],
    ['name'=>'Admin','username'=>'admin','password'=>bcrypt('password'),'status'=>1,'super_user'=>1]
);
echo 'Done' . PHP_EOL;
"
```

### 8. Start the dev server

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

Admin panel: [http://127.0.0.1:8000/admin](http://127.0.0.1:8000/admin)  
Credentials: `admin@example.com` / `password`

Navigate to **Settings → Square Catalog Sync** to reach this extension's settings page.

---

## Daily workflow

```bash
# Start MySQL if not already running
brew services start mysql

# Start the dev server (from tastyigniter-app/)
export PATH="/opt/homebrew/opt/php@8.3/bin:/opt/homebrew/bin:$PATH"
php artisan serve --host=127.0.0.1 --port=8000
```

After editing extension code, no restart is needed — PHP serves files directly.

After adding a migration to `database/migrations/`:

```bash
php artisan igniter:up --force
```

---

## Why MySQL, not SQLite

TastyIgniter targets MySQL. During initial setup SQLite was used as a shortcut
(MySQL wasn't installed), which caused two concrete problems:

1. **`MailTemplate::syncAll()` crash** — TI inserts mail templates without a
   `subject` value, relying on MySQL's non-strict mode to fill the NOT NULL column
   with an empty string. SQLite's stricter enforcement throws a constraint violation.
   Patching the SQLite schema with `PRAGMA writable_schema` worked around it, but
   it's the kind of patch that masks rather than fixes.

2. **Coupons migration duplicate index** — a migration created an index that SQLite
   considered already present; MySQL handles this transparently. The SQLite run
   required faking the migration in the `migrations` table to continue.

Running on MySQL from the start avoids this class of problem entirely.

---

## Extension structure

```
tastyigniter-square-sync/          ← this repo
├── composer.json                  ← package metadata; square/square ^42.0 dep
├── database/migrations/           ← adds square_object_id to TI tables; creates sync log table
├── resources/
│   ├── models/settings.php        ← form field definitions for the admin settings page
│   ├── models/settings_form.php   ← FormController config (toolbar, fields)
│   └── views/settings/edit.blade.php
├── routes/web.php                 ← POST /square/webhook (public, no auth)
└── src/
    ├── Extension.php              ← registers services, settings page entry, hourly cron
    ├── Http/Controllers/
    │   ├── Settings.php           ← admin settings + sync status + "Sync Now"
    │   └── Webhook.php            ← receives Square webhook events
    ├── Jobs/
    │   ├── SyncSquareCatalog.php  ← queued full or incremental sync; cache lock prevents overlap
    │   └── ProcessWebhook.php     ← decodes webhook payload, dispatches SyncSquareCatalog
    ├── Models/
    │   ├── Settings.php           ← credential storage; access token encrypted at rest
    │   └── SyncLog.php            ← event log; auto-prunes to 200 entries
    └── Services/
        ├── SquareClientFactory.php  ← builds SDK client; Square API version pinned to 2025-04-16
        ├── CatalogFetcher.php       ← full + incremental catalog fetch (generator-based, paginated)
        ├── CatalogMapper.php        ← Square objects → TI database rows, idempotent upserts
        └── WebhookVerifier.php      ← HMAC-SHA256 signature verification
```

---

## Square credentials

The Square access token is stored in TastyIgniter's `extension_settings` table,
encrypted with Laravel's `encrypt()` / `decrypt()`. It is never stored in plaintext
or logged.

For local testing, use the **Sandbox** environment toggle in the settings page.
Your sandbox credentials are in the Square Developer Dashboard.

The production token is in the `token` file at the repo root (not committed to git).

---

## Known limitations (v1)

- Image sync is stubbed — downloads are logged but not executed yet
- Catalog version tracking after a full sync uses a timestamp placeholder; will
  be corrected on the next webhook or cron run
- `menu_menu_options` pivot table name assumed — verify against your TI version
  if modifier list attachments don't appear
