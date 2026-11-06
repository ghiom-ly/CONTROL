<?php

namespace Tests\Feature;

use App\Alerts\AlertEngine;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\DailyReport;
use App\Models\Issue;
use App\Models\Leave;
use App\Models\ReportStore;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\ShipmentFollowup;
use App\Models\Store;
use App\Models\StoreDailyMetric;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class AlertRulesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\AlertRulesSeeder::class);
    }

    /** يشغّل قاعدة واحدة فقط ويعيد عدد التنبيهات المفتوحة لها */
    private function only(string $key, ?CarbonImmutable $now = null): int
    {
        AlertRule::query()->update(['is_enabled' => false]);
        AlertRule::where('key', $key)->update(['is_enabled' => true]);
        app(AlertEngine::class)->run($now ?? CarbonImmutable::now());

        return Alert::where('rule_key', $key)->where('status', 'open')->count();
    }

    private function metric(Store $s, int $daysAgo, int $n): void
    {
        StoreDailyMetric::create(['store_id' => $s->id, 'metric_date' => today()->subDays($daysAgo), 'shipments_count' => $n]);
    }

    public function test_shipments_below_average(): void
    {
        $s = Store::factory()->create();
        foreach (range(2, 15) as $d) {
            $this->metric($s, $d, 20);
        }
        $this->metric($s, 1, 18);
        $this->assertSame(0, $this->only('shipments_below_average'));
        StoreDailyMetric::where('store_id', $s->id)->where('metric_date', today()->subDay())->update(['shipments_count' => 10]);
        $this->assertSame(1, $this->only('shipments_below_average'));
    }

    public function test_store_stopped_reporting(): void
    {
        $late = Store::factory()->create();
        $recent = Store::factory()->create();
        $archived = Store::factory()->create(['status' => 'archived']);
        $this->metric($late, 5, 10);
        $this->metric($recent, 2, 10);
        $this->metric($archived, 5, 10);
        $this->assertSame(1, $this->only('store_stopped_reporting'));
        $this->assertSame($late->id, Alert::sole()->subject_id);
    }

    public function test_repeated_store_issue(): void
    {
        $admin = $this->makeUser(Role::ADMIN);
        $s = Store::factory()->create();
        $mk = fn () => Issue::create(['store_id' => $s->id, 'reported_by' => $admin->id, 'reported_at' => now()->subDays(3), 'type' => 'other', 'title' => 't', 'description' => 'd', 'status' => 'new']);
        $mk();
        $mk();
        $this->assertSame(0, $this->only('repeated_store_issue'));
        $mk();
        $this->assertSame(1, $this->only('repeated_store_issue'));
    }

    public function test_report_approval_delay(): void
    {
        DailyReport::factory()->pendingReview()->for($this->makeUser())->create(['submitted_at' => now()->subHours(5)]);
        $this->assertSame(0, $this->only('report_approval_delay'));
        DailyReport::factory()->pendingReview()->for($this->makeUser())->create(['submitted_at' => now()->subHours(30)]);
        $this->assertSame(1, $this->only('report_approval_delay'));
    }

    public function test_missing_report_after_deadline(): void
    {
        $emp = $this->makeUser();
        $this->assertSame(0, $this->only('employee_missing_report', CarbonImmutable::today()->setTime(17, 0)));
        $this->assertSame(1, $this->only('employee_missing_report', CarbonImmutable::today()->setTime(19, 0)));

        DailyReport::factory()->pendingReview()->for($emp)->create(['first_submitted_at' => now()]);
        $this->assertSame(0, $this->only('employee_missing_report', CarbonImmutable::today()->setTime(19, 30)));
    }

    public function test_missing_report_skips_leave(): void
    {
        $emp = $this->makeUser();
        Leave::create(['user_id' => $emp->id, 'from_date' => today(), 'to_date' => today(), 'type' => 'leave']);
        $this->assertSame(0, $this->only('employee_missing_report', CarbonImmutable::today()->setTime(19, 0)));
    }

    public function test_conflicting_codes(): void
    {
        [$a, $b] = Store::factory()->count(2)->create();
        $mk = function (Store $store, string $code) {
            $emp = $this->makeUser();
            $r = DailyReport::factory()->pendingReview()->for($emp)->create();
            $rs = ReportStore::create(['daily_report_id' => $r->id, 'store_id' => $store->id, 'shipments_count' => 1]);
            $sh = Shipment::firstOrCreate(['code' => $code], ['store_id' => $store->id, 'registered_at' => today(), 'processing_status' => 'open']);
            ShipmentFollowup::create(['shipment_id' => $sh->id, 'daily_report_id' => $r->id, 'report_store_id' => $rs->id, 'store_id' => $store->id,
                'shipment_code' => $code, 'user_id' => $emp->id, 'followup_at' => now(), 'result' => 'customer_no_answer']);
        };
        $mk($a, 'SAME1');
        $mk($a, 'SAME1');
        $this->assertSame(0, $this->only('duplicate_or_conflicting_codes'));
        $mk($b, 'SAME1');
        $this->assertSame(1, $this->only('duplicate_or_conflicting_codes'));
    }

    public function test_edit_rules_in_settings(): void
    {
        $rule = AlertRule::where('key', 'shipments_below_average')->sole();
        $payload = ['rules' => [$rule->id => ['is_enabled' => '0', 'level' => 'critical', 'parameters' => ['percent' => '40', 'compare_days' => '14', 'hacker' => '1']]]];

        $this->actingAs($this->makeUser(Role::ADMIN))->put('/settings/alert-rules', $payload)->assertForbidden();
        $this->actingAs($this->makeUser(Role::SUPER_ADMIN))->put('/settings/alert-rules', $payload)->assertRedirect();

        $rule->refresh();
        $this->assertFalse($rule->is_enabled);
        $this->assertSame('critical', $rule->level->value);
        $this->assertSame(['percent' => 40, 'compare_days' => 14], $rule->parameters);
    }
}
