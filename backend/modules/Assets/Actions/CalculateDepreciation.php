<?php

declare(strict_types=1);

namespace Modules\Assets\Actions;

use App\Core\Money\Money;
use InvalidArgumentException;
use Modules\Assets\Exceptions\AssetException;
use Modules\Assets\Models\Asset;

/**
 * Builds an asset's depreciation schedule, year by year.
 *
 * Two properties this class exists to guarantee:
 *   1. Book value never falls below the salvage value. An asset written down
 *      past what it could be sold for is a fiction.
 *   2. The charges over the full useful life sum to exactly
 *      (purchase price − salvage value). Not approximately — a schedule that
 *      loses three minor units a year leaves the books unexplainable.
 *
 * Straight-line gets there by allocating the depreciable base with the same
 * remainder-distributing split the ledger uses for shared expenses.
 * Declining-balance gets there by charging whatever is left down to salvage in
 * the final year, which is also how an accountant would close the schedule out.
 */
final class CalculateDepreciation
{
    private const RATE_SCALE = 1_000_000;

    /**
     * @return list<array{
     *   year:int, opening:Money, depreciation:Money, accumulated:Money, closing:Money
     * }>
     */
    public function handle(Asset $asset): array
    {
        $this->assertUsable($asset);

        $currency = $asset->priceCurrency();

        if (! $asset->depreciates()) {
            return [];
        }

        $charges = $asset->depreciation_method === Asset::DEPRECIATION_LINEAR
            ? $this->linearCharges($asset)
            : $this->decliningCharges($asset);

        $opening = (int) $asset->purchase_price;
        $accumulated = 0;
        $rows = [];

        foreach ($charges as $index => $charge) {
            $accumulated += $charge;

            $rows[] = [
                'year' => $index + 1,
                'opening' => Money::of($opening, $currency),
                'depreciation' => Money::of($charge, $currency),
                'accumulated' => Money::of($accumulated, $currency),
                'closing' => Money::of($opening - $charge, $currency),
            ];

            $opening -= $charge;
        }

        return $rows;
    }

    /** Accumulated depreciation after $years complete years. */
    public function accumulatedAfter(Asset $asset, int $years): Money
    {
        $this->assertUsable($asset);

        $total = 0;

        foreach (array_slice($this->chargesFor($asset), 0, max($years, 0)) as $charge) {
            $total += $charge;
        }

        return Money::of($total, $asset->priceCurrency());
    }

    public function bookValueAfter(Asset $asset, int $years): Money
    {
        return $asset->purchasePrice()->minus($this->accumulatedAfter($asset, $years));
    }

    /** Book value on a date, counting whole years held since purchase. */
    public function bookValueOn(Asset $asset, ?\DateTimeInterface $on = null): Money
    {
        $on = $on ?? now();
        $purchased = $asset->purchase_date;

        if ($purchased === null) {
            return $asset->purchasePrice();
        }

        $years = max(0, (int) $purchased->diffInYears($on, absolute: false));

        return $this->bookValueAfter($asset, $years);
    }

    /** @return list<int> */
    private function chargesFor(Asset $asset): array
    {
        return match ($asset->depreciation_method) {
            Asset::DEPRECIATION_NONE => [],
            Asset::DEPRECIATION_LINEAR => $this->linearCharges($asset),
            Asset::DEPRECIATION_DECLINING => $this->decliningCharges($asset),
            default => throw AssetException::unknownDepreciationMethod((string) $asset->depreciation_method),
        };
    }

    /** @return list<int> */
    private function linearCharges(Asset $asset): array
    {
        $slices = $asset->depreciableBase()->allocateEvenly((int) $asset->useful_life_years);

        return array_map(static fn (Money $slice): int => $slice->minorUnits, $slices);
    }

    /** @return list<int> */
    private function decliningCharges(Asset $asset): array
    {
        $years = (int) $asset->useful_life_years;
        $rate = $this->scaledRate($asset);
        $salvage = (int) $asset->salvage_value;
        $book = (int) $asset->purchase_price;

        $charges = [];

        for ($year = 1; $year <= $years; $year++) {
            $charge = $year === $years
                // Closing year: write the remainder off so the schedule lands
                // exactly on the salvage value instead of trailing a stub.
                ? $book - $salvage
                : min($this->divideRoundHalfUp($book * $rate, self::RATE_SCALE), $book - $salvage);

            $charges[] = $charge;
            $book -= $charge;
        }

        return $charges;
    }

    private function assertUsable(Asset $asset): void
    {
        $method = (string) $asset->depreciation_method;

        if (! in_array($method, Asset::DEPRECIATION_METHODS, true)) {
            throw AssetException::unknownDepreciationMethod($method);
        }

        if ((int) $asset->salvage_value > (int) $asset->purchase_price) {
            throw AssetException::salvageAbovePurchase(
                (int) $asset->salvage_value,
                (int) $asset->purchase_price,
            );
        }

        if ($method === Asset::DEPRECIATION_NONE) {
            return;
        }

        if ((int) $asset->useful_life_years < 1) {
            throw AssetException::usefulLifeRequired($method);
        }

        if ($method === Asset::DEPRECIATION_DECLINING) {
            $rate = $this->scaledRate($asset);

            if ($rate <= 0 || $rate >= self::RATE_SCALE) {
                throw AssetException::depreciationRateRequired();
            }
        }
    }

    /** The rate as an integer number of millionths, so no float touches an amount. */
    private function scaledRate(Asset $asset): int
    {
        $value = $asset->depreciation_rate;

        if ($value === null) {
            return 0;
        }

        $text = trim((string) $value);

        if (! preg_match('/^(-?)(\d*)(?:\.(\d*))?$/', $text, $matches)) {
            throw new InvalidArgumentException("[{$text}] is not a valid depreciation rate.");
        }

        $whole = $matches[2] === '' ? '0' : $matches[2];
        $fraction = substr(str_pad($matches[3] ?? '', 6, '0'), 0, 6);

        return ($matches[1] === '-' ? -1 : 1) * (int) ($whole.$fraction);
    }

    private function divideRoundHalfUp(int $numerator, int $denominator): int
    {
        $quotient = intdiv($numerator, $denominator);
        $remainder = $numerator % $denominator;

        if (abs($remainder) * 2 >= $denominator) {
            $quotient += $numerator < 0 ? -1 : 1;
        }

        return $quotient;
    }
}
