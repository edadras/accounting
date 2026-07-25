<?php

declare(strict_types=1);

namespace Modules\Alerts\Channels;

use Modules\Alerts\Models\ChannelKeys;

final class WhatsAppChannel extends LoggingChannel
{
    public function key(): string
    {
        return ChannelKeys::WHATSAPP;
    }
}
