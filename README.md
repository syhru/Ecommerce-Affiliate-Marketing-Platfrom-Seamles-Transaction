# TDR Seamless Transaction

A Laravel-based e-commerce platform built for TDR HPZ — complete with Midtrans payment integration, a referral-based affiliate program, and real-time Telegram order notifications.

## Demo

Visit the live demo: [here](#)

## Features

- **Product Catalog** — browse, filter, and search products by category, brand, and type
- **Shopping Cart** — add multiple products and manage quantities before checkout
- **Checkout & Payment** — seamless Midtrans Snap payment gateway (sandbox & production)
- **Order Tracking** — real-time status updates with detailed tracking logs
- **Telegram Notifications** — get order status updates directly in Telegram
- **Affiliate Program** — generate referral links, track commissions, and request withdrawals
- **Admin Panel** — manage orders, products, users, affiliates, and view audit logs
- **REST API** — full API support with Sanctum Bearer token authentication

## Tech Stack

| Layer            | Technology                  |
| ---------------- | --------------------------- |
| Framework        | Laravel 12                  |
| Language         | PHP 8.2+                    |
| Auth             | Laravel Sanctum + Session   |
| Payment          | Midtrans (Snap)             |
| Notifications    | Telegram Bot API            |
| Queue / Cache    | Redis                       |
| Database         | MySQL                       |
| Frontend         | Blade + Tailwind CSS + Vite |
| Containerization | Docker + Nginx              |

## User Roles

| Role        | Access                                                 |
| ----------- | ------------------------------------------------------ |
| `customer`  | Browse products, place orders, view own orders         |
| `affiliate` | All customer access + affiliate dashboard & withdrawal |
| `admin`     | Full access via web admin panel                        |

## API Documentation

Full REST API reference is available at:

**[https://documenter.getpostman.com/view/37270059/2sBXcGEfVr](https://documenter.getpostman.com/view/37270059/2sBXcGEfVr)**

## Installation

**1. Clone the repository**

```bash
git clone https://github.com/SutaSS/TDR-Seamless-Transaction.git
cd TDR-Seamless-Transaction
```

**2. Install dependencies**

```bash
composer install
npm install
```

**3. Configure environment**

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` and fill in the required values (see [Environment Variables](#environment-variables)).

**4. Set up the database**

```bash
php artisan migrate --seed
```

**5. Build assets & start the server**

```bash
npm run build
php artisan serve
```

Open your browser and navigate to `http://localhost:8000`.

## Docker Setup

```bash
cp .env.example .env
docker compose up -d
```

For production, use `docker-compose.prod.yml`.

## Environment Variables

```env
APP_URL=http://localhost:8000

DB_DATABASE=tdr_db
DB_USERNAME=root
DB_PASSWORD=

MIDTRANS_MERCHANT_ID=
MIDTRANS_SERVER_KEY=
MIDTRANS_CLIENT_KEY=
MIDTRANS_IS_PRODUCTION=false

TELEGRAM_BOT_TOKEN=
TELEGRAM_BOT_USERNAME=
```

## Queue Worker

Jobs for order processing and notifications are queued. Run the worker with:

```bash
php artisan queue:work
```

## Issues

Found a bug or have a feature request? Please open an issue on the [GitHub Issues](https://github.com/SutaSS/TDR-Seamless-Transaction/issues) page.

## License

This project is licensed under the [MIT License](LICENSE).
