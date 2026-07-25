<?php

declare(strict_types=1);

namespace Modules\DataOps\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Modules\DataOps\Exceptions\DataOpsException;
use Modules\DataOps\Support\AccountDeletion;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a new sign-in while an account is waiting to be purged.
 *
 * Sign-in lives in routes/api.php, which this module does not own, so the check
 * is pushed onto the `api` middleware group by DataOpsServiceProvider rather
 * than added to that route.
 *
 * The password is verified before refusing. Answering "scheduled for deletion"
 * to a bare email would tell anyone who guessed an address that it exists and
 * what state it is in; behind a correct password it only tells the account's
 * own owner why their token stopped working.
 */
final class RefuseScheduledAccounts
{
    private const LOGIN_PATH = 'api/v1/auth/login';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST') || ! $request->is(self::LOGIN_PATH)) {
            return $next($request);
        }

        $email = $request->input('email');
        $password = $request->input('password');

        if (! is_string($email) || ! is_string($password)) {
            return $next($request);
        }

        $user = User::query()->where('email', mb_strtolower(trim($email)))->first();

        if ($user === null || ! AccountDeletion::isScheduled($user)) {
            return $next($request);
        }

        if (! Hash::check($password, (string) $user->password)) {
            return $next($request);
        }

        return DataOpsException::accountPendingDeletion(
            AccountDeletion::purgeAfter($user) ?? now()->toImmutable(),
        )->toResponse($request);
    }
}
