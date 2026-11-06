<?php

namespace Tests\Feature;

use App\Models\DailyReport;
use App\Models\Issue;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\Store;
use App\Reports\ReportParams;
use App\Reports\ReportRegistry;
use App\Support\ComparePeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ReportsOperationsTest extends TestCase
{
    private function build(string $key, array $extra = [])
    {
        $p = new ReportParams(ComparePeriod::fromRequest(Request::create('/')), $this->makeUser(Role::ADMIN), ...$extra);

        return ReportRegistry::make($key)->build($p);
    }

    public function test_open_codes_age_and_filter(): void
    {
        $s = Store::factory()->create(['name' => 'متجر أكواد']);
        Shipment::create(['code' => 'AGE5', 'store_id' => $s->id, 'registered_at' => today()->subDays(5), 'processing_status' => 'open', 'last_result' => 'customer_no_answer']);
        $row = $this->build('open_codes')->sections[0]['rows'][0];
        $this->assertSame('AGE5', $row[0]);
        $this->assertSame(5, $row[5]);
        $this->assertSame([], $this->build('open_codes', ['store' => Store::factory()->create()])->sections[0]['rows']);
    }

    public function test_issues_report_includes_open_old_issue(): void
    {
        $admin = $this->makeUser(Role::ADMIN);
        $mk = fn ($title, $status, $daysAgo) => Issue::create(['store_id' => Store::factory()->create()->id, 'reported_by' => $admin->id,
            'reported_at' => now()->subDays($daysAgo), 'type' => 'other', 'title' => $title, 'description' => 'd', 'status' => $status,
            'resolved_at' => $status === 'resolved' ? now()->subDays($daysAgo - 1) : null]);
        $old = $mk('قديمة مفتوحة', 'new', 40);
        $mk('قديمة محلولة', 'resolved', 40);
        $numbers = array_column($this->build('issues')->sections[0]['rows'], 0);
        $this->assertSame([$old->fresh()->issue_number], $numbers);
    }

    public function test_contacts_report_sections(): void
    {
        $titles = array_column($this->build('contacts')->sections, 'title');
        $this->assertSame(['إحصائيات الموظفين', 'اتصالات الإدارة بالمتاجر'], $titles);
    }

    public function test_excel_has_all_rows_beyond_pdf_cap(): void
    {
        $s = Store::factory()->create();
        $now = now();
        DB::table('shipments')->insert(array_map(fn ($i) => ['code' => 'BULK'.$i, 'store_id' => $s->id, 'registered_at' => today(),
            'processing_status' => 'open', 'followups_count' => 0, 'created_at' => $now, 'updated_at' => $now], range(1, 2100)));

        $res = $this->actingAs($this->makeUser(Role::ADMIN))->get('/reports-center/open_codes/excel')->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        file_put_contents($tmp, $res->streamedContent());
        $sheet = IOFactory::load($tmp)->getSheetByName('الأكواد');
        $this->assertSame(2100 + 3, $sheet->getHighestDataRow());
    }

    public function test_employee_monthly_summary(): void
    {
        $emp = $this->makeUser(Role::EMPLOYEE, ['name' => 'موظف التقرير']);
        DailyReport::factory()->approved()->for($emp)->create(['work_date' => today(), 'start_delivery_rate' => 50, 'end_delivery_rate' => 60]);
        $d = $this->build('employee_monthly', ['month' => \Carbon\CarbonImmutable::today(), 'employee' => $emp]);
        $this->assertSame(10.0, $d->summary['متوسط التحسن']);
        $this->assertSame(1, $d->summary['التقارير المعتمدة']);
        $this->assertCount(today()->daysInMonth, $d->sections[0]['rows']);
    }

    public function test_employee_report_empty_month(): void
    {
        $emp = $this->makeUser();
        $this->actingAs($this->makeUser(Role::ADMIN))->get("/reports-center/employee_monthly/print?user_id={$emp->id}&month=".now()->format('Y-m'))
            ->assertOk()->assertSee($emp->name);
    }

    public function test_employee_report_requires_employee(): void
    {
        $admin = $this->makeUser(Role::ADMIN);
        $this->actingAs($admin)->get('/reports-center/employee_monthly/pdf')->assertSessionHasErrors('user_id');
        $this->actingAs($admin)->get("/reports-center/employee_monthly/pdf?user_id={$admin->id}")->assertSessionHasErrors('user_id');
    }

    public function test_attendance_monthly_rows(): void
    {
        $this->makeUser();
        $this->makeUser();
        $this->assertCount(2, $this->build('attendance_monthly', ['month' => \Carbon\CarbonImmutable::today()])->sections[0]['rows']);
    }

    public function test_every_report_exports_in_every_format(): void
    {
        $admin = $this->makeUser(Role::ADMIN);
        $emp = $this->makeUser();
        $store = Store::factory()->create();
        foreach (array_keys(ReportRegistry::all()) as $key) {
            $q = "?user_id={$emp->id}&store_id={$store->id}&month=".now()->format('Y-m');
            foreach (['pdf', 'excel', 'print'] as $fmt) {
                $this->actingAs($admin)->get("/reports-center/{$key}/{$fmt}{$q}")->assertOk();
            }
        }
    }
}
