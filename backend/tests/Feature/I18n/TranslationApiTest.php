<?php

declare(strict_types=1);

namespace Tests\Feature\I18n;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Laravel\Sanctum\Sanctum;
use Modules\I18n\Models\Translation;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\LedgerTestCase;

/**
 * This endpoint is the whole reason a wording fix does not need a new app
 * build: the client ships a fallback dictionary, overlays whatever it gets
 * here, and asks again with `since=` so the usual answer is empty.
 */
final class TranslationApiTest extends LedgerTestCase
{
    use RefreshDatabase;

    private function store(string $locale, string $key, string $value, string $group = 'app'): Translation
    {
        return Translation::query()->create([
            'locale' => $locale,
            'group' => $group,
            'key' => $key,
            'value' => $value,
        ]);
    }

    #[Test]
    public function the_dictionary_is_readable_without_signing_in(): void
    {
        // The sign-in screen needs its own words before anyone has a token.
        $this->store('fa', 'auth.signIn', 'ورود');

        $response = $this->getJson('/api/v1/translations/fa')->assertOk();

        // The keys themselves contain dots, so read the map rather than using
        // a dotted JSON path.
        $this->assertSame('ورود', $response->json('data')['auth.signIn']);
        $this->assertSame('fa', $response->json('meta.locale'));
    }

    #[Test]
    public function an_unsupported_locale_is_rejected(): void
    {
        $this->getJson('/api/v1/translations/xx')->assertNotFound();
    }

    #[Test]
    public function a_locale_only_ever_returns_its_own_strings(): void
    {
        $this->store('fa', 'nav.dashboard', 'داشبورد');
        $this->store('en', 'nav.dashboard', 'Dashboard');

        $data = $this->getJson('/api/v1/translations/en')->assertOk()->json('data');

        $this->assertSame(['nav.dashboard' => 'Dashboard'], $data);
    }

    #[Test]
    public function since_returns_only_what_changed(): void
    {
        $this->store('en', 'nav.dashboard', 'Dashboard');

        $checkedAt = $this->getJson('/api/v1/translations/en')->json('meta.checked_at');

        // Nothing has moved, so a second ask is empty — which is what makes
        // polling on every app start cheap.
        $this->getJson('/api/v1/translations/en?since='.urlencode($checkedAt))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->travel(2)->seconds();
        $this->store('en', 'nav.reports', 'Reports');

        $response = $this->getJson('/api/v1/translations/en?since='.urlencode($checkedAt))
            ->assertOk();

        $this->assertSame(['nav.reports' => 'Reports'], $response->json('data'));
    }

    #[Test]
    public function a_malformed_since_is_rejected_rather_than_ignored(): void
    {
        // Silently returning the whole dictionary would hide a client bug and
        // quietly cost the user their bandwidth on every start.
        $this->store('en', 'nav.dashboard', 'Dashboard');

        $this->getJson('/api/v1/translations/en?since=not-a-date')->assertStatus(422);
    }

    #[Test]
    public function a_key_with_no_value_is_not_served(): void
    {
        // `i18n:missing` records untranslated keys as empty rows; serving them
        // would replace a working English fallback with a blank label.
        Translation::query()->create([
            'locale' => 'tr', 'group' => 'app', 'key' => 'nav.reports', 'value' => null,
        ]);

        $this->getJson('/api/v1/translations/tr')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function an_authenticated_caller_can_override_a_string(): void
    {
        $this->store('en', 'nav.reports', 'Reports');

        Sanctum::actingAs($this->makeUser('editor@example.test'));

        $this->putJson('/api/v1/translations', [
            'locale' => 'en',
            'key' => 'nav.reports',
            'value' => 'Insights',
        ])->assertOk()->assertJsonPath('data.is_overridden', true);

        $this->assertSame(
            'Insights',
            $this->getJson('/api/v1/translations/en')->json('data')['nav.reports'],
        );
    }

    #[Test]
    public function overriding_requires_authentication(): void
    {
        $this->putJson('/api/v1/translations', [
            'locale' => 'en', 'key' => 'nav.reports', 'value' => 'Hacked',
        ])->assertUnauthorized();
    }

    #[Test]
    public function the_missing_command_reports_gaps_per_locale(): void
    {
        $this->store('en', 'nav.dashboard', 'Dashboard');
        $this->store('en', 'nav.reports', 'Reports');
        $this->store('fa', 'nav.dashboard', 'داشبورد');

        // A key present in English and missing in Persian falls back silently
        // at runtime, which nobody notices until a Persian speaker does.
        $missing = $this->artisan('i18n:missing');

        // artisan() degrades to a bare exit code when console output is not
        // mocked; these tests keep it mocked, so there is a command to drive.
        $this->assertInstanceOf(PendingCommand::class, $missing);

        $missing->expectsOutputToContain('1 missing translations')
            ->assertSuccessful();
    }
}
