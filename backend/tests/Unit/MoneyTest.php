<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Money\Currency;
use App\Core\Money\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Money is the foundation every other number in the product sits on, so these
 * tests are deliberately paranoid.
 */
final class MoneyTest extends TestCase
{
    #[Test]
    public function it_refuses_to_add_two_different_currencies(): void
    {
        $lira = Money::of(35000, 'TRY');
        $dollars = Money::of(1000, 'USD');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Convert explicitly/');

        $lira->plus($dollars);
    }

    #[Test]
    public function it_refuses_to_compare_two_different_currencies(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of(1, 'USD')->compareTo(Money::of(1, 'EUR'));
    }

    #[Test]
    public function it_parses_decimal_strings_at_the_currency_precision(): void
    {
        $this->assertSame(1234, Money::fromDecimalString('12.34', 'USD')->minorUnits);
        $this->assertSame(-1234, Money::fromDecimalString('-12.34', 'USD')->minorUnits);
        $this->assertSame(500000, Money::fromDecimalString('500000', 'IRR')->minorUnits);
        $this->assertSame(12345, Money::fromDecimalString('0.00012345', 'BTC')->minorUnits);
        $this->assertSame(1200, Money::fromDecimalString('12.0', 'USD')->minorUnits);
        $this->assertSame(1200, Money::fromDecimalString('12', 'USD')->minorUnits);
    }

    #[Test]
    public function it_rejects_more_precision_than_the_currency_has(): void
    {
        // Rounding 12.345 to 12.34 without telling anyone is how a ledger
        // quietly stops adding up.
        $this->expectException(InvalidArgumentException::class);

        Money::fromDecimalString('12.345', 'USD');
    }

    #[Test]
    public function it_rejects_decimals_on_a_zero_decimal_currency(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromDecimalString('500.5', 'IRR');
    }

    #[Test]
    public function it_round_trips_through_a_decimal_string(): void
    {
        foreach ([['12.34', 'USD'], ['0.00012345', 'BTC'], ['500000', 'IRR'], ['-7.05', 'EUR']] as [$text, $code]) {
            $this->assertSame($text, Money::fromDecimalString($text, $code)->toDecimalString());
        }
    }

    #[Test]
    public function it_converts_between_currencies_with_different_precision(): void
    {
        // 500,000 IRR (0 dp) at 0.0000167 USD → 8.35 USD → 835 minor units.
        $rials = Money::of(500000, 'IRR');
        $dollars = $rials->convertTo(Currency::of('USD'), 0.0000167);

        $this->assertSame('USD', $dollars->currency->code);
        $this->assertSame(835, $dollars->minorUnits);
    }

    #[Test]
    public function conversion_rounds_half_up_in_both_directions(): void
    {
        $this->assertSame(3, Money::of(5, 'USD')->convertTo(Currency::of('EUR'), 0.5)->minorUnits);
        $this->assertSame(-3, Money::of(-5, 'USD')->convertTo(Currency::of('EUR'), 0.5)->minorUnits);
    }

    #[Test]
    public function converting_to_the_same_currency_is_a_no_op(): void
    {
        $amount = Money::of(1234, 'USD');

        $this->assertTrue($amount->equals($amount->convertTo('USD', 999.0)));
    }

    #[Test]
    public function splitting_evenly_never_loses_or_invents_a_minor_unit(): void
    {
        $bill = Money::of(10000, 'TRY'); // ₺100.00 among 3 people
        $shares = $bill->allocateEvenly(3);

        $this->assertCount(3, $shares);
        $this->assertSame([3334, 3333, 3333], array_map(fn (Money $m) => $m->minorUnits, $shares));
        $this->assertSame(10000, array_sum(array_map(fn (Money $m) => $m->minorUnits, $shares)));
    }

    #[Test]
    public function splitting_a_negative_amount_still_sums_back_exactly(): void
    {
        $refund = Money::of(-10000, 'TRY');
        $shares = $refund->allocateEvenly(3);

        $this->assertSame(-10000, array_sum(array_map(fn (Money $m) => $m->minorUnits, $shares)));
    }

    #[Test]
    public function splitting_by_weights_sums_back_exactly(): void
    {
        $bill = Money::of(10000, 'USD');
        $shares = $bill->allocateByWeights([1, 1, 2]);

        $this->assertSame(10000, array_sum(array_map(fn (Money $m) => $m->minorUnits, $shares)));
        $this->assertSame(5000, $shares[2]->minorUnits);
    }

    #[Test]
    public function a_thousand_random_splits_always_reconcile(): void
    {
        // The property that actually matters, checked broadly rather than at a
        // few hand-picked numbers.
        mt_srand(20260725);

        for ($i = 0; $i < 1000; $i++) {
            $amount = Money::of(mt_rand(-500000, 500000), 'USD');
            $parts = mt_rand(1, 17);
            $total = array_sum(array_map(
                fn (Money $m) => $m->minorUnits,
                $amount->allocateEvenly($parts),
            ));

            $this->assertSame($amount->minorUnits, $total, "Failed splitting {$amount} into {$parts}");
        }
    }

    #[Test]
    public function it_sums_a_collection_in_one_currency(): void
    {
        $total = Money::sum([
            Money::of(1000, 'USD'),
            Money::of(250, 'USD'),
            Money::of(-100, 'USD'),
        ], 'USD');

        $this->assertSame(1150, $total->minorUnits);
    }

    #[Test]
    public function unknown_currencies_are_rejected_at_construction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Currency::of('XYZ');
    }
}
