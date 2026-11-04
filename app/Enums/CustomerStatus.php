<?php

namespace App\Enums;

enum CustomerStatus: string
{
    use HasLabels;

    case Answered = 'answered';
    case NoAnswer = 'no_answer';
    case Unreachable = 'unreachable';
    case Refused = 'refused';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Answered => 'رد العميل',
            self::NoAnswer => 'لم يرد العميل',
            self::Unreachable => 'لا يمكن الوصول',
            self::Refused => 'رفض الاستلام',
            self::Unknown => 'غير معروف',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Answered => 'green',
            self::NoAnswer => 'orange',
            self::Unreachable => 'red',
            self::Refused => 'red',
            self::Unknown => 'gray',
        };
    }
}
