<?php

namespace Tests\Feature;

use App\Models\DailyReport;
use App\Models\ReportStore;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\StoreDailyMetric;
use App\Services\StoreInsightsService;
use Tests\TestCase;

class StoreDetailsTest extends TestCase
{
    public function test_week_comparison_numbers(): void
    {
        $store = Store::factory()->create();
        for ($d = 13; $d >= 0; $d--) {
            StoreDailyMetric::create(['store_id' => $store->id, 'metric_date' => today()->subDays($d), 'shipments_count' => $d >= 7 ? 10 : 15]);
        }

        $cmp = app(StoreInsightsService::class)->weekComparison($store, today());
        $this->assertSame(['current' => 105, 'previous' => 70, 'diff' => 35, 'percent' => 50.0], $cmp);
    }

    public function test_week_comparison_null_when_no_data(): void
    {
        $cmp = app(StoreInsightsService::class)->weekComparison(Store::factory()->create(), today());
        $this->assertSame(['current' => null, 'previous' => null, 'diff' => null, 'percent' => null], $cmp);
    }

    public function test_daily_orders_keeps_missing_days_null(): void
    {
        $store = Store::factory()->create();
        StoreDailyMetric::create(['store_id' => $store->id, 'metric_date' => today(), 'shipments_count' => 9]);

        $daily = app(StoreInsightsService::class)->dailyOrders($store, today()->subDays(2), today());
        $this->assertSame([today()->subDays(2)->toDateString() => null, today()->subDay()->toDateString() => null, today()->toDateString() => 9], $daily->all());
    }

    public function test_unapproved_report_notes_not_shown(): void
    {
        $store = Store::factory()->create();
        $u = $this->makeUser();
        foreach (['pending_review' => 'ملاحظة معلقة', 'approved' => 'ملاحظة معتمدة'] as $status => $note) {
            $r = DailyReport::factory()->state(['status' => $status])->for($u)->create();
            ReportStore::factory()->create(['daily_report_id' => $r->id, 'store_id' => $store->id, 'notes' => $note]);
        }

        $this->actingAs($this->makeUser(Role::ADMIN))->get("/stores/{$store->id}")
            ->assertOk()->assertSee('ملاحظة معتمدة')->assertDontSee('ملاحظة معلقة');
    }

    public function test_open_codes_listed_for_store(): void
    {
        $store = Store::factory()->create();
        $other = Store::factory()->create();
        Shipment::create(['code' => 'OPEN1', 'store_id' => $store->id, 'registered_at' => today(), 'last_result' => 'customer_no_answer', 'processing_status' => 'open']);
        Shipment::create(['code' => 'DONE1', 'store_id' => $store->id, 'registered_at' => today(), 'last_result' => 'customer_no_answer', 'processing_status' => 'resolved']);
        Shipment::create(['code' => 'OTHER1', 'store_id' => $other->id, 'registered_at' => today(), 'last_result' => 'courier_no_answer', 'processing_status' => 'open']);
        Shipment::create(['code' => 'PROM1', 'store_id' => $store->id, 'registered_at' => today(), 'last_result' => 'courier_promised_delivery', 'processing_status' => 'open']);

        $this->actingAs($this->makeUser(Role::ADMIN))->get("/stores/{$store->id}")
            ->assertOk()->assertSee('OPEN1')->assertDontSee('DONE1')->assertDontSee('OTHER1')->assertDontSee('PROM1');
    }
}
