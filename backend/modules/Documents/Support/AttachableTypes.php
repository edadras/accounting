<?php

declare(strict_types=1);

namespace Modules\Documents\Support;

use Illuminate\Database\Eloquent\Model;
use Modules\Documents\Exceptions\DocumentException;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\Transaction;

/**
 * The closed set of records a document may hang off.
 *
 * The request names an alias, never a class. Resolving a class name straight
 * out of a request body would let a caller reach any Eloquent model in the
 * application — including ones with no workspace scope.
 */
final class AttachableTypes
{
    /** @var array<string, class-string<Model>> */
    private const MAP = [
        'transaction' => Transaction::class,
        'category' => Category::class,
        'account' => Account::class,
    ];

    /** @return list<string> */
    public static function aliases(): array
    {
        return array_keys(self::MAP);
    }

    /** @return class-string<Model> */
    public static function resolve(string $alias): string
    {
        return self::MAP[$alias]
            ?? throw DocumentException::forbiddenAttachableType($alias, self::aliases());
    }

    public static function aliasFor(string $class): ?string
    {
        $alias = array_search($class, self::MAP, true);

        return $alias === false ? null : $alias;
    }
}
