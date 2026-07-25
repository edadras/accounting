<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use Modules\AI\Contracts\AiProvider;
use Modules\AI\Providers\Gateway\DeterministicProvider;
use Modules\AI\Providers\Gateway\OpenAiProvider;
use Modules\AI\Tools\ToolRegistry;
use PHPUnit\Framework\Attributes\Test;

final class ProviderBindingTest extends AiTestCase
{
    #[Test]
    public function without_a_key_the_deterministic_provider_is_bound(): void
    {
        config(['ai.key' => null]);

        $this->assertInstanceOf(DeterministicProvider::class, app(AiProvider::class));
        $this->assertSame('deterministic', app(AiProvider::class)->name());
    }

    #[Test]
    public function with_a_key_the_gateway_is_bound(): void
    {
        config(['ai.key' => 'sk-test-not-used']);

        // Constructing it makes no request; nothing in this suite ever calls it.
        $this->assertInstanceOf(OpenAiProvider::class, app(AiProvider::class));
        $this->assertSame('openai', app(AiProvider::class)->name());
    }

    #[Test]
    public function the_gateway_refuses_rather_than_calling_out_without_a_key(): void
    {
        config(['ai.key' => '']);

        $this->expectExceptionMessage('no API key configured');

        (new OpenAiProvider)->complete('TASK: finora.chat', 'hello');
    }

    #[Test]
    public function no_tool_accepts_a_workspace_argument(): void
    {
        foreach (app(ToolRegistry::class)->schemas() as $schema) {
            $properties = $schema['parameters']['properties'] ?? [];
            $names = is_array($properties) ? array_keys($properties) : [];

            foreach ($names as $name) {
                $this->assertStringNotContainsStringIgnoringCase(
                    'workspace',
                    (string) $name,
                    "Tool [{$schema['name']}] must not offer a workspace argument.",
                );
            }
        }
    }

    #[Test]
    public function the_registry_exposes_exactly_the_documented_tools(): void
    {
        // The eight of docs/08-ai-layer.md §5, and nothing else: the reachable
        // surface of the chat is this list.
        $this->assertEqualsCanonicalizing([
            'get_spending_summary',
            'get_transactions',
            'get_budget_status',
            'get_account_balances',
            'get_investment_performance',
            'get_upcoming_obligations',
            'get_cashflow_forecast',
            'create_budget_draft',
        ], array_keys(app(ToolRegistry::class)->all()));
    }

    #[Test]
    public function an_unknown_tool_is_refused(): void
    {
        [, $workspace] = $this->world('unknown-tool@example.test');

        $this->expectExceptionMessage('No tool named [run_sql]');

        $this->inWorkspace($workspace, fn () => app(ToolRegistry::class)->execute('run_sql', []));
    }
}
