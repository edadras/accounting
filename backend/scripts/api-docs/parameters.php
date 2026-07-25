<?php

declare(strict_types=1);

/**
 * Reusable header parameters. `WorkspaceId` is attached automatically by the
 * generator to every route whose middleware stack contains ResolveWorkspace, so
 * the spec cannot claim a route is workspace-scoped when the router disagrees.
 */
return [
    'WorkspaceId' => [
        'name' => 'X-Workspace-Id',
        'in' => 'header',
        'required' => true,
        'schema' => ['type' => 'string', 'minLength' => 26, 'maxLength' => 26],
        'example' => '01J8ZQ7X0000000000000WSPCE',
        'description' => 'ULID of the workspace this request operates in. Membership is '
            .'verified on every request. Missing → `400 workspace_required`; not a member '
            .'(or no such workspace) → `403 workspace_forbidden`.',
    ],
    'AcceptLanguage' => [
        'name' => 'Accept-Language',
        'in' => 'header',
        'required' => false,
        'schema' => ['type' => 'string', 'enum' => ['fa', 'en', 'tr', 'ar'], 'default' => 'fa'],
        'description' => 'Preferred language for human-readable text. Error `code` values are '
            .'always English regardless.',
    ],
    'RequestId' => [
        'name' => 'X-Request-Id',
        'in' => 'header',
        'required' => false,
        'schema' => ['type' => 'string'],
        'description' => 'Correlation id. Echoed back as `error.request_id` (and in `meta.request_id` '
            .'where the endpoint emits one), which is what makes a user-reported failure findable '
            .'in the logs.',
    ],
    'IdempotencyKey' => [
        'name' => 'Idempotency-Key',
        'in' => 'header',
        'required' => false,
        'schema' => ['type' => 'string', 'maxLength' => 64],
        'description' => 'Retry-safety key for writes. Where it is honoured (see the "Idempotency" '
            .'section of the description) a repeat with the same key returns the original record '
            .'instead of creating a second one, and the header wins over any `idempotency_key` '
            .'field in the body.',
    ],
    'DeviceId' => [
        'name' => 'X-Device-Id',
        'in' => 'header',
        'required' => false,
        'schema' => ['type' => 'string', 'maxLength' => 64],
        'description' => 'Calling device. Also accepted as a `device_id` body/query field; the '
            .'field is read first, then this header.',
    ],
];
