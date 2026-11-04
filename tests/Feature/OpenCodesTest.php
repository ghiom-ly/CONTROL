<?php

namespace Tests\Feature;

use App\Models\DailyReport;
use App\Models\ReportStore;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\ShipmentFollowup;
use App\Models\Store;
use App\Models\User;
use App\Services\Reports\ReportApprovalService;
use Tests\TestCase;

class OpenCodesTest extends TestCase
{
    private User $emp;

    private User $admin;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->emp = $this->makeUser();
        $this->admin = $this->makeUser(Role::ADMIN);
        $this->store = Store::factory()->create(['name' => 'متجر الأمل']);
    }

    /** ينشئ تقريراً معتمداً بالأكواد [code => result] */
    private function approved(array $codes, ?User $owner = null): DailyReport
    {
        $owner ??= $this->emp;
        $r = DailyReport::factory()->pendingReview()->for($owner)->create();
        $rs = ReportStore::create(['daily_report_id' => $r->id, 'store_id' => $this->store->id, 'shipments_count' => 1]);
        foreach ($codes as $code => $result) {
            ShipmentFollowup::create(['daily_report_id' => $r->id, 'report_store_id' => $rs->id, 'store_id' => $this->store->id,
                'shipment_code' => $code, 'user_id' => $owner->id, 'followup_at' => now(), 'result' => $result]);
        }
        app(ReportApprovalService::class)->approve($r, $this->admin);

        return $r;
    }

    public function test_lists_only_official_unanswered_codes_by_tab(): void
    {
        $this->approved(['OC-CUS1-X' => 'customer_no_answer', 'OC-COU1-X' => 'courier_no_answer', 'OC-OK1-X' => 'delivered_after_followup']);
        // كود في تقرير غير معتمد لا يظهر
        $p = DailyReport::factory()->pendingReview()->for($this->emp)->create();
        ShipmentFollowup::create(['daily_report_id' => $p->id, 'store_id' => $this->store->id, 'shipment_code' => 'OC-PEND1-X',
            'user_id' => $this->emp->id, 'followup_at' => now(), 'result' => 'customer_no_answer']);

        $this->actingAs($this->admin);
        $this->get('/open-codes?tab=customer')->assertOk()->assertSee('OC-CUS1-X')->assertDontSee('OC-COU1-X')->assertDontSee('OC-OK1-X')->assertDontSee('OC-PEND1-X')->assertSee('متجر الأمل');
        $this->get('/open-codes?tab=courier')->assertOk()->assertSee('OC-COU1-X')->assertDontSee('OC-CUS1-X');
    }

    public function test_mark_resolved_removes_from_list(): void
    {
        $this->approved(['OC-R1-X' => 'customer_no_answer']);
        $id = Shipment::where('code', 'OC-R1-X')->value('id');

        $this->actingAs($this->admin)->post('/open-codes/mark', ['shipment_ids' => [$id], 'action' => 'resolved', 'note' => 'تم مع المتجر'])
            ->assertRedirect();
        $s = Shipment::find($id);
        $this->assertSame('resolved', $s->processing_status->value);
        $this->assertStringContainsString('تم مع المتجر', $s->notes);
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => Shipment::class, 'auditable_id' => $id, 'event' => 'updated']);
        $this->get('/open-codes?tab=customer')->assertDontSee('OC-R1-X');
    }

    public function test_forwarded_stays_with_badge(): void
    {
        $this->approved(['OC-F1-X' => 'customer_no_answer']);
        $id = Shipment::where('code', 'OC-F1-X')->value('id');
        $this->actingAs($this->admin)->post('/open-codes/mark', ['shipment_ids' => [$id], 'action' => 'forwarded']);
        $this->assertSame('in_progress', Shipment::find($id)->processing_status->value);
        $this->get('/open-codes?tab=customer')->assertSee('OC-F1-X')->assertSee('قيد المعالجة');
    }

    public function test_new_unanswered_followup_reopens_resolved_code(): void
    {
        $this->approved(['OC-RE1-X' => 'customer_no_answer']);
        Shipment::where('code', 'OC-RE1-X')->update(['processing_status' => 'resolved']);
        $this->travel(1)->hours();
        $this->approved(['OC-RE1-X' => 'customer_no_answer'], $this->makeUser());

        $this->assertSame('open', Shipment::where('code', 'OC-RE1-X')->first()->processing_status->value);
        $this->actingAs($this->admin)->get('/open-codes?tab=customer')->assertSee('OC-RE1-X');
    }

    public function test_later_other_result_removes_code(): void
    {
        $this->approved(['OC-L1-X' => 'courier_no_answer']);
        $this->travel(1)->hours();
        $this->approved(['OC-L1-X' => 'courier_promised_delivery'], $this->makeUser());
        $this->actingAs($this->admin)->get('/open-codes?tab=courier')->assertOk()->assertDontSee('OC-L1-X');
    }

    public function test_employee_cannot_mark(): void
    {
        $this->approved(['OC-E1-X' => 'customer_no_answer']);
        $this->actingAs($this->emp)->post('/open-codes/mark', ['shipment_ids' => [Shipment::value('id')], 'action' => 'resolved'])->assertForbidden();
    }

    public function test_employee_cannot_open_codes_screen(): void
    {
        $this->approved(['OC-MINE1-X' => 'customer_no_answer']);
        $this->actingAs($this->emp)->get('/open-codes?tab=customer')->assertForbidden();
    }

    public function test_role_without_view_all_sees_only_own_codes(): void
    {
        // دور مخصص يملك عرض الشحنات دون عرض كل التقارير
        $this->emp->role->permissions()->attach(\App\Models\Permission::where('name', 'shipments.view')->value('id'));
        $this->emp->flushPermissionCache();
        $this->approved(['OC-MINE1-X' => 'customer_no_answer']);
        $this->approved(['OC-THEIRS1-X' => 'customer_no_answer'], $this->makeUser());
        $this->actingAs($this->emp->fresh())->get('/open-codes?tab=customer')->assertOk()->assertSee('OC-MINE1-X')->assertDontSee('OC-THEIRS1-X');
    }

    public function test_filters_by_store(): void
    {
        $this->approved(['OC-S1-X' => 'customer_no_answer']);
        $other = Store::factory()->create();
        $this->actingAs($this->admin)->get('/open-codes?tab=customer&store_id='.$other->id)->assertOk()->assertDontSee('OC-S1-X');
    }
}
