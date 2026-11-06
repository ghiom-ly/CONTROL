<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Store;
use App\Models\StoreDailyMetric;
use App\Reports\ReportParams;
use App\Reports\ReportRegistry;
use App\Support\ComparePeriod;
use Illuminate\Http\Request;
use Tests\TestCase;

class ReportCenterTest extends TestCase
{
    public function test_index_lists_nine_reports_and_permissions(): void
    {
        $res = $this->actingAs($this->makeUser(Role::ADMIN))->get('/reports-center')->assertOk();
        foreach (['الملخص التنفيذي', 'أداء كل المتاجر', 'المتاجر المتراجعة والمتوقفة', 'الأكواد المفتوحة', 'المشكلات', 'الاتصالات',
            'تقرير الموظف الشهري', 'الحضور الشهري لكل الموظفين', 'تقرير المتجر'] as $title) {
            $res->assertSee($title);
        }
        $this->actingAs($this->makeUser(Role::VIEWER))->get('/reports-center')->assertOk();
        $this->actingAs($this->makeUser())->get('/reports-center')->assertForbidden();
    }

    public function test_executive_pdf_excel_print(): void
    {
        $admin = $this->makeUser(Role::ADMIN);
        $pdf = $this->actingAs($admin)->get('/reports-center/executive/pdf')->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->actingAs($admin)->get('/reports-center/executive/excel')->assertOk()->assertDownload();
        $this->actingAs($admin)->get('/reports-center/executive/print?period=month')->assertOk()->assertSee('window.print')->assertSee('الملخص التنفيذي');
        $this->assertDatabaseHas('audit_logs', ['event' => 'report_exported']);
    }

    public function test_store_performance_rows(): void
    {
        $a = Store::factory()->create(['name' => 'متجر أداء']);
        StoreDailyMetric::create(['store_id' => $a->id, 'metric_date' => today()->subDays(8), 'shipments_count' => 10]);
        StoreDailyMetric::create(['store_id' => $a->id, 'metric_date' => today(), 'shipments_count' => 15]);
        $b = Store::factory()->create();
        StoreDailyMetric::create(['store_id' => $b->id, 'metric_date' => today(), 'shipments_count' => 3]);
        Store::factory()->create(['name' => 'متجر بلا نشاط']);

        $p = new ReportParams(ComparePeriod::fromRequest(Request::create('/')), $this->makeUser(Role::ADMIN));
        $rows = ReportRegistry::make('stores')->build($p)->sections[0]['rows'];
        $this->assertCount(2, $rows);
        $row = collect($rows)->first(fn ($r) => $r[0] === 'متجر أداء');
        $this->assertSame([10, 15, 50.0], [$row[2], $row[3], $row[4]]);
    }

    public function test_empty_report_message(): void
    {
        $this->actingAs($this->makeUser(Role::ADMIN))->get('/reports-center/declining/print')->assertOk()->assertSee('لا توجد بيانات في هذه الفترة');
    }

    public function test_unknown_report_404(): void
    {
        $admin = $this->makeUser(Role::ADMIN);
        $this->actingAs($admin)->get('/reports-center/nope/pdf')->assertNotFound();
        $this->actingAs($admin)->get('/reports-center/executive/docx')->assertNotFound();
    }
}
