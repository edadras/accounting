<?php

declare(strict_types=1);

namespace Modules\MarketData\Console;

use Illuminate\Console\Command;
use Modules\MarketData\Actions\RefreshInvestmentPrices;

final class RefreshPricesCommand extends Command
{
    protected $signature = 'market:prices
        {--workspace= : Only reprice positions in this workspace}';

    protected $description = 'Fetch asset prices from the configured provider and reprice open positions';

    public function handle(): int
    {
        $action = new RefreshInvestmentPrices(
            workspaceId: $this->option('workspace') === null ? null : (string) $this->option('workspace'),
        );

        /** @var array{updated:int, snapshots:int, rejected:int} $result */
        $result = $this->laravel->call([$action, 'handle']);

        $this->info(sprintf(
            '%d position(s) repriced, %d snapshot(s) written, %d rejected.',
            $result['updated'],
            $result['snapshots'],
            $result['rejected'],
        ));

        return self::SUCCESS;
    }
}
