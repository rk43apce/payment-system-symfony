# Fund Transfer API

A production-grade fund transfer API built with Symfony 8, implementing ledger-based balance management, idempotent transactions, and distributed locking.

---

## Submission Notes

- Time spent: ~5 hours
- AI tools used: Amazon Q and GitHub Copilot for coding, review and debugging
- Packaging: project includes `compose.yaml` and `compose.override.yaml` for Docker Compose-based setup. No standalone `Dockerfile` is included.

---

## Table of Contents

- [Prerequisites](#prerequisites)
- [Installation](#installation)
- [Configuration](#configuration)
- [Database Setup](#database-setup)
- [Running the Application](#running-the-application)
- [API Reference](#api-reference)
- [Architecture](#architecture)
- [Error Handling](#error-handling)
- [Logging](#logging)

---

## Prerequisites

| Requirement | Version |
|-------------|---------|
| PHP         | >= 8.4  |
| MySQL       | >= 8.0  |
| Redis       | >= 6.0  |
| Composer    | >= 2.x  |
| Symfony CLI | Latest  |

---

## Installation

```bash
# Clone the repository
git clone <repository-url>
cd payshera-fund-transfer-api

# Install dependencies
composer install
```

---

## Configuration

Copy the environment file and update values:

```bash
cp .env .env.local
```

Edit `.env.local`:

```dotenv
APP_ENV=dev
APP_SECRET=<your-secret-key>

# MySQL
DATABASE_URL="mysql://<user>:<password>@127.0.0.1:3306/payshera_fund_transfer?serverVersion=8.0.32&charset=utf8mb4"

# Redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

> **Note:** Redis is optional. If unavailable, the system falls open and relies on the database unique constraint as the hard idempotency guard.

---

## Database Setup

```bash
# Create the database
php bin/console doctrine:database:create

# Run all migrations
php bin/console doctrine:migrations:migrate --no-interaction
```

### Schema Overview

```
user
├── id          INT
├── name        VARCHAR(255)
└── email       VARCHAR(255)

account_ledger                        ← source of truth for balances
├── id              INT
├── user_id         INT (FK → user)
├── amount          BIGINT            ← stored in paise/cents (×100)
├── type            ENUM(CREDIT, DEBIT)
├── reference_type  ENUM(TOP_UP, TRANSFER)
├── reference_id    INT               ← transfer.id for transfers, NULL for top-ups
├── idempotency_key VARCHAR(64) UNIQUE
└── created_at      DATETIME

transfers
├── id              INT
├── sender_id       INT (FK → user)
├── recipient_id    INT (FK → user)
├── amount          BIGINT
├── status          VARCHAR(50)
├── idempotency_key VARCHAR(64) UNIQUE
└── created_at      DATETIME
```

> **Balance** is never stored as a column. It is always derived as `SUM(CREDIT) - SUM(DEBIT)` from `account_ledger`.

---

## Running the Application

```bash
# Start the development server
symfony server:start

# Or using PHP built-in server
php -S localhost:8000 -t public/
```

---

## API Reference

All `POST` endpoints require the `Idempotency-Key` header (8–64 characters).  
Amounts are in **decimal format** (e.g. `10.50`) and stored internally as integer paise/cents.

---

### Check Balance

```
GET /balance/{user_id}
```

**Example**

```bash
curl http://localhost:8000/balance/1
```

**Response `200`**

```json
{
  "success": true,
  "data": {
    "user_id": 1,
    "balance": 100.50
  },
  "request_id": "req_abc123"
}
```

---

### Add Balance (Top Up)

```
POST /balance/add
Headers:
  Content-Type: application/json
  Idempotency-Key: <unique-key>
```

**Request Body**

| Field     | Type   | Required | Description              |
|-----------|--------|----------|--------------------------|
| `user_id` | int    | Yes      | ID of the user to top up |
| `amount`  | float  | Yes      | Amount to add (e.g. `100.50`) |

**Example**

```bash
curl -X POST http://localhost:8000/balance/add \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: topup-user1-20260418-001" \
  -d '{"user_id": 1, "amount": 100.50}'
```

**Response `200`**

```json
{
  "success": true,
  "data": {
    "user_id": 1,
    "balance": 100.50
  },
  "request_id": "req_abc123"
}
```

---

### Transfer Funds

```
POST /transfer
Headers:
  Content-Type: application/json
  Idempotency-Key: <unique-key>
```

**Request Body**

| Field          | Type   | Required | Description                        |
|----------------|--------|----------|------------------------------------|
| `sender_id`    | int    | Yes      | ID of the sender                   |
| `recipient_id` | int    | Yes      | ID of the recipient                |
| `amount`       | float  | Yes      | Amount to transfer (e.g. `10.50`)  |

**Example**

```bash
curl -X POST http://localhost:8000/transfer \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: txn-user1-to-user2-20260418-001" \
  -d '{
    "sender_id": 1,
    "recipient_id": 2,
    "amount": 10.50
  }'
```

**Response `201`**

```json
{
  "success": true,
  "data": {
    "id": 1,
    "sender_id": 1,
    "recipient_id": 2,
    "amount": 10.50,
    "status": "COMPLETED",
    "created_at": "2026-04-18 19:00:00"
  },
  "request_id": "req_abc123"
}
```

---

## Architecture

### Ledger-Based Balance

Balances are never stored as a column. Every credit and debit is an immutable row in `account_ledger`. Balance is always computed as:

```sql
SELECT SUM(CASE WHEN type = 'CREDIT' THEN amount ELSE -amount END)
FROM account_ledger
WHERE user_id = ?
```

This gives a full audit trail and makes every balance change traceable.

### Transaction Integrity

- `SELECT FOR UPDATE` locks user rows inside the transaction before any balance check
- Rows are always locked in ascending `user_id` order to prevent deadlocks
- Deadlocks are automatically retried up to 3 times with 50ms/100ms backoff
- Explicit `REPEATABLE READ` isolation level configured in `doctrine.yaml`

### Idempotency

Every `POST` request requires an `Idempotency-Key` header (enforced by `IdempotencySubscriber`).

- Transfers: unique key stored on `transfers.idempotency_key`
- Top-ups: unique key stored on `account_ledger.idempotency_key`
- Duplicate requests return the original result without re-executing

### Distributed Locking

Redis `SET NX EX` is used to prevent concurrent duplicate submissions. If Redis is unavailable, the system fails open and the database unique constraint on `idempotency_key` acts as the hard guard.

### Security Notes

- The app validates request payloads, enforces idempotency, and uses ledger-backed accounting to avoid balance drift.
- The current implementation does not include authentication/authorization, so production deployment should protect the API and ensure the caller is authorized to debit the sender account.
- Avoid logging sensitive tokens or idempotency keys in production logs.
- Rate limiting is supported and should be enabled to reduce abuse of the transfer and top-up endpoints.

### Float Amount Handling

```
API Input  →  Stored (paise)  →  Response
  10.50    →     1050         →   10.50
  10.5     →     1050         →   10.50
```

Conversion uses `(int) round(float * 100)` to avoid IEEE 754 floating point precision loss.

### Rate Limiting

| Endpoint       | Limit        |
|----------------|--------------|
| `POST /transfer`    | 30 req/min   |
| `POST /balance/add` | 20 req/min   |
| `GET /balance/{id}` | 100 req/min  |

---

## Error Handling

All errors return a consistent JSON structure:

```json
{
  "error": {
    "code": "error_code",
    "message": "Human readable message",
    "type": "invalid_request_error | api_error",
    "details": {}
  },
  "request_id": "req_abc123"
}
```

### Error Codes

| HTTP | Code                      | Cause                                      |
|------|---------------------------|--------------------------------------------|
| 400  | `invalid_request`         | Wrong `Content-Type` or malformed JSON     |
| 409  | `transfer_error`          | Insufficient funds, lock contention        |
| 422  | `validation_error`        | Missing or invalid fields                  |
| 422  | `missing_idempotency_key` | `Idempotency-Key` header not provided      |
| 422  | `invalid_idempotency_key` | Key length not between 8 and 64 characters |
| 429  | `rate_limit_exceeded`     | Too many requests                          |
| 404  | `not_found`               | User not found                             |
| 500  | `internal_server_error`   | Unexpected server error                    |

---

## Logging

Logs are written to `var/log/`:

| File            | Content                        |
|-----------------|--------------------------------|
| `dev.log`       | All application logs           |
| `transfer.log`  | Transfer-specific logs         |
| `balance.log`   | Balance check and top-up logs  |

Every log entry includes structured context:

```json
{
  "request_id": "req_abc123",
  "idempotency_key": "txn-001",
  "sender_id": 1,
  "recipient_id": 2,
  "amount": 10.50,
  "processing_ms": 42.5
}
```

In production (`APP_ENV=prod`), the `fingers_crossed` handler buffers all logs and only flushes them when an error occurs, reducing log noise.
