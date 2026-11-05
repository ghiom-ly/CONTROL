<?php

namespace Tests\Feature;

use App\Models\DailyReport;
use App\Models\Issue;
use App\Models\MessageTemplate;
use App\Models\ReportStore;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\StoreDailyMetric;
use App\Services\Messages\MessageContext;
use App\Services\Messages\MessageRenderer;
use App\Support\MessageVariables;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class MessageRenderingTest extends TestCase
{
    private function ctx(Store $s, array $opts = []): array
    {
        return app(MessageContext::class)->forStore($s, $this->makeUser(Role::ADMIN, ['name' => 'المرسل']), $opts);
    }

    public function test_unknown_variable_rejected_helper(): void
    {
        $this->assertSame(['الأكواد', 'غلط'], MessageVariables::unknownIn('مرحبا {اسم_المتجر} {الأكواد} {غلط}', 'general'));
        $this->assertSame([], MessageVariables::unknownIn('{الأكواد} {عدد_الأكواد} {اسم_الشركة}', 'open_codes'));
    }

    public function test_render_replaces_and_reports_missing(): void
    {
        $r = MessageRenderer::render('أهلاً {اسم_المتجر} {غير_معروف}', ['اسم_المتجر' => 'الأمل']);
        $this->assertSame('أهلاً الأمل {غير_معروف}', $r['text']);
        $this->assertSame(['غير_معروف'], $r['missing']);
    }

    public function test_common_values(): void
    {
        $s = Store::factory()->create(['name' => 'متجر الأمل', 'owner_name' => 'علي']);
        $v = $this->ctx($s);
        $this->assertSame('متجر الأمل', $v['اسم_المتجر']);
        $this->assertSame('علي', $v['صاحب_المتجر']);
        $this->assertSame('المرسل', $v['اسم_المرسل']);
        $this->assertSame('شركة النورس', $v['اسم_الشركة']);
        $this->assertSame($s->code, $v['كود_المتجر']);
    }

    public function test_open_codes_values(): void
    {
        $s = Store::factory()->create();
        foreach (['MSG-A1' => 'customer_no_answer', 'MSG-A2' => 'customer_no_answer', 'MSG-C1' => 'courier_no_answer'] as $code => $res) {
            Shipment::create(['code' => $code, 'store_id' => $s->id, 'registered_at' => today(), 'last_result' => $res, 'processing_status' => 'open']);
        }
        $v = $this->ctx($s);
        $this->assertSame(2, (int) $v['عدد_الأكواد']);
        $this->assertSame(['MSG-A1', 'MSG-A2'], collect(explode("\n", $v['الأكواد']))->sort()->values()->all());
        $this->assertSame('2', $v['الأكواد_المفتوحة']);
        $this->assertSame("X1\nX2", $this->ctx($s, ['codes' => ['X1', 'X2']])['الأكواد']);
    }

    public function test_performance_values(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-20 12:00'));
        $s = Store::factory()->create();
        $emp = $this->makeUser();
        foreach ([['2026-11-15', 72], ['2026-11-18', 81]] as [$d, $rate]) {
            $r = DailyReport::factory()->approved()->for($emp)->create(['work_date' => $d, 'shift_started_at' => "$d 09:00"]);
            ReportStore::create(['daily_report_id' => $r->id, 'store_id' => $s->id, 'start_orders' => 10, 'start_rate' => $rate, 'shipments_count' => 5]);
            StoreDailyMetric::create(['store_id' => $s->id, 'metric_date' => $d, 'shipments_count' => 5]);
        }
        $v = $this->ctx($s, ['period' => '7d']);
        $this->assertSame('آخر 7 أيام', $v['الفترة']);
        $this->assertSame('10', $v['عدد_الطلبيات']);
        $this->assertSame('72%', $v['نسبة_البداية']);
        $this->assertSame('81%', $v['نسبة_النهاية']);
        $this->assertSame('+9%', $v['التحسن']);

        $today = $this->ctx($s, ['period' => 'today']);
        $this->assertSame('غير متوفر', $today['نسبة_البداية']);
        $this->assertSame('غير متوفر', $today['التحسن']);

        $one = Store::factory()->create();
        $r = DailyReport::factory()->approved()->for($emp)->create(['work_date' => '2026-11-19', 'shift_started_at' => '2026-11-19 09:00']);
        ReportStore::create(['daily_report_id' => $r->id, 'store_id' => $one->id, 'start_orders' => 3, 'start_rate' => 60.5]);
        $this->assertSame('0%', $this->ctx($one)['التحسن']);
        $this->assertSame('60.5%', $this->ctx($one)['نسبة_البداية']);
    }

    public function test_issue_values(): void
    {
        $s = Store::factory()->create();
        $i = Issue::create(['store_id' => $s->id, 'reported_by' => $this->makeUser()->id, 'reported_at' => '2026-11-10 10:00',
            'type' => 'delivery_delay', 'title' => 'تأخير تسليم', 'description' => 'تأخر ثلاثة أيام']);
        $v = $this->ctx($s, ['issue' => $i]);
        $this->assertSame('تأخير تسليم', $v['عنوان_المشكلة']);
        $this->assertSame($i->fresh()->issue_number, $v['رقم_المشكلة']);
        $this->assertSame('2026/11/10', $v['تاريخ_المشكلة']);
    }

    public function test_seeded_templates_pending(): void
    {
        $this->assertSame(4, MessageTemplate::where('status', 'pending')->count());
        $this->assertSame([], MessageVariables::unknownIn(MessageTemplate::where('type', 'performance')->value('body'), 'performance'));
    }

    public function test_permissions_defaults(): void
    {
        $admin = $this->makeUser(Role::ADMIN);
        $this->assertTrue($admin->hasPermission('messages.send'));
        $this->assertFalse($admin->hasPermission('messages.approve'));
        $this->assertFalse($this->makeUser()->hasPermission('messages.send'));
    }
}
