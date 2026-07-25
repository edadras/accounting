<?php

declare(strict_types=1);

namespace Modules\Sync\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A refusal by the sync engine, carrying a machine-readable code and the HTTP
 * status the API should answer with.
 *
 * The code stays English and stable; the message the user sees is translated
 * client-side from that code (docs/05-api-conventions.md).
 */
final class SyncException extends RuntimeException
{
    /** @param  array<string, mixed>  $details */
    private function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    /** @param  list<string>  $allowed */
    public static function unknownEntity(string $entity, array $allowed): self
    {
        return new self(
            'unknown_entity',
            "Entity [{$entity}] is not syncable.",
            422,
            ['entity' => $entity, 'allowed' => $allowed],
        );
    }

    public static function misconfiguredEntity(string $entity, string $class): self
    {
        return new self(
            'misconfigured_entity',
            "Entity [{$entity}] is mapped to [{$class}], which is not an Eloquent model.",
            500,
            ['entity' => $entity],
        );
    }

    /** @param  list<string>  $missing */
    public static function incompletePayload(string $entity, array $missing): self
    {
        return new self(
            'incomplete_payload',
            'The payload is missing fields the server cannot invent: '.implode(', ', $missing).'.',
            422,
            ['entity' => $entity, 'missing' => $missing],
        );
    }

    public static function deviceNotFound(string $id): self
    {
        return new self('device_not_found', "Device [{$id}] is not registered to this user.", 404);
    }

    public static function deviceRevoked(string $id): self
    {
        return new self('device_revoked', "Device [{$id}] has been revoked and may no longer sync.", 403);
    }

    public static function invalidCursor(): self
    {
        return new self('invalid_cursor', 'The cursor is not one this server issued.', 422);
    }

    public static function invalidTimestamp(string $value): self
    {
        return new self('invalid_timestamp', "[{$value}] is not an ISO-8601 timestamp.", 422);
    }

    public function toResponse(): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
                'details' => $this->details,
                'request_id' => request()->header('X-Request-Id'),
            ],
        ], $this->status);
    }
}
