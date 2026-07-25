<?php

use App\Providers\AppServiceProvider;
use Modules\AI\Providers\AIServiceProvider;
use Modules\Alerts\Providers\AlertsServiceProvider;
use Modules\Assets\Providers\AssetsServiceProvider;
use Modules\Banking\Providers\BankingServiceProvider;
use Modules\Billing\Providers\BillingServiceProvider;
use Modules\Budget\Providers\BudgetServiceProvider;
use Modules\Buildings\Providers\BuildingsServiceProvider;
use Modules\Business\Providers\BusinessServiceProvider;
use Modules\Core\Providers\CoreServiceProvider;
use Modules\Documents\Providers\DocumentsServiceProvider;
use Modules\Family\Providers\FamilyServiceProvider;
use Modules\Investment\Providers\InvestmentServiceProvider;
use Modules\Ledger\Providers\LedgerServiceProvider;
use Modules\Recurring\Providers\RecurringServiceProvider;
use Modules\Reports\Providers\ReportsServiceProvider;
use Modules\Search\Providers\SearchServiceProvider;
use Modules\Sync\Providers\SyncServiceProvider;
use Modules\Travel\Providers\TravelServiceProvider;

return [
    AppServiceProvider::class,

    // Domain modules. Each owns its migrations, routes and bindings; installing
    // one is a single line here and nothing else.
    //
    // Order matters only at the edges: Core installs the workspace context that
    // every other module's global scope reads, Ledger owns the accounts and
    // categories the rest post against, and the last two hook the modules above.
    CoreServiceProvider::class,
    LedgerServiceProvider::class,

    BudgetServiceProvider::class,
    ReportsServiceProvider::class,
    BankingServiceProvider::class,
    InvestmentServiceProvider::class,
    AssetsServiceProvider::class,
    BuildingsServiceProvider::class,
    BusinessServiceProvider::class,
    TravelServiceProvider::class,
    FamilyServiceProvider::class,
    RecurringServiceProvider::class,
    DocumentsServiceProvider::class,
    BillingServiceProvider::class,
    AIServiceProvider::class,
    SyncServiceProvider::class,

    // Alerts scans the modules above for due cheques, instalments and budgets;
    // Search hooks their model events to keep its index in step. Both need the
    // rest registered first.
    AlertsServiceProvider::class,
    SearchServiceProvider::class,
];
