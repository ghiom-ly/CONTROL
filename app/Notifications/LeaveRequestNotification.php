<?php

namespace App\Notifications;

use App\Models\LeaveRequest;
use Illuminate\Notifications\Notification;

/** طلب جديد ⇒ للمديرين؛ قرار ⇒ للموظف */
class LeaveRequestNotification extends Notification
{
    public function __construct(private readonly LeaveRequest $request, private readonly bool $forManager) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $r = $this->request;
        if ($this->forManager) {
            return ['leave_request_id' => $r->id, 'title' => 'طلب جديد: '.$r->user->name,
                'message' => $r->summary(), 'url' => route('leave-requests.index')];
        }

        return ['leave_request_id' => $r->id,
            'title' => $r->status === 'approved' ? 'تم قبول طلبك' : 'تم رفض طلبك',
            'message' => $r->summary().($r->decision_note ? ' — '.$r->decision_note : ''),
            'url' => route('my-requests.index')];
    }
}
