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
TELEGRAM_API_ID
TELEGRAM_API_HASH
TELEGRAM_BOT_TOKEN
TELEGRAM_BOT_USERNAME
TELEGRAM_INDEX_CHANNEL_ID
APP_SESSION_DIR
CATALOG_SEED_FILE
PUBLIC_BOT_ONLY
ADMIN_PASSWORD
TELEGRAM_WEBHOOK_SECRET
```

Do not commit `.env`, `sessions/`, `vendor/`, `composer.phar`, or log files.

Use `/telegram/webhook` as the Telegram webhook path, for example:

```text
https://your-render-service.onrender.com/telegram/webhook
```

After Render deploys, set the bot webhook from your local machine:

```sh
php scripts/set_webhook.php https://your-render-service.onrender.com/telegram/webhook
```
