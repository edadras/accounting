<?php

declare(strict_types=1);

namespace Modules\Ledger\Database\Seeders;

use Modules\Core\Models\Workspace;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;

/**
 * Gives a brand-new workspace a wallet and a usable category tree.
 *
 * Names carry a `name_key` so they render in the user's language; the moment
 * the user renames one it becomes plain text and stops being translated
 * (docs/06-i18n-rtl.md §6).
 */
final class DefaultLedgerSeeder
{
    /**
     * Expense tree, nested to three levels to show that depth is unlimited.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const EXPENSE_TREE = [
        'home' => [
            'food' => ['restaurant', 'groceries', 'bread', 'meat'],
            'rent' => [],
            'bills' => ['electricity', 'water', 'gas', 'internet'],
        ],
        'transport' => [
            'taxi' => [],
            'fuel' => [],
            'repairs' => [],
        ],
        'health' => ['doctor' => [], 'pharmacy' => [], 'insurance' => []],
        'leisure' => ['travel' => [], 'subscriptions' => []],
        'education' => [],
    ];

    /** @var list<string> */
    private const INCOME_ROOTS = ['salary', 'business', 'investment_income', 'gift'];

    public function seed(Workspace $workspace): void
    {
        if (Account::query()->exists()) {
            return; // Already seeded; running twice must not duplicate anything.
        }

        Account::query()->create([
            'name' => 'Wallet',
            'type' => 'cash',
            'currency' => $workspace->base_currency,
            'sort_order' => 0,
            'icon' => 'wallet',
        ]);

        foreach (self::EXPENSE_TREE as $root => $children) {
            $parent = $this->makeCategory($root, 'expense', null);

            foreach ($children as $child => $grandchildren) {
                $childCategory = $this->makeCategory($child, 'expense', $parent);

                foreach ($grandchildren as $grandchild) {
                    $this->makeCategory($grandchild, 'expense', $childCategory);
                }
            }
        }

        foreach (self::INCOME_ROOTS as $root) {
            $this->makeCategory($root, 'income', null);
        }
    }

    private function makeCategory(string $key, string $type, ?Category $parent): Category
    {
        return Category::query()->create([
            'parent_id' => $parent?->id,
            'name' => $key,
            'name_key' => "category.{$key}",
            'type' => $type,
            'is_system' => true,
        ]);
    }
}
