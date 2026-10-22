# Extendable Order & Payment Management API (Laravel 12)

A clean, production-grade **Order and Payment Management RESTful API** built with **Laravel 12**, **Laravel Boost**, **MySQL 8.0**, **Redis**, **RabbitMQ** (`vladimir-yuldashev/laravel-queue-rabbitmq`), and **Docker** (with PHP 8.3-FPM & **OPcache/JIT** enabled).

---

## Table of Contents
1. [Key Architectural Highlights](#1-key-architectural-highlights)
2. [Tech Stack & Infrastructure](#2-tech-stack--infrastructure)
3. [Setup & Installation Instructions](#3-setup--installation-instructions)
4. [Payment Gateway Extensibility (Strategy Pattern Guide)](#4-payment-gateway-extensibility-strategy-pattern-guide)
5. [How Famous Engineering Problems Are Solved](#5-how-famous-engineering-problems-are-solved)
6. [API Endpoints Reference](#6-api-endpoints-reference)
7. [Running Automated Tests](#7-running-automated-tests)
8. [Postman Collection](#8-postman-collection)
9. [Assumptions & Design Notes](#9-assumptions--design-notes)

---

## 1. Key Architectural Highlights
- **Service-Oriented Architecture**: Controllers (`AuthController`, `OrderController`, `PaymentController`, `PaymentWebhookController`) are strictly thin HTTP adapters. Business rules, transactions, locking, and caching live in dedicated services (`AuthService`, `OrderService`, `PaymentService`, `WebhookService`) using immutable DTOs (`OrderDTO`, `OrderItemDTO`, `PaymentPayloadDTO`, `PaymentResultDTO`, `WebhookEventDTO`).
- **Strategy & Factory Design Patterns**: Payment gateways implement `PaymentGatewayInterface` and are dynamically resolved via `PaymentGatewayFactory`. Adding a new payment provider requires **zero changes** to existing controllers, services, or database schemas.
- **PSR-12 & Strict Typing**: Every class uses `declare(strict_types=1);`, PHP 8.3 native backed Enums (`OrderStatusEnum`, `PaymentStatusEnum`, `PaymentMethodEnum`), and typed properties/return types.

---

## 2. Tech Stack & Infrastructure
| Layer | Technology |
| :--- | :--- |
| **Framework** | Laravel 12 (PHP 8.3-FPM Alpine + OPcache & JIT) |
| **AI / DX Tooling** | Laravel Boost (`laravel/boost`) |
| **Authentication** | JWT (`php-open-source-saver/jwt-auth`) |
| **Database** | MySQL 8.0 |
| **Cache & Atomic Locks** | Redis 7.4 Alpine (`Cache::lock()`) |
| **Message Queue** | RabbitMQ 3.13 Management (`vladimir-yuldashev/laravel-queue-rabbitmq`) |
| **Web Server** | Nginx 1.27 Alpine |

---

## 3. Setup & Installation Instructions

### Prerequisites
- Docker & Docker Compose v2+

### Step 1: Build and Start the Docker Containers
From the project root directory, run:
```bash
docker compose up -d --build
```
The `entrypoint.sh` script automatically:
1. Initializes `.env` from `.env.example` if missing.
2. Waits for MySQL, Redis, and RabbitMQ health checks.
3. Generates `APP_KEY` and `JWT_SECRET`.
4. Runs database migrations (`php artisan migrate --force`).
5. Declares the RabbitMQ queues (`default`, `payment_webhooks`, `payments`) and starts the RabbitMQ queue worker in `tocaan_queue`.

### Step 2: Seed Demo Data (Optional)
```bash
docker exec -it tocaan_app php artisan db:seed
```
This creates a demo user (`test@example.com` / `password123`) along with sample `pending`, `confirmed`, and paid orders.

### Service URLs
- **API Base URL**: `http://localhost:8080/api/v1`
- **RabbitMQ Management UI**: `http://localhost:15672` (User: `guest` / Password: `guest`)
- **MySQL Forwarded Port**: `localhost:33060`
- **Redis Forwarded Port**: `localhost:63790`

---

## 4. Payment Gateway Extensibility (Strategy Pattern Guide)

The payment system is built around the **Strategy Pattern** (`PaymentGatewayInterface`) and **Factory Pattern** (`PaymentGatewayFactory`) so new payment gateways (e.g., `stripe`, `hyperpay`, `APS`) can be added with **minimal effort** and **zero modifications** to `PaymentService`, `PaymentController`, or database migrations.

### How to Add a New Payment Gateway in 2 Steps

#### Step 1: Create the Concrete Gateway Strategy Class
Create `app/Services/Payments/Gateways/StripeGateway.php` implementing `PaymentGatewayInterface`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Payments\Gateways;

use App\DTOs\Payment\PaymentPayloadDTO;
use App\DTOs\Payment\PaymentResultDTO;
use App\DTOs\Payment\WebhookEventDTO;
use App\Enums\PaymentStatusEnum;
use App\Models\Order;
use App\Services\Payments\Contracts\PaymentGatewayInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StripeGateway implements PaymentGatewayInterface
{
    public function __construct(
        private readonly array $config = []
    ) {}

    public function getIdentifier(): string
    {
        return 'stripe';
    }

    public function charge(Order $order, PaymentPayloadDTO $payload): PaymentResultDTO
    {
        return new PaymentResultDTO(
            status: PaymentStatusEnum::SUCCESSFUL,
            transactionId: 'pi_' . Str::lower(Str::random(24)),
            gatewayResponse: [
                'gateway' => 'stripe',
                'amount' => (float) $order->total_amount,
            ],
            message: 'Stripe payment succeeded.'
        );
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        $signature = (string) $request->header('X-Webhook-Signature', '');
        $secret = (string) ($this->config['webhook_secret'] ?? '');

        return $signature !== '' && hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $signature);
    }

    public function parseWebhookPayload(Request $request): WebhookEventDTO
    {
        $payload = $request->all();

        return new WebhookEventDTO(
            gateway: $this->getIdentifier(),
            eventId: (string) ($payload['event_id'] ?? ''),
            eventType: (string) ($payload['event_type'] ?? 'payment_intent.succeeded'),
            transactionId: (string) ($payload['transaction_id'] ?? ''),
            targetStatus: PaymentStatusEnum::SUCCESSFUL,
            rawPayload: $payload,
        );
    }
}
```

#### Step 2: Register the Gateway in `config/payment.php` (and `.env`)
Add the entry under `gateways` in `config/payment.php`:

```php
'stripe' => [
    'enabled' => (bool) env('STRIPE_ENABLED', true),
    'driver' => \App\Services\Payments\Gateways\StripeGateway::class,
    'api_key' => env('STRIPE_API_KEY'),
    'api_secret' => env('STRIPE_API_SECRET'),
    'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
],
```
*(Alternatively, you can register a gateway dynamically at runtime in any ServiceProvider using `$factory->extend('stripe', fn ($app) => new StripeGateway(...))`.)*

Once registered, `POST /api/v1/orders/{order}/payments` with `"payment_method": "stripe"` and `POST /api/v1/webhooks/payments/stripe` work immediately!

---

## 5. How Famous Engineering Problems Are Solved

### 1. N+1 Query Problem
- `Model::preventLazyLoading(! $this->app->isProduction())` is enabled in `AppServiceProvider`. Any accidental lazy-loaded relationship throws an exception immediately during development and testing.
- `OrderService` and `PaymentService` explicitly eager-load `with(['items', 'payments'])` and `withCount('payments')`.
- API Resources (`OrderResource`, `PaymentResource`) strictly use `$this->whenLoaded()` and `$this->whenCounted()`.

### 2. Idempotency
- **API Request Idempotency (`EnsureIdempotency` Middleware)**:
  - Applied to `POST /api/v1/orders` and `POST /api/v1/orders/{order}/payments`.
  - When a client sends an `Idempotency-Key` header, the middleware acquires a Redis lock (`idempotency_lock:{user}:{key}`) and computes a deterministic SHA-256 hash of the HTTP method, URI, and normalized request body.
  - Replaying the exact same request returns the cached response with header `X-Idempotent-Replay: true` without re-running the controller or creating duplicate database records.
  - Reusing the same `Idempotency-Key` with a modified payload is rejected with `422 Unprocessable Entity`.
- **Webhook Idempotency**:
  - `webhook_events` enforces a composite `UNIQUE(gateway, event_id)` constraint and Redis atomic lock (`webhook_lock:{gateway}:{event_id}`) so duplicate webhook deliveries from payment providers are acknowledged (`200 OK`) and ignored safely.

### 3. Webhooks & RabbitMQ Queue Processing
- Incoming webhooks (`POST /api/v1/webhooks/payments/{gateway}`) verify the raw payload's `X-Webhook-Signature` using HMAC-SHA256 against the gateway's `webhook_secret`.
- Validated events are persisted in `webhook_events` and dispatched via `ProcessPaymentWebhookJob` to the **RabbitMQ** `payment_webhooks` queue for asynchronous processing.
- Direct payment charges also dispatch `PaymentProcessedJob` to the **RabbitMQ** `payments` queue (`afterCommit()`).

### 4. Caching & Concurrency Locks (Redis + Database Pessimistic Locks)
- **Distributed Redis Lock**: `PaymentService::processPayment()` acquires `Cache::lock("order_payment:{$orderId}", 15)` before entering the database transaction so two concurrent requests for the same order cannot double-charge the customer.
- **Database Pessimistic Locking (`SELECT ... FOR UPDATE`)**: Inside `DB::transaction()`, `Order::where('id', $orderId)->lockForUpdate()->first()` locks the order row during payment processing, order updates, and order deletion.
- **Versioned Redis Cache Invalidation**: User order listings and order details are cached in Redis and automatically invalidated whenever an order is created, updated, deleted, or paid.

---

## 6. API Endpoints Reference

| Method | Endpoint | Auth | Description |
| :--- | :--- | :--- | :--- |
| `POST` | `/api/v1/auth/register` | Public | Register user & receive JWT token |
| `POST` | `/api/v1/auth/login` | Public | Authenticate user & receive JWT token |
| `GET` | `/api/v1/auth/me` | JWT | Get authenticated user profile |
| `POST` | `/api/v1/auth/refresh` | JWT | Refresh JWT token |
| `POST` | `/api/v1/auth/logout` | JWT | Invalidate JWT token |
| `GET` | `/api/v1/orders` | JWT | List paginated orders (`?status=pending\|confirmed\|cancelled&per_page=15`) |
| `POST` | `/api/v1/orders` | JWT | Create order & calculate total (supports `Idempotency-Key`) |
| `GET` | `/api/v1/orders/{order}` | JWT | View single order with items and payments |
| `PUT` | `/api/v1/orders/{order}` | JWT | Update order details, status, or items |
| `DELETE` | `/api/v1/orders/{order}` | JWT | Delete order (rejected with `422` if payments exist) |
| `GET` | `/api/v1/payments` | JWT | List all payments (paginated, filterable by `status` & `payment_method`) |
| `GET` | `/api/v1/orders/{order}/payments` | JWT | List payments for a specific order (paginated) |
| `POST` | `/api/v1/orders/{order}/payments` | JWT | Process payment for a `confirmed` order (supports `Idempotency-Key`) |
| `POST` | `/api/v1/webhooks/payments/{gateway}` | HMAC | Receive & verify gateway webhook (`X-Webhook-Signature`) |

---

## 7. Running Automated Tests

Run the full PHPUnit test suite (Unit + Feature tests covering Auth, Orders, N+1 prevention, Payments, Strategy Factory, Idempotency, and Webhooks) inside the Docker container:

```bash
docker exec -it tocaan_app php artisan test
```

---

## 8. Postman Collection

Import [`postman_collection.json`](./postman_collection.json) directly into Postman:
1. It organizes all endpoints into **Authentication**, **Orders Management**, **Payments Management**, and **Payment Webhooks**.
2. Logging in or registering automatically saves the `jwt_token` collection variable.
3. Creating an order automatically saves the `order_id` collection variable.
4. The **Webhook** request includes a Pre-request Script that automatically computes the `X-Webhook-Signature` HMAC-SHA256 header from the JSON body.

---

## 9. Assumptions & Design Notes
- **Order Ownership**: Authenticated users manage and pay for their own orders (`user_id` check).
- **Business Rule — Confirmed Orders Only**: Payments can only be processed when an order's status is `confirmed`. Newly created orders default to `pending` (unless `"status": "confirmed"` is passed or updated via `PUT /api/v1/orders/{order}`).
- **Business Rule — Deletion Protection**: Orders with any associated payment records (`pending`, `successful`, or `failed`) cannot be deleted (`422 Unprocessable Entity`), enforced both in `OrderService::deleteOrder()` with `lockForUpdate()` and at the database foreign-key level (`restrictOnDelete()`).
- **Gateway Simulation**: Both `credit_card` and `paypal` gateways simulate live payment processing. You can simulate failed or pending payments by passing `"simulate_failure": true` or `"simulate_pending": true` in the payment request (or using a card number ending in `0000` for failure / `1111` for pending).
