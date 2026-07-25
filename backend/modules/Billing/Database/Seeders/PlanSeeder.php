<?php

declare(strict_types=1);

namespace Modules\Billing\Database\Seeders;

use Modules\Billing\Models\Plan;
use Modules\Billing\Support\PlanRegistry;

/**
 * Projects Config/plans.php onto the `plans` table.
 *
 * Idempotent and overwriting: running it again after a config change brings the
 * catalogue back in step rather than leaving a second row behind.
 */
final class PlanSeeder
{
    public function seed(): void
    {
        $rank = 0;

        foreach (PlanRegistry::all() as $code => $definition) {
            Plan::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name' => $definition['name'],
                    'price' => (int) $definition['price'],
                    'currency' => $definition['currency'],
                    'interval' => $definition['interval'],
                    'features' => [
                        'limits' => $definition['limits'] ?? [],
                        'flags' => $definition['flags'] ?? [],
                    ],
                    'rank' => $rank++,
                    'is_public' => true,
                ],
            );
        }
    }
}
