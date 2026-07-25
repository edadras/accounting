<?php

declare(strict_types=1);

namespace Modules\MarketData\Console;

use Illuminate\Console\Command;
use Modules\MarketData\Actions\RefreshExchangeRates;

final class RefreshRatesCommand extends Command
{
    protected $signature = 'market:rates
        {--base= : Fetch against this base currency instead of market.base}
        {--quote=* : Only these quote currencies}';

    protected $description = 'Fetch exchange rates from the configured provider and append them';

    public function handle(): int
    {
        /** @var list<string> $quotes */
        $quotes = (array) $this->option('quote');

        $action = new RefreshExchangeRates(
            base: $this->option('base') === null ? null : (string) $this->option('base'),
            quotes: $quotes === [] ? null : $quotes,
        );

        /** @var array{stored:int, rejected:int, base:string} $result */
        $result = $this->laravel->call([$action, 'handle']);

        $this->info(sprintf(
            'Base %s: %d rate(s) stored, %d rejected.',
            $result['base'],
            $result['stored'],
            $result['rejected'],
        ));

        return self::SUCCESS;
    }
}
