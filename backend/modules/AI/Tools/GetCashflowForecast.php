<?php

declare(strict_types=1);

namespace Modules\AI\Tools;

use Modules\AI\Actions\ForecastCashflow;
use Modules\AI\Contracts\Tool;

final class GetCashflowForecast implements Tool
{
    public function __construct(private readonly ForecastCashflow $forecast) {}

    public function name(): string
    {
        return 'get_cashflow_forecast';
    }

    public function description(): string
    {
        return 'Projected balance N days out from the recent trend, less obligations already committed.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 365],
            ],
        ];
    }

    public function run(array $arguments): array
    {
        $days = (int) ($arguments['days'] ?? 0);

        return $this->forecast->handle(max(0, min(365, $days)));
    }
}
