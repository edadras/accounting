<?php

declare(strict_types=1);

namespace Modules\Security\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Installs Laravel's `encrypted` cast on a model this module does not own.
 *
 * Encryption at rest is a security policy (docs/07-security.md §3), and the
 * modules whose columns it covers — `accounts.iban` lives in Ledger — should
 * not have to know about it. Adding the cast from here keeps the policy and its
 * migration in one place, and means a module can be brought under encryption
 * without editing it.
 *
 * The cast is merged onto the instance rather than declared on the class, which
 * covers the two moments that matter:
 *
 * - `retrieved` — every hydrated record carries the cast, so reads decrypt and
 *   later writes re-encrypt exactly as a declared cast would;
 * - `saving` — a record built from scratch has no cast yet, so its plaintext is
 *   pushed back through one before the row is written.
 *
 * Values that are already ciphertext are left untouched, so re-saving a record
 * neither double-encrypts it nor marks the column dirty — an unchanged column
 * must not show up as a change in the audit trail.
 */
final class EncryptedColumns
{
    /**
     * @param  class-string<Model>  $model
     * @param  list<string>  $columns
     */
    public static function install(string $model, array $columns): void
    {
        $casts = array_fill_keys($columns, 'encrypted');

        $model::retrieved(static function (Model $record) use ($casts): void {
            $record->mergeCasts($casts);
        });

        $model::saving(static function (Model $record) use ($casts, $columns): void {
            $raw = $record->getAttributes();

            $record->mergeCasts($casts);

            foreach ($columns as $column) {
                $value = $raw[$column] ?? null;

                if (! is_string($value) || ColumnCipher::isEncrypted($value)) {
                    continue;
                }

                // The cast is in place now, so this assignment encrypts.
                $record->setAttribute($column, $value);
            }
        });
    }
}
