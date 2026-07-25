<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Ledger\Models\Account;
use PHPUnit\Framework\Attributes\Test;

/**
 * docs/07-security.md §3 asks for the sensitive columns to be encrypted at
 * rest. The only way to know that actually happened is to go around the model
 * and read the row itself — a cast that quietly did nothing would pass every
 * test that only ever reads back through Eloquent.
 */
final class EncryptedColumnsTest extends SecurityTestCase
{
    private const IBAN = 'TR330006100519786457841326';

    use RefreshDatabase;

    #[Test]
    public function the_two_factor_secret_is_unreadable_in_the_row_but_readable_through_the_model(): void
    {
        $user = $this->makeUser('ciphertext@example.test');
        ['secret' => $secret, 'recovery_codes' => $codes] = $this->enrollInTwoFactor($user);

        $row = DB::table('users')->where('id', $user->id)->first();

        $this->assertNotSame($secret, $row->two_factor_secret);
        $this->assertStringNotContainsString($secret, (string) $row->two_factor_secret);
        $this->assertStringNotContainsString($codes[0], (string) $row->two_factor_recovery_codes);

        // Laravel's envelope, not something that merely looks scrambled.
        $this->assertIsArray(json_decode(base64_decode((string) $row->two_factor_secret), true));

        $reloaded = User::query()->findOrFail($user->id);

        $this->assertSame($secret, $reloaded->two_factor_secret);
        $this->assertSame($codes, $reloaded->recoveryCodes());

        // And it never travels out over the API either.
        $this->assertArrayNotHasKey('two_factor_secret', $reloaded->toArray());
    }

    #[Test]
    public function an_iban_written_before_encryption_existed_is_encrypted_by_the_migration(): void
    {
        $user = $this->makeUser('iban@example.test');
        $workspace = $this->makeWorkspace($user);
        $account = $this->makeAccount($workspace);

        // A row as it would have been left by the code that predates this
        // module: the column written directly, in the clear.
        DB::table('accounts')->where('id', $account->id)->update(['iban' => self::IBAN]);

        $migration = $this->ibanMigration();
        $migration->up();

        $raw = DB::table('accounts')->where('id', $account->id)->value('iban');

        $this->assertNotSame(self::IBAN, $raw);
        $this->assertStringNotContainsString('786457841326', (string) $raw);

        $this->assertSame(self::IBAN, $this->reload($account)->iban);

        // Running it again must not encrypt the ciphertext a second time.
        $migration->up();
        $this->assertSame(self::IBAN, $this->reload($account)->iban);

        // And down() really puts the plaintext back, rather than leaving a
        // column full of unreadable strings behind.
        $migration->down();
        $this->assertSame(self::IBAN, DB::table('accounts')->where('id', $account->id)->value('iban'));
    }

    #[Test]
    public function an_iban_saved_through_the_model_never_reaches_the_row_in_the_clear(): void
    {
        $user = $this->makeUser('iban2@example.test');
        $workspace = $this->makeWorkspace($user);

        $account = $this->inWorkspace($workspace, fn () => Account::query()->create([
            'name' => 'Bank',
            'type' => 'bank',
            'currency' => 'TRY',
            'iban' => self::IBAN,
        ]));

        $this->assertNotSame(
            self::IBAN,
            DB::table('accounts')->where('id', $account->id)->value('iban'),
        );
        $this->assertSame(self::IBAN, $this->reload($account)->iban);

        // A later edit re-encrypts rather than storing the new value plainly.
        $updated = $this->reload($account);
        $updated->iban = 'DE89370400440532013000';
        $updated->save();

        $this->assertNotSame(
            'DE89370400440532013000',
            DB::table('accounts')->where('id', $account->id)->value('iban'),
        );
        $this->assertSame('DE89370400440532013000', $this->reload($account)->iban);
    }

    #[Test]
    public function saving_an_untouched_record_does_not_report_the_iban_as_changed(): void
    {
        $user = $this->makeUser('iban3@example.test');
        $workspace = $this->makeWorkspace($user);
        $this->actingAs($user);

        $account = $this->inWorkspace($workspace, fn () => Account::query()->create([
            'name' => 'Bank', 'type' => 'bank', 'currency' => 'TRY', 'iban' => self::IBAN,
        ]));

        $this->inWorkspace($workspace, function () use ($account): void {
            $fresh = $this->reload($account);
            $fresh->name = 'Renamed bank';
            $fresh->save();
        });

        $entry = DB::table('audit_logs')
            ->where('action', 'account.updated')
            ->where('subject_id', $account->id)
            ->latest('created_at')
            ->first();

        // Re-encrypting on every save would make the iban look changed each
        // time and bury the field the reviewer is actually looking for.
        $this->assertNotNull($entry);
        $this->assertSame(['name'], array_keys(json_decode($entry->after, true)));
    }

    private function reload(Account $account): Account
    {
        return $this->inWorkspace(
            $account->workspace,
            fn () => Account::query()->findOrFail($account->id),
        );
    }

    private function ibanMigration(): Migration
    {
        return require base_path('modules/Security/Database/Migrations/2026_01_01_001320_encrypt_account_ibans.php');
    }
}
