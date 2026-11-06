<?php

namespace App\Notifications;

use App\Models\Issue;
use Illuminate\Notifications\Notification;

class IssueNotification extends Notification
{
    public function __construct(private readonly Issue $issue, private readonly string $kind) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'issue_id' => $this->issue->id,
            'title' => ($this->kind === 'assigned' ? 'أُسندت إليك مشكلة: ' : 'مشكلة جديدة: ').$this->issue->title,
            'message' => $this->issue->issue_number.' — '.($this->issue->store?->name ?? ''),
            'url' => route('issues.show', $this->issue),
        ];
    }
}
