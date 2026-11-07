<?php

namespace Tests\Feature;

use App\Models\MessageTemplate;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreMessage;
use App\Support\WhatsAppText;
use Tests\TestCase;

class WhatsAppLongMessageTest extends TestCase
{
    private function send(string $body)
    {
        MessageTemplate::query()->update(['status' => 'approved', 'version' => 1]);
        $s = Store::factory()->create(['phone' => '0935043921']);

        return $this->actingAs($this->makeUser(Role::ADMIN))->post("/stores/{$s->id}/message", [
            'template_id' => MessageTemplate::where('type', 'general')->value('id'), 'period' => '7d', 'body' => $body]);
    }

    public function test_markdown_bold_converted_to_whatsapp_bold(): void
    {
        $this->assertSame("*خدمة تأكيد*\nنص _مائل_", WhatsAppText::clean("## **خدمة تأكيد**\r\nنص __مائل__"));
        $res = $this->send('مرحبا **خدمة تأكيد الطلبيات**');
        $this->assertSame('https://wa.me/218935043921?text='.rawurlencode('مرحبا *خدمة تأكيد الطلبيات*'), $res->headers->get('Location'));
        $this->assertSame('مرحبا *خدمة تأكيد الطلبيات*', StoreMessage::sole()->body);
    }

    public function test_long_message_opens_chat_without_text(): void
    {
        $body = str_repeat('يسعدنا في شركة النورس أن نقدم لكم خدمة تأكيد الطلبيات. ', 30);
        $res = $this->send($body);
        $this->assertSame('https://wa.me/218935043921', $res->headers->get('Location'));
        $this->assertSame(1, StoreMessage::count());   // تُسجَّل كاملة رغم فتح المحادثة بلا نص
    }

    public function test_bulk_preview_shows_cleaned_text_and_copy_hint(): void
    {
        MessageTemplate::query()->update(['status' => 'approved', 'version' => 1]);
        $t = MessageTemplate::where('type', 'general')->first();
        $t->update(['body' => 'مرحبا **{اسم_المتجر}**']);
        $s = Store::factory()->create(['name' => 'متجر نجمة', 'phone' => '0911111111']);
        $this->actingAs($this->makeUser(Role::ADMIN))->post('/messages/bulk/preview', ['template_id' => $t->id, 'store_ids' => [$s->id]])
            ->assertSee('مرحبا *متجر نجمة*')->assertDontSee('**متجر نجمة**')->assertSee('تُنسخ تلقائياً');
    }

    public function test_url_helper_threshold(): void
    {
        $this->assertSame('https://wa.me/218911111111?text='.rawurlencode('قصير'), WhatsAppText::url('218911111111', 'قصير'));
        $this->assertSame('https://wa.me/218911111111', WhatsAppText::url('218911111111', str_repeat('ا', WhatsAppText::MAX_CHARS + 1)));
        $this->assertSame('https://wa.me/?text='.rawurlencode('قصير'), WhatsAppText::url(null, 'قصير'));
    }
}
