<?php

declare(strict_types=1);

namespace Modules\Alerts\Channels;

use Modules\Alerts\Models\ChannelKeys;

final class SmsChannel extends LoggingChannel
{
    public function key(): string
    {
        return ChannelKeys::SMS;
    }
}
