<?php

declare(strict_types=1);

/**
 * Responses attached to every operation by the generator, so that 175
 * operations do not each repeat them.
 */
$envelope = fn (string $description, string $example, array $extra = []): array => [
    'description' => $description,
    'content' => [
        'application/json' => [
            'schema' => ['$ref' => '#/components/schemas/Error'],
            'example' => ['error' => array_merge([
                'code' => $example,
                'message' => 'Human-readable explanation, in English, for a developer.',
                'request_id' => '01J8ZQ7X0000000000000RQIDX',
            ], $extra)],
        ],
    ],
];

return [
    'Unauthenticated' => $envelope(
        'No token, or a token that is expired or revoked.',
        'unauthenticated',
    ),
    'WorkspaceRequired' => $envelope(
        'The `X-Workspace-Id` header was missing or blank.',
        'workspace_required',
    ),
    'WorkspaceForbidden' => $envelope(
        'The caller is not a member of that workspace — or it does not exist. The two are '
            .'answered identically on purpose, so the endpoint cannot be used to probe which '
            .'workspace ids are real.',
        'workspace_forbidden',
    ),
    'Forbidden' => [
        'description' => 'The caller\'s role does not permit this action. Note that role checks '
            .'raised by a bare abort return Laravel\'s default `{"message": "..."}` body rather '
            .'than the error envelope.',
        'content' => [
            'application/json' => [
                'schema' => [
                    'oneOf' => [
                        ['$ref' => '#/components/schemas/Error'],
                        ['$ref' => '#/components/schemas/FrameworkError'],
                    ],
                ],
            ],
        ],
    ],
    'NotFound' => [
        'description' => 'No such record in this workspace. Records looked up with `findOrFail` '
            .'answer with Laravel\'s default `{"message": "..."}` body; those raised as domain '
            .'exceptions carry a `code` such as `account_not_found`.',
        'content' => [
            'application/json' => [
                'schema' => [
                    'oneOf' => [
                        ['$ref' => '#/components/schemas/Error'],
                        ['$ref' => '#/components/schemas/FrameworkError'],
                    ],
                ],
            ],
        ],
    ],
    'ValidationFailed' => [
        'description' => 'The request body or query failed validation. **Two shapes are possible.** '
            .'Laravel\'s validator answers `{"message", "errors"}`; a domain rule that refuses '
            .'after validation passed answers the `{"error": {"code", ...}}` envelope with a code '
            .'such as `currency_mismatch`, `zero_amount` or `negative_amount`.',
        'content' => [
            'application/json' => [
                'schema' => [
                    'oneOf' => [
                        ['$ref' => '#/components/schemas/ValidationError'],
                        ['$ref' => '#/components/schemas/Error'],
                    ],
                ],
            ],
        ],
    ],
    'Conflict' => $envelope(
        'The request conflicts with current state — for example a duplicate invoice number, an '
            .'allowance already paid for the period, or a draft that was already resolved.',
        'conflict',
    ),
    'RateLimited' => [
        'description' => 'Too many requests. `docs/05-api-conventions.md` §10 sets the intended '
            .'budgets: 5/min per IP for auth, 120/min per user generally, 60/min per device for '
            .'sync, and a plan-dependent daily allowance for AI and OCR. `X-RateLimit-Limit` and '
            .'`X-RateLimit-Remaining` accompany responses.',
        'content' => [
            'application/json' => [
                'schema' => ['$ref' => '#/components/schemas/Error'],
                'example' => ['error' => [
                    'code' => 'rate_limited',
                    'message' => 'Too many requests.',
                    'request_id' => '01J8ZQ7X0000000000000RQIDX',
                ]],
            ],
        ],
        'headers' => [
            'X-RateLimit-Limit' => ['schema' => ['type' => 'integer'], 'description' => 'Requests permitted in the window.'],
            'X-RateLimit-Remaining' => ['schema' => ['type' => 'integer'], 'description' => 'Requests left in the window.'],
        ],
    ],
    'ServerError' => $envelope(
        'Unhandled failure. A few domain invariants also surface here rather than as a client '
            .'error, because they mean the server produced something impossible: '
            .'`unbalanced_transaction` and `inconsistent_invoice_totals` are 500 by design — the '
            .'request was valid and the books still did not add up.',
        'server_error',
    ),
    'ServiceUnavailable' => $envelope(
        'A dependency the request needs is not reachable — an AI or OCR provider, or a market '
            .'data source.',
        'service_unavailable',
        ['details' => ['provider' => 'openai']],
    ),
];
