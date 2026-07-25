<?php

declare(strict_types=1);

namespace Modules\Core\Exceptions;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A refusal by the membership rules, carrying a stable machine-readable code.
 *
 * Implements Responsable so the framework renders it without needing a line in
 * `bootstrap/app.php`, matching the other modules.
 */
final class MembershipException extends RuntimeException implements Responsable
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

    public static function alreadyMember(string $email): self
    {
        return new self('already_member', "{$email} is already a member of this workspace.", 409);
    }

    public static function alreadyInvited(string $email): self
    {
        return new self('already_invited', "{$email} already has a pending invitation.", 409);
    }

    public static function invitationNotFound(): self
    {
        // Deliberately the same answer for a wrong, revoked or expired token:
        // a different one would let someone probe which tokens exist.
        return new self('invitation_invalid', 'This invitation is not valid.', 404);
    }

    public static function invitationNotForYou(): self
    {
        return new self(
            'invitation_email_mismatch',
            'This invitation was issued to a different email address.',
            403,
        );
    }

    public static function cannotRemoveOwner(): self
    {
        return new self('cannot_remove_owner', 'The workspace owner cannot be removed.', 422);
    }

    public static function cannotChangeOwnerRole(): self
    {
        return new self('cannot_change_owner_role', "The owner's role cannot be changed.", 422);
    }

    public static function cannotInviteOwnerRole(): self
    {
        return new self(
            'cannot_invite_as_owner',
            'A workspace has exactly one owner; invite an admin instead.',
            422,
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
