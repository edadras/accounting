<?php

declare(strict_types=1);

namespace Modules\Travel\Http\Controllers\Concerns;

use Modules\Travel\Exceptions\TravelException;
use Modules\Travel\Models\Trip;

/**
 * Trips are looked up by hand rather than by route-model binding: binding is
 * substituted before ResolveWorkspace has run, so the workspace scope would not
 * yet know which workspace to constrain the lookup to.
 */
trait ResolvesTrip
{
    protected function trip(string $id): Trip
    {
        return Trip::query()->findOr($id, callback: fn () => throw TravelException::tripNotFound($id));
    }
}
