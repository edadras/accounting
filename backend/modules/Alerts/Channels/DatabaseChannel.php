<?php

declare(strict_types=1);

namespace Modules\Alerts\Channels;

use App\Models\User;
use Modules\Alerts\Contracts\NotificationChannel;
use Modules\Alerts\Models\Alert;
use Modules\Alerts\Models\ChannelKeys;
use Modules\Alerts\Support\DeliveryLog;

/**
 * Always on, and the only channel that cannot fail: the alert row is itself the
 * delivery, and it was written before this ran.
 */
final class DatabaseChannel implements NotificationChannel
{
    public function __construct(private readonly DeliveryLog $deliveries) {}

    public function key(): string
    {
        return ChannelKeys::DATABASE;
    }

    public function send(Alert $alert, User $user): bool
    {
        $this->deliveries->record($this->key(), $alert, (int) $user->id);

        return true;
    }
}
