<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Channels
    |--------------------------------------------------------------------------
    |
    | Every channel is a thin adapter over a driver. Only the `log` driver is
    | implemented: it records the delivery and writes a line, which is what
    | tests and local development need and what keeps a provider credential out
    | of the repository. Naming a driver that has no adapter yet fails loudly
    | rather than swallowing the notification.
    |
    */

    'channels' => [
        'database' => ['driver' => 'database'],
        'push' => ['driver' => env('ALERTS_PUSH_DRIVER', 'log')],
        'email' => ['driver' => env('ALERTS_EMAIL_DRIVER', 'log')],
        'sms' => ['driver' => env('ALERTS_SMS_DRIVER', 'log')],
        'telegram' => ['driver' => env('ALERTS_TELEGRAM_DRIVER', 'log')],
        'whatsapp' => ['driver' => env('ALERTS_WHATSAPP_DRIVER', 'log')],
    ],

    /*
    |--------------------------------------------------------------------------
    | Quiet hours
    |--------------------------------------------------------------------------
    |
    | The fallback window for a member who has not set their own. Null on both
    | ends means no quiet hours at all, which is the default: deferring a
    | notification nobody asked to defer is its own kind of bug.
    |
    */

    'quiet_hours' => [
        'start' => env('ALERTS_QUIET_START'),
        'end' => env('ALERTS_QUIET_END'),
    ],

    'default_lead_days' => 3,

];
