<?php

declare(strict_types=1);

namespace Modules\Alerts\Contracts;

use Carbon\CarbonImmutable;
use Modules\Alerts\Models\AlertRule;
use Modules\Alerts\Support\AlertCandidate;

/**
 * Turns the state of one domain into the alerts it would justify right now.
 *
 * A scanner is pure: it reads, it decides nothing about channels or timing, and
 * it may safely produce the same candidate on every run — deduplication is the
 * dispatcher's job, not the scanner's.
 */
interface AlertScanner
{
    public function type(): string;

    /** @return iterable<AlertCandidate> */
    public function scan(AlertRule $rule, CarbonImmutable $now): iterable;
}
