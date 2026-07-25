<?php

declare(strict_types=1);

namespace Modules\Alerts\Support;

use Illuminate\Contracts\Container\Container;
use Modules\Alerts\Contracts\AlertScanner;
use Modules\Alerts\Exceptions\AlertException;
use Modules\Alerts\Models\AlertRule;
use Modules\Alerts\Scanners\BudgetThresholdScanner;
use Modules\Alerts\Scanners\CheckDueScanner;
use Modules\Alerts\Scanners\InstallmentDueScanner;
use Modules\Alerts\Scanners\LowBalanceScanner;

final readonly class ScannerRegistry
{
    /** @var array<string, class-string<AlertScanner>> */
    private const SCANNERS = [
        AlertRule::TYPE_CHECK_DUE => CheckDueScanner::class,
        AlertRule::TYPE_INSTALLMENT_DUE => InstallmentDueScanner::class,
        AlertRule::TYPE_BUDGET_THRESHOLD => BudgetThresholdScanner::class,
        AlertRule::TYPE_LOW_BALANCE => LowBalanceScanner::class,
    ];

    public function __construct(private Container $container) {}

    public function get(string $type): AlertScanner
    {
        $class = self::SCANNERS[$type] ?? throw AlertException::unknownRuleType($type);

        /** @var AlertScanner */
        return $this->container->make($class);
    }
}
