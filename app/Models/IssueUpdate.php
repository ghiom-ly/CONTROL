<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IssueUpdate extends Model
{
    protected $fillable = ['issue_id', 'user_id', 'old_status', 'new_status', 'old_assignee_id', 'new_assignee_id', 'comment'];

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function newAssignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'new_assignee_id');
    }
}
