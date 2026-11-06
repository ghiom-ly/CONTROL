<?php

namespace Tests\Feature;

use App\Models\DailyReport;
use App\Models\Issue;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreDailyMetric;
use App\Services\Analytics\AnalyticsService;
use App\Support\ComparePeriod;
use Illuminate\Http\Request;
use Tests\TestCase;

class AnalyticsPageTest extends TestCase
{
    private function week(): ComparePeriod
    {
        return ComparePeriod::fromRequest(Request::create('/analytics'));
    }

    private function metric(Store $s, int $daysAgo, int $count): void
    {
        StoreDailyMetric::create(['store_id' => $s->id, 'metric_date' => today()->subDays($daysAgo), 'shipments_count' => $count]);
    }

    public function test_page_sections_and_permissions(): void
    {
        $this->actingAs($this->makeUser(Role::ADMIN))->get('/analytics')->assertOk()
            ->assertSee('هل الشغل يتحسن؟')->assertSee('أي المتاجر تتراجع؟')->assertSee('أي الموظفين أداؤهم أقل؟')->assertSee('ما المشاكل المتكررة؟');
        $this->actingAs($this->makeUser(Role::VIEWER))->get('/analytics')->assertOk();
        $this->actingAs($this->makeUser())->get('/analytics')->assertForbidden();
    }

    public function test_declining_store_listed(): void
    {
        $down = Store::factory()->create(['name' => 'متجر نازل']);
        $up = Store::factory()->create(['name' => 'متجر صاعد']);
        $this->metric($down, 9, 20);
        $this->metric($down, 1, 5);
        $this->metric($up, 9, 5);
        $this->metric($up, 1, 20);

        $list = app(AnalyticsService::class)->decliningStores($this->week());
        $this->assertSame([$down->id], $list->pluck('store.id')->all());
        $this->assertSame(-75.0, $list[0]['percent']);
        $this->actingAs($this->makeUser(Role::ADMIN))->get('/analytics')->assertSee('متجر نازل');
    }

    public function test_stopped_store_listed(): void
    {
        $s = Store::factory()->create();
        $this->metric($s, 9, 10);
        $this->assertSame([$s->id], app(AnalyticsService::class)->stoppedStores($this->week())->pluck('id')->all());
    }

    public function test_employee_row(): void
    {
        $emp = $this->makeUser(Role::EMPLOYEE, ['name' => 'موظف التحليل']);
        DailyReport::factory()->approved()->for($emp)->create(['work_date' => today(), 'start_total_orders' => 40, 'start_delivery_rate' => 50, 'end_delivery_rate' => 60]);
        $row = app(AnalyticsService::class)->employees($this->week())->firstWhere('user.id', $emp->id);
        $this->assertSame(1, $row['reports']);
        $this->assertSame(10.0, $row['improvement']);
        $this->assertArrayHasKey('late', $row);
        $this->assertArrayHasKey('customer_rate', $row);
    }

    public function test_issue_stats(): void
    {
        $admin = $this->makeUser(Role::ADMIN);
        $s = Store::factory()->create();
        foreach ([0, 10] as $h) {
            Issue::create(['store_id' => $s->id, 'reported_by' => $admin->id, 'reported_at' => now()->subHours(20), 'type' => 'delivery_delay',
                'title' => 't', 'description' => 'd', 'status' => $h ? 'resolved' : 'new', 'resolved_at' => $h ? now()->subHours(10) : null]);
        }
        $st = app(AnalyticsService::class)->issueStats($this->week());
        $this->assertSame(2, $st['by_type']['تأخر التسليم']);
        $this->assertSame(10.0, $st['avg_resolution_hours']);
        $this->assertSame($s->id, $st['top_stores'][0]['store']->id);
    }

    public function test_empty_period_renders(): void
    {
        $this->actingAs($this->makeUser(Role::ADMIN))->get('/analytics')->assertOk()->assertSee('لا توجد بيانات');
    }

    public function test_period_buttons(): void
    {
        $admin = $this->makeUser(Role::ADMIN);
        $this->actingAs($admin)->get('/analytics?period=day')->assertOk()->assertSee('مقابل');
        $this->actingAs($admin)->get('/analytics?period=month')->assertOk();
        $this->actingAs($admin)->get('/analytics?period=custom&from=2026-11-05&to=2026-11-01')->assertSessionHasErrors('to');
    }
}
