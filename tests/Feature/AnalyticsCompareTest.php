<?php

namespace Tests\Feature;

use App\Models\DailyReport;
use App\Models\Store;
use App\Models\StoreDailyMetric;
use App\Services\Analytics\AnalyticsService;
use App\Support\ComparePeriod;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AnalyticsCompareTest extends TestCase
{
    private function period(array $q = []): ComparePeriod
    {
        return ComparePeriod::fromRequest(Request::create('/analytics', 'GET', $q));
    }

    private function row(string $key, ?ComparePeriod $p = null): array
    {
        return collect(app(AnalyticsService::class)->compare($p ?? $this->period()))->firstWhere('key', $key);
    }

    public function test_presets(): void
    {
        $w = $this->period();
        $this->assertSame('week', $w->preset);
        $this->assertSame(today()->subDays(6)->toDateString(), $w->from->toDateString());
        $this->assertSame(today()->toDateString(), $w->to->toDateString());
        $this->assertSame(today()->subDays(7)->toDateString(), $w->prevTo->toDateString());
        $this->assertSame(today()->subDays(13)->toDateString(), $w->prevFrom->toDateString());

        $d = $this->period(['period' => 'day']);
        $this->assertSame(today()->subDay()->toDateString(), $d->prevFrom->toDateString());
        $this->assertSame(1, $d->days());

        $m = $this->period(['period' => 'month']);
        $this->assertSame(30, $m->days());
        $this->assertSame(today()->subDays(59)->toDateString(), $m->prevFrom->toDateString());
    }

    public function test_custom_range_validation(): void
    {
        $this->expectException(ValidationException::class);
        $this->period(['period' => 'custom', 'from' => '2026-11-05', 'to' => '2026-11-01']);
    }

    public function test_compare_rows(): void
    {
        $s = Store::factory()->create();
        StoreDailyMetric::create(['store_id' => $s->id, 'metric_date' => today()->subDays(8), 'shipments_count' => 10]);
        StoreDailyMetric::create(['store_id' => $s->id, 'metric_date' => today(), 'shipments_count' => 15]);
        DailyReport::factory()->approved()->for($this->makeUser())->create(['work_date' => today(), 'start_total_orders' => 100, 'start_delivery_rate' => 60, 'end_delivery_rate' => 70]);
        DailyReport::factory()->approved()->for($this->makeUser())->create(['work_date' => today()->subDays(8), 'start_total_orders' => 100, 'start_delivery_rate' => 50, 'end_delivery_rate' => 55]);

        $orders = $this->row('orders');
        $this->assertEquals([10.0, 15.0, 5.0, 50.0], [$orders['previous'], $orders['current'], $orders['diff'], $orders['percent']]);
        $this->assertEquals([50.0, 60.0], [$this->row('start_rate')['previous'], $this->row('start_rate')['current']]);
        $this->assertEquals([5.0, 10.0], [$this->row('improvement')['previous'], $this->row('improvement')['current']]);
        $this->assertTrue($this->row('open_codes')['lower_is_better']);
        $this->assertSame(
            ['orders', 'start_rate', 'end_rate', 'improvement', 'reports_approved', 'followups', 'delivered_after_followup',
                'customer_no_answer', 'courier_no_answer', 'open_codes', 'issues_new', 'issues_resolved'],
            array_column(app(AnalyticsService::class)->compare($this->period()), 'key'));
    }

    public function test_percent_null_when_previous_zero(): void
    {
        $s = Store::factory()->create();
        StoreDailyMetric::create(['store_id' => $s->id, 'metric_date' => today(), 'shipments_count' => 15]);
        $this->assertNull($this->row('orders')['percent']);
    }

    public function test_unapproved_reports_ignored(): void
    {
        DailyReport::factory()->pendingReview()->for($this->makeUser())->create(['work_date' => today(), 'start_total_orders' => 100, 'start_delivery_rate' => 90]);
        $this->assertNull($this->row('start_rate')['current']);
        $this->assertEquals(0, $this->row('reports_approved')['current']);
    }
}
