<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Security\Support\ColumnCipher;

/**
 * Encrypts `accounts.iban` at rest (docs/07-security.md §3).
 *
 * The column already exists and may hold plaintext, so this migration has to
 * carry that data across rather than only changing the shape of the table. Both
 * directions are written to be safe to run twice: `ColumnCipher` recognises a
 * value it has already converted and leaves it alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 34 characters of IBAN become a few hundred of ciphertext.
        $this->resize(fn (Blueprint $table) => $table->text('iban')->nullable()->change(), 'text');

        $this->convert(static fn (string $iban): ?string => ColumnCipher::isEncrypted($iban)
            ? null
            : ColumnCipher::encrypt($iban));
    }

    public function down(): void
    {
        // Decrypt first: narrowing the column while it still holds ciphertext
        // would truncate it, and then there would be nothing to decrypt.
        $this->convert(static fn (string $iban): ?string => ColumnCipher::isEncrypted($iban)
            ? ColumnCipher::decrypt($iban)
            : null);

        $this->resize(fn (Blueprint $table) => $table->string('iban', 34)->nullable()->change(), 'varchar');
    }

    /**
     * Rewrites every non-null iban for which $convert returns a new value.
     *
     * @param  callable(string): ?string  $convert  null means "leave this row alone"
     */
    private function convert(callable $convert): void
    {
        DB::table('accounts')
            ->whereNotNull('iban')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($convert): void {
                foreach ($rows as $row) {
                    $converted = $convert((string) $row->iban);

                    if ($converted === null) {
                        continue;
                    }

                    DB::table('accounts')->where('id', $row->id)->update(['iban' => $converted]);
                }
            });
    }

    /**
     * Changes the column only when it is not already of $currentType — a column
     * rebuild is expensive on a large table and, on SQLite, disruptive enough
     * that it is worth not repeating.
     */
    private function resize(callable $definition, string $targetType): void
    {
        if (Schema::getColumnType('accounts', 'iban') === $targetType) {
            return;
        }

        Schema::table('accounts', function (Blueprint $table) use ($definition): void {
            $definition($table);
        });
    }
};
