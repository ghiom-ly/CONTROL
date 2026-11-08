<?php

namespace Tests\Feature;

use App\Enums\ReportStatus;
use App\Models\DailyReport;
use App\Models\Role;
use App\Models\Store;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\ReportWhatsappFormatter;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ReportSubmitButtonAndDeliveredCodesTest extends TestCase
{
    private const SUBMIT_LABEL = 'إرسال للمراجعة';

    private const DELIVERED_TITLE = 'الأكواد المسلّمة بعد المتابعة';

    private User $emp;

    private Store $a;

    private Store $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->emp = $this->makeUser(Role::EMPLOYEE);
        $this->a = Store::factory()->assignedTo($this->emp->id)->create(['name' => 'متجر الأول']);
        $this->b = Store::factory()->assignedTo($this->emp->id)->create(['name' => 'متجر الثاني']);
    }

    private function draft(): DailyReport
    {
        $this->actingAs($this->emp)->post('/reports/start', ['stores' => [
            ['store_id' => $this->a->id, 'start_orders' => 10, 'start_rate' => 50],
            ['store_id' => $this->b->id, 'start_orders' => 5, 'start_rate' => 40],
        ]])->assertSessionHasNoErrors();

        return DailyReport::where('user_id', $this->emp->id)->latest('id')->firstOrFail();
    }

    private function draftWithCodes(): DailyReport
    {
        $r = $this->draft();
        $this->actingAs($this->emp)->put("/reports/{$r->id}", ['stores' => [
            ['store_id' => $this->a->id, 'shipments_count' => 3, 'codes' => [
                ['code' => 'DLV100', 'result' => 'delivered_after_followup'],
                ['code' => 'DLV200', 'result' => 'delivered_after_followup'],
                ['code' => 'WAIT300', 'result' => 'courier_promised_delivery'],
            ]],
            ['store_id' => $this->b->id, 'shipments_count' => 1, 'codes' => [
                ['code' => 'NOPE400', 'result' => 'customer_no_answer'],
            ]],
        ]])->assertSessionHasNoErrors();

        return $r->fresh();
    }

    public function test_owner_sees_submit_form_on_report_page_and_it_submits(): void
    {
        $r = $this->draft();
        $this->actingAs($this->emp)->get("/reports/{$r->id}")->assertOk()
            ->assertSee(self::SUBMIT_LABEL)->assertSee(route('reports.submit', $r), false)->assertSee('name="end_delivery_rate"', false);

        $this->actingAs($this->emp)->post("/reports/{$r->id}/submit", ['end_delivery_rate' => 60])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(ReportStatus::PendingReview, $r->fresh()->status);
        $this->actingAs($this->emp)->get("/reports/{$r->id}")->assertDontSee(route('reports.submit', $r), false);
    }

    public function test_others_do_not_see_submit_form(): void
    {
        $r = $this->draft();
        foreach ([Role::ADMIN, Role::SUPER_ADMIN] as $role) {
            $this->actingAs($this->makeUser($role))->get("/reports/{$r->id}")->assertOk()->assertDontSee(route('reports.submit', $r), false);
        }
    }

    public function test_rejected_report_submit_follows_the_edit_setting(): void
    {
        $r = $this->draft();
        $r->update(['status' => ReportStatus::Rejected, 'rejection_reason' => 'صحح']);
        $this->actingAs($this->emp)->get("/reports/{$r->id}")->assertSee(route('reports.submit', $r), false);

        SystemSetting::where('key', 'employee_can_edit_rejected')->update(['value' => '0']);
        Cache::flush();
        $this->actingAs($this->emp)->get("/reports/{$r->id}")->assertDontSee(route('reports.submit', $r), false);
    }

    public function test_edit_page_has_shortcut_to_submit_bar_for_owner(): void
    {
        $r = $this->draft();
        $this->actingAs($this->emp)->get("/reports/{$r->id}/edit")->assertOk()
            ->assertSee('href="#submit-bar"', false)->assertSee('id="submit-bar"', false)->assertSee('حفظ وإرسال للمراجعة');
    }

    public function test_edit_page_has_visible_delivered_codes_field_per_store(): void
    {
        $r = $this->draft();
        $this->actingAs($this->emp)->get("/reports/{$r->id}/edit")->assertOk()
            ->assertSee(self::DELIVERED_TITLE)->assertSee('addDelivered(s)', false);
    }

    public function test_report_page_lists_delivered_codes_per_store(): void
    {
        $r = $this->draftWithCodes();
        $res = $this->actingAs($this->emp)->get("/reports/{$r->id}")->assertOk()->assertSee(self::DELIVERED_TITLE);
        $html = $res->getContent();
        $section = substr($html, strpos($html, self::DELIVERED_TITLE));
        $section = substr($section, 0, strpos($section, '</section>'));
        $this->assertStringContainsString('متجر الأول', $section);
        $this->assertStringContainsString('DLV100', $section);
        $this->assertStringContainsString('DLV200', $section);
        $this->assertStringContainsString('متجر الثاني', $section);
        $this->assertStringContainsString('لا يوجد', $section);
        $this->assertStringNotContainsString('WAIT300', $section);
        $this->assertStringNotContainsString('NOPE400', $section);
    }

    public function test_whatsapp_text_has_delivered_codes_per_store_and_keeps_template_numbers(): void
    {
        $text = ReportWhatsappFormatter::format($this->draftWithCodes());
        $this->assertStringContainsString('4) ملاحظات', $text);
        $this->assertStringContainsString('5) '.self::DELIVERED_TITLE.':', $text);
        $this->assertStringContainsString('- متجر الأول (2): DLV100 DLV200', $text);
        $this->assertStringContainsString('- متجر الثاني: لا يوجد', $text);
    }
}
