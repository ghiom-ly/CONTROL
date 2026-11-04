<?php

namespace App\Enums;

enum ContactResult: string
{
    use HasLabels;

    case Answered = 'answered';
    case NoAnswer = 'no_answer';
    case Busy = 'busy';
    case Unreachable = 'unreachable';
    case WrongNumber = 'wrong_number';
    case CallbackRequested = 'callback_requested';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Answered => 'تم الرد',
            self::NoAnswer => 'لم يتم الرد',
            self::Busy => 'مشغول',
            self::Unreachable => 'مغلق / خارج التغطية',
            self::WrongNumber => 'رقم خاطئ',
            self::CallbackRequested => 'طلب معاودة الاتصال',
            self::Other => 'أخرى',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Answered => 'green',
            self::NoAnswer => 'red',
            self::Busy => 'yellow',
            self::Unreachable => 'red',
            self::WrongNumber => 'gray',
            self::CallbackRequested => 'blue',
            self::Other => 'gray',
        };
    }
}
