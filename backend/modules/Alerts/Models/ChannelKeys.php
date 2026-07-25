<?php

declare(strict_types=1);

namespace Modules\Alerts\Models;

/** The channel names shared by rules, preferences and delivery records. */
final class ChannelKeys
{
    public const DATABASE = 'database';

    public const PUSH = 'push';

    public const EMAIL = 'email';

    public const SMS = 'sms';

    public const TELEGRAM = 'telegram';

    public const WHATSAPP = 'whatsapp';

    /** @var list<string> */
    public const ALL = [
        self::DATABASE,
        self::PUSH,
        self::EMAIL,
        self::SMS,
        self::TELEGRAM,
        self::WHATSAPP,
    ];
}
