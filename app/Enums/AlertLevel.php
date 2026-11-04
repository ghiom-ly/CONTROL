<?php

namespace App\Enums;

enum AlertLevel: string
{
    use HasLabels;

    case Info = 'info';
    case Warning = 'warning';
    case Important = 'important';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Info => 'معلومات',
            self::Warning => 'تحذير',
            self::Important => 'مهم',
            self::Critical => 'حرج',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Info => 'blue',
            self::Warning => 'yellow',
            self::Important => 'orange',
            self::Critical => 'red',
        };
    }
}
