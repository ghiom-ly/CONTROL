<?php

namespace App\Enums;

enum ProcessingStatus: string
{
    use HasLabels;

    case Open = 'open';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'مفتوحة',
            self::InProgress => 'قيد المعالجة',
            self::Resolved => 'تمت المعالجة',
            self::Closed => 'مغلقة',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'yellow',
            self::InProgress => 'blue',
            self::Resolved => 'green',
            self::Closed => 'gray',
        };
    }
}
