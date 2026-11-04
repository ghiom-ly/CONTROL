<?php

namespace App\Enums;

enum ReportStatus: string
{
    use HasLabels;

    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'مسودة',
            self::PendingReview => 'قيد المراجعة',
            self::Approved => 'معتمد',
            self::Rejected => 'مرفوض',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::PendingReview => 'yellow',
            self::Approved => 'green',
            self::Rejected => 'red',
        };
    }
}
