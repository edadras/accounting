<?php

declare(strict_types=1);

namespace Modules\I18n\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\I18n\Models\Translation;

final class TranslationController
{
    private const LOCALES = ['fa', 'en', 'tr', 'ar'];

    /**
     * The whole dictionary for a locale, or only what changed since a moment.
     *
     * This endpoint is the reason a wording fix does not need a new app build:
     * the client keeps a bundled fallback, overlays whatever it gets here, and
     * asks again with `since=` so the usual answer is empty.
     *
     * Unauthenticated on purpose — the login screen needs its own words, and a
     * dictionary is not a secret.
     */
    public function show(Request $request, string $locale): JsonResponse
    {
        abort_unless(in_array($locale, self::LOCALES, true), 404);

        $query = Translation::query()
            ->where('locale', $locale)
            ->whereNotNull('value');

        $since = $request->query('since');

        if (is_string($since) && $since !== '') {
            try {
                $query->where('updated_at', '>', new \DateTimeImmutable($since));
            } catch (\Exception) {
                abort(422, 'Invalid `since` timestamp.');
            }
        }

        $rows = $query->get(['group', 'key', 'value', 'updated_at']);

        $data = [];
        foreach ($rows as $row) {
            // The client's keys are flat: "transaction.create.title". The group
            // is a filing convenience on this side only.
            $data[$row->group === 'app' ? $row->key : "{$row->group}.{$row->key}"] = $row->value;
        }

        return response()->json([
            'data' => (object) $data,
            'meta' => [
                'locale' => $locale,
                'count' => count($data),
                // The client sends this back as `since` next time. Taking it
                // from the server's clock, not the device's, is what stops a
                // skewed phone from missing an update forever.
                'checked_at' => now()->toIso8601String(),
                'version' => Translation::query()->where('locale', $locale)->max('updated_at'),
            ],
        ]);
    }

    /**
     * Override one string. Authenticated, because changing what the product
     * says to everyone is not something an anonymous caller should do.
     */
    public function upsert(Request $request): JsonResponse
    {
        $data = $request->validate([
            'locale' => ['required', Rule::in(self::LOCALES)],
            'group' => ['nullable', 'string', 'max:64'],
            'key' => ['required', 'string', 'max:191'],
            'value' => ['required', 'string', 'max:5000'],
        ]);

        $translation = Translation::query()->updateOrCreate(
            [
                'locale' => $data['locale'],
                'group' => $data['group'] ?? 'app',
                'key' => $data['key'],
            ],
            [
                'value' => $data['value'],
                'is_overridden' => true,
            ],
        );

        return response()->json([
            'data' => [
                'locale' => $translation->locale,
                'group' => $translation->group,
                'key' => $translation->key,
                'value' => $translation->value,
                'is_overridden' => $translation->is_overridden,
                'updated_at' => $translation->updated_at?->toIso8601String(),
            ],
        ]);
    }
}
