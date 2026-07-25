<?php

declare(strict_types=1);

namespace Tests\Feature\DataOps;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

final class BackupVerifyTest extends DataOpsTestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        config(['backup.backup.destination.disks' => ['local']]);

        $this->directory = (string) config('backup.backup.name');
    }

    #[Test]
    public function the_module_configures_the_backup_package_from_inside_itself(): void
    {
        // The config file is never published to the root config/ directory, so
        // these values can only have come from the module.
        $this->assertFalse(file_exists(config_path('backup.php')));
        $this->assertSame('finora', config('backup.backup.name'));
        $this->assertTrue(config('backup.backup.verify_backup'));
        $this->assertSame(7, config('backup.cleanup.default_strategy.keep_all_backups_for_days'));
        $this->assertSame(4, config('backup.cleanup.default_strategy.keep_weekly_backups_for_weeks'));
        $this->assertSame(12, config('backup.cleanup.default_strategy.keep_monthly_backups_for_months'));
    }

    #[Test]
    public function verification_fails_when_there_is_no_backup_at_all(): void
    {
        $this->artisan('backup:verify')
            ->expectsOutputToContain('No backup found')
            ->assertExitCode(1);
    }

    #[Test]
    public function verification_passes_for_a_fresh_non_empty_backup(): void
    {
        Storage::disk('local')->put("{$this->directory}/2026-07-25-030000.zip", 'pretend-this-is-a-zip');

        $this->artisan('backup:verify')->assertExitCode(0);
    }

    #[Test]
    public function an_empty_archive_is_not_a_backup(): void
    {
        Storage::disk('local')->put("{$this->directory}/2026-07-25-030000.zip", '');

        $this->artisan('backup:verify')
            ->expectsOutputToContain('is empty')
            ->assertExitCode(1);
    }

    #[Test]
    public function a_backup_older_than_the_configured_age_fails(): void
    {
        Storage::disk('local')->put("{$this->directory}/2026-07-25-030000.zip", 'pretend-this-is-a-zip');

        $this->travel(config('dataops.backup.max_age_hours') + 2)->hours();

        $this->artisan('backup:verify')
            ->expectsOutputToContain('the limit is')
            ->assertExitCode(1);
    }
}
