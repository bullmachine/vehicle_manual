# Deploying the BULL Vehicle Manual Portal

Steps to put the portal on a live server. The app has two parts:

- **backend/**: Laravel API (PHP 8.2+, MySQL)
- **frontend/**: React app, built into static files (`frontend/dist`)

The examples use Windows + XAMPP, which is how the project runs today. Linux equivalents are noted where they differ.

---

## 1. Install the PDF renderer (Imagick + Ghostscript)

Manual pages are turned into watermarked images on the server. Without Imagick and Ghostscript, customers see a grey placeholder page instead of the manual.

**Windows / XAMPP**

1. Check your PHP build: run `php -i | findstr "Thread Architecture"`.
   XAMPP's PHP 8.2 is **Thread Safe (TS), x64, VS16**.
2. Download the matching `php_imagick` package (PHP 8.2, TS, x64) from the PECL Windows builds at https://windows.php.net/downloads/pecl/releases/imagick/.
3. From the zip:
   - copy `php_imagick.dll` into `D:\xampp\php\ext\`
   - copy all the `CORE_RL_*.dll` and `IM_MOD_RL_*.dll` files into `D:\xampp\php\` (next to `php.exe`)
4. In `D:\xampp\php\php.ini` add:
   ```ini
   extension=imagick
   extension=intl
   ```
   (`intl` gives proper country names in mobile-number error messages.)
5. Install **Ghostscript** (64-bit) from https://ghostscript.com/releases/gsdnld.html and make sure its `bin` folder is on the system `PATH`.
6. Restart Apache (and any `php artisan serve` window).
7. Check: `php -m` must list `imagick` and `intl`.

**Linux (Ubuntu/Debian):** `sudo apt install php8.2-imagick php8.2-intl ghostscript`, then restart PHP-FPM/Apache. If PDFs fail with a "not authorized" error, allow PDF in `/etc/ImageMagick-6/policy.xml` (`<policy domain="coder" rights="read" pattern="PDF" />`).

Once it works, set this in `backend/.env` so a missing renderer becomes a clear error instead of silently showing placeholders:

```env
PDF_RENDERER_REQUIRED=true
```

---

## 2. Production settings

### backend/.env

Change these lines (keep everything else, including the `SAP_MACHINE_API_*` settings):

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<api-domain>
FRONTEND_URL=https://<portal-domain>

CORS_ALLOWED_ORIGINS=https://<portal-domain>
SANCTUM_STATEFUL_DOMAINS=<portal-domain>
SESSION_SECURE_COOKIE=true          # only when the site runs on https

ADMIN_EMAIL=<real admin email>
ADMIN_PASSWORD=<strong password>    # only used the first time the database is seeded
```

`APP_DEBUG=false` matters: with `true`, error responses show file paths and code to the public.

`FRONTEND_URL` is used to build the links printed in QR codes. Set it before generating QR labels.

### frontend/.env.production

```env
VITE_BASE_PATH=/                     # or the sub-folder the portal is served from
VITE_API_URL=https://<api-domain>/api
```

It currently points at `http://localhost:8000/api`, which only works on the developer's PC.

### Deploy commands

```bash
# backend
cd backend
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan db:seed --force           # first install only: roles, permissions, languages, first admin (no demo data in production)
php artisan config:cache
php artisan route:cache

# load machines from SAP (choose how far back customers' machines go)
php artisan machines:sync --from=20200101 --to=<today YYYYMMDD>

# frontend
cd ../frontend
npm ci
npm run build                          # output in frontend/dist
```

Point the web server at `backend/public` for the API and serve `frontend/dist` for the portal. Both folders already contain an `.htaccess` for Apache.

After any later change to `backend/.env`, run `php artisan config:cache` again.

---

## 3. Start the scheduler (nightly SAP sync)

The SAP machine sync runs every night at 01:30 (last 7 days, plants from `SAP_MACHINE_PLANTS`). Laravel only runs scheduled jobs if something calls `schedule:run` every minute.

**Windows: Task Scheduler**

1. Open **Task Scheduler** → **Create Task…**
2. *General*: name `Vehicle manual scheduler`, select **Run whether user is logged on or not**.
3. *Triggers* → New: **Daily**, then tick **Repeat task every 1 minute** for a duration of **Indefinitely**.
4. *Actions* → New:
   - Program: `D:\xampp\php\php.exe`
   - Arguments: `artisan schedule:run`
   - Start in: `D:\xampp\htdocs\vehicle_manual\backend`
5. Save.

**Linux: cron** (`crontab -e` as the web user):

```cron
* * * * * cd /var/www/vehicle_manual/backend && php artisan schedule:run >> /dev/null 2>&1
```

**Check it works:** run `php artisan schedule:list`, and the next morning open **Admin → Machines**: the header shows the time and result of the last sync. A failed sync is shown in red with the reason.

---

## After going live

- Sign in with the first admin account and change its password, or create personal admin accounts and deactivate the default one.
- Map manuals to the machine FERTs (**Manuals → Map FERTs**). A customer only sees manuals mapped to their machine's FERT.
- Never run `php artisan migrate:fresh` on the live database: it deletes everything.
