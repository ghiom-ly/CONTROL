<?php

namespace Tests\Feature;

use App\Models\MessageTemplate;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\User;
use Tests\TestCase;

class BulkMessageTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeUser(Role::ADMIN);
        MessageTemplate::query()->update(['status' => 'approved', 'version' => 1]);
    }

    private function preview(string $type, array $storeIds)
    {
        return $this->actingAs($this->admin)->post('/messages/bulk/preview', [
            'template_id' => MessageTemplate::where('type', $type)->value('id'), 'store_ids' => $storeIds,
        ]);
    }

    public function test_bulk_preview_rows_for_general(): void
    {
        $a = Store::factory()->create(['name' => 'متجر بلك أ', 'phone' => '0911111111', 'owner_name' => 'سعيد']);
        $b = Store::factory()->create(['name' => 'متجر بلك ب', 'phone' => '0922222222']);
        $this->preview('general', [$a->id, $b->id])->assertOk()
            ->assertSee('متجر بلك أ')->assertSee('متجر بلك ب')->assertSee('السلام عليكم سعيد')
            ->assertSee(route('stores.message.send', $a), false);
    }

    public function test_open_codes_skips_stores_without_codes(): void
    {
        $with = Store::factory()->create(['name' => 'متجر بأكواد', 'phone' => '0911111111']);
        $without = Store::factory()->create(['name' => 'متجر بلا أكواد', 'phone' => '0922222222']);
        Shipment::create(['code' => 'BLK-1', 'store_id' => $with->id, 'registered_at' => today(), 'last_result' => 'customer_no_answer', 'processing_status' => 'open']);
        $this->preview('open_codes', [$with->id, $without->id])->assertOk()
            ->assertSee('BLK-1')->assertSee('متجر بلا أكواد')->assertSee('لا توجد أكواد مفتوحة — استُبعد');
    }

    public function test_issue_type_not_allowed(): void
    {
        $s = Store::factory()->create(['phone' => '0911111111']);
        $this->preview('issue', [$s->id])->assertSessionHasErrors('template_id');
    }

    public function test_limit_300(): void
    {
        $this->preview('general', range(1, 301))->assertSessionHasErrors('store_ids');
    }

    public function test_invalid_phone_flagged(): void
    {
        $s = Store::factory()->create(['name' => 'متجر رقم خطأ', 'phone' => '12']);
        $this->preview('general', [$s->id])->assertOk()->assertSee('رقم غير صالح لواتساب');
    }

    public function test_all_active_stores_shortcut(): void
    {
        Store::factory()->create(['name' => 'متجر نشط للكل', 'phone' => '0911111111']);
        Store::factory()->create(['name' => 'متجر مؤرشف للكل', 'status' => 'archived', 'phone' => '0911111112']);
        $this->actingAs($this->admin)->post('/messages/bulk/preview', ['template_id' => MessageTemplate::where('type', 'general')->value('id'), 'all_active' => 1])
            ->assertOk()->assertSee('متجر نشط للكل')->assertDontSee('متجر مؤرشف للكل');
    }

    public function test_employee_forbidden(): void
    {
        $this->actingAs($this->makeUser())->get('/messages/bulk')->assertForbidden();
    }
}
