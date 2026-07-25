<?php

declare(strict_types=1);

namespace Modules\AI\Support;

use Carbon\CarbonImmutable;

final readonly class ResolvedDate
{
    public function __construct(
        public CarbonImmutable $date,
        public string $expression,
        public int $offset,
        public int $length,
    ) {}
}
