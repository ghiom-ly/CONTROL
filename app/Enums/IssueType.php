<?php

namespace App\Enums;

enum IssueType: string
{
    use HasLabels;

    case ShipmentsDecline = 'shipments_decline';
    case ShipmentsStopped = 'shipments_stopped';
    case DeliveryDelay = 'delivery_delay';
    case HighReturns = 'high_returns';
    case ShipmentDataError = 'shipment_data_error';
    case Communication = 'communication';
    case Pickup = 'pickup';
    case StoreInquiry = 'store_inquiry';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::ShipmentsDecline => 'انخفاض عدد الشحنات',
            self::ShipmentsStopped => 'توقف إرسال الشحنات',
            self::DeliveryDelay => 'تأخر التسليم',
            self::HighReturns => 'ارتفاع المرتجعات',
            self::ShipmentDataError => 'خطأ في بيانات الشحنات',
            self::Communication => 'مشكلة في التواصل',
            self::Pickup => 'مشكلة في الاستلام',
            self::StoreInquiry => 'استفسار من المتجر',
            self::Other => 'مشكلة أخرى',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::ShipmentsDecline => 'yellow',
            self::ShipmentsStopped => 'red',
            self::DeliveryDelay => 'orange',
            self::HighReturns => 'red',
            self::ShipmentDataError => 'yellow',
            self::Communication => 'orange',
            self::Pickup => 'orange',
            self::StoreInquiry => 'blue',
            self::Other => 'gray',
        };
    }
}
