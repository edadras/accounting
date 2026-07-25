<?php

use App\Providers\AppServiceProvider;
use Modules\Assets\Providers\AssetsServiceProvider;
use Modules\Banking\Providers\BankingServiceProvider;
use Modules\Budget\Providers\BudgetServiceProvider;
use Modules\Buildings\Providers\BuildingsServiceProvider;
use Modules\Business\Providers\BusinessServiceProvider;
use Modules\Core\Providers\CoreServiceProvider;
use Modules\Documents\Providers\DocumentsServiceProvider;
use Modules\Investment\Providers\InvestmentServiceProvider;
use Modules\Ledger\Providers\LedgerServiceProvider;
use Modules\Reports\Providers\ReportsServiceProvider;
use Modules\Search\Providers\SearchServiceProvider;
use Modules\Travel\Providers\TravelServiceProvider;

return [
    AppServiceProvider::class,

    // Domain modules. Each owns its migrations, routes and bindings; installing
    // one is a single line here and nothing else.
    //
    // Order matters only for the first two: Core installs the workspace context
    // that every other module's global scope reads, and Ledger owns the accounts
    // and categories the rest post against.
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
    DocumentsServiceProvider::class,

    // Search last: it hooks model events on the modules above to keep its index
    // in step, so they must already be registered.
    SearchServiceProvider::class,
];
