<?php

declare(strict_types=1);

namespace Modules\Capture\Support;

use Modules\Capture\Models\IngestAlias;
use Modules\Core\Models\Workspace;

/**
 * Which books an inbound message is for.
 *
 * The recipient address is the whole routing decision, so it is read
 * carefully: the local part, minus any `+tag` a provider appended, hashed, and
 * looked up. Nothing in the message body — not a subject line, not a footer —
 * can influence which workspace it lands in.
 *
 * The alias lookup deliberately ignores the global workspace scope: at this
 * point in the request there is no active workspace, because finding it is what
 * this class is for.
 */
final class InboxAddress
{
    public static function token(string $address): ?string
    {
        $address = trim(mb_strtolower($address));

        // Providers hand over anything from "a@b" to "Books <a@b>".
        if (preg_match('/<([^>]+)>/', $address, $m) === 1) {
            $address = trim($m[1]);
        }

        $at = strrpos($address, '@');

        if ($at === false || $at === 0) {
            return null;
        }

        $local = substr($address, 0, $at);
        $plus = strrpos($local, '+');

        if ($plus !== false) {
            $local = substr($local, $plus + 1);
        }

        return $local === '' ? null : $local;
    }

    /** @return array{alias: IngestAlias, workspace: Workspace}|null */
    public static function resolve(string $address): ?array
    {
        $token = self::token($address);

        if ($token === null) {
            return null;
        }

        $alias = IngestAlias::query()
            ->withoutWorkspaceScope()
            ->active()
            ->where('token_hash', IngestAlias::hash($token))
            ->first();

        if ($alias === null) {
            return null;
        }

        $workspace = Workspace::query()->find($alias->workspace_id);

        return $workspace === null ? null : ['alias' => $alias, 'workspace' => $workspace];
    }
}
