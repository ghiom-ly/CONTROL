<?php

namespace Tests\Feature;

use App\Models\DailyReport;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\ShipmentFollowup;
use App\Models\Store;
use App\Models\StoreMessage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class StoreReportShareTest extends TestCase
{
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = Store::factory()->create(['name' => 'متجر التقرير', 'phone' => '0912345678', 'owner_name' => 'علي']);
    }

    private function link(?int $storeId = null): string
    {
        return URL::temporarySignedRoute('store-report.public', now()->addDays(7),
            ['store' => $storeId ?? $this->store->id, 'from' => today()->subDays(6)->toDateString(), 'to' => today()->toDateString()]);
    }

    public function test_share_redirects_to_whatsapp_with_signed_link(): void
    {
        $res = $this->actingAs($this->makeUser(Role::ADMIN))->post("/stores/{$this->store->id}/report-share", ['period' => 'week']);
        $url = $res->headers->get('Location');
        $this->assertStringStartsWith('https://wa.me/218912345678?text=', $url);
        $this->assertStringContainsString('%2Fr%2Fstore%2F'.$this->store->id, $url);
        $m = StoreMessage::sole();
        $this->assertSame('report', $m->type);
        $this->assertArrayHasKey('url', $m->meta);
    }

    public function test_public_link_serves_pdf_without_login(): void
    {
        $res = $this->get($this->link())->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $res->getContent());
    }

    public function test_tampered_link_rejected(): void
    {
        $other = Store::factory()->create();
        $tampered = str_replace('/r/store/'.$this->store->id, '/r/store/'.$other->id, $this->link());
        $this->get($tampered)->assertForbidden()->assertSee('انتهت صلاحية الرابط');
    }

    public function test_link_expires_after_seven_days(): void
    {
        $link = $this->link();
        $this->travel(8)->days();
        $this->get($link)->assertForbidden();
    }

    public function test_store_report_has_no_employee_names(): void
    {
        $emp = $this->makeUser(Role::EMPLOYEE, ['name' => 'موظف سري']);
        $r = DailyReport::factory()->approved()->for($emp)->create();
        $s = Shipment::create(['code' => 'SEC1', 'store_id' => $this->store->id, 'registered_at' => today(), 'processing_status' => 'open',
            'last_followup_user_id' => $emp->id]);
        ShipmentFollowup::create(['shipment_id' => $s->id, 'daily_report_id' => $r->id, 'store_id' => $this->store->id, 'shipment_code' => 'SEC1',
            'user_id' => $emp->id, 'followup_at' => now(), 'result' => 'customer_no_answer', 'is_official' => true, 'notes' => 'ملاحظة داخلية']);

        $admin = $this->makeUser(Role::ADMIN, ['name' => 'المدير المستخرج']);
        $this->actingAs($admin)->get("/reports-center/store/print?store_id={$this->store->id}")->assertOk()
            ->assertSee('SEC1')->assertDontSee('موظف سري')->assertDontSee('ملاحظة داخلية')->assertDontSee('المدير المستخرج');
    }

    public function test_viewer_cannot_share_and_invalid_phone(): void
    {
        $this->actingAs($this->makeUser(Role::VIEWER))->post("/stores/{$this->store->id}/report-share", ['period' => 'week'])->assertForbidden();
        $this->store->update(['phone' => '123']);
        $this->actingAs($this->makeUser(Role::ADMIN))->post("/stores/{$this->store->id}/report-share", ['period' => 'week'])
            ->assertSessionHasErrors('phone');
        $this->assertSame(0, StoreMessage::count());
    }

    public function test_store_page_has_report_buttons(): void
    {
        $this->actingAs($this->makeUser(Role::ADMIN))->get("/stores/{$this->store->id}")->assertOk()
            ->assertSee('إرسال تقرير المتجر عبر واتساب')->assertSee('تحميل تقرير المتجر');
    }
}
