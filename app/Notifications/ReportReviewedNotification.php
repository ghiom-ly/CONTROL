<?php

namespace App\Notifications;

use App\Enums\ReportStatus;
use App\Models\DailyReport;
use Illuminate\Notifications\Notification;

class ReportReviewedNotification extends Notification
{
    public function __construct(private readonly DailyReport $report) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $approved = $this->report->status === ReportStatus::Approved;

        return [
            'report_id' => $this->report->id,
            'report_number' => $this->report->report_number,
            'status' => $this->report->status->value,
            'reason' => $this->report->rejection_reason,
            'title' => $approved ? 'تم اعتماد تقريرك' : 'أُرجع تقريرك للتعديل',
            'message' => $this->report->report_number.($approved ? '' : ' — '.$this->report->rejection_reason),
            // صفحة العرض دائماً: فيها سبب الإرجاع، وزر التعديل يظهر فقط إن كان مسموحاً
            'url' => route('reports.show', $this->report),
        ];
    }
}
