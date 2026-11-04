<?php

namespace App\Enums;

enum FollowupResult: string
{
    use HasLabels;

    case DeliveredAfterFollowup = 'delivered_after_followup';
    case CourierNoAnswer = 'courier_no_answer';
    case CourierAnsweredStillDelayed = 'courier_answered_still_delayed';
    case ReturnedOrNotWithCourier = 'returned_or_not_with_courier';
    case CustomerNoAnswer = 'customer_no_answer';
    case CourierPromisedDelivery = 'courier_promised_delivery';
    case OtherIssue = 'other_issue';

    public function label(): string
    {
        return match ($this) {
            self::DeliveredAfterFollowup => 'تم التسليم بعد المتابعة',
            self::CourierNoAnswer => 'المندوب لم يرد',
            self::CourierAnsweredStillDelayed => 'المندوب رد والطلبية ما زالت متأخرة',
            self::ReturnedOrNotWithCourier => 'الطلبية رجعت أو لم تعد مع المندوب',
            self::CustomerNoAnswer => 'المندوب رد لكن العميل لم يرد',
            self::CourierPromisedDelivery => 'المندوب وعد بالتسليم',
            self::OtherIssue => 'مشكلة أخرى',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::DeliveredAfterFollowup => 'green',
            self::CourierNoAnswer => 'red',
            self::CourierAnsweredStillDelayed => 'yellow',
            self::ReturnedOrNotWithCourier => 'red',
            self::CustomerNoAnswer => 'orange',
            self::CourierPromisedDelivery => 'blue',
            self::OtherIssue => 'gray',
        };
    }
}
