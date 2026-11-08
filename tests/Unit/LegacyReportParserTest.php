<?php

namespace Tests\Unit;

use App\Models\DailyReport;
use App\Models\ReportStore;
use App\Models\ShipmentFollowup;
use App\Models\Store;
use App\Services\Imports\LegacyReportParser;
use App\Support\ReportWhatsappFormatter;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LegacyReportParserTest extends TestCase
{
    private const TEMPLATE = <<<'TXT'
ريبورت تاريخ: 2026-10-20
اسم الموظف: أحمد سالم

1) ملخص حركة الأوردرات:
- كام متجر تم العمل عليه: 2
- إجمالي الأوردرات التي عملت عليها اليوم: 55
  - متجر الأمل: 34
  - متجر السلام: 21

2) أوقات المحاولات:
- إجمالي عدد المحاولات الإجمالية التي أجريتها اليوم: 2 محاولة بالمجمل.
- مواعيد المحاولات:
  - المحاولة الأولى: الساعة 10:30
  - المحاولة الثانية: الساعة 14:15

3) الأكواد غير المُجاب عليها:
- أكواد لم يرد عليها الزبون (لتحويلها للمتاجر): A1 A2
- أكواد لم يرد عليها المندوب أو لا تستجيب ولا تحل (مع إرسال السكرينات): B1

4) ملاحظات واجهت الموظف أو استفسارات:
- تأخر المندوب
- متى يصل الدفع؟
TXT;

    private function parse(string $t): array
    {
        return (new LegacyReportParser)->parse($t);
    }

    public function test_parses_company_template(): void
    {
        [$r] = $this->parse(self::TEMPLATE);
        $this->assertSame('2026-10-20', $r['date']);
        $this->assertSame('أحمد سالم', $r['employee_name']);
        $this->assertSame([['name' => 'متجر الأمل', 'count' => 34], ['name' => 'متجر السلام', 'count' => 21]], $r['stores']);
        $this->assertSame(['10:30', '14:15'], $r['attempt_times']);
        $this->assertSame(['A1', 'A2'], $r['customer_codes']);
        $this->assertSame(['B1'], $r['courier_codes']);
        $this->assertSame("تأخر المندوب\nمتى يصل الدفع؟", $r['notes']);
        $this->assertSame([], $r['errors']);
        $this->assertSame(64, strlen($r['hash']));
    }

    public function test_round_trip_with_formatter(): void
    {
        $emp = $this->makeUser('employee', ['name' => 'سارة']);
        $s = Store::factory()->create(['name' => 'متجر النجمة']);
        $rep = DailyReport::factory()->draft()->for($emp)->create(['work_date' => Carbon::parse('2026-09-01'), 'attempt_times' => ['09:05'], 'notes' => 'ملاحظة']);
        $rs = ReportStore::create(['daily_report_id' => $rep->id, 'store_id' => $s->id, 'shipments_count' => 12]);
        ShipmentFollowup::create(['daily_report_id' => $rep->id, 'report_store_id' => $rs->id, 'store_id' => $s->id,
            'shipment_code' => 'RT-1', 'user_id' => $emp->id, 'followup_at' => now(), 'result' => 'courier_no_answer']);

        [$r] = $this->parse(ReportWhatsappFormatter::format($rep->fresh()));
        $this->assertSame('2026-09-01', $r['date']);
        $this->assertSame([['name' => 'متجر النجمة', 'count' => 12]], $r['stores']);
        $this->assertSame(['09:05'], $r['attempt_times']);
        $this->assertSame([], $r['customer_codes']);
        $this->assertSame(['RT-1'], $r['courier_codes']);
        $this->assertSame('ملاحظة', $r['notes']);
    }

    public function test_delivered_codes_section_is_not_imported_as_notes(): void
    {
        $emp = $this->makeUser('employee', ['name' => 'سارة']);
        $s = Store::factory()->create(['name' => 'متجر النجمة']);
        $rep = DailyReport::factory()->draft()->for($emp)->create(['work_date' => Carbon::parse('2026-09-01'), 'attempt_times' => ['09:05'], 'notes' => 'ملاحظة']);
        $rs = ReportStore::create(['daily_report_id' => $rep->id, 'store_id' => $s->id, 'shipments_count' => 12]);
        ShipmentFollowup::create(['daily_report_id' => $rep->id, 'report_store_id' => $rs->id, 'store_id' => $s->id,
            'shipment_code' => 'DLV-1', 'user_id' => $emp->id, 'followup_at' => now(), 'result' => 'delivered_after_followup']);

        $text = ReportWhatsappFormatter::format($rep->fresh());
        $this->assertStringContainsString('DLV-1', $text);
        [$r] = $this->parse($text);
        $this->assertSame('ملاحظة', $r['notes']);
        $this->assertSame([], $r['errors']);
    }

    public function test_tolerates_whatsapp_formatting(): void
    {
        $text = "*ريبورت تاريخ:* ٤/١١/٢٠٢٦\n\n*اسم الموظف:* _علي_\n\n\n*1) ملخص حركة الأوردرات:*\n• كام متجر تم العمل عليه: ١\n• متجر الأمل: ٣٤\n• متجر الريم: غير متوفر\n\n*3) الأكواد غير المُجاب عليها:*\n• أكواد لم يرد عليها الزبون (لتحويلها للمتاجر): لا يوجد\n";
        [$r] = $this->parse($text);
        $this->assertSame('2026-11-04', $r['date']);
        $this->assertSame('علي', $r['employee_name']);
        $this->assertSame([['name' => 'متجر الأمل', 'count' => 34], ['name' => 'متجر الريم', 'count' => null]], $r['stores']);
        $this->assertSame([], $r['customer_codes']);
        $this->assertSame([], $r['attempt_times']);
    }

    public function test_multiple_reports_in_one_paste(): void
    {
        $two = self::TEMPLATE."\n\n".str_replace('2026-10-20', '21-10-2026', self::TEMPLATE);
        $out = $this->parse($two);
        $this->assertCount(2, $out);
        $this->assertSame('2026-10-21', $out[1]['date']);
        $this->assertNotSame($out[0]['hash'], $out[1]['hash']);
    }

    public function test_missing_date_is_error_only_for_that_report(): void
    {
        $text = "ريبورت تاريخ: ؟؟\nاسم الموظف: س\n\n".self::TEMPLATE;
        $out = $this->parse($text);
        $this->assertSame(['تاريخ التقرير غير موجود أو غير صالح'], $out[0]['errors']);
        $this->assertSame([], $out[1]['errors']);
    }

    public function test_pm_times_converted(): void
    {
        [$r] = $this->parse("ريبورت تاريخ: 2026-10-20\n2) أوقات المحاولات:\n- المحاولة الأولى: الساعة 9:30 ص\n- المحاولة الثانية: الساعة 2:15 م\n- المحاولة الثالثة: 12:10 م");
        $this->assertSame(['09:30', '14:15', '12:10'], $r['attempt_times']);
    }

    public function test_missing_sections_give_empty_lists(): void
    {
        [$r] = $this->parse('ريبورت تاريخ: 2026-10-20');
        $this->assertSame([], $r['stores']);
        $this->assertSame([], $r['courier_codes']);
        $this->assertNull($r['notes']);
        $this->assertNull($r['employee_name']);
    }
}
