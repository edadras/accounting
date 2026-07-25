<?php

use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;
use Modules\AI\Providers\AIServiceProvider;
use Modules\Alerts\Providers\AlertsServiceProvider;
use Modules\Assets\Providers\AssetsServiceProvider;
use Modules\Audit\Providers\AuditServiceProvider;
use Modules\Banking\Providers\BankingServiceProvider;
use Modules\Billing\Providers\BillingServiceProvider;
use Modules\Budget\Providers\BudgetServiceProvider;
use Modules\Buildings\Providers\BuildingsServiceProvider;
use Modules\Business\Providers\BusinessServiceProvider;
use Modules\Capture\Providers\CaptureServiceProvider;
use Modules\Core\Providers\CoreServiceProvider;
use Modules\DataOps\Providers\DataOpsServiceProvider;
use Modules\Documents\Providers\DocumentsServiceProvider;
use Modules\Family\Providers\FamilyServiceProvider;
use Modules\I18n\Providers\I18nServiceProvider;
use Modules\Investment\Providers\InvestmentServiceProvider;
use Modules\Ledger\Providers\LedgerServiceProvider;
use Modules\MarketData\Providers\MarketDataServiceProvider;
use Modules\Recurring\Providers\RecurringServiceProvider;
use Modules\Reports\Providers\ReportsServiceProvider;
use Modules\Search\Providers\SearchServiceProvider;
use Modules\Security\Providers\SecurityServiceProvider;
use Modules\Sync\Providers\SyncServiceProvider;
use Modules\Payroll\Providers\PayrollServiceProvider;
use Modules\Travel\Providers\TravelServiceProvider;

return [
    AppServiceProvider::class,
    HorizonServiceProvider::class,

    // Domain modules. Each owns its migrations, routes and bindings; installing
    // one is a single line here and nothing else.
    //
    // The list is ordered, not alphabetical, and the order is load-bearing at
    // both ends. Core installs the workspace context every other module's global
    // scope reads. Ledger owns the accounts and categories the rest post
    // against. Audit must precede the models whose observers reach for its
    // recorder. At the bottom, Capture turns messages into drafts through the AI
    // module, Alerts scans the modules above it for due dates, and Search hooks
    // their model events to keep its index in step.
    CoreServiceProvider::class,
    LedgerServiceProvider::class,

    AuditServiceProvider::class,
    I18nServiceProvider::class,
    SecurityServiceProvider::class,
    DataOpsServiceProvider::class,
    MarketDataServiceProvider::class,

    BudgetServiceProvider::class,
    ReportsServiceProvider::class,
    BankingServiceProvider::class,
    InvestmentServiceProvider::class,
    AssetsServiceProvider::class,
    BuildingsServiceProvider::class,
    BusinessServiceProvider::class,
    PayrollServiceProvider::class,
    TravelServiceProvider::class,
    FamilyServiceProvider::class,
    RecurringServiceProvider::class,
    DocumentsServiceProvider::class,
    BillingServiceProvider::class,

    AIServiceProvider::class,
    CaptureServiceProvider::class,
    SyncServiceProvider::class,

    AlertsServiceProvider::class,
    SearchServiceProvider::class,
];
