<?php

declare(strict_types=1);

namespace Modules\AI\Support;

use Modules\AI\Exceptions\AiException;
use Modules\Core\Models\Workspace;

/**
 * The opt-out, checked in one place.
 *
 * docs/07-security.md §5.6 requires that a user can switch the AI layer off
 * entirely and keep a working product. That is only true if every entry point
 * asks, so every Action asks here rather than each deciding for itself.
 */
final class AiSwitch
{
    public static function enabledFor(?Workspace $workspace): bool
    {
        if (! (bool) config('ai.enabled', true)) {
            return false;
        }

        $setting = data_get($workspace->settings ?? [], 'ai.enabled');

        return $setting === null ? true : (bool) $setting;
    }

    public static function assertEnabled(?Workspace $workspace): void
    {
        if (! self::enabledFor($workspace)) {
            throw AiException::disabled();
        }
    }
}
