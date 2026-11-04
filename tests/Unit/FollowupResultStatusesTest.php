<?php

namespace Tests\Unit;

use App\Enums\CourierStatus as C;
use App\Enums\CustomerStatus as U;
use App\Enums\FollowupResult as R;
use App\Enums\ProcessingStatus as P;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FollowupResultStatusesTest extends TestCase
{
    public static function cases(): array
    {
        return [
            'courier_no_answer' => [R::CourierNoAnswer, C::NoAnswer, null, P::Open],
            'customer_no_answer' => [R::CustomerNoAnswer, C::Answered, U::NoAnswer, P::Open],
            'still_delayed' => [R::CourierAnsweredStillDelayed, C::Answered, null, null],
            'promised' => [R::CourierPromisedDelivery, C::Answered, null, null],
            'delivered' => [R::DeliveredAfterFollowup, null, null, P::Resolved],
            'returned' => [R::ReturnedOrNotWithCourier, null, null, null],
            'other' => [R::OtherIssue, null, null, null],
        ];
    }

    #[DataProvider('cases')]
    public function test_implied_statuses_match_spec_table(R $result, ?C $courier, ?U $customer, ?P $processing): void
    {
        $this->assertSame(
            ['courier' => $courier, 'customer' => $customer, 'processing' => $processing],
            $result->impliedStatuses()
        );
    }

    public function test_is_unanswered_buckets(): void
    {
        $this->assertSame('customer', R::CustomerNoAnswer->isUnanswered());
        $this->assertSame('courier', R::CourierNoAnswer->isUnanswered());
        $this->assertNull(R::CourierPromisedDelivery->isUnanswered());
    }
}
