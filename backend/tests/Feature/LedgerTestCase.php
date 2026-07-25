<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Modules\Core\Actions\CreateWorkspace;
use Modules\Core\Models\Workspace;
use Modules\Core\Support\WorkspaceContext;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Tests\TestCase;

abstract class LedgerTestCase extends TestCase
{
    protected function makeUser(string $email): User
    {
        return User::query()->create([
            'name' => explode('@', $email)[0],
            'email' => $email,
            'password' => 'password123',
        ]);
    }

    protected function makeWorkspace(User $owner, string $name = 'Personal', string $currency = 'TRY'): Workspace
    {
        return app(CreateWorkspace::class)->handle(
            owner: $owner,
            name: $name,
            baseCurrency: $currency,
        );
    }

    /** Runs $callback with $workspace active — mirrors what the middleware does. */
    protected function inWorkspace(Workspace $workspace, callable $callback): mixed
    {
        return app(WorkspaceContext::class)->runFor($workspace, $callback);
    }

    protected function makeAccount(
        Workspace $workspace,
        string $name = 'Wallet',
        string $currency = 'TRY',
        int $openingBalance = 0,
    ): Account {
        return $this->inWorkspace($workspace, fn () => Account::query()->create([
            'name' => $name,
            'type' => 'cash',
            'currency' => $currency,
            'opening_balance' => $openingBalance,
            'current_balance' => $openingBalance,
        ]));
    }

    protected function makeCategory(Workspace $workspace, string $name, ?Category $parent = null): Category
    {
        return $this->inWorkspace($workspace, fn () => Category::query()->create([
            'parent_id' => $parent?->id,
            'name' => $name,
            'type' => 'expense',
        ]));
    }

    protected function tearDown(): void
    {
        app(WorkspaceContext::class)->forget();

        parent::tearDown();
    }
}
