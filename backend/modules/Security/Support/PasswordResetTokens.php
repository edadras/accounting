<?php

declare(strict_types=1);

namespace Modules\Security\Support;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issues and spends password reset tokens in Laravel's `password_reset_tokens`
 * table.
 *
 * Only the hash is stored: a reset link is a temporary password, and a leaked
 * database must not hand anyone a working one. A token is spent the moment it
 * matches, so a replayed link resets nothing.
 */
final class PasswordResetTokens
{
    public function issue(User $user): string
    {
        $token = Str::random(64);

        // One live token per address — asking again invalidates the last link.
        DB::table($this->table())->updateOrInsert(
            ['email' => $user->email],
            ['token' => $this->hash($token), 'created_at' => now()],
        );

        return $token;
    }

    public function consume(User $user, string $token): bool
    {
        $row = DB::table($this->table())->where('email', $user->email)->first();

        if ($row === null || ! hash_equals((string) $row->token, $this->hash($token))) {
            return false;
        }

        DB::table($this->table())->where('email', $user->email)->delete();

        return $row->created_at !== null
            && Carbon::parse($row->created_at)->addMinutes($this->lifetimeMinutes())->isFuture();
    }

    public function forget(User $user): void
    {
        DB::table($this->table())->where('email', $user->email)->delete();
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function table(): string
    {
        return (string) config('auth.passwords.users.table', 'password_reset_tokens');
    }

    private function lifetimeMinutes(): int
    {
        return (int) config('auth.passwords.users.expire', 60);
    }
}
