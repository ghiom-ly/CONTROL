<?php

namespace App\Notifications;

use App\Models\TopupRequest;
use Illuminate\Notifications\Notification;

/** طلب جديد ⇒ للمدير العام؛ قرار أو بلاغ عطل ⇒ حسب الطرف. لا يحتوي رقم الكرت أبداً */
class TopupRequestNotification extends Notification
{
    public function __construct(private readonly TopupRequest $request, private readonly string $event) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $r = $this->request;

        return match ($this->event) {
            'new' => ['topup_request_id' => $r->id, 'title' => 'طلب كرت تعبئة: '.$r->user->name, 'message' => $r->summary(), 'url' => route('topups.index')],
            'faulty' => ['topup_request_id' => $r->id, 'title' => 'كرت لا يعمل: '.$r->user->name, 'message' => $r->summary().' — '.$r->employee_note, 'url' => route('topups.index', ['status' => 'faulty'])],
            default => ['topup_request_id' => $r->id,
                'title' => $r->status === 'issued' ? 'تم إدراج كرت التعبئة' : 'تم رفض طلب الكرت',
                'message' => $r->summary().($r->decision_note ? ' — '.$r->decision_note : ''), 'url' => route('my-topups.index')],
        };
    }
}
