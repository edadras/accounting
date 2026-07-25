<?php

declare(strict_types=1);

return [
    'title' => 'Finora API',
    'version' => '1.0.0',
    'summary' => 'Personal and small-business financial operating system.',
    'description' => <<<'MD'
        REST API for Finora. Conventions below are the ones in
        `docs/05-api-conventions.md`; where the implementation differs from that
        document, this spec describes the implementation, and the difference is
        called out on the schema concerned.

        ## Money — read this before anything else

        **Every monetary amount in this API is an integer number of the
        currency's minor units.** It is never a decimal and never a float.

        `35000` with currency `TRY` is **₺350.00**, not ₺35,000.
        `35000` with currency `IRR` is **35,000 rials**, because IRR has zero
        decimal places.

        The number of decimal places is carried alongside the amount as
        `minor_unit`, because it is the single fact that makes the integer
        interpretable: `USD`/`TRY`/`EUR`/`AED`/`USDT` are 2, `IRR`/`IRT` are 0,
        `XAU` is 4, `BTC`/`ETH` are 8. Do not hard-code 2.

        In **responses**, money is normally an object — see the `Money` schema —
        carrying the integer, the currency, the minor unit and a display-only
        decimal string. In **requests**, money is a bare integer field (usually
        `amount`) with the currency in a sibling `currency` field.

        The `decimal` and `formatted` strings are for display only. Never parse
        them back into a number and never compute with them.

        ## Authentication

        `Authorization: Bearer <token>` — a Laravel Sanctum personal access
        token from `POST /auth/login`, or from `POST /auth/2fa/verify` when the
        account has two-factor confirmed (in that case login returns a challenge
        instead of a token).

        ## Workspace scoping

        Most endpoints operate inside one workspace and require
        `X-Workspace-Id: <ulid>`. Every such operation lists the header
        explicitly. Omitting it is `400 workspace_required`; sending the id of a
        workspace the caller is not a member of is `403 workspace_forbidden` —
        deliberately the same answer as a workspace that does not exist, so the
        endpoint cannot be used to discover which ids are real.

        The endpoints that must not require it are the ones that establish
        workspace context in the first place: `/auth/*`, `/me`, `/workspaces`,
        `/invitations/{token}/accept`, `/me/security-log`, `/me/restore`,
        `DELETE /me`, and the public `/translations/{locale}`.

        ## Idempotency

        `Idempotency-Key: <ulid>` on writes. It is honoured today by the write
        paths that create financial records — `POST /transactions`,
        `POST /investments/{id}/trades`, `POST /building-charges/{charge}/pay` —
        where the header takes precedence over any `idempotency_key` field in
        the body. Sending it on other writes is harmless and forward-compatible.

        ## Errors

        Domain refusals answer with a stable, machine-readable, always-English
        `code`; the `message` is prose for a developer, not for a user. Clients
        are expected to translate from the `code`.

        ```json
        {
          "error": {
            "code": "currency_mismatch",
            "message": "Transaction currency USD does not match the account currency TRY.",
            "details": { "given": "USD", "expected": "TRY" },
            "request_id": "01J..."
          }
        }
        ```

        Two caveats about the real behaviour, both worth knowing before writing
        a client error handler:

        1. **Laravel validation failures do not use this envelope.** A `422`
           from the framework's validator is `{"message": "...", "errors":
           {"field": ["..."]}}`. Handle both shapes.
        2. **Some `403`s and `404`s do not use it either.** Bare permission
           aborts and `findOrFail` misses return Laravel's default
           `{"message": "..."}` body. Only refusals raised as domain exceptions
           carry a `code`.

        ## Identifiers, times, pagination

        - Ids are **ULIDs** as 26-character strings. Many create endpoints accept
          a client-generated `id`, which is what makes offline-created records
          survive sync.
        - Timestamps are **ISO-8601 in UTC**. The sync endpoints use the
          stricter `YYYY-MM-DDTHH:MM:SSZ` form.
        - Paginated lists answer `{"data": [...], "meta": {"page", "per_page",
          "total"}}`. `per_page` defaults to 50 and is capped at 200. Not every
          list endpoint paginates — the ones that do are marked.
        - The sync feed is cursor-based instead, with an opaque `next_cursor`.

        ## Generation

        This document is generated from `php artisan route:list --json` by
        `backend/scripts/build-api-docs.php`, which refuses to write if a live
        route is undescribed or a described route no longer exists.
        MD,
    'license' => ['name' => 'Proprietary', 'identifier' => 'LicenseRef-Proprietary'],
];
