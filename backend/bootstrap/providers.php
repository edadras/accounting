<?php

use App\Providers\AppServiceProvider;
use Modules\Core\Providers\CoreServiceProvider;
use Modules\Ledger\Providers\LedgerServiceProvider;

return [
    AppServiceProvider::class,

    // Domain modules. Each owns its migrations, routes and bindings; adding a
    // module is one line here and nothing else.
    CoreServiceProvider::class,
    LedgerServiceProvider::class,
];
