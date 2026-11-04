<?php

namespace App\Policies;

use App\Models\User;

/**
 * ملاحظة: Gate::before يمنح المدير العام كل الصلاحيات.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('users.view');
    }

    public function view(User $actor, User $user): bool
    {
        return $actor->is($user) || $actor->hasPermission('users.view');
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('users.manage');
    }

    public function update(User $actor, User $user): bool
    {
        // لا يستطيع غير المدير العام تعديل حساب مدير عام
        return $actor->hasPermission('users.manage') && ! $user->isSuperAdmin();
    }

    public function delete(User $actor, User $user): bool
    {
        return ! $actor->is($user) && $this->update($actor, $user);
    }
}
