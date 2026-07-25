<?php

declare(strict_types=1);

namespace Modules\DataOps\Exceptions;

use DateTimeInterface;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A refusal by the data-operations rules, carrying a stable machine-readable
 * code.
 *
 * Implements Responsable so the framework renders it without needing a line in
 * `bootstrap/app.php`, matching Modules\Core\Exceptions\MembershipException.
 */
final class DataOpsException extends RuntimeException implements Responsable
{
    /** @param array<string, mixed> $details */
    private function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function exportForbidden(): self
    {
        return new self(
            'export_forbidden',
            'Only an owner or an admin may export a workspace.',
            403,
        );
    }

    public static function exportNotReady(string $status): self
    {
        return new self(
            'export_not_ready',
            'This export is not ready to download.',
            409,
            ['status' => $status],
        );
    }

    public static function exportExpired(): self
    {
        return new self(
            'export_expired',
            'This export has expired. Request a new one.',
            410,
        );
    }

    public static function incorrectPassword(): self
    {
        return new self(
            'incorrect_password',
            'The password does not match this account.',
            403,
        );
    }

    public static function deletionAlreadyScheduled(DateTimeInterface $purgeAfter): self
    {
        return new self(
            'deletion_already_scheduled',
            'This account is already scheduled for deletion.',
            409,
            ['purge_after' => $purgeAfter->format(DATE_ATOM)],
        );
    }

    public static function deletionNotScheduled(): self
    {
        return new self(
            'deletion_not_scheduled',
            'This account is not scheduled for deletion.',
            422,
        );
    }

    public static function deletionWindowClosed(DateTimeInterface $purgeAfter): self
    {
        return new self(
            'deletion_window_closed',
            'The window for cancelling this deletion has closed.',
            410,
            ['purge_after' => $purgeAfter->format(DATE_ATOM)],
        );
    }

    public static function accountPendingDeletion(DateTimeInterface $purgeAfter): self
    {
        return new self(
            'account_pending_deletion',
            'This account is scheduled for deletion. Cancel the deletion from a signed-in device to use it again.',
            403,
            ['purge_after' => $purgeAfter->format(DATE_ATOM)],
        );
    }

    public function toResponse($request): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
                'details' => $this->details,
                'request_id' => $request->header('X-Request-Id'),
            ],
        ], $this->status);
    }
}
