<?php

declare(strict_types=1);

namespace Modules\Alerts\Contracts;

use App\Models\User;
use Modules\Alerts\Models\Alert;

/**
 * One way of reaching a person.
 *
 * Implementations are adapters and nothing more: they carry an already-built
 * alert to a transport. Whether the alert should exist, who it is for and when
 * it may be sent are decided before anything gets here.
 */
interface NotificationChannel
{
    public function key(): string;

    /** False means this channel failed; the others still get their turn. */
    public function send(Alert $alert, User $user): bool;
}
