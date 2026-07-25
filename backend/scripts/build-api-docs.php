<?php

declare(strict_types=1);

/**
 * Generates docs/openapi.yaml and docs/finora.postman_collection.json.
 *
 * Both artefacts are built from the SAME source of truth — the router's own
 * `route:list` output — so the collection cannot describe a different API from
 * the spec, and neither can describe an endpoint that does not exist. A
 * hand-maintained spec drifts within a sprint; this one fails instead.
 *
 * Route facts (path, method, auth middleware, workspace middleware) come from
 * the router. Payload facts (request fields, response shapes) come from
 * scripts/api-docs/{schemas,operations}.php, which are written by hand from the
 * FormRequests, inline validators and API Resources.
 *
 * The script refuses to emit anything if a live route has no entry in
 * operations.php, or if operations.php describes a route that no longer exists.
 * That is the whole point: silence is how a spec rots.
 *
 * Usage:
 *   php backend/scripts/build-api-docs.php            # asks artisan for routes
 *   php backend/scripts/build-api-docs.php routes.json
 *   php backend/scripts/build-api-docs.php --check    # verify only, write nothing
 */

require __DIR__.'/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

$root = dirname(__DIR__, 2);
$backend = dirname(__DIR__);

$args = array_slice($argv, 1);
$checkOnly = in_array('--check', $args, true);
$routeFile = null;

foreach ($args as $arg) {
    if (! str_starts_with($arg, '--')) {
        $routeFile = $arg;
    }
}

if ($routeFile !== null) {
    $raw = file_get_contents($routeFile);
} else {
    $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($backend.'/artisan').' route:list --json';
    $raw = shell_exec($cmd);
}

if (! is_string($raw) || trim($raw) === '') {
    fwrite(STDERR, "could not obtain the route list\n");
    exit(1);
}

/** @var list<array<string,mixed>> $routes */
$routes = json_decode(trim($raw), true, 512, JSON_THROW_ON_ERROR);

/** @var array<string,array<string,mixed>> $schemas */
$schemas = require __DIR__.'/api-docs/schemas.php';
/** @var array<string,array<string,mixed>> $operations */
$operations = require __DIR__.'/api-docs/operations.php';

// ---------------------------------------------------------------- route facts

$live = [];

foreach ($routes as $route) {
    $uri = (string) $route['uri'];

    // Horizon's dashboard, the health endpoint, the Sanctum CSRF cookie and the
    // storage fallback are framework surface, not the product's v1 API.
    if (! str_starts_with($uri, 'api/')) {
        continue;
    }

    $middleware = array_map('strval', (array) ($route['middleware'] ?? []));

    foreach (explode('|', (string) $route['method']) as $method) {
        if ($method === 'HEAD') {
            continue;
        }

        $live['/'.$uri][$method] = [
            'action' => (string) ($route['action'] ?? ''),
            'auth' => (bool) array_filter($middleware, fn (string $m) => str_contains($m, 'sanctum')),
            'workspace' => (bool) array_filter($middleware, fn (string $m) => str_contains($m, 'ResolveWorkspace')),
            'middleware' => $middleware,
        ];
    }
}

$liveKeys = [];

foreach ($live as $path => $methods) {
    foreach (array_keys($methods) as $method) {
        $liveKeys[] = $method.' '.$path;
    }
}

sort($liveKeys);
$describedKeys = array_keys($operations);
sort($describedKeys);

$undescribed = array_diff($liveKeys, $describedKeys);
$stale = array_diff($describedKeys, $liveKeys);

if ($undescribed !== [] || $stale !== []) {
    foreach ($undescribed as $key) {
        fwrite(STDERR, "route has no description: {$key}\n");
    }

    foreach ($stale as $key) {
        fwrite(STDERR, "described route no longer exists: {$key}\n");
    }

    fwrite(STDERR, "\nopenapi.yaml not written. Update scripts/api-docs/operations.php.\n");
    exit(1);
}

// ------------------------------------------------------------------ the spec

/**
 * Module name from the controller's namespace — this is what groups the spec's
 * tags and the collection's folders, so both group identically by construction.
 */
function moduleOf(string $action): string
{
    if (preg_match('/^Modules\\\\([A-Za-z0-9]+)\\\\/', $action, $m) === 1) {
        return $m[1];
    }

    return 'Core';
}

$paths = [];
$tagsSeen = [];

foreach ($live as $path => $methods) {
    ksort($methods);

    foreach ($methods as $method => $facts) {
        $key = $method.' '.$path;
        $meta = $operations[$key];

        $tag = $meta['tag'] ?? moduleOf($facts['action']);
        $tagsSeen[$tag] = true;

        $parameters = [];

        // Path parameters are read off the URI, never listed by hand: a renamed
        // segment then cannot leave a stale parameter behind.
        if (preg_match_all('/\{([A-Za-z0-9_]+)\??\}/', $path, $m) > 0) {
            foreach ($m[1] as $name) {
                $parameters[] = [
                    'name' => $name,
                    'in' => 'path',
                    'required' => true,
                    'schema' => $meta['path'][$name] ?? ['type' => 'string'],
                    'description' => $meta['pathDesc'][$name] ?? 'ULID identifier.',
                ];
            }
        }

        if ($facts['workspace']) {
            $parameters[] = ['$ref' => '#/components/parameters/WorkspaceId'];
        }

        $parameters[] = ['$ref' => '#/components/parameters/AcceptLanguage'];
        $parameters[] = ['$ref' => '#/components/parameters/RequestId'];

        $isWrite = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);

        if ($isWrite) {
            $parameters[] = ['$ref' => '#/components/parameters/IdempotencyKey'];
        }

        foreach ($meta['query'] ?? [] as $name => $spec) {
            $parameters[] = [
                'name' => $name,
                'in' => 'query',
                'required' => false,
                'schema' => $spec['schema'] ?? ['type' => 'string'],
                'description' => $spec['description'] ?? '',
            ];
        }

        foreach ($meta['headers'] ?? [] as $name => $spec) {
            $parameters[] = [
                'name' => $name,
                'in' => 'header',
                'required' => $spec['required'] ?? false,
                'schema' => $spec['schema'] ?? ['type' => 'string'],
                'description' => $spec['description'] ?? '',
            ];
        }

        $operation = [
            'operationId' => $meta['id'],
            'tags' => [$tag],
            'summary' => $meta['summary'],
        ];

        if (isset($meta['description'])) {
            $operation['description'] = $meta['description'];
        }

        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        if (isset($meta['body'])) {
            $operation['requestBody'] = [
                'required' => $meta['bodyRequired'] ?? true,
                'content' => [
                    ($meta['bodyType'] ?? 'application/json') => ['schema' => $meta['body']],
                ],
            ];
        }

        $responses = [];

        foreach ($meta['responses'] as $status => $spec) {
            // A shared response is referenced, not redescribed — it carries its
            // own description in components and has no inline one to read.
            if (isset($spec['$ref'])) {
                $responses[(string) $status] = $spec;

                continue;
            }

            $response = ['description' => $spec['description']];

            if (isset($spec['schema'])) {
                $response['content'] = [
                    ($spec['type'] ?? 'application/json') => ['schema' => $spec['schema']],
                ];
            }

            if (isset($spec['headers'])) {
                $response['headers'] = $spec['headers'];
            }

            $responses[(string) $status] = $response;
        }

        // Every route can answer with these, so they are attached here rather
        // than repeated 175 times in the metadata table.
        $responses['422'] ??= ['$ref' => '#/components/responses/ValidationFailed'];
        $responses['429'] ??= ['$ref' => '#/components/responses/RateLimited'];
        $responses['500'] ??= ['$ref' => '#/components/responses/ServerError'];

        if ($facts['auth']) {
            $responses['401'] ??= ['$ref' => '#/components/responses/Unauthenticated'];
            $operation['security'] = [['bearerAuth' => []]];
        } elseif (isset($meta['security'])) {
            $operation['security'] = $meta['security'];
        } else {
            // An empty list is meaningful in OpenAPI: it overrides the
            // document-level default and marks the route as genuinely public.
            $operation['security'] = [];
        }

        if ($facts['workspace']) {
            $responses['400'] ??= ['$ref' => '#/components/responses/WorkspaceRequired'];
            $responses['403'] ??= ['$ref' => '#/components/responses/WorkspaceForbidden'];
        }

        ksort($responses);
        $operation['responses'] = $responses;

        $paths[$path][strtolower($method)] = $operation;
    }
}

ksort($paths);

$tags = array_keys($tagsSeen);
sort($tags);

$spec = [
    'openapi' => '3.1.0',
    'info' => require __DIR__.'/api-docs/info.php',
    'servers' => [
        ['url' => 'http://localhost:8000', 'description' => 'Local development server'],
    ],
    'tags' => array_map(
        fn (string $t) => ['name' => $t, 'description' => tagDescription($t)],
        $tags,
    ),
    'security' => [['bearerAuth' => []]],
    'paths' => $paths,
    'components' => [
        'securitySchemes' => [
            'bearerAuth' => [
                'type' => 'http',
                'scheme' => 'bearer',
                'description' => 'Laravel Sanctum personal access token, returned by '
                    .'`POST /api/v1/auth/login` (or `POST /api/v1/auth/2fa/verify` '
                    .'when two-factor is confirmed). Send as `Authorization: Bearer <token>`.',
            ],
        ],
        'parameters' => require __DIR__.'/api-docs/parameters.php',
        'responses' => require __DIR__.'/api-docs/responses.php',
        'schemas' => $schemas,
    ],
];

function tagDescription(string $tag): string
{
    return [
        'AI' => 'Natural-language capture, receipt and voice drafts, chat and insights. Nothing is posted to the ledger without an explicit confirm call.',
        'Alerts' => 'Alert rules, delivered alerts, and per-channel delivery preferences.',
        'Assets' => 'Owned assets and their depreciation schedules.',
        'Audit' => 'Append-only workspace audit trail and the caller\'s own security log.',
        'Banking' => 'Banks, cheques and loans with generated instalment schedules.',
        'Billing' => 'Plans, subscriptions, trials and subscription invoices.',
        'Budget' => 'Budgets across six scopes, and live budget status.',
        'Buildings' => 'Buildings, units, charge issuance and collection, and building reports.',
        'Business' => 'Contacts, projects, sales/purchase invoices and payments.',
        'Capture' => 'Inbound capture channels: text, bank SMS, QR and email. All produce drafts.',
        'Core' => 'Authentication, workspaces, members and invitations.',
        'DataOps' => 'Workspace data export and account deletion.',
        'Documents' => 'File uploads and polymorphic attachment to ledger records.',
        'Family' => 'Family members, spending caps and allowances.',
        'I18n' => 'Translation dictionary, served per locale with delta support.',
        'Investment' => 'Investment positions, trades and performance.',
        'Ledger' => 'Accounts, categories and double-entry transactions. The core of the product.',
        'MarketData' => 'Exchange rates and instrument prices.',
        'Recurring' => 'Recurring transaction rules.',
        'Reports' => 'Financial reports and their exports.',
        'Search' => 'Lexical and semantic search across the workspace.',
        'Security' => 'Two-factor authentication and password reset.',
        'Sync' => 'Offline-first delta sync and device registration.',
        'Travel' => 'Trips, split expenses and settlement.',
    ][$tag] ?? $tag;
}

// -------------------------------------------------------------- the collection

$folders = [];

foreach ($live as $path => $methods) {
    ksort($methods);

    foreach ($methods as $method => $facts) {
        $meta = $operations[$method.' '.$path];
        $tag = $meta['tag'] ?? moduleOf($facts['action']);

        $headers = [];

        if ($facts['auth']) {
            $headers[] = ['key' => 'Authorization', 'value' => 'Bearer {{token}}', 'type' => 'text'];
        }

        if ($facts['workspace']) {
            $headers[] = ['key' => 'X-Workspace-Id', 'value' => '{{workspace_id}}', 'type' => 'text'];
        }

        $headers[] = ['key' => 'Accept', 'value' => 'application/json', 'type' => 'text'];
        $headers[] = ['key' => 'Accept-Language', 'value' => '{{locale}}', 'type' => 'text'];

        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            // Disabled by default so the example can be sent twice without the
            // second send being answered from the first send's stored result.
            $headers[] = ['key' => 'Idempotency-Key', 'value' => '{{$guid}}', 'type' => 'text', 'disabled' => true];
        }

        $isMultipart = ($meta['bodyType'] ?? null) === 'multipart/form-data';

        if (isset($meta['body']) && ! $isMultipart) {
            $headers[] = ['key' => 'Content-Type', 'value' => 'application/json', 'type' => 'text'];
        }

        // Postman's own {{var}} placeholders must survive into the URL, so the
        // path keeps its variables rather than being filled with a fake ULID.
        $url = '{{base_url}}'.$path;
        $query = [];

        // Not $spec: that name holds the assembled OpenAPI document from here
        // to the write at the bottom, and reusing it as a loop variable left
        // the last query parameter to be dumped as the whole file.
        foreach ($meta['query'] ?? [] as $name => $parameter) {
            $query[] = [
                'key' => $name,
                'value' => (string) ($parameter['example'] ?? ''),
                'description' => $parameter['description'] ?? '',
                'disabled' => true,
            ];
        }

        $request = [
            'method' => $method,
            'header' => $headers,
            'url' => [
                'raw' => $url.($query === [] ? '' : '?'.implode('&', array_map(
                    fn (array $q) => $q['key'].'='.$q['value'],
                    $query,
                ))),
                'host' => ['{{base_url}}'],
                'path' => array_values(array_filter(explode('/', ltrim($path, '/')))),
                'query' => $query,
            ],
            'description' => $meta['summary'].(isset($meta['description']) ? "\n\n".$meta['description'] : ''),
        ];

        if (isset($meta['body'])) {
            if ($isMultipart) {
                $request['body'] = [
                    'mode' => 'formdata',
                    'formdata' => array_map(
                        fn (string $name) => [
                            'key' => $name,
                            'type' => $name === 'file' ? 'file' : 'text',
                            'value' => $name === 'file' ? '' : '',
                        ],
                        array_keys($meta['body']['properties'] ?? []),
                    ),
                ];
            } else {
                $request['body'] = [
                    'mode' => 'raw',
                    'raw' => json_encode(
                        exampleFor($meta['body'], $schemas),
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                    ),
                    'options' => ['raw' => ['language' => 'json']],
                ];
            }
        }

        $folders[$tag][] = [
            'name' => $meta['summary'],
            'request' => $request,
            'response' => [],
        ];
    }
}

/**
 * Builds a request example from the request schema, preferring an explicitly
 * written `example` and falling back to a type-shaped placeholder. Only
 * required fields are emitted: an example that sends every optional field is
 * one nobody can read.
 *
 * @param  array<string,mixed>  $schema
 * @param  array<string,array<string,mixed>>  $schemas
 */
function exampleFor(array $schema, array $schemas): array
{
    if (isset($schema['$ref'])) {
        $name = basename((string) $schema['$ref']);
        $schema = $schemas[$name] ?? [];
    }

    $required = $schema['required'] ?? [];
    $out = [];

    foreach ($schema['properties'] ?? [] as $name => $property) {
        if (! in_array($name, $required, true) && ! isset($property['example'])) {
            continue;
        }

        $out[$name] = $property['example'] ?? placeholderFor($property);
    }

    return $out;
}

/** @param array<string,mixed> $property */
function placeholderFor(array $property): mixed
{
    if (isset($property['enum'][0])) {
        return $property['enum'][0];
    }

    $type = $property['type'] ?? 'string';
    $type = is_array($type) ? ($type[0] ?? 'string') : $type;

    return match ($type) {
        'integer' => 0,
        'number' => 0,
        'boolean' => false,
        'array' => [],
        'object' => new stdClass,
        default => match ($property['format'] ?? '') {
            'date' => '2026-07-25',
            'date-time' => '2026-07-25T09:30:00Z',
            'email' => 'person@example.com',
            default => '',
        },
    };
}

ksort($folders);

$collection = [
    'info' => [
        'name' => 'Finora API v1',
        'description' => "Generated by backend/scripts/build-api-docs.php from `php artisan route:list --json`.\n"
            ."Do not edit by hand — regenerate instead, or the collection will drift from the router.\n\n"
            ."Set `base_url`, then run Core → Sign in; the test script on that request stores `token`\n"
            ."and `workspace_id` automatically. Every other request reads them from the collection\n"
            ."variables, so no token is ever pasted into a saved request.\n\n"
            ."Amounts are ALWAYS integers in the currency's minor units — 35000 with currency TRY is\n"
            .'₺350.00, not ₺35,000. See docs/openapi.yaml, schema `Money`.',
        'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
    ],
    'variable' => [
        ['key' => 'base_url', 'value' => 'http://localhost:8000', 'type' => 'string'],
        ['key' => 'token', 'value' => '', 'type' => 'string'],
        ['key' => 'workspace_id', 'value' => '', 'type' => 'string'],
        ['key' => 'locale', 'value' => 'en', 'type' => 'string'],
    ],
    'item' => array_map(
        fn (string $tag) => [
            'name' => $tag,
            'description' => tagDescription($tag),
            'item' => $folders[$tag],
        ],
        array_keys($folders),
    ),
];

// The one request that has to do more than be sent: capturing the token here is
// what keeps every other request free of a pasted credential.
foreach ($collection['item'] as $i => $folder) {
    if ($folder['name'] !== 'Core') {
        continue;
    }

    foreach ($folder['item'] as $j => $item) {
        if (! in_array($item['request']['method'].' '.$item['request']['url']['raw'], [
            'POST {{base_url}}/api/v1/auth/login',
            'POST {{base_url}}/api/v1/auth/register',
        ], true)) {
            continue;
        }

        $collection['item'][$i]['item'][$j]['event'] = [[
            'listen' => 'test',
            'script' => [
                'type' => 'text/javascript',
                'exec' => [
                    'const body = pm.response.json();',
                    'if (body.data && body.data.token) {',
                    "    pm.collectionVariables.set('token', body.data.token);",
                    '}',
                    'const ws = (body.data && (body.data.workspace || (body.data.workspaces || [])[0]));',
                    'if (ws && ws.id) {',
                    "    pm.collectionVariables.set('workspace_id', ws.id);",
                    '}',
                ],
            ],
        ]];
    }
}

// ------------------------------------------------------------------- write out

$yaml = "# Generated by backend/scripts/build-api-docs.php — do not edit by hand.\n"
    ."# Regenerate with:  php backend/scripts/build-api-docs.php\n"
    ."# Verify without writing:  php backend/scripts/build-api-docs.php --check\n"
    .Yaml::dump($spec, 12, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);

$json = json_encode($collection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";

$operationCount = count($liveKeys);
$pathCount = count($paths);

if ($checkOnly) {
    printf("check passed: %d operations over %d paths, all described\n", $operationCount, $pathCount);
    exit(0);
}

file_put_contents($root.'/docs/openapi.yaml', $yaml);
file_put_contents($root.'/docs/finora.postman_collection.json', $json);

printf(
    "docs/openapi.yaml                     %d paths, %d operations, %d tags\n",
    $pathCount,
    $operationCount,
    count($tags),
);
printf(
    "docs/finora.postman_collection.json   %d folders, %d requests\n",
    count($folders),
    array_sum(array_map('count', $folders)),
);
