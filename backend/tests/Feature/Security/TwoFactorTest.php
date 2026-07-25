<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Audit\Models\AuditLog;
use Modules\Security\Models\TwoFactorChallenge;
use PHPUnit\Framework\Attributes\Test;

/**
 * A stolen password is the ordinary way an account is lost. The second factor
 * is what stands between that and someone reading — and moving — the money, so
 * these tests pin the properties that make it worth having: it is really in
 * force at sign-in, each way past it works exactly once, and it cannot be
 * switched off by whoever happens to be holding the session.
 */
final class TwoFactorTest extends SecurityTestCase
{
    use RefreshDatabase;

    #[Test]
    public function enabling_and_confirming_makes_login_answer_with_a_challenge(): void
    {
        $user = $this->makeUser('enrol@example.test');

        Sanctum::actingAs($user);

        $enable = $this->postJson('/api/v1/auth/2fa/enable')->assertOk();
        $secret = $enable->json('data.secret');

        $this->assertNotEmpty($secret);
        $this->assertStringStartsWith('otpauth://totp/', $enable->json('data.otpauth_uri'));
        $this->assertStringContainsString('secret='.$secret, $enable->json('data.otpauth_uri'));

        // An unconfirmed secret changes nothing: the user may never have got it
        // into their authenticator, and locking them out would be worse.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'enrol@example.test',
            'password' => 'password123',
        ])->assertOk()->assertJsonPath('data.two_factor_required', null);

        $confirm = $this->postJson('/api/v1/auth/2fa/confirm', [
            'code' => $this->codeFor($secret),
        ])->assertOk();

        $codes = $confirm->json('data.recovery_codes');
        $this->assertCount(8, $codes);
        $this->assertSame($codes, array_unique($codes));

        $this->loginExpectingChallenge($user->refresh());

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.two_factor_enabled']);
    }

    #[Test]
    public function a_correct_code_exchanges_the_challenge_for_a_working_token(): void
    {
        $user = $this->makeUser('totp@example.test');
        $workspace = $this->makeWorkspace($user);
        ['secret' => $secret] = $this->enrollInTwoFactor($user);

        $challenge = $this->loginExpectingChallenge($user);

        $verified = $this->postJson('/api/v1/auth/2fa/verify', [
            'challenge' => $challenge['challenge'],
            'code' => $this->codeFor($secret),
        ])->assertOk();

        $token = $verified->json('data.token');
        $this->assertNotEmpty($token);
        $this->assertSame($workspace->id, $verified->json('data.workspaces.0.id'));

        $this->withToken($token)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'totp@example.test');
    }

    #[Test]
    public function a_wrong_code_is_refused_and_recorded(): void
    {
        $user = $this->makeUser('wrong@example.test');
        ['secret' => $secret] = $this->enrollInTwoFactor($user);

        $challenge = $this->loginExpectingChallenge($user);

        $this->postJson('/api/v1/auth/2fa/verify', [
            'challenge' => $challenge['challenge'],
            'code' => $this->wrongCodeFor($secret),
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_two_factor_code');

        // Nobody is signed in when this happens, so the account it concerns is
        // the subject rather than the actor.
        $failure = AuditLog::query()
            ->where('action', 'auth.two_factor_failed')
            ->where('subject_type', 'user')
            ->where('subject_id', (string) $user->id)
            ->first();

        $this->assertNotNull($failure, 'A failed second factor is exactly what a breach review looks for.');
        $after = $failure->after;
        $this->assertIsArray($after);
        $this->assertSame('login', $after['stage']);

        // And the challenge still has not become a token.
        $this->withToken('nonsense')->getJson('/api/v1/me')->assertUnauthorized();
    }

    #[Test]
    public function a_recovery_code_works_once_and_not_twice(): void
    {
        $user = $this->makeUser('recovery@example.test');
        ['recovery_codes' => $codes] = $this->enrollInTwoFactor($user);
        $code = $codes[0];

        $first = $this->loginExpectingChallenge($user);
        $this->postJson('/api/v1/auth/2fa/verify', [
            'challenge' => $first['challenge'],
            'code' => $code,
        ])->assertOk();

        $this->assertCount(7, $user->refresh()->recoveryCodes());

        $second = $this->loginExpectingChallenge($user);
        $this->postJson('/api/v1/auth/2fa/verify', [
            'challenge' => $second['challenge'],
            'code' => $code,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_two_factor_code');

        // The rest of the sheet is untouched.
        $this->postJson('/api/v1/auth/2fa/verify', [
            'challenge' => $second['challenge'],
            'code' => $codes[1],
        ])->assertOk();
    }

    #[Test]
    public function a_spent_or_expired_challenge_is_refused(): void
    {
        $user = $this->makeUser('replay@example.test');
        ['secret' => $secret] = $this->enrollInTwoFactor($user);

        $challenge = $this->loginExpectingChallenge($user);
        $this->postJson('/api/v1/auth/2fa/verify', [
            'challenge' => $challenge['challenge'],
            'code' => $this->codeFor($secret),
        ])->assertOk();

        // Replaying it — even with a code that is currently valid — buys nothing.
        $this->postJson('/api/v1/auth/2fa/verify', [
            'challenge' => $challenge['challenge'],
            'code' => $this->codeFor($secret),
        ])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'two_factor_challenge_invalid');

        $stale = $this->loginExpectingChallenge($user);

        TwoFactorChallenge::query()
            ->where('token_hash', TwoFactorChallenge::hashToken($stale['challenge']))
            ->firstOrFail()
            ->forceFill(['expires_at' => now()->subMinute()])
            ->save();

        $this->postJson('/api/v1/auth/2fa/verify', [
            'challenge' => $stale['challenge'],
            'code' => $this->codeFor($secret),
        ])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'two_factor_challenge_invalid');

        // An invented challenge answers the same, so none of this can be probed.
        $this->postJson('/api/v1/auth/2fa/verify', [
            'challenge' => str_repeat('x', 64),
            'code' => $this->codeFor($secret),
        ])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'two_factor_challenge_invalid');
    }

    #[Test]
    public function guessing_is_rate_limited(): void
    {
        $user = $this->makeUser('bruteforce@example.test');
        ['secret' => $secret] = $this->enrollInTwoFactor($user);

        $challenge = $this->loginExpectingChallenge($user);

        for ($attempt = 0; $attempt < TwoFactorChallenge::MAX_ATTEMPTS; $attempt++) {
            $this->postJson('/api/v1/auth/2fa/verify', [
                'challenge' => $challenge['challenge'],
                'code' => $this->wrongCodeFor($secret),
            ])->assertStatus($attempt === TwoFactorChallenge::MAX_ATTEMPTS - 1 ? 401 : 422);
        }

        // Spent attempts end the sign-in rather than leaving something to keep
        // guessing against — even the right code no longer opens it.
        $this->postJson('/api/v1/auth/2fa/verify', [
            'challenge' => $challenge['challenge'],
            'code' => $this->codeFor($secret),
        ])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'two_factor_challenge_invalid');
    }

    #[Test]
    public function it_cannot_be_switched_off_without_a_password_or_a_code(): void
    {
        $user = $this->makeUser('sticky@example.test');
        $this->enrollInTwoFactor($user);

        Sanctum::actingAs($user->refresh());

        $this->postJson('/api/v1/auth/2fa/disable')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'two_factor_confirmation_required');

        $this->postJson('/api/v1/auth/2fa/disable', ['password' => 'not-the-password'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'two_factor_confirmation_required');

        $this->assertTrue($user->refresh()->hasTwoFactorEnabled());
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.two_factor_failed']);

        // Still on: login answers with a challenge, not a token.
        $this->loginExpectingChallenge($user->refresh());
    }

    #[Test]
    public function the_password_or_a_current_code_switches_it_off(): void
    {
        $withPassword = $this->makeUser('bypassword@example.test');
        $this->enrollInTwoFactor($withPassword);

        Sanctum::actingAs($withPassword->refresh());
        $this->postJson('/api/v1/auth/2fa/disable', ['password' => 'password123'])
            ->assertOk()
            ->assertJsonPath('data.two_factor_enabled', false);

        $this->assertFalse($withPassword->refresh()->hasTwoFactorEnabled());
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.two_factor_disabled']);

        $withCode = $this->makeUser('bycode@example.test');
        ['secret' => $secret] = $this->enrollInTwoFactor($withCode);

        Sanctum::actingAs($withCode->refresh());
        $this->postJson('/api/v1/auth/2fa/disable', ['code' => $this->codeFor($secret)])
            ->assertOk();

        $this->assertFalse($withCode->refresh()->hasTwoFactorEnabled());

        // And the password alone signs them in again.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'bycode@example.test',
            'password' => 'password123',
        ])->assertOk()->assertJsonPath('data.two_factor_required', null);
    }

    #[Test]
    public function turning_it_off_retires_any_half_finished_sign_in(): void
    {
        $user = $this->makeUser('abandoned@example.test');
        ['secret' => $secret] = $this->enrollInTwoFactor($user);

        $challenge = $this->loginExpectingChallenge($user);

        Sanctum::actingAs($user->refresh());
        $this->postJson('/api/v1/auth/2fa/disable', ['password' => 'password123'])->assertOk();

        $this->postJson('/api/v1/auth/2fa/verify', [
            'challenge' => $challenge['challenge'],
            'code' => $this->codeFor($secret),
        ])->assertUnauthorized();
    }
}
