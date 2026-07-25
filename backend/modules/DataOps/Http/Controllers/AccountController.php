<?php

declare(strict_types=1);

namespace Modules\DataOps\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Modules\Core\Http\Concerns\ResolvesCurrentUser;
use Modules\DataOps\Actions\CancelAccountDeletion;
use Modules\DataOps\Actions\ScheduleAccountDeletion;
use Modules\DataOps\Exceptions\DataOpsException;
use Modules\DataOps\Support\AccountDeletion;

/**
 * The right to be deleted (docs/07-security.md §8).
 *
 * Nothing here erases anything: it schedules the erasure and states when it
 * happens, so a person who taps the wrong button — or whose phone is briefly in
 * someone else's hands — has the whole grace period to undo it.
 */
final class AccountController
{
    use ResolvesCurrentUser;

    public function destroy(Request $request, ScheduleAccountDeletion $schedule): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
        ]);

        $user = $this->currentUser($request);

        // Deleting an account is the one operation a stolen token must not be
        // enough for on its own.
        if (! Hash::check($data['password'], (string) $user->password)) {
            throw DataOpsException::incorrectPassword();
        }

        $graceDays = (int) config('dataops.deletion.grace_days');
        $purgeAfter = $schedule->handle($user);

        return response()->json([
            'data' => [
                'status' => 'deletion_scheduled',
                'grace_days' => $graceDays,
                'requested_at' => AccountDeletion::requestedAt($user)?->toIso8601String(),
                'purge_after' => $purgeAfter->toIso8601String(),
            ],
        ], 202);
    }

    public function restore(Request $request, CancelAccountDeletion $cancel): JsonResponse
    {
        $cancel->handle($this->currentUser($request));

        return response()->json([
            'data' => ['status' => 'active'],
        ]);
    }
}
