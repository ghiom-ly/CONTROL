<?php

namespace App\Policies;

use App\Models\Issue;
use App\Models\User;

/** Gate::before يمنح المدير العام كل الصلاحيات */
class IssuePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('issues.view');
    }

    public function view(User $actor, Issue $issue): bool
    {
        return $actor->hasPermission('issues.view');
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('issues.manage');
    }

    public function update(User $actor, Issue $issue): bool
    {
        return $actor->hasPermission('issues.manage');
    }
}
