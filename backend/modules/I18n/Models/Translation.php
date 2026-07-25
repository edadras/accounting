<?php

declare(strict_types=1);

namespace Modules\I18n\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One translated string.
 *
 * Not workspace-scoped: wording is a property of the product, not of anyone's
 * books, and the app needs it before a workspace has been chosen — in fact
 * before sign-in, so the login screen can speak the right language.
 */
final class Translation extends Model
{
    protected $fillable = ['locale', 'group', 'key', 'value', 'is_overridden'];

    protected function casts(): array
    {
        return [
            'is_overridden' => 'boolean',
            'updated_at' => 'datetime',
        ];
    }
}
