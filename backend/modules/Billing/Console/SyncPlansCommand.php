<?php

declare(strict_types=1);

namespace Modules\Billing\Console;

use Illuminate\Console\Command;
use Modules\Billing\Database\Seeders\PlanSeeder;

final class SyncPlansCommand extends Command
{
    protected $signature = 'billing:sync-plans';

    protected $description = 'Project the plan catalogue in Config/plans.php onto the plans table.';

    public function handle(PlanSeeder $seeder): int
    {
        $seeder->seed();

        $this->info('Plan catalogue synced.');

        return self::SUCCESS;
    }
}
