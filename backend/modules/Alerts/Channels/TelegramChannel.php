<?php

declare(strict_types=1);

namespace Modules\Alerts\Channels;

use Modules\Alerts\Models\ChannelKeys;

final class TelegramChannel extends LoggingChannel
{
    public function key(): string
    {
        return ChannelKeys::TELEGRAM;
    }
}
