<?php

namespace Tests\Feature;

use App\Models\ImportBatch;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreAlias;
use App\Models\User;
use App\Services\Imports\ShiftFileImporter;
use App\Services\Reports\ReportApprovalService;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesSpreadsheets;
use Tests\TestCase;

class ImportCenterTest extends TestCase
{
    use MakesSpreadsheets;

    private User $emp;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        $this->emp = $this->makeUser();
        Store::factory()->assignedTo($this->emp->id)->create(['name' => 'متجر الأمل']);
    }

    private function applied(User $who, string $name = 'shift.xlsx'): ImportBatch
    {
        $svc = app(ShiftFileImporter::class);
        $b = $svc->upload($this->xlsx([['المتجر', 'الإجمالي', 'المسلم'], ['متجر الأمل', 10, 8]], $name), 'shift_start', null, $who)['batch'];
        $svc->saveMapping($b, ['layout' => 'stores', 'columns' => ['store' => 'المتجر', 'total' => 'الإجمالي', 'delivered' => 'المسلم']], []);
        $svc->apply($b->fresh(), $who);

        return $b->fresh();
    }

    public function test_import_center_is_for_managers_only(): void
    {
        $this->applied($this->emp, 'mine-file.xlsx');
        $this->applied($this->makeUser(), 'theirs-file.xlsx');
        $this->actingAs($this->emp)->get('/imports')->assertForbidden();
        $this->actingAs($this->makeUser(Role::ADMIN))->get('/imports')->assertOk()->assertSee('mine-file.xlsx')->assertSee('theirs-file.xlsx');
    }

    public function test_admin_filters_by_type(): void
    {
        $this->applied($this->emp, 'start-file.xlsx');
        $this->actingAs($this->makeUser(Role::ADMIN));
        $this->get('/imports')->assertSee('start-file.xlsx');
        $this->get('/imports?report_type=legacy_whatsapp')->assertOk()->assertDontSee('start-file.xlsx');
    }

    public function test_download_original_protected(): void
    {
        $b = $this->applied($this->emp);
        $this->actingAs($this->emp)->get("/imports/{$b->id}/download")->assertOk();
        $this->actingAs($this->makeUser())->get("/imports/{$b->id}/download")->assertForbidden();
    }

    public function test_batch_approved_when_report_approved(): void
    {
        $b = $this->applied($this->emp);
        $r = $b->report;
        $r->update(['status' => 'pending_review', 'submitted_at' => now()]);
        $admin = $this->makeUser(Role::ADMIN);
        app(ReportApprovalService::class)->approve($r, $admin);

        $b->refresh();
        $this->assertSame('approved', $b->status->value);
        $this->assertSame($admin->id, $b->approved_by);
    }

    public function test_cancelled_batch_stays_cancelled_on_approval(): void
    {
        $b = $this->applied($this->emp);
        $svc = app(ShiftFileImporter::class);
        $extra = $svc->upload($this->xlsx([['المتجر'], ['x']], 'other.xlsx'), 'shift_end', $b->report, $this->emp)['batch'];
        $svc->cancel($extra);
        $b->report->update(['status' => 'pending_review', 'submitted_at' => now()]);
        app(ReportApprovalService::class)->approve($b->report, $this->makeUser(Role::ADMIN));
        $this->assertSame('cancelled', $extra->fresh()->status->value);
    }

    public function test_alias_listed_on_store_page_and_delete_requires_manage(): void
    {
        $store = Store::first();
        $alias = StoreAlias::create(['store_id' => $store->id, 'alias' => 'الأمل القديم', 'normalized_alias' => 'الامل القديم', 'created_by' => $this->emp->id]);

        $viewer = $this->makeUser(Role::VIEWER);
        $this->actingAs($viewer)->get("/stores/{$store->id}")->assertSee('الأمل القديم');
        $this->actingAs($viewer)->delete("/store-aliases/{$alias->id}")->assertForbidden();
        $this->actingAs($this->makeUser(Role::ADMIN))->delete("/store-aliases/{$alias->id}")->assertRedirect();
        $this->assertSame(0, StoreAlias::count());
    }

    public function test_sidebar_imports_link_works_for_roles(): void
    {
        foreach ([Role::SUPER_ADMIN, Role::ADMIN] as $role) {
            $this->actingAs($this->makeUser($role))->get('/imports')->assertOk();
        }
        $this->actingAs($this->makeUser(Role::VIEWER))->get('/imports')->assertForbidden();
        $this->actingAs($this->makeUser(Role::EMPLOYEE))->get('/imports')->assertForbidden();
        $this->actingAs($this->makeUser(Role::ADMIN))->get('/')->assertSee(route('imports.index'));
    }
}
