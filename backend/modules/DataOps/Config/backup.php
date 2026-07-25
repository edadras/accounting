<?php

declare(strict_types=1);

use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes;

/*
|--------------------------------------------------------------------------
| spatie/laravel-backup, as Finora configures it
|--------------------------------------------------------------------------
|
| This file stays inside the module rather than being published into the root
| config/ directory: installing DataOps is one provider, not a publish step
| somebody can forget. DataOpsServiceProvider lays these values over the
| package's own defaults, so only what Finora actually changes is listed here.
|
| The settings come from docs/07-security.md §3 and §7.
|
*/

return [

    'backup' => [
        'name' => env('BACKUP_NAME', 'finora'),

        'source' => [
            'files' => [
                'include' => [base_path()],

                'exclude' => [
                    base_path('vendor'),
                    base_path('node_modules'),
                    storage_path('framework'),

                    // User exports are complete copies of a workspace with a
                    // few days to live; backing them up doubles the amount of
                    // personal data at rest for nothing.
                    storage_path('app/'.env('DATAOPS_EXPORT_DIRECTORY', 'exports')),
                ],

                'relative_path' => base_path(),
            ],

            'databases' => [env('DB_CONNECTION', 'pgsql')],
        ],

        'destination' => [
            // §7: a separate object store, not the disk the application runs on.
            'disks' => [env('BACKUP_DISK', 'local')],
        ],

        // §3: "backup — encrypted with a key separate from the database".
        'password' => env('BACKUP_ARCHIVE_PASSWORD'),
        'encryption' => 'default',

        // Opening the zip again before calling it a backup is the cheap half of
        // "a backup nobody has verified is not a backup"; `backup:verify` is
        // the half that keeps checking afterwards.
        'verify_backup' => true,
    ],

    'monitor_backups' => [
        [
            'name' => env('BACKUP_NAME', 'finora'),
            'disks' => [env('BACKUP_DISK', 'local')],
            'health_checks' => [
                MaximumAgeInDays::class => 1,
                MaximumStorageInMegabytes::class => 5000,
            ],
        ],
    ],

    'cleanup' => [
        // §7: 7 daily, 4 weekly, 12 monthly.
        'default_strategy' => [
            'keep_all_backups_for_days' => 7,
            'keep_daily_backups_for_days' => 7,
            'keep_weekly_backups_for_weeks' => 4,
            'keep_monthly_backups_for_months' => 12,
            'keep_yearly_backups_for_years' => 2,
            'delete_oldest_backups_when_using_more_megabytes_than' => 5000,
        ],
    ],

];
