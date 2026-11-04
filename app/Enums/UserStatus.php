<?php

namespace App\Enums;

enum UserStatus: string
{
    use HasLabels;

    case Active = 'active';
    case Inactive = 'inactive';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'نشط',
            self::Inactive => 'غير نشط',
            self::Suspended => 'موقوف',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'green',
            self::Inactive => 'gray',
            self::Suspended => 'red',
        };
    }
}
