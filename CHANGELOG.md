# Changelog

All notable changes to Finora are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Dates are the dates of the commits that introduced the work, taken from
`git log`. There are no release tags yet, so `0.1.0` describes everything on the
default branch rather than a published artefact.

Two conventions this project treats as breaking changes, per
`docs/05-api-conventions.md` §12: removing an API field, and changing what an
existing field means. Adding a field is not breaking, and clients are required
to ignore fields they do not recognise.

## [Unreleased]

### Added

- **Sign-in.** The client had an `AuthRepository` and no screen using it, so the
  app only ever ran on in-memory demo data. Sign-in, register, workspace picker,
  sign-out and an auth gate; the gate keys on whether a backend is configured,
  never on whether a token exists, so an unconfigured build still opens straight
  into the demo shell.
- **UI for six backends that had none**: Alerts (inbox, rules, quiet hours),
  Recurring, Family (allowances and caps), Budget management, Payroll
  (employees, runs, payslips, tax rules) and Search. Billing joins settings.
- **`PATCH /api/v1/budgets/{id}`** — the module had no update endpoint, so
  editing a budget meant creating a replacement and deleting the original, which
  leaves a duplicate whenever the second leg fails. Currency cannot change and a
  custom period cannot be inverted; both are validated against the stored row.
- **`ApiException.rawDetails`** — `details` was flattened to
  `Map<String, List<String>>`, which stringified structured payloads. A
  `downgrade_blocked` answer naming the exact limit reached the UI as unusable
  text, so screens showed a generic refusal instead.

### Fixed

- **Five finished screens were reachable from nothing** — conflict resolution,
  documents (and its preview), report export, forgot-password and the two-factor
  challenge. Conflict resolution was the costly one: the sync engine has always
  counted conflicts, so a lost edit was already detected and simply could not be
  looked at. There is now a test for reachability itself, because every one of
  those screens passed its own suite the whole time it was unreachable.
- **The transactions screen read the wall clock** for its Today/Yesterday
  headings while its rows were pinned to the injected clock, so the goldens
  passed all day and failed at midnight on a suite nobody had touched.
- **`payslip_count` never appeared on `GET /payroll/runs`** — the controller
  counted without loading the relation and the resource asked whether it was
  loaded.
- **The OpenAPI description named error codes the server does not throw**
  (`payroll_run_immutable` and two others) and the wrong field on
  `POST /payroll/runs/{id}/pay`.
- `docs/openapi.yaml` — OpenAPI 3.1 description of the v1 HTTP API, generated
  from `php artisan route:list` so it cannot describe endpoints that do not
  exist. Documents Sanctum bearer auth, the `X-Workspace-Id` requirement,
  `Idempotency-Key` on writes, and the error envelope.
- `docs/finora.postman_collection.json` — Postman collection built from the same
  route data, one folder per module, driven by `base_url`, `token` and
  `workspace_id` variables.
- `CHANGELOG.md` — this file. `docs/10-quality-and-dod.md` §2 has required it
  per task since M0; it did not exist.
- `backend/scripts/check-translation-keys.php` — fails when the `fa`, `en`, `tr`
  and `ar` key sets in the client's bundled translations diverge
  (§5 step 7).

### Changed

- `backend/phpunit.xml` now declares the coverage source set (`app/` and
  `modules/`, excluding migrations, providers, routes and config) and records
  the §4 per-area targets. Coverage requires PCOV or Xdebug; without a driver
  PHPUnit warns and still exits 0.
- CI runs the three steps `docs/10-quality-and-dod.md` §5 promised and the
  workflow was missing: PHPStan level 8, tests under coverage with the report
  uploaded as an artefact, and the translation-key parity check.

## [0.1.0] - 2026-07-25

First end-to-end version: a Laravel 12 modular monolith and a Flutter client,
covering every milestone in `docs/04-roadmap.md` from M0 to M10.

Measured on this tree: 175 registered `api/v1` routes across 24 route-carrying
modules, 35 migrations, and 612 translation keys in each of the four locales.

### M0 — Foundation

#### Added

- The documentation set that the rest of the work is held to: vision,
  architecture, module catalogue, data model, roadmap, API conventions,
  i18n/RTL rules, security model, AI layer, sync design, and quality bar
  (`docs/00-` … `docs/10-`).
- `docker-compose.yml` with MySQL 8.4, Redis, Meilisearch, MinIO and Mailpit.
- GitHub Actions CI: PHPUnit, Pint, `flutter analyze`, `flutter test`, and a
  migration rollback-and-reapply check.
- Laravel Horizon with the six-queue split from `docs/01` §5 — realtime, ocr,
  ai, media, reports, maintenance — with per-queue timeouts matched to the work.
- Laravel Reverb with one private channel per workspace. Channel authorisation
  repeats the membership check `ResolveWorkspace` performs for HTTP, because a
  broadcast channel is a second door into the same books.
- A MinIO disk alongside `s3`, so development and production use one driver.
- `SECURITY.md` and `backend/scripts/smoke.sh`, an end-to-end check that the
  providers, routes and middleware are actually wired together.

#### Deliberately not added

- `spatie/laravel-permission`, `activitylog` and `medialibrary`. `docs/01`
  listed them, but this codebase already has its own roles, audit trail and
  document store; installing theirs would mean two of each.

### M1 — Ledger Core

#### Added

- `Money` and `Currency` value objects. Amounts are integers in minor units and
  never floats; the currency's `minor_unit` travels with every amount, since it
  is the single fact that makes the integer interpretable. Combining two
  currencies without an explicit rate raises rather than guesses, and
  `allocateEvenly`/`allocateByWeights` always sum back to the original exactly.
- Workspace isolation: a global query scope that fails closed (`WHERE 1 = 0`
  when no workspace is active) plus `ResolveWorkspace`, which verifies
  membership once instead of in every controller.
- Accounts, unlimited-depth categories via a materialised path, and transactions
  that post real double-entry rows inside a single database transaction.
- Cross-currency transfers convert the destination leg at its own rate rather
  than forcing the two legs to cancel, so FX difference stays visible.
- `Idempotency-Key` handling, so a retried write cannot duplicate a transaction.
- Sanctum authentication; registering seeds a wallet and a three-level category
  tree.

### M2 — First App

#### Added

- Flutter client with a neon design system in three files — palette, effects,
  theme. Glow is treated as information rather than decoration: only the
  net-worth figure, the active tab and the primary action carry it.
- Dashboard, transaction list, quick-add sheet, accounts and settings screens.
- Client-side `Money` and `MoneyFormatter` mirroring the backend, with
  Persian/Arabic digit parsing and bidi isolates so amounts never reorder inside
  right-to-left text.
- Four languages (`fa`, `en`, `tr`, `ar`) resolved through a `Translator` that
  overlays server strings on a bundled fallback. No display string is
  hard-coded.
- Vazirmatn bundled, so Persian, Arabic and Latin come from one family and the
  typeface does not change with the language.
- Golden tests for every screen in Persian (RTL) and English (LTR), plus the
  light theme. The tests load the real font and the Material icon font, without
  which the engine draws boxes and the images prove nothing.

#### Fixed

- `NeonCard` rendered as a solid slab of accent colour rather than dark glass: a
  `BoxDecoration` ignores its `color` whenever a `gradient` is set, so the card
  was filled only with a 7% tint and its own halo bled through the backdrop
  blur. `NeonEffects.glass()` now blends the tint into the surface and returns
  one opaque gradient. Found by the goldens.

### M3 — Budget & Reports

#### Added

- Budget module with six scopes (overall, category, project, trip, building,
  member), monthly/yearly/custom periods, rollover, and alert thresholds.
  Category budgets count the whole subtree; transfers are never counted as
  spending.
- Reports module: cash-flow, net-worth, expense and income trends, top
  categories, merchants and accounts, and category breakdown. Transfers appear
  in no report, and date ranges partition cleanly with no double counting.
- Report export to CSV, XLSX and PDF, inline for small results and queued for
  large ones. XLSX writes amounts as numbers with a currency format so a column
  can be summed; CSV writes plain decimals with no thousands separator, which
  would make the file unparseable in half the world's locales.
- A Persian PDF shaper written from scratch: dompdf has neither shaping nor
  bidi, so left alone it renders Persian unjoined and reversed. Verified by
  decoding the generated PDF rather than by looking at it.
- Client reports tab: cash-flow bars where income and expense share one scale so
  the comparison is honest, and budget cards coloured by state with rollover
  shown separately.

### M4 — Documents & Search

#### Added

- Documents module: polymorphic attachment to transactions, accounts and
  categories through a whitelist of attachable types, with mime and size limits
  and a checksum per file.
- Search module with Persian/Arabic normalisation applied at write time. SQLite's
  parser overflows at 31 nested `REPLACE()` calls and correct folding needs
  about 45, so an index table is kept in step by model events instead.
- Semantic search over a local concept lexicon, so "everything to do with the
  car" finds fuel, garage, insurance and fines across five categories with no
  network call.
- `search:reindex` for backfilling the index.

### M5 — AI Layer

#### Added

- Natural-language capture, receipt OCR, voice notes, tool-calling chat and
  statistical insights, all behind an `AiProvider` seam.
- A rule-based deterministic provider, so the layer works and is tested with no
  network and no API key; OpenAI takes over when `ai.key` is set.
- Prompt-injection defence that is asserted rather than described: `workspace_id`
  is injected server-side and stripped from tool arguments, and an injection
  attempt planted in a transaction description produces byte-identical tool
  calls to the control run.
- Capture channels — text, bank SMS, QR and email — which all produce a draft
  and never post a transaction. Five SMS shapes are configured and each is
  tested in both directions, because reading a withdrawal as a deposit doubles
  the error rather than halving it. The email webhook fails closed when no
  secret is configured.
- AI drafts are explicit: nothing is written to the ledger until the user
  confirms.

### M6 — Banking, Checks, Loans, Alerts

#### Added

- Banks, cheques with a lifecycle (`draft` … `cleared`/`bounced`/`void`), and
  loans with generated instalment schedules. Instalment principal parts sum to
  the loan principal across 11 awkward rate/term combinations, and an annuity
  schedule closes at exactly zero.
- Alerts engine: cheque, instalment, budget and balance scanners, deduplicated
  by unique index, with per-channel preferences and quiet hours that defer
  rather than drop.

### M7 — Offline-First & Sync

#### Added

- Sync push/pull over a whitelisted entity registry (transaction, account,
  category, budget) with per-field conflict detection and an opaque, totally
  ordered cursor.
- The rule the module exists for: a disagreement about an amount, currency,
  account or date is never auto-resolved, only surfaced. Non-financial fields
  merge last-write-wins.
- Device registration and revocation.
- Client offline outbox and sync engine, so writes land locally and are marked
  pending.

#### Fixed

- The conflict-resolution widget tests hung the whole suite for thirty minutes.
  The screen was never broken and needed no change: `testWidgets` drives a fake
  clock that never advances Dio's real timers, so an awaited request simply
  never returned, and because it blocks the isolate synchronously `--timeout`
  could not interrupt it. Everything touching Dio now runs inside
  `tester.runAsync`. Both traps are written down where the next person will hit
  them.

### M8 — Verticals

#### Added

- Buildings: units, charge issuance on fixed, per-area, per-resident and mixed
  formulas, payments, and debtor and fund reports. Charges across 40 units sum
  to the total on every formula, and decimal areas are parsed digit by digit,
  never through a float.
- Business: contacts, projects, invoices with per-line tax rates and an
  allocated header discount, payments, and project profitability.
  `sum(line totals) == subtotal` and `subtotal - discount + tax == total`.
- Travel: trips, split expenses in equal/percent/weight/exact modes, and
  settlement that zeroes every member in at most n-1 transfers via
  greedy largest-creditor pairing. Split shares always sum to the expense.
- Family: members, spending caps and allowances.
- Recurring rules, which post at most once per due date.
- Workspace invitations. Only the token hash is stored, so a leaked database
  hands nobody a working link; the plaintext is returned once and never again.
  Holding the link is not enough — the accepting user's email must match. An
  unknown token answers exactly as a revoked one does, so the endpoint cannot be
  used to probe which tokens exist. The owner cannot be demoted or removed, and
  nobody can be invited as a second owner.
- Audit trail, append-only by construction: `updating` and `deleting` throw. An
  update stores only the fields that actually moved. Credentials and tokens are
  redacted before storage; amounts are kept verbatim, since that is the point of
  the log. A failed audit write is swallowed — a lost audit row is bad, a lost
  expense because auditing hiccuped is worse. Sign-in, sign-out and failed
  sign-in carry no workspace and surface at `/me/security-log`.
- Translations API. `GET /translations/{locale}` is public, because the sign-in
  screen needs its own words before anyone has a token and a dictionary is not
  secret. `?since=` returns only the delta using the server's clock, so a skewed
  device cannot miss an update forever. A key with no value is never served — it
  would replace a working fallback with a blank label. `i18n:missing` reports
  keys present in one locale and absent from another.
- Two-factor authentication (TOTP) with single-use recovery codes. Once 2FA is
  confirmed, login returns a challenge rather than a token.
- Password reset that answers identically for a known and an unknown email — a
  different answer tells an attacker which addresses are registered — and
  revokes every existing token on success.
- Encryption at rest for `two_factor_secret` and `accounts.iban`. The IBAN
  migration encrypts existing rows in place and decrypts them again on rollback.
- Workspace data export as CSV, JSON and documents in one archive, with amounts
  written through `toDecimalString()`: a spreadsheet cell reading `35000` for
  ₺350.00 is worse than no export at all.
- Account deletion with a grace period and a restore path, plus `accounts:purge`
  for the irreversible part and `backup:verify`, because a backup nobody has
  verified is not a backup.
- Market data: scheduled rate and price fetches behind a provider seam. A rate
  that is zero, negative, non-numeric or more than ten times the previous one is
  rejected and recorded, because a bad rate applied silently re-values every
  multi-currency balance in the product. History is appended, never overwritten.
- Client screens for two-factor setup and recovery codes, password reset, data
  and report export, account deletion, members and invitations, and the audit
  and security logs — all reachable from an "account & security" section in
  settings.

### M9 — Investment & Assets

#### Added

- Investments with weighted-average cost that survives many buys and is never
  moved by a sell, trades (buy/sell/dividend/fee/split), realised and unrealised
  profit, and ROI.
- Assets with linear and declining depreciation. Depreciation over the full life
  sums to `cost - salvage` and never dips below salvage.

### M10 — Monetization & Launch

#### Added

- Plans, trials and subscriptions, with a single `Entitlements` class every
  limit must go through — enforced by a test that scans for stray numeric limits
  elsewhere.
- Downgrading below current usage is refused rather than being destructive.

### Security

- Cross-workspace isolation is asserted per endpoint, not assumed: a user from
  another workspace receives `403`.
- No domain query runs without a `workspace_id`.
- Amounts are never computed with floating point.
- No financial conflict is resolved silently.

<!--
Keep-a-Changelog version link references are omitted deliberately: this
repository has no public hosting URL and no release tags yet, so any
compare/release link written here would be a guess. Add them with the first
tagged release.
-->
