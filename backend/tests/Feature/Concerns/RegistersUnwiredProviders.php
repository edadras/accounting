<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\ServiceProvider;
use Modules\Family\Providers\FamilyServiceProvider;
use Modules\Recurring\Providers\RecurringServiceProvider;

/**
 * Registers the Family and Recurring providers for a test.
 *
 * They are not in bootstrap/providers.php yet — that line lands when the
 * milestone is wired into the app — so these tests bring their own migrations
 * and routes rather than depending on the wiring having happened.
 */
trait RegistersUnwiredProviders
{
    /** @var list<class-string<ServiceProvider>> */
    private const UNWIRED_PROVIDERS = [
        FamilyServiceProvider::class,
        RecurringServiceProvider::class,
    ];

    private static bool $unwiredTablesMigrated = false;

    protected function setUp(): void
    {
        if (! self::$unwiredTablesMigrated) {
            // The whole suite shares one in-memory database, migrated once by
            // whichever test class ran first — and that class had no reason to
            // register these providers, so their tables are missing. Asking for
            // one more migration run, from a class that does register them, is
            // what puts the tables there without touching bootstrap/providers.php.
            RefreshDatabaseState::$migrated = false;
            self::$unwiredTablesMigrated = true;
        }

        parent::setUp();
    }

    public function createApplication(): Application
    {
        $app = parent::createApplication();

        foreach (self::UNWIRED_PROVIDERS as $provider) {
            if ($app->getProvider($provider) === null) {
                $app->register($provider);
            }
        }

        return $app;
    }
}
