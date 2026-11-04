<?php

namespace App\Enums;

enum StoreStatus: string
{
    use HasLabels;

    case Active = 'active';
    case Stopped = 'stopped';
    case NeedsFollowup = 'needs_followup';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'نشط',
            self::Stopped => 'متوقف',
            self::NeedsFollowup => 'يحتاج متابعة',
            self::Archived => 'مؤرشف',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'green',
            self::Stopped => 'red',
            self::NeedsFollowup => 'yellow',
            self::Archived => 'gray',
        };
    }
}
