<?php

namespace App\Enums;

enum ContactParty: string
{
    use HasLabels;

    case Store = 'store';
    case Customer = 'customer';
    case Courier = 'courier';

    public function label(): string
    {
        return match ($this) {
            self::Store => 'المتجر',
            self::Customer => 'العميل',
            self::Courier => 'المندوب',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Store => 'blue',
            self::Customer => 'orange',
            self::Courier => 'navy',
        };
    }
}
