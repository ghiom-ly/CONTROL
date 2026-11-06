<?php

namespace Tests\Feature;

use App\Models\DailyReport;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\ShipmentFollowup;
use App\Models\Store;
use App\Models\StoreDailyMetric;
use App\Reports\ReportData;
use App\Reports\ReportParams;
use App\Reports\ReportRegistry;
use App\Reports\Renderers\ExcelRenderer;
use App\Support\Branding;
use App\Support\ComparePeriod;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class Phase8ReviewFixesTest extends TestCase
{
    private function excelCells(ReportData $d): array
    {
        $res = app(ExcelRenderer::class)->download($d, 'x.xlsx');
        ob_start();
        $res->sendContent();
        $tmp = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        file_put_contents($tmp, ob_get_clean());

        return [IOFactory::load($tmp)->getSheet(0), $tmp];
    }

    public function test_excel_writes_text_as_text_no_formulas(): void
    {
        $d = new ReportData('t', 's', [], [['title' => 'أكواد', 'headers' => ['الكود', 'العدد'],
            'rows' => [['=1+', 3], ['=HYPERLINK("http://evil","x")', 4], ['1234567890123456789', 5]]]]);
        [$sheet] = $this->excelCells($d);
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('A4')->getDataType());
        $this->assertSame('=1+', $sheet->getCell('A4')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('A5')->getDataType());
        $this->assertSame('1234567890123456789', $sheet->getCell('A6')->getValue());
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('B4')->getDataType());
    }

    public function test_pdf_cap_is_500(): void
    {
        $d = new ReportData('t', 's', [], [['title' => 'x', 'headers' => ['a'], 'rows' => array_map(fn ($i) => [$i], range(1, 600))]]);
        $html = view('reports-center.document', ['data' => $d, 'brand' => Branding::header(), 'mode' => 'print'])->render();
        $this->assertSame(500, substr_count($html, '<tr class="r">'));
        $this->assertStringContainsString('تم عرض أول 500', $html);
    }

    public function test_deleted_employee_is_validation_error_not_500(): void
    {
        $emp = $this->makeUser();
        $emp->delete();
        $this->actingAs($this->makeUser(Role::ADMIN))->get("/reports-center/employee_monthly/pdf?user_id={$emp->id}")
            ->assertSessionHasErrors('user_id');
    }

    public function test_employee_monthly_counts_only_approved(): void
    {
        $emp = $this->makeUser();
        DailyReport::factory()->approved()->for($emp)->create(['work_date' => today(), 'start_delivery_rate' => 50, 'end_delivery_rate' => 60]);
        DailyReport::factory()->pendingReview()->for($emp)->create(['work_date' => today(), 'start_delivery_rate' => 10, 'end_delivery_rate' => 90]);
        $d = ReportRegistry::make('employee_monthly')->build(new ReportParams(ComparePeriod::fromRequest(Request::create('/')),
            $this->makeUser(Role::ADMIN), month: CarbonImmutable::today(), employee: $emp));
        $this->assertSame(1, $d->summary['التقارير المعتمدة']);
        $this->assertSame(10.0, $d->summary['متوسط التحسن']);
    }

    public function test_store_report_counts_only_official_followups(): void
    {
        $s = Store::factory()->create();
        $emp = $this->makeUser();
        $sh = Shipment::create(['code' => 'OF1', 'store_id' => $s->id, 'registered_at' => today(), 'processing_status' => 'open']);
        foreach ([true, false] as $official) {
            $r = DailyReport::factory()->{$official ? 'approved' : 'pendingReview'}()->for($emp)->create();
            ShipmentFollowup::create(['shipment_id' => $sh->id, 'daily_report_id' => $r->id, 'store_id' => $s->id, 'shipment_code' => 'OF1',
                'user_id' => $emp->id, 'followup_at' => now(), 'result' => 'customer_no_answer', 'is_official' => $official]);
        }
        $d = ReportRegistry::make('store')->build(new ReportParams(ComparePeriod::fromRequest(Request::create('/')), $this->makeUser(Role::ADMIN), store: $s));
        $this->assertSame(1, $d->summary['المتابعات']);
    }

    public function test_executive_summary_uses_keys_not_positions(): void
    {
        $s = Store::factory()->create();
        StoreDailyMetric::create(['store_id' => $s->id, 'metric_date' => today(), 'shipments_count' => 15]);
        DailyReport::factory()->approved()->for($this->makeUser())->create(['work_date' => today(), 'start_delivery_rate' => 50, 'end_delivery_rate' => 64]);
        $d = ReportRegistry::make('executive')->build(new ReportParams(ComparePeriod::fromRequest(Request::create('/')), $this->makeUser(Role::ADMIN)));
        $this->assertEquals(15, $d->summary['الطلبيات']);
        $this->assertSame('64%', $d->summary['نسبة التسليم (نهاية)']);
        $this->assertEquals(1, $d->summary['التقارير المعتمدة']);
    }
}
