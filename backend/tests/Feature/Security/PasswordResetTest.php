<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Audit\Models\AuditLog;
use Modules\Security\Notifications\PasswordResetRequested;
use PHPUnit\Framework\Attributes\Test;

/**
 * Password reset is the one door that opens without a password, so it is the
 * one an attacker tries first: to find out who banks here, to replay a link
 * they found, or to keep the session they already stole.
 */
final class PasswordResetTest extends SecurityTestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_known_and_an_unknown_address_get_the_same_answer(): void
    {
        Notification::fake();

        $user = $this->makeUser('registered@example.test');

        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'registered@example.test']);
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'stranger@example.test']);

        // Anything that differs here is a membership oracle: feed it a list of
        // addresses and it says which of them have money on this system.
        $this->assertSame($known->status(), $unknown->status());
        $this->assertSame(200, $known->status());
        $this->assertSame($known->json(), $unknown->json());

        Notification::assertSentTo($user, PasswordResetRequested::class);
        Notification::assertSentTimes(PasswordResetRequested::class, 1);
    }

    #[Test]
    public function a_reset_token_works_once_and_takes_every_existing_session_with_it(): void
    {
        $user = $this->makeUser('forgetful@example.test');

        $oldToken = $this->postJson('/api/v1/auth/login', [
            'email' => 'forgetful@example.test',
            'password' => 'password123',
        ])->assertOk()->json('data.token');

        $this->withToken($oldToken)->getJson('/api/v1/me')->assertOk();

        $token = $this->requestResetToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'forgetful@example.test',
            'token' => $token,
            'password' => 'a-brand-new-password',
        ])->assertOk()->assertJsonPath('data.status', 'password_reset');

        // Whoever prompted the reset may be holding a stolen token; a new
        // password that leaves it working has reset nothing.
        $this->assertSame(0, $user->tokens()->count());

        // The guard caches the user it resolved earlier in this test; a real
        // request would arrive at a fresh container.
        $this->app['auth']->forgetGuards();

        $this->withToken($oldToken)->getJson('/api/v1/me')->assertUnauthorized();
        $this->flushHeaders();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'forgetful@example.test',
            'password' => 'a-brand-new-password',
        ])->assertOk();

        // Single use — the link cannot be replayed.
        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'forgetful@example.test',
            'token' => $token,
            'password' => 'another-password',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_reset_token');

        $this->assertSame(
            1,
            AuditLog::query()->where('action', 'auth.password_reset')->where('user_id', $user->id)->count(),
        );
    }

    #[Test]
    public function an_expired_token_is_refused(): void
    {
        $user = $this->makeUser('slow@example.test');
        $token = $this->requestResetToken($user);

        DB::table('password_reset_tokens')
            ->where('email', $user->email)
            ->update(['created_at' => now()->subMinutes((int) config('auth.passwords.users.expire') + 5)]);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'slow@example.test',
            'token' => $token,
            'password' => 'too-late-for-this',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_reset_token');

        $this->postJson('/api/v1/auth/login', [
            'email' => 'slow@example.test',
            'password' => 'password123',
        ])->assertOk();
    }

    #[Test]
    public function a_wrong_token_is_refused_and_the_stored_one_is_never_the_plaintext(): void
    {
        $user = $this->makeUser('hashed@example.test');
        $token = $this->requestResetToken($user);

        // A leaked table must not hand anyone a working link.
        $this->assertDatabaseMissing('password_reset_tokens', ['token' => $token]);
        $this->assertDatabaseHas('password_reset_tokens', ['token' => hash('sha256', $token)]);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'hashed@example.test',
            'token' => str_repeat('z', 64),
            'password' => 'guessed-my-way-in',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_reset_token');

        $this->postJson('/api/v1/auth/login', [
            'email' => 'hashed@example.test',
            'password' => 'password123',
        ])->assertOk();
    }

    /** Pulls the one plaintext copy of the token out of the notification it left in. */
    private function requestResetToken(User $user): string
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();

        $token = null;

        Notification::assertSentTo($user, PasswordResetRequested::class, function ($notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });

        $this->assertNotNull($token);

        return $token;
    }
}
