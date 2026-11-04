<?php

namespace Tests\Feature;

use App\Models\DailyReport;
use App\Models\ReportStore;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\ShipmentFollowup;
use App\Models\Store;
use App\Services\DashboardService;
use App\Support\Period;
use Illuminate\Database\QueryException;
use Tests\TestCase;

/** اختبارات قواعد سلامة البيانات على مستوى قاعدة البيانات والخدمات */
class DataIntegrityTest extends TestCase
{
    private function report(string $status, int $userId, int $storeId, ?int $shipments): DailyReport
    {
        $r = DailyReport::create(['work_date' => today(), 'user_id' => $userId, 'status' => $status]);
        ReportStore::create(['daily_report_id' => $r->id, 'store_id' => $storeId, 'shipments_count' => $shipments]);

        return $r;
    }

    public function test_numbers_and_codes_are_generated(): void
    {
        $u = $this->makeUser();
        $store = Store::create(['name' => 'متجر الأمل']);
        $r = DailyReport::create(['work_date' => today(), 'user_id' => $u->id]);

        $this->assertMatchesRegularExpression('/^STR-\d{5}$/', $store->fresh()->code);
        $this->assertMatchesRegularExpression('/^RPT-\d{4}-\d{6}$/', $r->fresh()->report_number);
        $this->assertSame('متجر الامل', $store->fresh()->normalized_name);
        $this->assertNotEquals($r->work_date, $r->created_at, 'تاريخ التقرير منفصل عن تاريخ الإدخال');
    }

    public function test_same_code_cannot_repeat_inside_one_report(): void
    {
        $u = $this->makeUser();
        $store = Store::create(['name' => 'متجر']);
        $r = $this->report('draft', $u->id, $store->id, 10);
        $s = Shipment::create(['code' => 'NR100', 'store_id' => $store->id, 'registered_at' => today()]);

        ShipmentFollowup::create(['shipment_id' => $s->id, 'daily_report_id' => $r->id, 'user_id' => $u->id, 'followup_at' => now(), 'result' => 'courier_no_answer']);
        $this->expectException(QueryException::class);
        ShipmentFollowup::create(['shipment_id' => $s->id, 'daily_report_id' => $r->id, 'user_id' => $u->id, 'followup_at' => now(), 'result' => 'delivered_after_followup']);
    }

    public function test_multiple_followups_for_same_shipment_across_reports(): void
    {
        $u = $this->makeUser();
        $store = Store::create(['name' => 'متجر']);
        $s = Shipment::create(['code' => Shipment::normalizeCode(' nr ١٢٣ '), 'store_id' => $store->id, 'registered_at' => today()]);
        $this->assertSame('NR123', $s->code);

        foreach (['courier_no_answer', 'delivered_after_followup'] as $result) {
            $r = $this->report('approved', $u->id, $store->id, 5);
            ShipmentFollowup::create(['shipment_id' => $s->id, 'daily_report_id' => $r->id, 'user_id' => $u->id, 'followup_at' => now(), 'result' => $result]);
        }
        $this->assertSame(2, $s->followups()->count());
        $this->assertSame(1, Shipment::count());

        $this->expectException(QueryException::class);
        Shipment::create(['code' => 'NR123', 'store_id' => $store->id, 'registered_at' => today()]);
    }

    public function test_dashboard_counts_only_approved_reports_and_keeps_missing_as_null(): void
    {
        $boss = $this->makeUser(Role::SUPER_ADMIN);
        $u = $this->makeUser();
        $store = Store::create(['name' => 'متجر']);
        $service = app(DashboardService::class);
        $period = Period::preset('today');

        $this->assertNull($service->summary($period, $boss)['shipments_reviewed'], 'لا بيانات = غير متوفر وليس صفراً');

        $this->report('pending_review', $u->id, $store->id, 40);
        $this->report('approved', $u->id, $store->id, null);
        $this->assertNull($service->summary($period, $boss)['shipments_reviewed']);

        $this->report('approved', $u->id, $store->id, 12);
        $stats = $service->summary($period, $boss);
        $this->assertSame(12, $stats['shipments_reviewed']);
        $this->assertSame(3, $stats['reports_total']);
        $this->assertSame(2, $stats['reports_approved']);
        $this->assertSame(1, $stats['reports_pending']);
    }

    public function test_employee_dashboard_is_scoped_to_own_reports(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();
        $store = Store::create(['name' => 'متجر']);
        $this->report('approved', $a->id, $store->id, 7);
        $this->report('approved', $b->id, $store->id, 9);

        $stats = app(DashboardService::class)->summary(Period::preset('today'), $a);
        $this->assertSame(7, $stats['shipments_reviewed']);
        $this->assertSame(1, $stats['reports_total']);
    }

    public function test_period_filter_changes_dashboard(): void
    {
        $boss = $this->makeUser(Role::SUPER_ADMIN);
        $store = Store::create(['name' => 'متجر']);
        $r = $this->report('approved', $boss->id, $store->id, 5);
        $r->update(['work_date' => today()->subDays(10)]);

        $svc = app(DashboardService::class);
        $this->assertNull($svc->summary(Period::preset('today'), $boss)['shipments_reviewed']);
        $this->assertSame(5, $svc->summary(Period::preset('last30'), $boss)['shipments_reviewed']);
        $this->actingAs($boss)->get('/?period=last30')->assertOk()->assertSee('آخر 30 يوماً');
    }
}
