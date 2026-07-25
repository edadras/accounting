<?php

declare(strict_types=1);

namespace Modules\Security\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Ledger\Models\Account;
use Modules\Security\Support\EncryptedColumns;
use Modules\Security\Support\PasswordResetTokens;

final class SecurityServiceProvider extends ServiceProvider
{
    /**
     * Sensitive columns this module keeps encrypted at rest, on models it does
     * not own (docs/07-security.md §3).
     *
     * `card_last4` is deliberately absent: four digits are not a card number,
     * which is the whole reason only four are stored.
     */
    private const ENCRYPTED_COLUMNS = [
        Account::class => ['iban'],
    ];

    public function register(): void
    {
        $this->app->singleton(PasswordResetTokens::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');

        foreach (self::ENCRYPTED_COLUMNS as $model => $columns) {
            EncryptedColumns::install($model, $columns);
        }
    }
}
