<?php

declare(strict_types=1);

namespace Modules\Alerts\Actions;

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\Alerts\Models\Alert;
use Modules\Alerts\Support\ChannelRegistry;
use Throwable;

/**
 * Hands one alert to each channel it was raised for and records the outcome per
 * channel.
 *
 * One channel failing must not cost the others their turn — an SMS provider
 * being down is not a reason for the in-app notification to disappear too — so
 * each send is isolated and its result written into `channels`.
 */
final readonly class DeliverAlert
{
    public function __construct(private ChannelRegistry $channels) {}

    public function handle(Alert $alert, ?User $user = null): Alert
    {
        $user ??= $alert->user;

        if ($user === null) {
            return $alert;
        }

        $results = $alert->deliveries();
        $anySent = false;

        foreach (array_keys($results) as $key) {
            if ($results[$key] !== Alert::DELIVERY_PENDING) {
                $anySent = $anySent || $results[$key] === Alert::DELIVERY_SENT;

                continue;
            }

            $results[$key] = $this->attempt($alert, $user, $key);
            $anySent = $anySent || $results[$key] === Alert::DELIVERY_SENT;
        }

        $alert->forceFill([
            'channels' => $results,
            'status' => $anySent ? Alert::STATUS_SENT : Alert::STATUS_FAILED,
            'sent_at' => $anySent ? CarbonImmutable::now() : null,
        ])->save();

        return $alert;
    }

    private function attempt(Alert $alert, User $user, string $channel): string
    {
        try {
            return $this->channels->get($channel)->send($alert, $user)
                ? Alert::DELIVERY_SENT
                : Alert::DELIVERY_FAILED;
        } catch (Throwable) {
            return Alert::DELIVERY_FAILED;
        }
    }
}
