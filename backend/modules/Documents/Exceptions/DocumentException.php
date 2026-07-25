<?php

declare(strict_types=1);

namespace Modules\Documents\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A refusal by the documents module, carrying a machine-readable code and the
 * HTTP status the API should answer with.
 *
 * The code stays English and stable; the message the user sees is translated
 * client-side from that code (docs/05-api-conventions.md).
 */
final class DocumentException extends RuntimeException
{
    private function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function forbiddenAttachableType(string $type, array $allowed): self
    {
        return new self(
            'forbidden_attachable_type',
            "Documents cannot be attached to [{$type}].",
            422,
            ['given' => $type, 'allowed' => $allowed],
        );
    }

    public static function attachableNotFound(string $type, string $id): self
    {
        return new self(
            'attachable_not_found',
            "No {$type} [{$id}] in this workspace.",
            404,
            ['type' => $type, 'id' => $id],
        );
    }

    public static function storageFailed(string $originalName): self
    {
        return new self(
            'document_storage_failed',
            "Could not store [{$originalName}].",
            500,
            ['original_name' => $originalName],
        );
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

    /**
     * bootstrap/app.php maps only LedgerException, so this one renders itself;
     * the wire format stays identical either way.
     */
    public function render(Request $request): ?JsonResponse
    {
        return $request->expectsJson() ? $this->toResponse() : null;
    }
}
