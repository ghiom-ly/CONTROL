<?php

namespace App\Enums;

enum ConfirmationResult: string
{
    use HasLabels;

    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case NoAnswer = 'no_answer';

    public function label(): string
    {
        return match ($this) {
            self::Confirmed => 'مؤكد',
            self::Cancelled => 'ملغى',
            self::NoAnswer => 'لا يرد',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Confirmed => 'green',
            self::Cancelled => 'red',
            self::NoAnswer => 'yellow',
        };
    }
}
