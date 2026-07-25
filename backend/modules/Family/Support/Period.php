<?php

declare(strict_types=1);

namespace Modules\Family\Support;

use Carbon\CarbonImmutable;
use Modules\Family\Exceptions\FamilyException;

/**
 * A YYYY-MM month, which is the unit both an allowance and a spending cap are
 * measured in.
 */
final readonly class Period
{
    private function __construct(public string $key, public CarbonImmutable $start, public CarbonImmutable $end) {}

    public static function of(string $period): self
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            throw FamilyException::invalidPeriod($period);
        }

        $start = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $period.'-01 00:00:00');

        return new self($period, $start->startOfMonth(), $start->endOfMonth());
    }

    public static function containing(?\DateTimeInterface $at = null): self
    {
        $moment = $at === null ? CarbonImmutable::now() : CarbonImmutable::instance(
            $at instanceof \DateTimeImmutable ? $at : \DateTimeImmutable::createFromInterface($at)
        );

        return self::of($moment->format('Y-m'));
    }
}
