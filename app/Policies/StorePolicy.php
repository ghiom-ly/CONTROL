<?php

namespace App\Policies;

use App\Models\Store;
use App\Models\User;

/**
 * ملاحظة: Gate::before يمنح المدير العام كل الصلاحيات.
 */
class StorePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('stores.view');
    }

    public function view(User $actor, Store $store): bool
    {
        return $actor->hasPermission('stores.view');
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('stores.manage');
    }

    public function update(User $actor, Store $store): bool
    {
        return $actor->hasPermission('stores.manage');
    }

    public function archive(User $actor, Store $store): bool
    {
        return $actor->hasPermission('stores.manage');
    }

    /** الحذف النهائي للمدير العام فقط (عبر Gate::before) */
    public function bulkDelete(User $actor): bool
    {
        return false;
    }
}
