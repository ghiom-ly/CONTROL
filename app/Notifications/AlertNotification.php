<?php

namespace App\Notifications;

use App\Models\Alert;
use Illuminate\Notifications\Notification;

class AlertNotification extends Notification
{
    public function __construct(private readonly Alert $alert) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'alert_id' => $this->alert->id,
            'title' => 'تنبيه: '.$this->alert->title,
            'message' => (string) $this->alert->details,
            'url' => route('alerts.index'),
        ];
    }
}
