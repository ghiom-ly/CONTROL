<?php

namespace Tests\Feature;

use App\Models\ConfirmationOrder;
use App\Models\DailyReport;
use App\Models\ReportStore;
use App\Models\Role;
use App\Models\Store;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\CancelReasons;
use Tests\TestCase;
use Tests\Concerns\MakesSpreadsheets;

class OldMinorsReportsTest extends TestCase
{
    use MakesSpreadsheets;

    private function conf(): User
    {
        return $this->makeUser(Role::EMPLOYEE, ['work_type' => 'confirmation']);
    }

    public function test_confirmation_employee_cannot_upload_shift_file(): void
    {
        \Illuminate\Support\Facades\Storage::fake('private');
        $this->actingAs($this->conf())->post('/imports/shift', ['kind' => 'shift_start', 'file' => $this->xlsx([['a'], ['b']])])
            ->assertSessionHasErrors('file');
    }

    public function test_submit_route_does_not_require_end_rate_for_confirmation(): void
    {
        $emp = $this->conf();
        $this->actingAs($emp)->post('/reports/start', []);
        $r = DailyReport::sole();
        ConfirmationOrder::create(['daily_report_id' => $r->id, 'store_id' => Store::factory()->create()->id, 'user_id' => $emp->id, 'code' => 'Q1', 'result' => 'confirmed']);
        $this->actingAs($emp)->post("/reports/{$r->id}/submit", [])->assertSessionHasNoErrors();
        $this->assertSame('pending_review', $r->fresh()->status->value);
    }

    public function test_report_lists_show_kind_badge(): void
    {
        $emp = $this->conf();
        DailyReport::factory()->pendingReview()->for($emp)->create(['kind' => 'confirmation']);
        $admin = $this->makeUser(Role::ADMIN);
        $this->actingAs($admin)->get('/reports')->assertSee('تأكيد طلبيات');
        $this->actingAs($admin)->get('/review')->assertSee('تأكيد طلبيات');
    }

    public function test_confirmation_attempts_normalized_and_limited(): void
    {
        $emp = $this->conf();
        $this->actingAs($emp)->post('/reports/start', []);
        $r = DailyReport::sole();
        $s = Store::factory()->create();
        $base = ['stores' => [['store_id' => $s->id, 'confirmed' => 'A1']]];
        $this->actingAs($emp)->put("/reports/{$r->id}/confirmation", $base + ['attempt_times' => ['١٠:٣٠']])->assertSessionHasNoErrors();
        $this->assertSame(['10:30'], $r->fresh()->attempt_times);
        $this->actingAs($emp)->put("/reports/{$r->id}/confirmation", $base + ['attempt_times' => ['10:00', '11:00', '12:00']])
            ->assertSessionHasErrors('attempts_over_limit_reason');
        $this->actingAs($emp)->put("/reports/{$r->id}/confirmation", $base + ['attempt_times' => ['10:00', '11:00', '12:00'], 'attempts_over_limit_reason' => 'زبائن كثيرين'])
            ->assertSessionHasNoErrors();
    }

    public function test_employee_code_lookup_hides_others_unapproved_confirmations(): void
    {
        $other = $this->conf();
        $r = DailyReport::factory()->pendingReview()->for($other)->create(['kind' => 'confirmation']);
        ConfirmationOrder::create(['daily_report_id' => $r->id, 'store_id' => Store::factory()->create()->id, 'user_id' => $other->id,
            'code' => 'PEND1', 'result' => 'cancelled', 'cancel_reason' => 'سبب مخفي']);
        $this->actingAs($this->makeUser())->get('/shipments/PEND1')->assertDontSee('سبب مخفي');
        $this->actingAs($other)->get('/shipments/PEND1')->assertSee('سبب مخفي');
    }

    public function test_work_type_empty_is_validation_error(): void
    {
        $boss = $this->makeUser(Role::SUPER_ADMIN);
        $this->actingAs($boss)->post('/users', ['name' => 'x', 'username' => 'xx1', 'role_id' => Role::where('name', 'employee')->value('id'),
            'status' => 'active', 'password' => 'Abcd1234', 'password_confirmation' => 'Abcd1234', 'work_type' => ''])->assertSessionHasErrors('work_type');
    }

    public function test_other_reason_not_duplicated(): void
    {
        SystemSetting::where('key', 'confirmation_cancel_reasons')->update(['value' => "سعر غالي\nأخرى"]);
        cache()->flush();
        $this->assertSame(['سعر غالي'], CancelReasons::all());
    }

    public function test_super_admin_cannot_submit_someone_elses_draft(): void
    {
        $emp = $this->makeUser();
        $r = DailyReport::factory()->draft()->for($emp)->create();
        ReportStore::create(['daily_report_id' => $r->id, 'store_id' => Store::factory()->create()->id, 'shipments_count' => 1]);
        $this->actingAs($this->makeUser(Role::SUPER_ADMIN))->post("/reports/{$r->id}/submit", ['end_delivery_rate' => 50])->assertForbidden();
        $this->assertSame('draft', $r->fresh()->status->value);
    }

    public function test_issue_request_store_must_be_in_report(): void
    {
        $emp = $this->makeUser();
        $mine = Store::factory()->assignedTo($emp->id)->create();
        $outside = Store::factory()->create();
        $r = DailyReport::factory()->draft()->for($emp)->create();
        $this->actingAs($emp)->put("/reports/{$r->id}", ['stores' => [['store_id' => $mine->id, 'shipments_count' => 1]],
            'issue_requests' => [['key' => 'k', 'store_id' => $outside->id, 'type' => 'other', 'title' => 't', 'description' => 'd']]])
            ->assertSessionHasErrors('issue_requests.0.store_id');
    }
}
