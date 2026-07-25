<?php

declare(strict_types=1);

namespace Modules\DataOps\Console;

use App\Models\User;
use Illuminate\Console\Command;
use Modules\DataOps\Actions\PurgeAccount;

/**
 * Carries out the deletions whose grace period has run out.
 *
 * Scheduling a deletion is reversible and cheap; this command is neither, which
 * is why it is a separate, scheduled step rather than something the API does
 * inline.
 */
final class PurgeAccountsCommand extends Command
{
    protected $signature = 'accounts:purge
        {--dry-run : List the accounts that are due without deleting anything}';

    protected $description = 'Permanently delete accounts whose deletion grace period has passed';

    public function handle(PurgeAccount $purge): int
    {
        $due = User::query()
            ->whereNotNull('deletion_purge_after')
            ->where('deletion_purge_after', '<=', now())
            ->orderBy('deletion_purge_after')
            ->get();

        if ($due->isEmpty()) {
            $this->info('No accounts are due for purging.');

            return self::SUCCESS;
        }

        foreach ($due as $user) {
            if ($this->option('dry-run')) {
                $this->line("would purge {$user->email} (#{$user->id})");

                continue;
            }

            $purge->handle($user);

            $this->line("purged {$user->email} (#{$user->id})");
        }

        $verb = $this->option('dry-run') ? 'due for purging' : 'purged';

        $this->info("{$due->count()} account(s) {$verb}.");

        return self::SUCCESS;
    }
}
