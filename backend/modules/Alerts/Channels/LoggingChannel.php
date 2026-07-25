<?php

declare(strict_types=1);

namespace Modules\Alerts\Channels;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Modules\Alerts\Contracts\NotificationChannel;
use Modules\Alerts\Exceptions\AlertException;
use Modules\Alerts\Models\Alert;
use Modules\Alerts\Support\DeliveryLog;

/**
 * The shared body of every outbound adapter.
 *
 * Each concrete channel is only a name and a transport choice, so the transport
 * lives here once. The single implemented driver is `log`: it records the
 * delivery and writes a line. A channel pointed at anything else refuses,
 * rather than pretending it delivered.
 */
abstract class LoggingChannel implements NotificationChannel
{
    public function __construct(protected readonly DeliveryLog $deliveries) {}

    public function send(Alert $alert, User $user): bool
    {
        $driver = (string) config("alerts.channels.{$this->key()}.driver", 'log');

        if ($driver !== 'log') {
            throw AlertException::driverNotImplemented($this->key(), $driver);
        }

        $this->deliveries->record($this->key(), $alert, (int) $user->id);

        Log::info('alerts.delivered', [
            'channel' => $this->key(),
            'alert_id' => $alert->id,
            'user_id' => $user->id,
            'type' => $alert->type,
        ]);

        return true;
    }
}
