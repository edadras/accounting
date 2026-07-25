<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Models\Workspace;
use Modules\Ledger\Actions\RecordTransaction;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\Transaction;
use Modules\Search\Contracts\SearchEngine;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\RegistersModuleProviders;
use Tests\Feature\LedgerTestCase;

/**
 * The example docs/08-ai-layer.md §4 gives, run against real rows:
 *
 *   «تمام هزینه‌های مربوط به ماشین» must find petrol, the garage, the
 *   insurance, the fine and the parking — even though they sit in different
 *   categories and not one of them contains the word «ماشین».
 *
 * The first test also runs the same query through the keyword engine, so what
 * is being demonstrated is not "semantic search returns rows" but "semantic
 * search returns rows keyword search provably cannot".
 */
final class SemanticSearchTest extends LedgerTestCase
{
    use RefreshDatabase;
    use RegistersModuleProviders;

    private const CAR_QUERY = 'تمام هزینه‌های مربوط به ماشین';

    protected function setUp(): void
    {
        parent::setUp();

        // No key: the filter extraction runs on the deterministic provider and
        // the whole test touches no network.
        config(['ai.key' => null, 'ai.enabled' => true]);

        Carbon::setTestNow('2026-07-25 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function it_finds_car_spending_scattered_across_five_categories(): void
    {
        [$user, $workspace] = $this->world();

        $carIds = [
            $this->spend($workspace, 'fuel', 'بنزین پمپ بنزین')->id,
            $this->spend($workspace, 'repairs', 'تعمیرگاه و مکانیک')->id,
            $this->spend($workspace, 'insurance', 'بیمه شخص ثالث خودرو')->id,
            $this->spend($workspace, 'taxi', 'جریمه و پارکینگ')->id,
            $this->spend($workspace, 'bills', 'تعویض لاستیک و باتری')->id,
        ];

        $restaurant = $this->spend($workspace, 'restaurant', 'شام با دوستان');
        $rent = $this->spend($workspace, 'rent', 'اجاره خانه');

        // Keyword search cannot answer this question at all: neither the whole
        // phrase nor the word «ماشین» appears in any of the rows.
        $keyword = $this->inWorkspace($workspace, fn (): array => [
            app(SearchEngine::class)->search(self::CAR_QUERY, [SearchEngine::TYPE_TRANSACTIONS]),
            app(SearchEngine::class)->search('ماشین', [SearchEngine::TYPE_TRANSACTIONS]),
        ]);

        $this->assertSame([], $keyword[0][SearchEngine::TYPE_TRANSACTIONS]);
        $this->assertSame([], $keyword[1][SearchEngine::TYPE_TRANSACTIONS]);

        Sanctum::actingAs($user);

        $response = $this->semantic($workspace, self::CAR_QUERY, ['types' => 'transactions']);
        $response->assertOk();

        $found = array_column(array_column($response->json('data'), 'record'), 'id');

        foreach ($carIds as $id) {
            $this->assertContains($id, $found);
        }

        $this->assertNotContains($restaurant->id, $found);
        $this->assertNotContains($rent->id, $found);
    }

    #[Test]
    public function the_answer_carries_the_numeric_summary_the_document_asks_for(): void
    {
        [$user, $workspace] = $this->world();

        $this->spend($workspace, 'fuel', 'بنزین', 300_00);
        $this->spend($workspace, 'repairs', 'تعمیرگاه', 700_00);
        $this->spend($workspace, 'restaurant', 'شام با دوستان', 999_00);

        Sanctum::actingAs($user);

        $response = $this->semantic($workspace, self::CAR_QUERY, ['types' => 'transactions']);

        $response->assertOk();
        $response->assertJsonPath('meta.summary.transaction_count', 2);
        $response->assertJsonPath('meta.summary.total', 1_000_00);
        $response->assertJsonPath('meta.summary.currency', 'TRY');
        $response->assertJsonPath('meta.embedding_model', 'local-hashed-v1');
    }

    #[Test]
    public function the_structured_filter_is_extracted_and_returned(): void
    {
        [$user, $workspace] = $this->world();

        $this->spend($workspace, 'fuel', 'بنزین');

        Sanctum::actingAs($user);

        $response = $this->semantic($workspace, self::CAR_QUERY);

        // «هزینه» says expenses; the query names no period, so none is invented.
        $response->assertJsonPath('meta.filter.type', Transaction::TYPE_EXPENSE);
        $response->assertJsonPath('meta.filter.from', null);
        $response->assertJsonPath('meta.filter.to', null);
        $response->assertJsonPath('meta.filter.provider', 'deterministic');
    }

    #[Test]
    public function a_period_in_the_query_narrows_the_rows_before_ranking(): void
    {
        [$user, $workspace] = $this->world();

        $thisYear = $this->spend($workspace, 'fuel', 'بنزین', 100_00, '2026-03-01 10:00:00');
        $lastYear = $this->spend($workspace, 'fuel', 'بنزین', 100_00, '2025-03-01 10:00:00');

        Sanctum::actingAs($user);

        $response = $this->semantic($workspace, 'هزینه‌های ماشین پارسال', ['types' => 'transactions']);

        $response->assertJsonPath('meta.filter.from', '2025-01-01');
        $response->assertJsonPath('meta.filter.to', '2025-12-31');

        $found = array_column(array_column($response->json('data'), 'record'), 'id');

        $this->assertContains($lastYear->id, $found);
        $this->assertNotContains($thisYear->id, $found);
    }

    #[Test]
    public function an_exact_word_still_outranks_a_merely_related_one(): void
    {
        [$user, $workspace] = $this->world();

        $exact = $this->spend($workspace, 'fuel', 'بنزین');
        $this->spend($workspace, 'repairs', 'تعمیرگاه');

        Sanctum::actingAs($user);

        $results = $this->semantic($workspace, 'بنزین', ['types' => 'transactions'])->json('data');

        $this->assertSame($exact->id, $results[0]['record']['id']);
        $this->assertGreaterThan(0.0, $results[0]['lexical_score']);
    }

    #[Test]
    public function results_come_back_ranked_with_their_scores_shown(): void
    {
        [$user, $workspace] = $this->world();

        $this->spend($workspace, 'fuel', 'بنزین پمپ بنزین');
        $this->spend($workspace, 'repairs', 'تعمیرگاه و مکانیک');

        Sanctum::actingAs($user);

        $results = $this->semantic($workspace, self::CAR_QUERY, ['types' => 'transactions'])->json('data');

        $scores = array_column($results, 'score');
        $descending = $scores;
        rsort($descending);

        $this->assertCount(2, $scores);
        $this->assertSame($descending, $scores);

        foreach ($results as $result) {
            $this->assertArrayHasKey('semantic_score', $result);
            $this->assertArrayHasKey('lexical_score', $result);
            $this->assertSame('transactions', $result['type']);
        }
    }

    #[Test]
    public function it_reaches_across_types_when_none_is_named(): void
    {
        [$user, $workspace] = $this->world();

        $this->spend($workspace, 'fuel', 'بنزین');

        Sanctum::actingAs($user);

        $types = array_unique(array_column($this->semantic($workspace, self::CAR_QUERY)->json('data'), 'type'));

        // The seeded tree has a `fuel` category, which is about cars too.
        $this->assertContains('transactions', $types);
        $this->assertContains('categories', $types);
    }

    #[Test]
    public function the_limit_caps_the_ranked_list(): void
    {
        [$user, $workspace] = $this->world();

        foreach (['بنزین', 'تعمیرگاه', 'لاستیک', 'پارکینگ'] as $description) {
            $this->spend($workspace, 'fuel', $description);
        }

        Sanctum::actingAs($user);

        $response = $this->semantic($workspace, self::CAR_QUERY, ['types' => 'transactions', 'limit' => 2]);

        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('meta.total', 2);
    }

    #[Test]
    public function it_never_returns_another_workspaces_rows(): void
    {
        [$owner, $ownerWorkspace] = $this->world('owner@example.test', 'Owner books');
        [$intruder, $intruderWorkspace] = $this->world('intruder@example.test', 'Intruder books');

        $secret = $this->spend($ownerWorkspace, 'fuel', 'بنزین مخصوص');

        Sanctum::actingAs($intruder);

        $found = array_column(
            array_column($this->semantic($intruderWorkspace, self::CAR_QUERY)->json('data'), 'record'),
            'id',
        );
        $this->assertNotContains($secret->id, $found);

        // Sending the owner's workspace id does not help either.
        $this->semantic($ownerWorkspace, self::CAR_QUERY)->assertForbidden();

        // And the owner still finds it, so the emptiness above was isolation
        // and not a broken query.
        Sanctum::actingAs($owner);
        $found = array_column(
            array_column($this->semantic($ownerWorkspace, self::CAR_QUERY)->json('data'), 'record'),
            'id',
        );
        $this->assertContains($secret->id, $found);
    }

    #[Test]
    public function an_empty_query_is_rejected(): void
    {
        [$user, $workspace] = $this->world();

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/search/semantic?q=', $this->headers($workspace))
            ->assertStatus(422)
            ->assertJsonValidationErrors('q');
    }

    #[Test]
    public function it_still_answers_with_the_ai_layer_switched_off(): void
    {
        [$user, $workspace] = $this->world();

        $fuel = $this->spend($workspace, 'fuel', 'بنزین');
        $workspace->forceFill(['settings' => ['ai' => ['enabled' => false]]])->save();

        Sanctum::actingAs($user);

        $response = $this->semantic($workspace->refresh(), self::CAR_QUERY, ['types' => 'transactions']);

        $response->assertOk();

        // No model, so no structured filter — but the ranking still works and
        // the product keeps functioning (docs/07-security.md §5.6).
        $response->assertJsonPath('meta.filter.type', null);
        $this->assertContains(
            $fuel->id,
            array_column(array_column($response->json('data'), 'record'), 'id'),
        );
    }

    /** @return array{0: User, 1: Workspace} */
    private function world(string $email = 'ali@example.test', string $name = 'Personal'): array
    {
        $user = $this->makeUser($email);
        $workspace = $this->makeWorkspace($user, $name);

        $this->makeAccount($workspace, 'Wallet', 'TRY', 10_000_000);

        return [$user, $workspace];
    }

    private function spend(
        Workspace $workspace,
        string $category,
        string $description,
        int $amount = 100_00,
        string $at = '2026-07-10 10:00:00',
    ): Transaction {
        return $this->inWorkspace($workspace, function () use ($category, $description, $amount, $at): Transaction {
            $account = Account::query()->where('name', 'Wallet')->firstOrFail();

            return app(RecordTransaction::class)->handle([
                'type' => Transaction::TYPE_EXPENSE,
                'account_id' => $account->id,
                'category_id' => Category::query()->where('name', $category)->value('id'),
                'amount' => $amount,
                'currency' => 'TRY',
                'occurred_at' => $at,
                'description' => $description,
            ]);
        });
    }

    /**
     * @param  array<string, string|int>  $extra
     * @return TestResponse<Response>
     */
    private function semantic(Workspace $workspace, string $query, array $extra = []): TestResponse
    {
        $parameters = http_build_query(['q' => $query] + $extra);

        return $this->getJson("/api/v1/search/semantic?{$parameters}", $this->headers($workspace));
    }

    /** @return array<string, string> */
    private function headers(Workspace $workspace): array
    {
        return ['X-Workspace-Id' => $workspace->id, 'Accept' => 'application/json'];
    }
}
