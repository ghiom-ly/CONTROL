<?php

namespace App\Enums;

enum CourierStatus: string
{
    use HasLabels;

    case Answered = 'answered';
    case NoAnswer = 'no_answer';
    case Unreachable = 'unreachable';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Answered => 'رد المندوب',
            self::NoAnswer => 'لم يرد المندوب',
            self::Unreachable => 'لا يمكن الوصول',
            self::Unknown => 'غير معروف',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Answered => 'green',
            self::NoAnswer => 'red',
            self::Unreachable => 'red',
            self::Unknown => 'gray',
        };
    }
}
