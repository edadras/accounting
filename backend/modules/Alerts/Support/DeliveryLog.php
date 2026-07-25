<?php

declare(strict_types=1);

namespace Modules\Alerts\Support;

use Modules\Alerts\Models\Alert;

/**
 * What the log driver would have sent, kept in memory for the life of the
 * process.
 *
 * This is the seam that lets a test assert "the SMS channel was used once and
 * the push channel not at all" without a provider, a credential or a network
 * call.
 */
final class DeliveryLog
{
    /** @var list<array{channel:string,alert_id:string,user_id:int,type:string}> */
    private array $entries = [];

    public function record(string $channel, Alert $alert, int $userId): void
    {
        $this->entries[] = [
            'channel' => $channel,
            'alert_id' => $alert->id,
            'user_id' => $userId,
            'type' => $alert->type,
        ];
    }

    /** @return list<array{channel:string,alert_id:string,user_id:int,type:string}> */
    public function entries(?string $channel = null): array
    {
        return $channel === null
            ? $this->entries
            : array_values(array_filter($this->entries, fn (array $e) => $e['channel'] === $channel));
    }

    public function countFor(string $channel): int
    {
        return count($this->entries($channel));
    }

    public function flush(): void
    {
        $this->entries = [];
    }
}
