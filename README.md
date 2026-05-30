# telegrammovies

PHP/MadelineProto backend for managing a Telegram movie catalog.

## Local setup

1. Install PHP 8.2+ and Composer.
2. Copy `.env.example` to `.env` and fill in the Telegram values.
3. Install dependencies:

```sh
composer install
```

4. Start the app:

```sh
php -S 127.0.0.1:8000
```

## Render setup

Use a Docker Web Service on Render. The app listens on Render's `PORT` environment variable.
Render is configured for bot-only public mode, so the dashboard is not served publicly.

Set these environment variables in Render:

```text
TELEGRAM_BOT_TOKEN
TELEGRAM_BOT_USERNAME
APP_SESSION_DIR
CATALOG_SEED_FILE
PUBLIC_BOT_ONLY
ADMIN_PASSWORD
TELEGRAM_WEBHOOK_PATH
AUTO_SET_WEBHOOK
BOT_MAX_FILES_PER_REQUEST
MAIN_CHANNEL_USERNAME
KEEPALIVE_SELF_PING_ENABLED
KEEPALIVE_PING_INTERVAL_MINUTES
```

Optional:

```text
WEB_APP_URL
PUBLIC_BASE_URL
```

`WEB_APP_URL` defaults to `https://telegrammovies.onrender.com/app/`, so no new Render environment variable is required unless the web app URL changes.
`PUBLIC_BASE_URL` is optional. Set it to `https://telegrammovies.onrender.com` only if Render does not provide `RENDER_EXTERNAL_URL`.

Do not commit `.env`, `sessions/`, `vendor/`, `composer.phar`, or log files.

Use `/telegram/webhook` as the Telegram webhook path, for example:

```text
https://your-render-service.onrender.com/telegram/webhook
```

After Render deploys, set the bot webhook from your local machine:

```sh
php scripts/set_webhook.php https://your-render-service.onrender.com/telegram/webhook
```

On Render, `AUTO_SET_WEBHOOK=true` uses Render's `RENDER_EXTERNAL_URL` and sets this webhook automatically during startup.

Use `/health` for Render health checks or `/warmup` for keepalive checks that also verify the catalog:

```text
https://your-render-service.onrender.com/health
https://your-render-service.onrender.com/warmup
```

Render free services can sleep when idle. This repo uses both a Render self-ping loop and a GitHub Actions keepalive workflow. For absolute no-sleep uptime, use a paid always-on Render service or add another external uptime monitor pointed at `/warmup`.

## Mini site and keepalive

The mini site lives in `docs/` and is served publicly by the Render app:

```text
https://telegrammovies.onrender.com/app/
```

The site pings Render while it is open. The stronger keepalive is `.github/workflows/keepalive.yml`, which pings `/warmup` from GitHub Actions every 5 minutes so the bot can wake while your PC is off. The Docker startup also runs `scripts/keepalive.php`, which self-pings `/warmup` every 5 minutes while the service is awake.

After changing the site URL, update the bot menu button:

```sh
php scripts/set_menu_button.php https://telegrammovies.onrender.com/app/
```
