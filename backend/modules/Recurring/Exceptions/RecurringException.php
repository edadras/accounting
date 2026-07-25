<?php

declare(strict_types=1);

namespace Modules\Recurring\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A refusal by the recurring module, carrying a machine-readable code and the
 * HTTP status the API should answer with.
 *
 * The code stays English and stable; the message the user sees is translated
 * client-side from that code (docs/05-api-conventions.md).
 */
final class RecurringException extends RuntimeException
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

    public static function ruleNotFound(string $id): self
    {
        return new self('recurring_rule_not_found', "Recurring rule [{$id}] does not exist in this workspace.", 404);
    }

    public static function unknownFrequency(string $frequency): self
    {
        return new self('unknown_frequency', "Unknown recurrence frequency [{$frequency}].");
    }

    /** @param  list<string>  $missing */
    public static function incompleteTemplate(string $ruleId, array $missing): self
    {
        return new self(
            'incomplete_recurring_template',
            "Recurring rule [{$ruleId}] cannot be posted; its template is missing: ".implode(', ', $missing).'.',
            422,
            ['rule_id' => $ruleId, 'missing' => $missing],
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
}
