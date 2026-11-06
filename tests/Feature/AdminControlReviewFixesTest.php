<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\DailyReport;
use App\Models\ReportStore;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\Imports\ShiftFileImporter;
use App\Services\UserPurgeService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesSpreadsheets;
use Tests\TestCase;

class AdminControlReviewFixesTest extends TestCase
{
    use MakesSpreadsheets;

    private User $boss;

    private User $emp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->boss = $this->makeUser(Role::SUPER_ADMIN);
        $this->emp = $this->makeUser(Role::EMPLOYEE, ['username' => 'purge.me']);
    }

    public function test_shift_time_can_be_edited_twice_then_admin_edit(): void
    {
        $this->travelTo(now()->setTime(15, 0));
        $r = DailyReport::factory()->approved()->for($this->emp)->create(['work_date' => today(), 'shift_started_at' => today()->setTime(9, 0)]);
        $this->actingAs($this->boss)->put("/reports/{$r->id}/shift-time", ['shift_started_at' => today()->format('Y-m-d').'T09:30'])->assertSessionHasNoErrors();
        $this->actingAs($this->boss)->put("/reports/{$r->id}/shift-time", ['shift_started_at' => today()->format('Y-m-d').'T09:45'])->assertSessionHasNoErrors();
        $this->assertSame(3, $r->fresh()->version);
    }

    public function test_shift_file_ignores_confirmation_only_store(): void
    {
        Storage::fake('private');
        $conf = Store::factory()->create(['name' => 'متجر تأكيد فقط', 'service_type' => 'confirmation']);
        Store::factory()->assignedTo($this->emp->id)->create(['name' => 'متجر متابعة']);
        $svc = app(ShiftFileImporter::class);
        $b = $svc->upload($this->xlsx([['المتجر', 'الإجمالي', 'المسلم'], ['متجر متابعة', 10, 5], ['متجر تأكيد فقط', 20, 10]]), 'shift_start', null, $this->emp)['batch'];
        $svc->saveMapping($b, ['layout' => 'stores', 'columns' => ['store' => 'المتجر', 'total' => 'الإجمالي', 'delivered' => 'المسلم']], []);
        $this->actingAs($this->emp)->post("/imports/{$b->id}/apply")->assertRedirect();
        $r = DailyReport::sole();
        $this->assertFalse($r->stores()->where('store_id', $conf->id)->exists());
        $this->assertSame(10, $r->start_total_orders);
    }

    public function test_store_switched_to_confirmation_does_not_lock_existing_draft(): void
    {
        $s = Store::factory()->assignedTo($this->emp->id)->create();
        $r = DailyReport::factory()->draft()->for($this->emp)->create();
        ReportStore::create(['daily_report_id' => $r->id, 'store_id' => $s->id, 'shipments_count' => 1]);
        $s->update(['service_type' => 'confirmation']);
        $this->actingAs($this->emp)->put("/reports/{$r->id}", ['stores' => [['store_id' => $s->id, 'shipments_count' => 2]]])->assertSessionHasNoErrors();
    }

    public function test_purge_failure_rolls_back_everything(): void
    {
        $r = DailyReport::factory()->approved()->for($this->emp)->create();
        $this->app->bind(\App\Services\Reports\ReportDeletionService::class, fn ($app) => new class($app->make(\App\Services\Reports\ReportApprovalService::class), $app->make(\App\Services\Reports\StoreMetricsBuilder::class), $app->make(\App\Services\Reports\ShipmentSummaryRefresher::class)) extends \App\Services\Reports\ReportDeletionService
        {
            public function forceDelete(\App\Models\DailyReport $report, \App\Models\User $actor): void
            {
                parent::forceDelete($report, $actor);
                throw new \RuntimeException('boom');
            }
        });
        try {
            app(UserPurgeService::class)->purge($this->emp, $this->boss);
        } catch (\RuntimeException) {
        }
        $this->assertNotNull(DailyReport::withTrashed()->find($r->id));
        $this->assertNotNull(User::find($this->emp->id));
    }

    public function test_restore_draft_refused_when_newer_open_shift(): void
    {
        $old = DailyReport::factory()->draft()->for($this->emp)->create(['work_date' => today()->subDay()]);
        $this->actingAs($this->boss)->delete("/reports/{$old->id}");
        DailyReport::factory()->draft()->for($this->emp)->create();
        $this->actingAs($this->boss)->patch("/reports/{$old->id}/restore")->assertSessionHasErrors('report');
        $this->assertSoftDeleted($old);
    }

    public function test_purge_deletes_attachment_and_import_files(): void
    {
        Storage::fake('private');
        $r = DailyReport::factory()->draft()->for($this->emp)->create();
        $att = app(\App\Services\AttachmentService::class)->storeForReport($r, UploadedFile::fake()->image('s.png'), 'courier_screens', $this->emp);
        $path = Attachment::sole()->path;
        Storage::disk('private')->assertExists($path);
        $svc = app(ShiftFileImporter::class);
        $b = $svc->upload($this->xlsx([['a'], ['b']]), 'shift_start', null, $this->emp)['batch'];
        Storage::disk('private')->assertExists($b->stored_path);

        $this->actingAs($this->boss)->delete("/users/{$this->emp->id}/purge", ['confirm' => 'purge.me'])->assertRedirect();
        $this->assertSame(0, Attachment::count());
        Storage::disk('private')->assertMissing($path);
        Storage::disk('private')->assertMissing($b->stored_path);
    }

    public function test_can_excuse_today(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-20 12:00'));
        \App\Models\SystemSetting::where('key', 'attendance_start_date')->update(['value' => '2026-11-01']);
        cache()->flush();
        $emp = $this->makeUser(Role::EMPLOYEE, ['hired_at' => '2026-01-01']);
        $this->actingAs($this->boss)->get("/attendance/{$emp->id}?month=2026-11")->assertOk()->assertSee('name="date" value="2026-11-20"', false);
    }
}
