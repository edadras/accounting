# Finora — Backend API

Laravel 12 · PHP 8.4 · modular monolith.

## Status

Milestone **M1 (Ledger Core)** from [`docs/04-roadmap.md`](../docs/04-roadmap.md):
auth, workspaces, accounts, unlimited-depth categories, and a double-entry
transaction engine with multi-currency support.

## Running

```bash
composer install
cp .env.example .env
touch database/database.sqlite      # or point DB_* at the docker-compose MySQL
php artisan key:generate
php artisan migrate
php artisan serve
```

`docker compose up -d` in the repository root starts MySQL, Redis, Meilisearch,
MinIO and Mailpit.

```bash
./vendor/bin/phpunit     # 36 tests, 1227 assertions
./vendor/bin/pint        # formatting
```

## Layout

```
app/Core/Money/          Money + Currency — the value objects everything else uses
modules/Core/            Workspace, membership, auth, the workspace scope
modules/Ledger/          Accounts, categories, transactions, entries
```

Each module owns its migrations, routes and container bindings, and is
registered with one line in `bootstrap/providers.php`. Modules talk to each
other through contracts and events, never by importing each other's Eloquent
models — which is what makes extracting one into its own service later a
mechanical change rather than a rewrite.

## The three rules

**1. No `float` ever holds an amount.**
`App\Core\Money\Money` stores integer minor units and carries its `Currency`.
It refuses to add two currencies without an explicit rate, refuses more decimal
places than the currency has, and splits amounts so the parts always sum back to
the original (`allocateEvenly`, `allocateByWeights` — the basis of Travel's
split-expense module).

**2. No domain query runs without a `workspace_id`.**
`WorkspaceScope` is a global scope applied by the `BelongsToWorkspace` trait.
With no active workspace it emits `WHERE 1 = 0` — it fails closed, so a wiring
mistake yields an empty list rather than another user's books. Bypassing it
requires the explicit, greppable `withoutWorkspaceScope()`.

**3. Every transaction balances.**
`RecordTransaction` writes the transaction and its entries inside one database
transaction. Debits increase an account, credits decrease it; a transfer credits
the source and debits the destination, and in the same currency the two base
amounts cancel exactly.

Cross-currency transfers deliberately do *not* force the two legs to cancel. The
destination is converted at its own rate, and the difference is a real FX gain or
loss rather than a rounding fudge.

## API shape

Every domain request carries the workspace, verified once in middleware:

```
Authorization: Bearer <sanctum-token>
X-Workspace-Id: <ulid>
Idempotency-Key: <ulid>        # on every write
```

Money always travels as an object, never a bare number:

```json
{
  "amount": { "value": 35000, "currency": "TRY", "minor_unit": 2, "decimal": "350.00" },
  "base":   { "value": 35000, "currency": "TRY", "minor_unit": 2, "decimal": "350.00", "fx_rate": "1" }
}
```

Errors carry a stable English `code` that the client translates itself:

```json
{ "error": { "code": "currency_mismatch", "message": "…", "details": {} } }
```

Full contract in [`docs/05-api-conventions.md`](../docs/05-api-conventions.md).

### Implemented endpoints

```
POST   /api/v1/auth/register · /auth/login · /auth/logout
GET    /api/v1/me
GET    /api/v1/workspaces                 POST /api/v1/workspaces
GET    /api/v1/accounts                   POST /api/v1/accounts
GET    /api/v1/accounts/{id}
GET    /api/v1/categories?tree=1          POST /api/v1/categories
GET    /api/v1/transactions               POST /api/v1/transactions
GET    /api/v1/transactions/{id}          DELETE /api/v1/transactions/{id}
```

Registering seeds the new workspace with a wallet and a three-level category
tree, so the first screen the user sees is never empty.

## Tests that may never be deleted

| Test | Guarantees |
|---|---|
| `MoneyTest` | Mixed currencies rejected; rounding half-up; 1000 random splits reconcile |
| `DoubleEntryTest` | Transfers net to zero; cached balances match their entries; retries are idempotent |
| `WorkspaceIsolationTest` | Every endpoint refuses another workspace's data, at the HTTP layer |
