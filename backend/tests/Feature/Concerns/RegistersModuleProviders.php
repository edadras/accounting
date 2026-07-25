<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use Illuminate\Foundation\Application;
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
    /**
     * The providers this trait brings with it, in the order they are booted.
     *
     * Deliberately left without a @var: a trait constant's PHPDoc is resolved
     * in the namespace of the class using the trait rather than this file's, so
     * any short class name in it would be read against the wrong namespace —
     * and the literal class-strings below are the more precise type anyway.
     */
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
