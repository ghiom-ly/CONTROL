<?php

namespace App\Policies;

use App\Models\User;

/** ملخص الكود للموظف (shipments.lookup)، والتاريخ الكامل لمن له shipments.view. Gate::before للمدير العام. */
class ShipmentPolicy
{
    public function lookup(User $actor): bool
    {
        return $actor->hasPermission('shipments.lookup') || $actor->hasPermission('shipments.view');
    }

    public function viewHistory(User $actor): bool
    {
        return $actor->hasPermission('shipments.view');
    }
}
