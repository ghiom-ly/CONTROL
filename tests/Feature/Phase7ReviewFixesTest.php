<?php

namespace Tests\Feature;

use App\Alerts\AlertEngine;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\DailyReport;
use App\Models\Issue;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\Store;
use App\Notifications\AlertNotification;
use App\Services\Analytics\AnalyticsService;
use App\Support\ComparePeriod;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class Phase7ReviewFixesTest extends TestCase
{
    public function test_custom_range_is_capped(): void
    {
        $admin = $this->makeUser(Role::ADMIN);
        $this->actingAs($admin)->get('/analytics?period=custom&from=2016-01-01&to=2026-11-06')->assertSessionHasErrors('to');
        $this->actingAs($admin)->get('/analytics?period=custom&from=2026-01-01&to=2026-11-06')->assertOk();
    }

    public function test_analytics_query_count_is_bounded_with_many_employees(): void
    {
        foreach (range(1, 15) as $i) {
            DailyReport::factory()->approved()->for($this->makeUser())->create(['work_date' => today()->subDays($i % 20)]);
        }
        $admin = $this->makeUser(Role::ADMIN);
        DB::enableQueryLog();
        $this->actingAs($admin)->get('/analytics?period=month')->assertOk();
        $n = count(DB::getQueryLog());
        $this->assertLessThan(80, $n, "عدد الاستعلامات {$n}");
    }

    public function test_dashboard_long_range_is_clamped(): void
    {
        $this->actingAs($this->makeUser(Role::ADMIN))->get('/?period=custom&from=2010-01-01&to=2026-11-06')->assertOk();
    }

    public function test_open_codes_has_no_fake_previous(): void
    {
        Shipment::create(['code' => 'OC1', 'store_id' => Store::factory()->create()->id, 'registered_at' => today(), 'processing_status' => 'open']);
        $row = collect(app(AnalyticsService::class)->compare(ComparePeriod::fromRequest(Request::create('/'))))->firstWhere('key', 'open_codes');
        $this->assertNull($row['previous']);
        $this->assertNull($row['diff']);
        $this->assertSame(1.0, $row['current']);
    }

    public function test_one_summary_notification_per_run(): void
    {
        Notification::fake();
        $this->seed(\Database\Seeders\AlertRulesSeeder::class);
        AlertRule::where('key', '!=', 'issue_resolution_delay')->update(['is_enabled' => false]);
        $admin = $this->makeUser(Role::ADMIN);
        foreach (range(1, 5) as $i) {
            Issue::create(['store_id' => Store::factory()->create()->id, 'reported_by' => $admin->id, 'reported_at' => now()->subDays(5),
                'type' => 'other', 'title' => "t{$i}", 'description' => 'd', 'status' => 'new']);
        }
        app(AlertEngine::class)->run(CarbonImmutable::now());
        $this->assertSame(5, Alert::count());
        Notification::assertSentToTimes($admin, AlertNotification::class, 1);
    }

    public function test_whatsapp_summary_page_is_copyable_and_capped(): void
    {
        foreach (range(1, 30) as $i) {
            Alert::create(['rule_key' => 'x', 'level' => 'warning', 'title' => "تنبيه رقم {$i}", 'fingerprint' => "f{$i}", 'status' => 'open',
                'first_seen_at' => now(), 'last_seen_at' => now()]);
        }
        $this->actingAs($this->makeUser(Role::ADMIN))->get('/alerts/whatsapp')->assertOk()
            ->assertSee('<textarea', false)->assertSee('نسخ')->assertSee('https://wa.me/?text=', false)
            ->assertSee('و 10 أخرى');
    }

    public function test_employee_table_sortable(): void
    {
        $this->makeUser(Role::EMPLOYEE, ['name' => 'موظف ترتيب']);
        $this->actingAs($this->makeUser(Role::ADMIN))->get('/analytics')->assertOk()
            ->assertSee("setSort('improvement')", false)->assertSee("setSort('orders')", false);
    }

    public function test_open_fingerprint_is_unique_and_schedule_does_not_overlap(): void
    {
        $mk = fn () => Alert::create(['rule_key' => 'r', 'level' => 'warning', 'title' => 't', 'fingerprint' => 'same', 'status' => 'open',
            'first_seen_at' => now(), 'last_seen_at' => now()]);
        $a = $mk();
        $a->update(['status' => 'closed']);
        $mk();   // مغلق + مفتوح بنفس البصمة مسموح
        $this->expectException(QueryException::class);
        try {
            $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command ?? '', 'nours:alerts'));
            $this->assertTrue($event->withoutOverlapping);
        } finally {
            $mk();   // مفتوحان بنفس البصمة ممنوع
        }
    }
}
