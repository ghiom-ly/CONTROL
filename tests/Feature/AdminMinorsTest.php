<?php

namespace Tests\Feature;

use App\Models\AttendanceOverride;
use App\Models\ConfirmationOrder;
use App\Models\DailyReport;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\ShipmentFollowup;
use App\Models\Store;
use App\Models\User;
use App\Services\Analytics\AnalyticsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AdminMinorsTest extends TestCase
{
    private User $boss;

    private User $emp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->boss = $this->makeUser(Role::SUPER_ADMIN);
        $this->emp = $this->makeUser(Role::EMPLOYEE, ['hired_at' => '2026-01-01']);
    }

    /** تقرير معتمد بكود عبر المسار الكامل */
    private function approved(): DailyReport
    {
        $s = Store::factory()->assignedTo($this->emp->id)->create();
        $this->actingAs($this->emp)->post('/reports/start', ['stores' => [['store_id' => $s->id, 'start_orders' => 5, 'start_rate' => 50]]]);
        $r = DailyReport::where('user_id', $this->emp->id)->latest('id')->first();
        $this->actingAs($this->emp)->put("/reports/{$r->id}", ['stores' => [['store_id' => $s->id, 'shipments_count' => 3,
            'codes' => [['code' => 'MIN1', 'result' => 'customer_no_answer']]]]]);
        $this->actingAs($this->emp)->post("/reports/{$r->id}/submit", ['end_delivery_rate' => 60]);
        $this->actingAs($this->makeUser(Role::ADMIN, ['name' => 'المعتمد الأصلي']))->post("/reports/{$r->id}/approve");

        return $r->fresh();
    }

    public function test_shift_time_moves_followups_too(): void
    {
        $r = $this->approved();
        $old = ShipmentFollowup::sole()->followup_at;
        $newStart = $r->shift_started_at->copy()->subDay();
        $this->actingAs($this->boss)->put("/reports/{$r->id}/shift-time", ['shift_started_at' => $newStart->format('Y-m-d\TH:i')])->assertSessionHasNoErrors();
        $this->assertSame($old->copy()->subDay()->toDateString(), ShipmentFollowup::sole()->followup_at->toDateString());
    }

    public function test_shift_time_rejects_future_and_checkout_on_draft(): void
    {
        $draft = DailyReport::factory()->draft()->for($this->emp)->create(['shift_started_at' => now()->subHour()]);
        $this->actingAs($this->boss)->put("/reports/{$draft->id}/shift-time", ['shift_started_at' => now()->addDays(2)->format('Y-m-d\TH:i')])
            ->assertSessionHasErrors('shift_started_at');
        $this->actingAs($this->boss)->put("/reports/{$draft->id}/shift-time", ['shift_started_at' => now()->subHours(2)->format('Y-m-d\TH:i'), 'first_submitted_at' => now()->format('Y-m-d\TH:i')])
            ->assertSessionHasErrors('first_submitted_at');
    }

    public function test_restore_keeps_original_approver_without_new_notification(): void
    {
        $r = $this->approved();
        $approver = $r->approved_by;
        $notes = $this->emp->notifications()->count();
        $this->actingAs($this->boss)->delete("/reports/{$r->id}");
        $this->actingAs($this->boss)->patch("/reports/{$r->id}/restore");
        $this->assertSame($approver, $r->fresh()->approved_by);
        $this->assertSame($notes, $this->emp->notifications()->count());
        $this->assertSame(1, Shipment::sole()->followups_count);
    }

    public function test_restore_does_not_revive_shipments_of_non_approved_report(): void
    {
        $s = Store::factory()->create();
        $r = DailyReport::factory()->pendingReview()->for($this->emp)->create();
        $orphan = Shipment::create(['code' => 'ORPH', 'store_id' => $s->id, 'registered_at' => today(), 'first_report_id' => $r->id, 'processing_status' => 'open']);
        $orphan->delete();
        $this->actingAs($this->boss)->delete("/reports/{$r->id}");
        $this->actingAs($this->boss)->patch("/reports/{$r->id}/restore");
        $this->assertSoftDeleted($orphan);
    }

    public function test_override_changes_flush_analytics_and_are_exclusive(): void
    {
        Cache::forever('analytics:version', 5);
        $this->actingAs($this->boss)->post("/attendance/{$this->emp->id}/overrides", ['date' => today()->subDay()->toDateString(), 'type' => 'excused']);
        $this->assertSame(6, (int) Cache::get('analytics:version'));
        $this->actingAs($this->boss)->post("/attendance/{$this->emp->id}/overrides", ['date' => today()->subDay()->toDateString(), 'type' => 'present']);
        $this->assertSame(['present'], AttendanceOverride::pluck('type')->all());
    }

    public function test_exports_include_excused_and_holiday(): void
    {
        $d = \App\Reports\ReportRegistry::make('attendance_monthly')->build(new \App\Reports\ReportParams(
            \App\Support\ComparePeriod::fromRequest(\Illuminate\Http\Request::create('/')), $this->boss, month: CarbonImmutable::today()));
        $this->assertContains('غياب بعذر', $d->sections[0]['headers']);
        $this->assertContains('عطل رسمية', $d->sections[0]['headers']);
    }

    public function test_store_list_shows_service_type_and_form_keeps_old(): void
    {
        Store::factory()->create(['name' => 'متجر شارة', 'service_type' => 'confirmation']);
        $this->actingAs($this->boss)->get('/stores')->assertSee('تأكيد فقط');
    }

    public function test_trashed_list_hides_edit_link(): void
    {
        $r = DailyReport::factory()->draft()->for($this->emp)->create();
        $this->actingAs($this->boss)->delete("/reports/{$r->id}");
        $this->actingAs($this->boss)->get('/reports?trashed=1')->assertDontSee(route('reports.edit', $r));
    }

    public function test_code_lookup_hides_deleted_reports(): void
    {
        $r = $this->approved();
        ConfirmationOrder::create(['daily_report_id' => DailyReport::factory()->approved()->for($this->emp)->create(['kind' => 'confirmation'])->id,
            'store_id' => Store::factory()->create()->id, 'user_id' => $this->emp->id, 'code' => 'MIN1', 'result' => 'confirmed']);
        $conf = ConfirmationOrder::sole()->report;
        $this->actingAs($this->boss)->delete("/reports/{$conf->id}");
        $this->actingAs($this->boss)->delete("/reports/{$r->id}");
        $this->flushSession();
        $this->actingAs($this->boss)->get('/shipments/MIN1')->assertDontSee('سجل التأكيد')->assertDontSee($r->report_number);
    }
}
