<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Modules\Documents\Providers\DocumentsServiceProvider;
use Modules\Search\Providers\SearchServiceProvider;

/**
 * Registers the Documents and Search providers for a test.
 *
 * They are not in bootstrap/providers.php yet — that line lands when the
 * milestone is wired into the app — so these tests bring their own migrations
 * and routes rather than depending on the wiring having happened.
 */
trait RegistersModuleProviders
{
    /** @var list<class-string<ServiceProvider>> */
    private const MODULE_PROVIDERS = [
        DocumentsServiceProvider::class,
        SearchServiceProvider::class,
    ];

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
}
