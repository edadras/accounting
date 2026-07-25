<?php

declare(strict_types=1);

namespace Modules\Core\Http\Concerns;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

/**
 * Turns `$request->user()` into a `User` instead of a `?User`.
 *
 * Every route that reaches these controllers sits behind `auth:sanctum`, so a
 * null user is a routing mistake rather than something a client can provoke —
 * and it should surface as a 401 at the door, not as a null dereference deep
 * inside a handler.
 */
trait ResolvesCurrentUser
{
    protected function currentUser(Request $request): User
    {
        return $request->user() ?? throw new AuthenticationException;
    }
}
