<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification;

class AttendanceAlertNotification extends Notification
{
    public function __construct(public readonly User $employee, public readonly string $kind) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $msg = $this->kind === 'not_started'
            ? "{$this->employee->name} لم يبدأ شفته بعد"
            : "{$this->employee->name} بدأ شفته ولم يرسل التقرير";

        return [
            'title' => $this->kind === 'not_started' ? 'تأخر في بدء الشفت' : 'تقرير لم يُرسل',
            'message' => $msg,
            'kind' => $this->kind,
            'user_id' => $this->employee->id,
            'url' => route('attendance.show', $this->employee),
        ];
    }
}
