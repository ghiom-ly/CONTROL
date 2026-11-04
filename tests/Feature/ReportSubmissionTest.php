<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\DailyReport;
use App\Models\ReportStore;
use App\Models\ShipmentFollowup;
use App\Models\Store;
use App\Models\User;
use Tests\TestCase;

class ReportSubmissionTest extends TestCase
{
    private User $emp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->emp = $this->makeUser();
    }

    private function reportWithStores(array $counts, string $state = 'draft'): DailyReport
    {
        $r = DailyReport::factory()->state(['status' => $state])->for($this->emp)->create(['attempt_times' => ['10:00', '12:00']]);
        foreach ($counts as $c) {
            ReportStore::create(['daily_report_id' => $r->id, 'store_id' => Store::factory()->create()->id, 'shipments_count' => $c]);
        }

        return $r;
    }

    public function test_submit_moves_to_pending_and_sets_totals(): void
    {
        $r = $this->reportWithStores([30, null, 12]);
        $this->actingAs($this->emp)->post("/reports/{$r->id}/submit", ['end_delivery_rate' => '٨١'])
            ->assertRedirect(route('reports.show', $r));

        $r->refresh();
        $this->assertSame('pending_review', $r->status->value);
        $this->assertNotNull($r->submitted_at);
        $this->assertSame('81.00', $r->end_delivery_rate);
        $this->assertSame(42, $r->total_shipments);
        $this->assertSame(2, $r->contact_attempts_count);
    }

    public function test_total_null_when_all_counts_null(): void
    {
        $r = $this->reportWithStores([null]);
        $r->update(['total_shipments' => 99]);
        $this->actingAs($this->emp)->post("/reports/{$r->id}/submit", ['end_delivery_rate' => 50]);
        $this->assertSame('pending_review', $r->fresh()->status->value);
        $this->assertNull($r->fresh()->total_shipments);
    }

    public function test_submit_requires_end_rate_and_a_store(): void
    {
        $empty = $this->reportWithStores([]);
        $this->actingAs($this->emp)->post("/reports/{$empty->id}/submit", ['end_delivery_rate' => 50])->assertSessionHasErrors('stores');
        $this->assertSame('draft', $empty->fresh()->status->value);

        $r = $this->reportWithStores([5]);
        $this->actingAs($this->emp)->post("/reports/{$r->id}/submit", [])->assertSessionHasErrors('end_delivery_rate');
        $this->actingAs($this->emp)->post("/reports/{$r->id}/submit", ['end_delivery_rate' => 101])->assertSessionHasErrors('end_delivery_rate');
    }

    public function test_cannot_submit_twice_or_others_report(): void
    {
        $r = $this->reportWithStores([5], 'pending_review');
        $this->actingAs($this->emp)->post("/reports/{$r->id}/submit", ['end_delivery_rate' => 50])->assertForbidden();
        $mine = $this->reportWithStores([5]);
        $this->actingAs($this->makeUser())->post("/reports/{$mine->id}/submit", ['end_delivery_rate' => 50])->assertForbidden();
    }

    public function test_resubmit_after_reject_creates_revision_and_bumps_version(): void
    {
        $r = $this->reportWithStores([5], 'rejected');
        $this->actingAs($this->emp)->post("/reports/{$r->id}/submit", ['end_delivery_rate' => 60])->assertSessionHasNoErrors();

        $r->refresh();
        $this->assertSame('pending_review', $r->status->value);
        $this->assertSame(2, $r->version);
        $this->assertNull($r->rejection_reason);
        $rev = $r->revisions()->sole();
        $this->assertSame(1, $rev->version);
        $this->assertSame('إعادة تقديم بعد الإرجاع', $rev->reason);
        $this->assertSame('rejected', $rev->snapshot['report']['status']);
        $this->assertCount(1, $rev->snapshot['stores']);
    }

    public function test_courier_without_screens_warns_but_submits(): void
    {
        $r = $this->reportWithStores([5]);
        $rs = $r->stores()->first();
        ShipmentFollowup::create(['daily_report_id' => $r->id, 'report_store_id' => $rs->id, 'store_id' => $rs->store_id,
            'shipment_code' => 'K1', 'user_id' => $this->emp->id, 'followup_at' => now(), 'result' => 'courier_no_answer']);

        $this->actingAs($this->emp)->post("/reports/{$r->id}/submit", ['end_delivery_rate' => 50])->assertSessionHas('warning');
        $r->refresh();
        $this->assertSame('pending_review', $r->status->value);
        $this->assertContains('courier_without_screens', $r->warningFlags());
    }

    public function test_warning_flags(): void
    {
        $other = $this->makeUser();
        $r = DailyReport::factory()->draft()->for($this->emp)->create(['attempt_times' => ['1:00', '2:00', '3:00']]);
        ReportStore::create(['daily_report_id' => $r->id, 'store_id' => Store::factory()->assignedTo($other->id)->create()->id, 'is_outside_assignment' => true]);
        $this->assertSame(['over_attempts', 'outside_assignment'], $r->fresh()->warningFlags());
    }

    public function test_show_page_has_whatsapp_text_and_is_protected(): void
    {
        $r = $this->reportWithStores([5]);
        $this->actingAs($this->emp)->get("/reports/{$r->id}")->assertOk()->assertSee('ريبورت تاريخ:', false);
        $this->actingAs($this->makeUser())->get("/reports/{$r->id}")->assertForbidden();
    }
}
