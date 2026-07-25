<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\AI\Contracts\EmbeddingProvider;
use Modules\AI\Providers\Gateway\HttpEmbeddingProvider;
use Modules\AI\Providers\Gateway\LocalEmbeddingProvider;
use Modules\AI\Support\Vector;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\RegistersModuleProviders;
use Tests\Feature\LedgerTestCase;

/**
 * The claim the local embedding provider has to earn.
 *
 * docs/08-ai-layer.md §4 says semantic search must find «بنزین» and
 * «تعمیرگاه» when the user asks about «ماشین», and explicitly notes that this
 * is what keyword search cannot do. Those three words share no letters, so
 * there is no clever string comparison that would pass this test — the vectors
 * either encode what the words mean or they do not.
 */
final class EmbeddingTest extends LedgerTestCase
{
    use RefreshDatabase;
    use RegistersModuleProviders;

    #[Test]
    public function petrol_and_the_garage_sit_nearer_to_a_car_than_a_restaurant_does(): void
    {
        $provider = app(EmbeddingProvider::class);

        $car = $provider->embed('ماشین');
        $restaurant = Vector::cosine($car, $provider->embed('رستوران'));

        foreach (['بنزین', 'تعمیرگاه', 'بیمه شخص ثالث', 'لاستیک', 'پارکینگ', 'خودرو'] as $word) {
            $this->assertGreaterThan(
                $restaurant,
                Vector::cosine($car, $provider->embed($word)),
                "«{$word}» must sit nearer to «ماشین» than «رستوران» does.",
            );
        }
    }

    #[Test]
    public function the_english_vocabulary_lands_in_the_same_place_as_the_persian(): void
    {
        $provider = app(EmbeddingProvider::class);

        $car = $provider->embed('ماشین');

        $this->assertGreaterThan(
            Vector::cosine($car, $provider->embed('restaurant')),
            Vector::cosine($car, $provider->embed('petrol')),
        );
    }

    #[Test]
    public function a_whole_phrase_still_lands_near_its_subject(): void
    {
        $provider = app(EmbeddingProvider::class);

        $query = $provider->embed('تمام هزینه‌های مربوط به ماشین');

        $fuel = Vector::cosine($query, $provider->embed('بنزین پمپ بنزین شهرداری'));
        $dinner = Vector::cosine($query, $provider->embed('شام در رستوران با دوستان'));

        $this->assertGreaterThan(0.3, $fuel, 'The connectives must not drown out the subject.');
        $this->assertGreaterThan($dinner, $fuel);
    }

    #[Test]
    public function the_same_text_always_produces_the_same_vector(): void
    {
        $provider = app(EmbeddingProvider::class);

        // Determinism is what lets the vector be cached forever and lets a
        // backfill be idempotent.
        $this->assertSame($provider->embed('بنزین'), $provider->embed('بنزین'));

        // And it survives the normalisation the index applies, so text typed
        // on an Arabic keyboard embeds like text typed on a Persian one.
        $this->assertSame($provider->embed('خريد بنزين'), $provider->embed('خرید بنزین'));
    }

    #[Test]
    public function every_vector_has_the_configured_width_and_unit_length(): void
    {
        $provider = app(EmbeddingProvider::class);

        $vector = $provider->embed('قبض برق خانه');

        $this->assertCount($provider->dimensions(), $vector);
        $this->assertEqualsWithDelta(1.0, Vector::cosine($vector, $vector), 0.000001);
    }

    #[Test]
    public function empty_text_embeds_to_nothing_rather_than_to_something_arbitrary(): void
    {
        $provider = app(EmbeddingProvider::class);

        $this->assertSame(array_fill(0, $provider->dimensions(), 0.0), $provider->embed('   '));
        $this->assertSame(0.0, Vector::cosine($provider->embed(''), $provider->embed('بنزین')));
    }

    #[Test]
    public function a_batch_embeds_exactly_as_the_rows_would_one_at_a_time(): void
    {
        $provider = app(EmbeddingProvider::class);

        $this->assertSame(
            [$provider->embed('بنزین'), $provider->embed('رستوران')],
            $provider->embedBatch(['بنزین', 'رستوران']),
        );
    }

    #[Test]
    public function the_local_provider_is_the_default_and_needs_no_key(): void
    {
        $this->assertInstanceOf(LocalEmbeddingProvider::class, app(EmbeddingProvider::class));
        $this->assertSame('local', config('ai.embeddings.driver'));
    }

    #[Test]
    public function the_http_driver_is_bound_when_it_is_configured(): void
    {
        config(['ai.embeddings.driver' => 'http']);

        $this->assertInstanceOf(HttpEmbeddingProvider::class, $this->app->make(EmbeddingProvider::class));
    }

    #[Test]
    public function the_http_provider_normalises_what_it_sends_and_what_it_gets_back(): void
    {
        config([
            'ai.embeddings.driver' => 'http',
            'ai.embeddings.dimensions' => 3,
            'ai.embeddings.model' => 'text-embedding-3-small',
            'ai.key' => 'test-key',
            'ai.base_url' => 'https://gateway.test/v1',
        ]);

        Http::fake(['gateway.test/*' => Http::response(['data' => [
            // Deliberately out of order, and not unit length.
            ['index' => 1, 'embedding' => [0.0, 4.0, 0.0]],
            ['index' => 0, 'embedding' => [3.0, 0.0, 0.0]],
        ]])]);

        $vectors = $this->app->make(EmbeddingProvider::class)->embedBatch(['خريد بنزين', 'رستوران']);

        $this->assertSame([[1.0, 0.0, 0.0], [0.0, 1.0, 0.0]], $vectors);

        Http::assertSent(function ($request): bool {
            // The text was folded before it left, so a query and a document
            // typed on different keyboards embed alike.
            return $request->url() === 'https://gateway.test/v1/embeddings'
                && $request['input'] === ['خرید بنزین', 'رستوران']
                && $request['model'] === 'text-embedding-3-small';
        });
    }

    #[Test]
    public function the_http_provider_reports_an_outage_rather_than_returning_a_wrong_vector(): void
    {
        config(['ai.embeddings.driver' => 'http', 'ai.key' => 'test-key']);

        Http::fake(['*' => Http::response('', 503)]);

        $this->expectExceptionMessage('embeddings');

        $this->app->make(EmbeddingProvider::class)->embed('بنزین');
    }

    #[Test]
    public function vectors_of_different_widths_score_zero_rather_than_blowing_up(): void
    {
        // What happens to a row embedded by an older model after the model
        // changes, before the backfill has caught up with it.
        $this->assertSame(0.0, Vector::cosine([1.0, 0.0], [1.0, 0.0, 0.0]));
        $this->assertSame(0.0, Vector::cosine([], []));
    }
}
