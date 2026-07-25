<?php

declare(strict_types=1);

namespace Modules\Alerts\Support;

use Illuminate\Contracts\Container\Container;
use Modules\Alerts\Channels\DatabaseChannel;
use Modules\Alerts\Channels\EmailChannel;
use Modules\Alerts\Channels\PushChannel;
use Modules\Alerts\Channels\SmsChannel;
use Modules\Alerts\Channels\TelegramChannel;
use Modules\Alerts\Channels\WhatsAppChannel;
use Modules\Alerts\Contracts\NotificationChannel;
use Modules\Alerts\Exceptions\AlertException;
use Modules\Alerts\Models\ChannelKeys;

final readonly class ChannelRegistry
{
    /** @var array<string, class-string<NotificationChannel>> */
    private const ADAPTERS = [
        ChannelKeys::DATABASE => DatabaseChannel::class,
        ChannelKeys::PUSH => PushChannel::class,
        ChannelKeys::EMAIL => EmailChannel::class,
        ChannelKeys::SMS => SmsChannel::class,
        ChannelKeys::TELEGRAM => TelegramChannel::class,
        ChannelKeys::WHATSAPP => WhatsAppChannel::class,
    ];

    public function __construct(private Container $container) {}

    public function has(string $key): bool
    {
        return array_key_exists($key, self::ADAPTERS);
    }

    public function get(string $key): NotificationChannel
    {
        $class = self::ADAPTERS[$key] ?? throw AlertException::unknownChannel($key);

        /** @var NotificationChannel */
        return $this->container->make($class);
    }
}
