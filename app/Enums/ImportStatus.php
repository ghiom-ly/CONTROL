<?php

namespace App\Enums;

enum ImportStatus: string
{
    use HasLabels;

    case Uploaded = 'uploaded';
    case Processing = 'processing';
    case NeedsReview = 'needs_review';
    case Approved = 'approved';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Uploaded => 'تم الرفع',
            self::Processing => 'قيد المعالجة',
            self::NeedsReview => 'بانتظار المراجعة',
            self::Approved => 'معتمد',
            self::Failed => 'فشل',
            self::Cancelled => 'ملغي',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Uploaded => 'gray',
            self::Processing => 'blue',
            self::NeedsReview => 'yellow',
            self::Approved => 'green',
            self::Failed => 'red',
            self::Cancelled => 'gray',
        };
    }
}
