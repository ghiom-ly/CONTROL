<?php

namespace Tests\Feature;

use App\Models\Issue;
use App\Models\IssueUpdate;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\User;
use App\Services\IssueService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OldMinorsIssuesTest extends TestCase
{
    private User $admin;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeUser(Role::ADMIN);
        $this->store = Store::factory()->create();
    }

    private function issue(array $a = []): Issue
    {
        return Issue::create($a + ['store_id' => $this->store->id, 'reported_by' => $this->admin->id, 'reported_at' => now(),
            'type' => 'other', 'title' => 't', 'description' => 'd', 'status' => 'new']);
    }

    public function test_action_taken_cannot_be_emptied_on_resolved(): void
    {
        $i = $this->issue(['status' => 'resolved', 'resolved_at' => now(), 'action_taken' => 'تم']);
        $this->actingAs($this->admin)->post("/issues/{$i->id}/updates", ['status' => 'resolved', 'action_taken' => '', 'comment' => 'x'])
            ->assertSessionHasErrors('action_taken');
        $this->assertSame('تم', $i->fresh()->action_taken);
    }

    public function test_empty_priority_is_validation_error(): void
    {
        $i = $this->issue();
        $this->actingAs($this->admin)->post("/issues/{$i->id}/updates", ['priority' => '', 'comment' => 'x'])->assertSessionHasErrors('priority');
    }

    public function test_empty_update_rejected(): void
    {
        $i = $this->issue();
        $this->actingAs($this->admin)->post("/issues/{$i->id}/updates", ['status' => 'new', 'priority' => 'medium'])->assertSessionHasErrors('comment');
        $this->assertSame(0, IssueUpdate::count());
    }

    public function test_unknown_shipment_code_rejected_on_store_issue(): void
    {
        $this->actingAs($this->admin)->post("/stores/{$this->store->id}/issues", ['title' => 't', 'type' => 'other', 'description' => 'd', 'priority' => 'medium',
            'shipment_code' => 'NOPE'])->assertSessionHasErrors('shipment_code');
    }

    public function test_managers_query_is_bounded(): void
    {
        foreach (range(1, 8) as $i) {
            $this->makeUser(Role::ADMIN);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $ids = IssueService::managers()->pluck('id');
        $this->assertGreaterThanOrEqual(9, $ids->count());
        $this->assertLessThanOrEqual(3, count(DB::getQueryLog()));
    }

    public function test_trashed_shipment_hides_contact_form(): void
    {
        $s = Shipment::create(['code' => 'TRSH', 'store_id' => $this->store->id, 'registered_at' => today(), 'processing_status' => 'open']);
        $s->delete();
        $this->actingAs($this->makeUser())->get('/shipments/TRSH')->assertOk()->assertDontSee('تسجيل اتصال');
    }

    public function test_internal_code_link_next_to_external(): void
    {
        Shipment::create(['code' => 'LNK1', 'store_id' => $this->store->id, 'registered_at' => today(), 'processing_status' => 'open', 'last_result' => 'customer_no_answer']);
        $this->actingAs($this->admin)->get('/open-codes')->assertSee(route('shipments.show', 'LNK1'), false);
    }
}
