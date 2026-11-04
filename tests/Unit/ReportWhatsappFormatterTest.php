<?php

namespace Tests\Unit;

use App\Models\DailyReport;
use App\Models\ReportStore;
use App\Models\ShipmentFollowup;
use App\Models\Store;
use App\Support\ReportWhatsappFormatter;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReportWhatsappFormatterTest extends TestCase
{
    public function test_whatsapp_text_matches_template(): void
    {
        $emp = $this->makeUser('employee', ['name' => 'أحمد سالم']);
        $a = Store::factory()->create(['name' => 'متجر الأمل']);
        $b = Store::factory()->create(['name' => 'متجر السلام']);
        $r = DailyReport::factory()->draft()->for($emp)->create([
            'work_date' => Carbon::parse('2026-11-04'), 'start_total_orders' => 140, 'start_delivery_rate' => 72,
            'end_delivery_rate' => 81.5, 'attempt_times' => ['10:30', '14:15', '16:00'], 'notes' => 'تأخر المندوب', 'inquiries' => null,
        ]);
        $ra = ReportStore::create(['daily_report_id' => $r->id, 'store_id' => $a->id, 'shipments_count' => 34]);
        ReportStore::create(['daily_report_id' => $r->id, 'store_id' => $b->id, 'shipments_count' => null]);
        foreach ([['A1', 'customer_no_answer'], ['A2', 'customer_no_answer'], ['C1', 'courier_promised_delivery']] as [$code, $res]) {
            ShipmentFollowup::create(['daily_report_id' => $r->id, 'report_store_id' => $ra->id, 'store_id' => $a->id,
                'shipment_code' => $code, 'user_id' => $emp->id, 'followup_at' => now(), 'result' => $res]);
        }

        $expected = <<<'TXT'
ريبورت تاريخ: 2026-11-04
اسم الموظف: أحمد سالم

بداية الشفت: إجمالي الطلبيات 140 — نسبة التسليم 72% ← 81.5%

1) ملخص حركة الأوردرات:
- كام متجر تم العمل عليه: 2
- إجمالي الأوردرات التي عملت عليها اليوم: 34
  - متجر الأمل: 34
  - متجر السلام: غير متوفر

2) أوقات المحاولات:
- إجمالي عدد المحاولات الإجمالية التي أجريتها اليوم: 3 محاولة بالمجمل.
- مواعيد المحاولات:
  - المحاولة الأولى: الساعة 10:30
  - المحاولة الثانية: الساعة 14:15
  - المحاولة الثالثة: الساعة 16:00

3) الأكواد غير المُجاب عليها:
- أكواد لم يرد عليها الزبون (لتحويلها للمتاجر): A1 A2
- أكواد لم يرد عليها المندوب أو لا تستجيب ولا تحل (مع إرسال السكرينات): لا يوجد

4) ملاحظات واجهت الموظف أو استفسارات:
- تأخر المندوب
TXT;

        $this->assertSame($expected, ReportWhatsappFormatter::format($r->fresh()));
    }
}
