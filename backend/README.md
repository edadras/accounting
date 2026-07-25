# Finora — Backend API

Laravel 12 · PHP 8.4 · modular monolith.

## Status

Twelve domain modules covering milestones **M1, M3, M4, M6, M8 and M9** of
[`docs/04-roadmap.md`](../docs/04-roadmap.md).

**209 tests, 4664 assertions, all green.** 93 API routes.

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
./vendor/bin/phpunit     # 209 tests, 4664 assertions
./vendor/bin/pint        # formatting
```

## Layout

```
app/Core/Money/     Money + Currency — the value objects everything else uses

modules/Core/       Workspace, membership, auth, the workspace scope
modules/Ledger/     Accounts, categories, transactions, entries

modules/Budget/     Budgets, period usage, rollover, alert thresholds
modules/Reports/    Cash flow, net worth, trends, top categories/merchants
modules/Banking/    Banks, cheques, loans, amortisation, instalments
modules/Investment/ Positions, weighted-average cost, realized profit, ROI
modules/Assets/     Assets, linear and declining depreciation, insurance
modules/Buildings/  Units, periodic charges, payments, debtors, fund
modules/Business/   Contacts, projects, invoices, numbering, payments
modules/Travel/     Trips, split expenses, minimum-transfer settlement
modules/Documents/  Uploads, polymorphic attachment, OCR fields
modules/Search/     Persian/Arabic normalisation, write-time index
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

Almost every module's headline test asserts the same shape of thing: **the parts
add up to the whole, exactly.** That is the property a finance product lives or
dies by, and it is the one that quietly breaks first.

| Test | Guarantees |
|---|---|
| `MoneyTest` | Mixed currencies rejected; rounding half-up; 1000 random splits reconcile |
| `DoubleEntryTest` | Transfers net to zero; cached balances match their entries; retries are idempotent |
| `WorkspaceIsolationTest` | Every endpoint refuses another workspace's data, at the HTTP layer |
| `BankingTest` | Instalment principal parts sum exactly to the loan principal, across 11 awkward rate/term combinations |
| `TravelTest` | Split shares sum exactly to the expense; a settled trip zeroes every member in at most n−1 transfers |
| `BuildingsTest` | Charges issued across 40 units sum exactly to the total, on area, resident and fixed formulas |
| `BusinessTest` | `Σ line totals == subtotal` and `subtotal − discount + tax == total`, with mixed per-line tax rates |
| `AssetTest` | Depreciation over the full life sums exactly to cost − salvage, and never dips below salvage |
| `InvestmentTest` | Weighted-average cost survives many buys; a sell never moves it; overselling is refused |
| `BudgetTest` | Category budgets count the whole subtree; transfers and income never count as spend |
| `ReportsTest` | Transfers appear in no report; date ranges partition without gap or overlap |
| `SearchTest` | `گوشت` matches text written with Arabic ک/ي, harakat, ZWNJ and Persian digits |

## Notes for whoever picks this up

- **Search indexes at write time.** Normalising the column in SQL needs ~45
  nested `REPLACE()` calls and SQLite's parser overflows at 31, so a
  `search_index` table is kept in step by model events instead. Rows written
  before the module existed are not indexed — a `search:reindex` backfill
  command is still needed before this runs against real data.
- **Budget scopes `project|trip|building|member` report zero.** They are stored
  and validated, but only `overall` and `category` are computed; the single
  place to extend is `CalculateBudgetUsage::constrainToScope()`.
- **`HasDocuments` is not yet used by `Transaction`.** The trait and pivot exist
  and are tested against real transaction rows through a stand-in model; wiring
  it onto the Ledger models is a one-line change per model.
