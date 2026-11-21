# Installation

Two environments are covered:

- **macOS (development):** verified on macOS with PHP 8.3.23, Laravel 13.43.0, ImageMagick 7
  (`magick`) and Potrace 1.16, all installed with Homebrew.
- **Ubuntu, Nginx and PHP-FPM (production target):** written from the code's requirements. **Not
  tested on Ubuntu.** Treat every Ubuntu step as a draft to verify.

## 1. Requirements

| Requirement | Used for | Verified on |
|---|---|---|
| PHP 8.3 or later (`composer.json` says `^8.3`) | Application | macOS, PHP 8.3.23 |
| Composer 2 | Dependencies | macOS |
| `dom` / `xml`, `mbstring`, `fileinfo`, `ctype`, `json`, `hash` | XML validation, text handling, MIME detection | macOS |
| `exif` (optional) | Not required for orientation: ImageMagick reads the orientation tag for JPEG, PNG and WebP. | macOS |
| `gd` | Test suite only (blank-image test) | macOS |
| ImageMagick 7 `magick` | Image preparation | macOS, 7.x |
| Potrace 1.16 | Tracing | macOS, 1.16 |

## 2. macOS (development)

```bash
brew install php composer imagemagick potrace

php -v                       # expect 8.3.x
magick -version              # ImageMagick 7.x
potrace --version            # 1.16

cd /path/to/pictosvg
composer install
cp .env.example .env
php artisan key:generate
php artisan serve            # http://localhost:8000 (APP_URL in .env.example)
```

The preview configuration in `.claude/launch.json` runs `php artisan serve --host=127.0.0.1 --port=8765`. Use either port.

Check the binaries the application will find:

```bash
command -v magick potrace
```

Set absolute paths in `.env` if they differ from the defaults:

```dotenv
PICTOSVG_MAGICK_BIN=/opt/homebrew/bin/magick
PICTOSVG_POTRACE_BIN=/opt/homebrew/bin/potrace
```

## 3. Ubuntu, Nginx and PHP-FPM (draft, untested)

### 3.1 Packages

```bash
sudo apt update
sudo apt install php-fpm php-cli php-xml php-mbstring php-gd php-curl unzip imagemagick potrace
sudo apt install composer     # or install Composer from getcomposer.org
php -m | grep -E 'fileinfo|dom|mbstring'   # confirm the required extensions
```

`php-gd` and `php-curl` are listed because the packages are commonly installed. The application
only needs GD for the test suite.

Ubuntu's `imagemagick` package is normally **ImageMagick 6**, which provides `convert`, not
`magick`. The application calls `magick` by default. Either:
- install an ImageMagick 7 `magick` binary and point `PICTOSVG_MAGICK_BIN` at it, or
- point `PICTOSVG_MAGICK_BIN` at `/usr/bin/convert` and test every conversion. The arguments
  (`-auto-threshold`, `-limit`, the `pbm:` writer) have **not** been checked against ImageMagick 6.

Check the Potrace version with `potrace --version`. The tested version is 1.16.

### 3.2 Application

```bash
sudo mkdir -p /var/www/pictosvg
sudo chown "$USER":www-data /var/www/pictosvg
git clone <your-repository-url> /var/www/pictosvg     # or copy the release
cd /var/www/pictosvg
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Edit `.env` for production:

```dotenv
APP_ENV=production
APP_DEBUG=false                    # .env.example sets true; change it for production
APP_URL=https://pictosvg.example

PICTOSVG_POTRACE_BIN=/usr/bin/potrace
PICTOSVG_MAGICK_BIN=/usr/bin/magick          # or /usr/bin/convert; see 3.1
PICTOSVG_MAX_UPLOAD_MB=20
PICTOSVG_MAX_PIXELS=40000000
PICTOSVG_TTL_MINUTES=60
PICTOSVG_PROCESS_TIMEOUT=40

SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync
DB_CONNECTION=null
```

Leave `PICTOSVG_AI_PROVIDER` and `PICTOSVG_AI_API_KEY` empty. No AI provider is implemented.

Permissions. PHP-FPM must write to `storage` and `bootstrap/cache`:

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache
```

Build the optimised autoloader and caches:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### 3.3 PHP upload and timeout limits

In the PHP-FPM pool or `php.ini` (for example `/etc/php/8.3/fpm/php.ini`):

```ini
upload_max_filesize = 25M
post_max_size = 25M
```

Both must be at least 20 MB, or large uploads fail before Laravel sees them. Restart PHP-FPM after
the change (`sudo systemctl restart php8.3-fpm`).

### 3.4 Nginx server block

`client_max_body_size` must be at least 20 MB.

```nginx
server {
    listen 80;
    server_name pictosvg.example;
    root /var/www/pictosvg/public;
    index index.php;

    client_max_body_size 25m;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_read_timeout 90s;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Enable it, test it and reload:

```bash
sudo ln -s /etc/nginx/sites-available/pictosvg /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

Add TLS with your usual process (for example Certbot). Not covered here.

### 3.5 Scheduler

Expired conversions are removed by `pictosvg:cleanup`, which is scheduled every ten minutes in
`routes/console.php`. The scheduler needs one cron entry for the PHP-FPM user:

```cron
* * * * * cd /var/www/pictosvg && php artisan schedule:run >> /dev/null 2>&1
```

Install it with `sudo crontab -u www-data -e`. Without it, expired files are still removed by the
1-in-20 sweep on upload and when a request touches them, but storage can grow.

### 3.6 Smoke test

```bash
curl -s -o /dev/null -w "%{http_code}\n" https://pictosvg.example/          # expect 200
curl -s -F "image=@tests/fixtures/logo.png" https://pictosvg.example/api/images/upload
```

Keep the `id` and `token` from the upload response. Then convert:

```bash
curl -s -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"id":"<id>","token":"<token>"}' https://pictosvg.example/api/svg/convert | head -c 300
```

Only use these checks for a server you control. The API contract is in [API.md](API.md).

## 4. Environment keys

From `.env.example` and `config/pictosvg.php`:

| Key | Default | Purpose |
|---|---|---|
| `PICTOSVG_POTRACE_BIN` | `potrace` | Potrace binary. Use an absolute path under PHP-FPM. |
| `PICTOSVG_MAGICK_BIN` | `magick` | ImageMagick binary. |
| `PICTOSVG_MAX_UPLOAD_MB` | `20` | Maximum upload size in MB. |
| `PICTOSVG_MAX_PIXELS` | `40000000` | Maximum decoded pixels (width × height). |
| `PICTOSVG_TTL_MINUTES` | `60` | Conversion lifetime from upload. |
| `PICTOSVG_PROCESS_TIMEOUT` | `40` | Seconds per external process. ImageMagick's own time limit is this minus 2 (minimum 5). |
| `PICTOSVG_STORAGE_PATH` | `storage/app/private/pictosvg` | Temporary storage root. |
| `PICTOSVG_MAGICK_MEMORY` | `1GiB` | ImageMagick memory and map limit. |
| `PICTOSVG_MAGICK_DISK` | `2GiB` | ImageMagick disk limit. |
| `PICTOSVG_AI_PROVIDER`, `PICTOSVG_AI_API_KEY` | empty | Reserved. No provider exists. Leave empty. |
| `APP_KEY` | generated | Signs tokens. Changing it invalidates all conversions. |

## 5. Upgrading and rollback

No database migrations exist, so upgrades only change code and config. Changing `APP_KEY` or the
storage root makes existing conversions unreachable. They expire on their own.
