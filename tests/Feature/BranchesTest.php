<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Branch;
use App\Models\DailyReport;
use App\Models\ReportStore;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\ShipmentFollowup;
use App\Models\Store;
use App\Models\User;
use App\Services\Reports\ReportApprovalService;
use Tests\TestCase;

/** بيانات الفروع (المسؤول ومسؤول المناديب) وإرسال الأكواد العالقة مع المناديب لمسؤول مناديب الفرع */
class BranchesTest extends TestCase
{
    private User $admin;

    private User $emp;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeUser(Role::SUPER_ADMIN);
        $this->emp = $this->makeUser();
        $this->store = Store::factory()->create(['name' => 'متجر الأمل']);
    }

    private function branch(array $over = []): Branch
    {
        return Branch::create(array_merge([
            'name' => 'فرع بنغازي', 'manager_name' => 'سالم', 'manager_phone' => '0911111111',
            'couriers_manager_name' => 'علي', 'couriers_manager_phone' => '0922222222',
        ], $over));
    }

    /** تقرير معتمد فيه أكواد «لم يرد المندوب» + سكرين مندوب */
    private function courierCodes(array $codes, ?User $owner = null): DailyReport
    {
        $owner ??= $this->emp;
        $r = DailyReport::factory()->pendingReview()->for($owner)->create();
        $rs = ReportStore::create(['daily_report_id' => $r->id, 'store_id' => $this->store->id, 'shipments_count' => 1]);
        foreach ($codes as $code) {
            ShipmentFollowup::create(['daily_report_id' => $r->id, 'report_store_id' => $rs->id, 'store_id' => $this->store->id,
                'shipment_code' => $code, 'user_id' => $owner->id, 'followup_at' => now(), 'result' => 'courier_no_answer']);
        }
        Attachment::create(['attachable_type' => DailyReport::class, 'attachable_id' => $r->id, 'category' => 'courier_screens',
            'disk' => 'local', 'path' => 'x/'.$r->id.'.png', 'original_name' => 'screen.png', 'mime_type' => 'image/png', 'size' => 10, 'sha256' => str_repeat('a', 64), 'uploaded_by' => $owner->id]);
        app(ReportApprovalService::class)->approve($r, $this->admin);

        return $r;
    }

    public function test_admin_manages_branches(): void
    {
        $this->actingAs($this->admin);
        $this->get('/settings/branches')->assertOk()->assertSee('الفروع');

        $this->post('/settings/branches', [
            'name' => 'فرع مصراتة', 'manager_name' => 'خالد', 'manager_phone' => '0913333333',
            'couriers_manager_name' => 'محمود', 'couriers_manager_phone' => '0924444444',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $b = Branch::where('name', 'فرع مصراتة')->firstOrFail();
        $this->assertSame('محمود', $b->couriers_manager_name);
        $this->get('/settings/branches')->assertSee('فرع مصراتة')->assertSee('0924444444');

        $this->put("/settings/branches/{$b->id}", ['name' => 'فرع مصراتة', 'couriers_manager_name' => 'يوسف', 'couriers_manager_phone' => '0925555555'])
            ->assertSessionHasNoErrors();
        $this->assertSame('يوسف', $b->fresh()->couriers_manager_name);
        $this->assertNull($b->fresh()->manager_name);

        $this->delete("/settings/branches/{$b->id}")->assertRedirect();
        $this->assertNull(Branch::find($b->id));
    }

    public function test_branch_validation(): void
    {
        $this->branch();
        $this->actingAs($this->admin)->post('/settings/branches', ['name' => 'فرع بنغازي', 'couriers_manager_phone' => '123'])
            ->assertSessionHasErrors(['name', 'couriers_manager_phone']);
    }

    public function test_employee_cannot_manage_branches(): void
    {
        $this->actingAs($this->emp);
        $this->get('/settings/branches')->assertForbidden();
        $this->post('/settings/branches', ['name' => 'x'])->assertForbidden();
    }

    public function test_courier_tab_offers_branch_send(): void
    {
        $this->branch();
        $this->courierCodes(['BR-COU1-X']);
        $this->actingAs($this->admin)->get('/open-codes?tab=courier')->assertOk()
            ->assertSee('فرع بنغازي')->assertSee('إرسال لمسؤول المناديب');
    }

    public function test_message_page_has_codes_screens_and_whatsapp_link(): void
    {
        $b = $this->branch();
        $r = $this->courierCodes(['BR-COU1-X', 'BR-COU2-X']);
        $ids = Shipment::whereIn('code', ['BR-COU1-X', 'BR-COU2-X'])->pluck('id')->all();
        $att = Attachment::where('attachable_id', $r->id)->firstOrFail();

        $res = $this->actingAs($this->admin)->get('/open-codes/branch-message?'.http_build_query(['branch_id' => $b->id, 'shipment_ids' => $ids]));
        $res->assertOk()->assertSee('BR-COU1-X')->assertSee('BR-COU2-X')->assertSee('علي')->assertSee('متجر الأمل')
            ->assertSee('https://wa.me/218922222222?text=', false)
            ->assertSee(route('attachments.show', $att), false);
    }

    public function test_employee_cannot_open_branch_message(): void
    {
        // الموظف ما عندهش صفحة الأكواد المفتوحة أصلاً، فما يقدرش يرسل منها
        $b = $this->branch();
        $this->courierCodes(['BR-MINE-X']);
        $ids = Shipment::where('code', 'BR-MINE-X')->pluck('id')->all();

        $this->actingAs($this->emp)->get('/open-codes/branch-message?'.http_build_query(['branch_id' => $b->id, 'shipment_ids' => $ids]))
            ->assertForbidden();
    }

    public function test_branch_without_valid_phone_opens_whatsapp_without_number(): void
    {
        $b = $this->branch(['couriers_manager_phone' => null]);
        $this->courierCodes(['BR-COU1-X']);
        $ids = Shipment::where('code', 'BR-COU1-X')->pluck('id')->all();

        $this->actingAs($this->admin)->get('/open-codes/branch-message?'.http_build_query(['branch_id' => $b->id, 'shipment_ids' => $ids]))
            ->assertOk()->assertSee('https://wa.me/?text=', false)->assertSee('رقم مسؤول المناديب غير مسجل');
    }
}
