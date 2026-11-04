<?php

namespace App\Enums;

enum IssueStatus: string
{
    use HasLabels;

    case New = 'new';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::New => 'جديدة',
            self::InProgress => 'قيد المعالجة',
            self::Resolved => 'تم الحل',
            self::Closed => 'مغلقة',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::New => 'blue',
            self::InProgress => 'yellow',
            self::Resolved => 'green',
            self::Closed => 'gray',
        };
    }
}
