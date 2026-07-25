<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\ServiceProvider;
use Modules\Family\Providers\FamilyServiceProvider;
use Modules\Recurring\Providers\RecurringServiceProvider;
use Modules\Security\Providers\SecurityServiceProvider;
use Modules\Security\Support\TwoFactorAuthenticator;
use Tests\Feature\LedgerTestCase;

/**
 * Base for the Security tests.
 *
 * The module is not in bootstrap/providers.php yet — that line lands when the
 * milestone is wired into the app — so these tests bring their own provider,
 * and with it their migrations and routes.
 */
abstract class SecurityTestCase extends LedgerTestCase
{
    /** @var list<class-string<ServiceProvider>> */
    private const MODULE_PROVIDERS = [
        SecurityServiceProvider::class,

        // The re-migration below rebuilds the whole schema from whatever this
        // application has registered, so the other modules that are not wired
        // up yet have to be here too — otherwise their tables would vanish for
        // the tests that run after these.
        FamilyServiceProvider::class,
        RecurringServiceProvider::class,
    ];

    private static bool $securityTablesMigrated = false;

    protected function setUp(): void
    {
        if (! self::$securityTablesMigrated) {
            // The whole suite shares one in-memory database, migrated once by
            // whichever test class ran first — and that class had no reason to
            // register this module, so its tables are missing. Asking for one
            // more migration run, from a class that does register it, is what
            // puts them there without touching bootstrap/providers.php.
            RefreshDatabaseState::$migrated = false;
            self::$securityTablesMigrated = true;
        }

        parent::setUp();
    }

    public function createApplication(): Application
    {
        $app = parent::createApplication();

        foreach (self::MODULE_PROVIDERS as $provider) {
            if ($app->getProvider($provider) === null) {
                $app->register($provider);
            }
        }

        return $app;
    }

    /**
     * Puts a user in the state the enrolment endpoints would leave them in,
     * without going through them — tests of the sign-in flow should not have to
     * hold an authenticated session first.
     *
     * @return array{secret: string, recovery_codes: list<string>}
     */
    protected function enrollInTwoFactor(User $user): array
    {
        $secret = app(TwoFactorAuthenticator::class)->generateSecret();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
        ])->save();

        return ['secret' => $secret, 'recovery_codes' => $user->regenerateRecoveryCodes()];
    }

    protected function codeFor(string $secret): string
    {
        return app(TwoFactorAuthenticator::class)->currentCode($secret);
    }

    /**
     * A code that is wrong but well-formed.
     *
     * Numerically next to the real one, which — because codes are a hash of the
     * time step, not a counter — is not the code for any nearby moment either.
     */
    protected function wrongCodeFor(string $secret): string
    {
        $code = (int) $this->codeFor($secret);

        return str_pad((string) (($code + 1) % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /** @return array{challenge: string, expires_at: string} */
    protected function loginExpectingChallenge(User $user, string $password = 'password123'): array
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => $password,
        ])->assertOk();

        $response->assertJsonPath('data.two_factor_required', true);
        $this->assertNull($response->json('data.token'), 'Login must not hand out a token when 2FA is on.');

        return [
            'challenge' => $response->json('data.challenge'),
            'expires_at' => $response->json('data.expires_at'),
        ];
    }
}
