<?php

namespace App\Notifications;

use App\Models\Alert;
use Illuminate\Notifications\Notification;

/** إشعار واحد يلخّص التنبيهات الجديدة في تشغيل واحد */
class AlertNotification extends Notification
{
    /** @param list<Alert> $alerts */
    public function __construct(private readonly array $alerts) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $n = count($this->alerts);
        $first = $this->alerts[0];

        return [
            'alert_ids' => array_map(fn (Alert $a) => $a->id, $this->alerts),
            'title' => $n === 1 ? 'تنبيه: '.$first->title : "{$n} تنبيهات جديدة",
            'message' => $n === 1 ? (string) $first->details : $first->title.' — و '.($n - 1).' أخرى',
            'url' => route('alerts.index'),
        ];
    }
}
