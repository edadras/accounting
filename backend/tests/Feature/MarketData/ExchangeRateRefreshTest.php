<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData;

use App\Core\Money\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Ledger\Actions\ExchangeRateResolver;
use Modules\MarketData\Contracts\RateProvider;
use Modules\MarketData\Gateways\StaticProvider;
use Modules\MarketData\Models\MarketRejection;
use Modules\MarketData\Support\RateGuard;
use PHPUnit\Framework\Attributes\Test;

/**
 * What a rate feed is and is not allowed to do to the books.
 *
 * A rate is not one number in one row. It is the multiplier under every foreign
 * balance, every report and every open position at once, and a wrong one moves
 * all of them without anybody being told. So the interesting cases here are all
 * the ones where the feed misbehaves.
 */
final class ExchangeRateRefreshTest extends MarketDataTestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://rates.test/latest';

    #[Test]
    public function a_fetched_rate_is_stored_with_full_precision_and_is_what_the_resolver_returns(): void
    {
        $this->useFeed(['EUR' => '1.234567890123']);

        $this->assertSame(1, $this->refreshRates(quotes: ['EUR'])['stored']);

        $stored = DB::table('exchange_rates')->where('quote_code', 'EUR')->value('rate');

        $this->assertSame(1.234567890123, (float) $stored, 'Twelve places survive the round trip.');
        $this->assertNotSame(
            round(1.234567890123, 6),
            (float) $stored,
            'Nothing rounded the rate on the way in.',
        );

        $this->assertSame(
            1.234567890123,
            (float) app(ExchangeRateResolver::class)->rate(Currency::of('USD'), Currency::of('EUR')),
            'The ledger reads exactly what the fetch stored.',
        );
    }

    #[Test]
    public function history_is_appended_never_overwritten(): void
    {
        $this->useFeed(['EUR' => '1.10']);
        $this->refreshRates(quotes: ['EUR']);

        $this->useFeed(['EUR' => '1.20']);
        $this->refreshRates(quotes: ['EUR']);

        $rows = DB::table('exchange_rates')
            ->where('quote_code', 'EUR')
            ->orderBy('rated_at')
            ->get();

        $this->assertCount(2, $rows, 'Two fetches leave two rows; the first is still explainable.');
        $this->assertSame([1.1, 1.2], $rows->map(fn ($row) => (float) $row->rate)->all());

        $this->assertSame(
            1.2,
            (float) app(ExchangeRateResolver::class)->rate(Currency::of('USD'), Currency::of('EUR')),
            'The newest row wins even when both landed in the same second.',
        );
    }

    #[Test]
    public function a_zero_negative_or_non_numeric_rate_is_rejected_and_the_previous_rate_survives(): void
    {
        $this->useFeed(['EUR' => '1.10', 'TRY' => '32.5', 'AED' => '3.67']);
        $this->assertSame(3, $this->refreshRates(quotes: ['EUR', 'TRY', 'AED'])['stored']);

        $this->useFeed(['EUR' => '0', 'TRY' => '-32.5', 'AED' => 'n/a']);
        $result = $this->refreshRates(quotes: ['EUR', 'TRY', 'AED']);

        $this->assertSame(0, $result['stored']);
        $this->assertSame(3, $result['rejected']);

        $resolver = app(ExchangeRateResolver::class);

        $this->assertSame(1.1, (float) $resolver->rate(Currency::of('USD'), Currency::of('EUR')));
        $this->assertSame(32.5, (float) $resolver->rate(Currency::of('USD'), Currency::of('TRY')));
        $this->assertSame(3.67, (float) $resolver->rate(Currency::of('USD'), Currency::of('AED')));

        $this->assertSame(3, DB::table('exchange_rates')->count(), 'Nothing was appended.');

        $this->assertSame(
            [
                'USD>AED' => RateGuard::NOT_NUMERIC,
                'USD>EUR' => RateGuard::NOT_POSITIVE,
                'USD>TRY' => RateGuard::NOT_POSITIVE,
            ],
            MarketRejection::query()->orderBy('subject')->pluck('reason', 'subject')->all(),
            'Every refusal is on the record — a rate that never arrived and one that was thrown away look identical otherwise.',
        );
    }

    #[Test]
    public function a_rate_fifty_times_the_last_one_is_rejected_as_implausible(): void
    {
        $this->useFeed(['TRY' => '32.5']);
        $this->refreshRates(quotes: ['TRY']);

        // A decimal point in the wrong place, which is what this actually looks
        // like in the wild.
        $this->useFeed(['TRY' => '1625']);
        $result = $this->refreshRates(quotes: ['TRY']);

        $this->assertSame(0, $result['stored']);
        $this->assertSame(1, $result['rejected']);

        $this->assertSame(
            32.5,
            (float) app(ExchangeRateResolver::class)->rate(Currency::of('USD'), Currency::of('TRY')),
        );

        $rejection = MarketRejection::query()->sole();

        $this->assertSame(RateGuard::IMPLAUSIBLE_JUMP, $rejection->reason);
        $this->assertSame('USD>TRY', $rejection->subject);
        $this->assertSame('1625', $rejection->value);
    }

    #[Test]
    public function a_move_inside_the_configured_factor_is_still_accepted(): void
    {
        $this->useFeed(['TRY' => '32.5']);
        $this->refreshRates(quotes: ['TRY']);

        // Nine-fold is violent but the guard is a sanity check, not a policy on
        // how much a currency may move.
        $this->useFeed(['TRY' => '292.5']);

        $this->assertSame(1, $this->refreshRates(quotes: ['TRY'])['stored']);
        $this->assertSame(
            292.5,
            (float) app(ExchangeRateResolver::class)->rate(Currency::of('USD'), Currency::of('TRY')),
        );
    }

    #[Test]
    public function a_timeout_leaves_the_stored_rate_untouched_and_does_not_throw(): void
    {
        $this->useFeed(['EUR' => '1.10']);
        $this->refreshRates(quotes: ['EUR']);

        config(['market.rates.endpoint' => self::ENDPOINT]);
        $this->fakeHttp(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $result = $this->refreshRates(quotes: ['EUR']);

        $this->assertSame(0, $result['stored']);
        $this->assertSame(0, $result['rejected'], 'An outage is not a rejection: nothing was offered.');

        $this->assertSame(1, DB::table('exchange_rates')->count());
        $this->assertSame(
            1.1,
            (float) app(ExchangeRateResolver::class)->rate(Currency::of('USD'), Currency::of('EUR')),
        );
    }

    #[Test]
    public function a_failing_endpoint_is_not_fatal_either(): void
    {
        config(['market.rates.endpoint' => self::ENDPOINT]);
        $this->fakeHttp(['*' => Http::response(['message' => 'upstream exploded'], 503)]);

        $this->assertSame(0, $this->refreshRates(quotes: ['EUR'])['stored']);
        $this->assertSame(0, DB::table('exchange_rates')->count());
    }

    #[Test]
    public function with_no_provider_configured_the_resolver_behaves_exactly_as_before(): void
    {
        $this->assertInstanceOf(StaticProvider::class, app(RateProvider::class));
        $this->assertSame(0, DB::table('exchange_rates')->count());

        $seed = ExchangeRateResolver::FALLBACK_TO_USD;
        $resolver = app(ExchangeRateResolver::class);

        foreach ([['USD', 'TRY'], ['EUR', 'IRR'], ['BTC', 'USD'], ['XAU', 'TRY']] as [$from, $to]) {
            $this->assertSame(
                (string) ($seed[$from] / $seed[$to]),
                $resolver->rate(Currency::of($from), Currency::of($to)),
                "{$from} → {$to} is the same number the ledger answered before this module existed.",
            );
        }

        $this->assertSame('1', $resolver->rate(Currency::of('TRY'), Currency::of('TRY')));
    }

    #[Test]
    public function the_static_provider_is_a_faithful_stand_in_for_the_fallback(): void
    {
        $stored = $this->refreshRates(base: 'USD', quotes: ['EUR', 'TRY', 'IRR', 'BTC']);

        $this->assertSame(4, $stored['stored']);
        $this->assertSame(
            ['static'],
            DB::table('exchange_rates')->distinct()->pluck('source')->all(),
        );

        $seed = ExchangeRateResolver::FALLBACK_TO_USD;
        $resolver = app(ExchangeRateResolver::class);

        // Now reading rows rather than the constant, and answering the same
        // numbers: a scheduled static refresh cannot move a balance.
        foreach (['EUR', 'TRY', 'IRR', 'BTC'] as $quote) {
            $this->assertSame(
                (string) ($seed['USD'] / $seed[$quote]),
                $resolver->rate(Currency::of('USD'), Currency::of($quote)),
            );
        }
    }

    /** @param array<string, mixed> $rates */
    private function useFeed(array $rates): void
    {
        config(['market.rates.endpoint' => self::ENDPOINT]);

        $this->fakeHttp(['*' => Http::response(['base' => 'USD', 'rates' => $rates])]);
    }
}
