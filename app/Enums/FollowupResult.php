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

    /**
     * الحالات المستنتجة من نتيجة المتابعة (جدول التصميم §6.5). ما لا يُستنتج يبقى null.
     *
     * @return array{courier: ?CourierStatus, customer: ?CustomerStatus, processing: ?ProcessingStatus}
     */
    public function impliedStatuses(): array
    {
        return match ($this) {
            self::CourierNoAnswer => ['courier' => CourierStatus::NoAnswer, 'customer' => null, 'processing' => ProcessingStatus::Open],
            self::CustomerNoAnswer => ['courier' => CourierStatus::Answered, 'customer' => CustomerStatus::NoAnswer, 'processing' => ProcessingStatus::Open],
            self::CourierAnsweredStillDelayed,
            self::CourierPromisedDelivery => ['courier' => CourierStatus::Answered, 'customer' => null, 'processing' => null],
            self::DeliveredAfterFollowup => ['courier' => null, 'customer' => null, 'processing' => ProcessingStatus::Resolved],
            self::ReturnedOrNotWithCourier,
            self::OtherIssue => ['courier' => null, 'customer' => null, 'processing' => null],
        };
    }

    /** القائمة التي ينتمي إليها الكود في القسم ③: customer | courier | null */
    public function isUnanswered(): ?string
    {
        return match ($this) {
            self::CustomerNoAnswer => 'customer',
            self::CourierNoAnswer => 'courier',
            default => null,
        };
    }
}
