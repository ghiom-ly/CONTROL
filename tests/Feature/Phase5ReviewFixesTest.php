<?php

namespace Tests\Feature;

use App\Models\Issue;
use App\Models\MessageTemplate;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\StoreMessage;
use App\Models\User;
use App\Support\WhatsAppNumber;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** اختبارات تثبّت ملاحظات المراجعة النهائية للمرحلة 5 */
class Phase5ReviewFixesTest extends TestCase
{
    private User $admin;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeUser(Role::ADMIN);
        $this->store = Store::factory()->create(['phone' => '0912345678']);
        MessageTemplate::query()->update(['status' => 'approved', 'version' => 1]);
    }

    // I1
    public function test_mark_forwarded_never_reopens_resolved_codes(): void
    {
        $open = Shipment::create(['code' => 'RF5-OPEN', 'store_id' => $this->store->id, 'registered_at' => today(), 'last_result' => 'customer_no_answer', 'processing_status' => 'open']);
        $done = Shipment::create(['code' => 'RF5-DONE', 'store_id' => $this->store->id, 'registered_at' => today(), 'last_result' => 'customer_no_answer', 'processing_status' => 'resolved']);
        $this->actingAs($this->admin)->post("/stores/{$this->store->id}/message", [
            'template_id' => MessageTemplate::where('type', 'open_codes')->value('id'), 'body' => 'x',
            'codes' => ['RF5-OPEN', 'RF5-DONE'], 'mark_forwarded' => 1,
        ])->assertRedirect();
        $this->assertSame('in_progress', $open->fresh()->processing_status->value);
        $this->assertSame('resolved', $done->fresh()->processing_status->value);
        $this->assertSame(['RF5-OPEN'], StoreMessage::sole()->meta['codes']);
    }

    // m1 (أُعيد تصنيفها: خطأ 500)
    public function test_string_codes_param_does_not_crash(): void
    {
        $this->actingAs($this->admin)->get("/stores/{$this->store->id}/message?codes=ABC")->assertOk();
        $this->actingAs($this->admin)->get("/stores/{$this->store->id}/message?codes[0][]=x")->assertOk();
    }

    // m3 (أُعيد تصنيفها: الأكواد تُهمل بصمت)
    public function test_open_codes_button_hidden_without_approved_open_codes_template(): void
    {
        Shipment::create(['code' => 'RF5-BTN', 'store_id' => $this->store->id, 'registered_at' => today(), 'last_result' => 'customer_no_answer', 'processing_status' => 'open']);
        $this->actingAs($this->admin)->get('/open-codes?tab=customer')->assertSee('إرسال للمتجر');
        MessageTemplate::where('type', 'open_codes')->update(['status' => 'pending']);
        $this->actingAs($this->admin)->get('/open-codes?tab=customer')->assertDontSee('إرسال للمتجر');
    }

    // m5
    public function test_closed_issue_cannot_be_sent(): void
    {
        $closed = Issue::create(['store_id' => $this->store->id, 'reported_by' => $this->admin->id, 'reported_at' => now(),
            'type' => 'other', 'title' => 'مغلقة', 'description' => 'y', 'status' => 'closed']);
        $this->actingAs($this->admin)->post("/stores/{$this->store->id}/message", [
            'template_id' => MessageTemplate::where('type', 'issue')->value('id'), 'body' => 'x', 'issue_id' => $closed->id,
        ])->assertSessionHasErrors('issue_id');
    }

    // m2
    public function test_bulk_preview_query_count_is_bounded(): void
    {
        $ids = Store::factory()->count(20)->create(['phone' => '0912345678'])->pluck('id')->all();
        DB::enableQueryLog();
        $this->actingAs($this->admin)->post('/messages/bulk/preview', ['template_id' => MessageTemplate::where('type', 'general')->value('id'), 'store_ids' => $ids])->assertOk();
        $this->assertLessThan(60, count(DB::getQueryLog()));
    }

    // m11
    public function test_more_libyan_phone_formats(): void
    {
        $this->assertSame('218912345678', WhatsAppNumber::normalize('+218 (0)91 2345678'));
        $this->assertSame('218912345678', WhatsAppNumber::normalize('912345678'));
        $this->assertSame('218912345678', WhatsAppNumber::normalize('2180912345678'));
    }
}
