<?php

declare(strict_types=1);

namespace Modules\Alerts\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A refusal by the alerts module, carrying a machine-readable code and the HTTP
 * status the API should answer with.
 *
 * Same contract as LedgerException (docs/05-api-conventions.md).
 */
final class AlertException extends RuntimeException
{
    private function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function unknownChannel(string $channel): self
    {
        return new self('unknown_channel', "Unknown notification channel [{$channel}].", 422, ['channel' => $channel]);
    }

    public static function unknownRuleType(string $type): self
    {
        return new self('unknown_rule_type', "Unknown alert rule type [{$type}].", 422, ['type' => $type]);
    }

    /**
     * A channel configured to use a driver nobody has written yet.
     *
     * Loud on purpose: a notification that silently vanishes because a provider
     * was named but never implemented is worse than one that fails.
     */
    public static function driverNotImplemented(string $channel, string $driver): self
    {
        return new self(
            'channel_driver_not_implemented',
            "Channel [{$channel}] is configured for driver [{$driver}], which has no adapter.",
            500,
            ['channel' => $channel, 'driver' => $driver],
        );
    }

    public static function ruleNotFound(string $id): self
    {
        return new self('alert_rule_not_found', "Alert rule [{$id}] does not exist in this workspace.", 404);
    }

    public static function alertNotFound(string $id): self
    {
        return new self('alert_not_found', "Alert [{$id}] does not exist in this workspace.", 404);
    }

    public static function invalidQuietWindow(string $value): self
    {
        return new self('invalid_quiet_window', "[{$value}] is not a valid HH:MM time.", 422);
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
