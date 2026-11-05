<?php

namespace Tests\Feature;

use App\Models\Issue;
use App\Models\MessageTemplate;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\StoreMessage;
use App\Models\User;
use Tests\TestCase;

class StoreMessageSendTest extends TestCase
{
    private User $admin;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeUser(Role::ADMIN);
        $this->store = Store::factory()->create(['name' => 'متجر الرسائل', 'phone' => '0912345678', 'owner_name' => 'علي']);
        MessageTemplate::query()->update(['status' => 'approved', 'version' => 1]);
    }

    private function tpl(string $type): MessageTemplate
    {
        return MessageTemplate::where('type', $type)->first();
    }

    public function test_send_redirects_to_whatsapp_and_logs(): void
    {
        $t = $this->tpl('general');
        $res = $this->actingAs($this->admin)->post("/stores/{$this->store->id}/message", ['template_id' => $t->id, 'body' => "مرحبا علي\nسطر ثانٍ"]);
        $res->assertRedirect('https://wa.me/218912345678?text='.rawurlencode("مرحبا علي\nسطر ثانٍ"));

        $m = StoreMessage::sole();
        $this->assertSame($this->store->id, $m->store_id);
        $this->assertSame($t->id, $m->message_template_id);
        $this->assertSame(1, $m->template_version);
        $this->assertSame('218912345678', $m->phone);
        $this->assertSame($this->admin->id, $m->sent_by);
    }

    public function test_form_prefills_rendered_text(): void
    {
        $this->actingAs($this->admin)->get("/stores/{$this->store->id}/message?template_id=".$this->tpl('general')->id)
            ->assertOk()->assertSee('السلام عليكم علي')->assertSee('إرسال عبر واتساب');
    }

    public function test_body_edit_is_what_is_logged(): void
    {
        $this->actingAs($this->admin)->post("/stores/{$this->store->id}/message", ['template_id' => $this->tpl('general')->id, 'body' => 'نص معدّل']);
        $this->assertSame('نص معدّل', StoreMessage::sole()->body);
    }

    public function test_cannot_send_unapproved_or_disabled(): void
    {
        foreach (['draft', 'pending', 'disabled'] as $status) {
            $t = $this->tpl('general');
            $t->update(['status' => $status]);
            $this->actingAs($this->admin)->post("/stores/{$this->store->id}/message", ['template_id' => $t->id, 'body' => 'x'])
                ->assertSessionHasErrors('template_id');
        }
        $this->assertSame(0, StoreMessage::count());
    }

    public function test_invalid_phone_blocks_send(): void
    {
        $this->store->update(['phone' => '123']);
        $this->actingAs($this->admin)->get("/stores/{$this->store->id}/message")->assertSee('رقم المتجر غير صالح لواتساب');
        $this->actingAs($this->admin)->post("/stores/{$this->store->id}/message", ['template_id' => $this->tpl('general')->id, 'body' => 'x'])
            ->assertSessionHasErrors('phone');
    }

    public function test_open_codes_prefilled_and_mark_forwarded(): void
    {
        $s = Shipment::create(['code' => 'SEND-A1', 'store_id' => $this->store->id, 'registered_at' => today(), 'last_result' => 'customer_no_answer', 'processing_status' => 'open']);
        $t = $this->tpl('open_codes');
        $this->actingAs($this->admin)->get("/stores/{$this->store->id}/message?template_id={$t->id}")->assertSee('SEND-A1');

        $this->actingAs($this->admin)->post("/stores/{$this->store->id}/message", [
            'template_id' => $t->id, 'body' => 'الأكواد: SEND-A1', 'codes' => ['SEND-A1'], 'mark_forwarded' => 1,
        ])->assertRedirect();
        $this->assertSame('in_progress', $s->fresh()->processing_status->value);
        $this->assertSame(['SEND-A1'], StoreMessage::sole()->meta['codes']);
    }

    public function test_selected_codes_from_open_codes_screen(): void
    {
        foreach (['SEL-1', 'SEL-2'] as $c) {
            Shipment::create(['code' => $c, 'store_id' => $this->store->id, 'registered_at' => today(), 'last_result' => 'customer_no_answer', 'processing_status' => 'open']);
        }
        $this->actingAs($this->admin)->get("/stores/{$this->store->id}/message?template_id=".$this->tpl('open_codes')->id.'&codes[]=SEL-2')
            ->assertSee('SEL-2')->assertDontSee('SEL-1');
    }

    public function test_issue_template_requires_store_issue(): void
    {
        $other = Store::factory()->create();
        $foreign = Issue::create(['store_id' => $other->id, 'reported_by' => $this->admin->id, 'reported_at' => now(), 'type' => 'other', 'title' => 'x', 'description' => 'y']);
        $t = $this->tpl('issue');
        $this->actingAs($this->admin)->post("/stores/{$this->store->id}/message", ['template_id' => $t->id, 'body' => 'x'])->assertSessionHasErrors('issue_id');
        $this->actingAs($this->admin)->post("/stores/{$this->store->id}/message", ['template_id' => $t->id, 'body' => 'x', 'issue_id' => $foreign->id])->assertSessionHasErrors('issue_id');

        $mine = Issue::create(['store_id' => $this->store->id, 'reported_by' => $this->admin->id, 'reported_at' => now(), 'type' => 'other', 'title' => 'مشكلتي', 'description' => 'y']);
        $this->actingAs($this->admin)->get("/stores/{$this->store->id}/message?template_id={$t->id}&issue_id={$mine->id}")->assertSee('مشكلتي');
        $this->actingAs($this->admin)->post("/stores/{$this->store->id}/message", ['template_id' => $t->id, 'body' => 'x', 'issue_id' => $mine->id])->assertRedirect();
        $this->assertSame($mine->id, StoreMessage::sole()->meta['issue_id']);
    }

    public function test_no_codes_shows_warning(): void
    {
        $this->actingAs($this->admin)->get("/stores/{$this->store->id}/message?template_id=".$this->tpl('open_codes')->id)
            ->assertSee('لا توجد أكواد مفتوحة لهذا المتجر');
    }

    public function test_store_page_lists_messages(): void
    {
        $this->actingAs($this->admin)->post("/stores/{$this->store->id}/message", ['template_id' => $this->tpl('general')->id, 'body' => 'رسالة مسجلة']);
        $this->actingAs($this->admin)->get("/stores/{$this->store->id}")->assertSee('رسالة مسجلة')->assertSee('إرسال رسالة');
    }

    public function test_employee_forbidden(): void
    {
        $this->actingAs($this->makeUser())->get("/stores/{$this->store->id}/message")->assertForbidden();
    }
}
