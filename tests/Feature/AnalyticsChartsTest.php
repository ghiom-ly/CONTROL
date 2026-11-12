<?php

namespace Tests\Feature;

use App\Models\DailyReport;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\ShipmentFollowup;
use App\Models\Store;
use App\Models\StoreDailyMetric;
use App\Services\Analytics\AnalyticsService;
use App\Support\ComparePeriod;
use Illuminate\Http\Request;
use Tests\TestCase;

class AnalyticsChartsTest extends TestCase
{
    public function test_chart_payload_shapes(): void
    {
        $s = Store::factory()->create();
        StoreDailyMetric::create(['store_id' => $s->id, 'metric_date' => today(), 'shipments_count' => 7]);
        StoreDailyMetric::create(['store_id' => $s->id, 'metric_date' => today()->subDays(8), 'shipments_count' => 9]);
        $emp = $this->makeUser();
        $r = DailyReport::factory()->approved()->for($emp)->create(['work_date' => today(), 'start_delivery_rate' => 50, 'end_delivery_rate' => 60]);
        $sh = Shipment::create(['code' => 'CH1', 'store_id' => $s->id, 'registered_at' => today(), 'processing_status' => 'open']);
        ShipmentFollowup::create(['shipment_id' => $sh->id, 'daily_report_id' => $r->id, 'store_id' => $s->id, 'shipment_code' => 'CH1',
            'user_id' => $emp->id, 'followup_at' => now(), 'result' => 'customer_no_answer', 'is_official' => true]);

        $p = ComparePeriod::fromRequest(Request::create('/analytics'));
        $c = app(AnalyticsService::class)->charts($p);

        $this->assertSame(['rate_trend', 'orders', 'results', 'declining', 'employees', 'issues'], array_keys($c));
        $this->assertCount(7, $c['rate_trend']['labels']);
        $this->assertSame(60.0, end($c['rate_trend']['end']));
        $this->assertCount(7, $c['orders']['current']);
        $this->assertCount(7, $c['orders']['previous']);
        $this->assertSame(7, (int) end($c['orders']['current']));
        $this->assertSame(9, (int) $c['orders']['previous'][5]);
        $this->assertSame(1, array_sum($c['results']['values']));
        $this->assertContains(\App\Enums\FollowupResult::CustomerNoAnswer->label(), $c['results']['labels']);
    }

    public function test_dashboard_shows_charts_for_admin_only(): void
    {
        $this->actingAs($this->makeUser(Role::ADMIN))->get('/')->assertOk()->assertSee('nours-charts', false);
        $this->actingAs($this->makeUser())->get('/')->assertOk()->assertDontSee('nours-charts', false);
    }

    public function test_dashboard_charts_empty(): void
    {
        $this->actingAs($this->makeUser(Role::ADMIN))->get('/')->assertOk()->assertSee('لا توجد بيانات في هذه الفترة');
    }

    public function test_employee_chart_explains_it_needs_approved_reports(): void
    {
        // تقرير بانتظار المراجعة لا يدخل المقارنة — والرسالة تشرح السبب بدل «لا توجد بيانات»
        $emp = $this->makeUser();
        \App\Models\DailyReport::factory()->pendingReview()->for($emp)->create(['work_date' => today()->subDay()]);
        $this->actingAs($this->makeUser(Role::ADMIN))->get('/')->assertOk()
            ->assertSee('لا توجد تقارير متابعة معتمدة في هذه الفترة')
            ->assertSee('تظهر المقارنة بعد اعتماد التقارير من صفحة المراجعة');
    }
}
