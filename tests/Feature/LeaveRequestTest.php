<?php

namespace Tests\Feature;

use App\Models\AttendanceOverride;
use App\Models\Leave;
use App\Models\LeaveRequest;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LeaveRequestTest extends TestCase
{
    private User $emp;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->emp = $this->makeUser(Role::EMPLOYEE, ['phone' => '0912345678']);
        $this->admin = $this->makeUser(Role::ADMIN);
    }

    private function submit(array $data, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->emp)->post('/my-requests', $data + ['reason' => 'ظرف عائلي']);
    }

    private function pending(array $a = []): LeaveRequest
    {
        return LeaveRequest::create($a + ['user_id' => $this->emp->id, 'kind' => 'leave', 'leave_type' => 'leave',
            'from_date' => today()->addDay(), 'to_date' => today()->addDays(2), 'reason' => 'سفر', 'status' => 'pending']);
    }

    public function test_employee_submits_leave_and_managers_notified(): void
    {
        $this->submit(['kind' => 'leave', 'leave_type' => 'sick', 'from_date' => today()->addDay()->toDateString(), 'to_date' => today()->addDays(2)->toDateString()])
            ->assertRedirect()->assertSessionHasNoErrors();
        $r = LeaveRequest::sole();
        $this->assertSame('pending', $r->status);
        $this->assertSame('sick', $r->leave_type);
        $this->assertSame(1, $this->admin->notifications()->count());
        $this->assertSame(0, $this->emp->notifications()->count());
    }

    public function test_leave_must_start_today_or_later_and_max_30_days(): void
    {
        $this->submit(['kind' => 'leave', 'leave_type' => 'leave', 'from_date' => today()->subDay()->toDateString(), 'to_date' => today()->toDateString()])
            ->assertSessionHasErrors('from_date');
        $this->submit(['kind' => 'leave', 'leave_type' => 'leave', 'from_date' => today()->toDateString(), 'to_date' => today()->addDays(31)->toDateString()])
            ->assertSessionHasErrors('to_date');
        $this->assertSame(0, LeaveRequest::count());
    }

    public function test_excuse_must_be_within_last_30_days(): void
    {
        $this->submit(['kind' => 'absence_excuse', 'from_date' => today()->addDay()->toDateString()])->assertSessionHasErrors('from_date');
        $this->submit(['kind' => 'late_excuse', 'from_date' => today()->subDays(31)->toDateString()])->assertSessionHasErrors('from_date');
        $this->submit(['kind' => 'absence_excuse', 'from_date' => today()->subDays(2)->toDateString()])->assertSessionHasNoErrors();
        $r = LeaveRequest::sole();
        $this->assertSame($r->from_date->toDateString(), $r->to_date->toDateString());
    }

    public function test_reason_required(): void
    {
        $this->actingAs($this->emp)->post('/my-requests', ['kind' => 'late_excuse', 'from_date' => today()->toDateString(), 'reason' => ''])
            ->assertSessionHasErrors('reason');
    }

    public function test_overlapping_request_rejected(): void
    {
        $this->pending();
        $this->submit(['kind' => 'leave', 'leave_type' => 'leave', 'from_date' => today()->addDays(2)->toDateString(), 'to_date' => today()->addDays(4)->toDateString()])
            ->assertSessionHasErrors('from_date');
        $this->assertSame(1, LeaveRequest::count());
    }

    public function test_only_employees_can_submit(): void
    {
        $this->submit(['kind' => 'late_excuse', 'from_date' => today()->toDateString()], $this->admin)->assertForbidden();
    }

    public function test_optional_attachment_stored_and_type_checked(): void
    {
        Storage::fake('local');
        $this->submit(['kind' => 'absence_excuse', 'from_date' => today()->toDateString(), 'attachment' => UploadedFile::fake()->create('x.exe', 10)])
            ->assertSessionHasErrors('attachment');
        $this->submit(['kind' => 'absence_excuse', 'from_date' => today()->toDateString(), 'attachment' => UploadedFile::fake()->create('شهادة.pdf', 100, 'application/pdf')])
            ->assertSessionHasNoErrors();
        $r = LeaveRequest::sole();
        Storage::disk('local')->assertExists($r->attachment_path);
        $this->assertSame('شهادة.pdf', $r->attachment_name);

        $this->actingAs($this->emp)->get("/leave-requests/{$r->id}/attachment")->assertOk();
        $this->actingAs($this->admin)->get("/leave-requests/{$r->id}/attachment")->assertOk();
        $this->actingAs($this->makeUser(Role::EMPLOYEE))->get("/leave-requests/{$r->id}/attachment")->assertForbidden();
    }

    public function test_employee_cancels_own_pending_only(): void
    {
        $r = $this->pending();
        $this->actingAs($this->makeUser(Role::EMPLOYEE))->patch("/my-requests/{$r->id}/cancel")->assertForbidden();
        $this->actingAs($this->emp)->patch("/my-requests/{$r->id}/cancel")->assertRedirect();
        $this->assertSame('cancelled', $r->fresh()->status);
        $this->actingAs($this->emp)->patch("/my-requests/{$r->id}/cancel")->assertSessionHasErrors('request');
    }

    public function test_approve_leave_creates_leave_and_notifies_employee(): void
    {
        $r = $this->pending(['leave_type' => 'sick']);
        $this->actingAs($this->admin)->patch("/leave-requests/{$r->id}/approve", ['note' => 'سلامتك'])->assertRedirect();
        $r->refresh();
        $this->assertSame('approved', $r->status);
        $this->assertSame($this->admin->id, $r->decided_by);
        $leave = Leave::sole();
        $this->assertSame('sick', $leave->type);
        $this->assertSame($r->leave_id, $leave->id);
        $this->assertSame(today()->addDays(2)->toDateString(), $leave->to_date->toDateString());
        $this->assertSame(1, $this->emp->notifications()->count());
    }

    public function test_approve_absence_and_late_excuses_create_overrides(): void
    {
        $a = $this->pending(['kind' => 'absence_excuse', 'leave_type' => null, 'from_date' => today()->subDays(3), 'to_date' => today()->subDays(3)]);
        $l = $this->pending(['kind' => 'late_excuse', 'leave_type' => null, 'from_date' => today()->subDay(), 'to_date' => today()->subDay()]);
        $this->actingAs($this->admin)->patch("/leave-requests/{$a->id}/approve");
        $this->actingAs($this->admin)->patch("/leave-requests/{$l->id}/approve");
        $this->assertSame(['excused', 'late_excused'], AttendanceOverride::orderBy('date')->pluck('type')->all());
        $this->assertSame(today()->subDays(3)->toDateString(), AttendanceOverride::where('type', 'excused')->sole()->date->toDateString());
    }

    public function test_reject_requires_reason_and_notifies(): void
    {
        $r = $this->pending();
        $this->actingAs($this->admin)->patch("/leave-requests/{$r->id}/reject", ['note' => ''])->assertSessionHasErrors('note');
        $this->actingAs($this->admin)->patch("/leave-requests/{$r->id}/reject", ['note' => 'ضغط عمل'])->assertRedirect();
        $this->assertSame('rejected', $r->fresh()->status);
        $this->assertSame('ضغط عمل', $r->fresh()->decision_note);
        $this->assertSame(0, Leave::count());
        $this->assertSame(1, $this->emp->notifications()->count());
    }

    public function test_decided_request_cannot_be_decided_again(): void
    {
        $r = $this->pending(['status' => 'rejected']);
        $this->actingAs($this->admin)->patch("/leave-requests/{$r->id}/approve")->assertSessionHasErrors('request');
        $this->assertSame(0, Leave::count());
    }

    public function test_employee_and_viewer_cannot_manage(): void
    {
        $r = $this->pending();
        $this->actingAs($this->emp)->patch("/leave-requests/{$r->id}/approve")->assertForbidden();
        $this->actingAs($this->emp)->get('/leave-requests')->assertForbidden();
        $this->actingAs($this->makeUser(Role::VIEWER))->get('/leave-requests')->assertForbidden();
    }

    public function test_manager_page_lists_pending_with_whatsapp_after_decision(): void
    {
        $r = $this->pending();
        $this->actingAs($this->admin)->get('/leave-requests')->assertOk()->assertSee($this->emp->name)->assertSee('قبول');
        $this->actingAs($this->admin)->patch("/leave-requests/{$r->id}/approve");
        $this->actingAs($this->admin)->get('/leave-requests?status=approved')->assertSee('https://wa.me/218912345678', false);
    }

    public function test_sidebar_shows_pending_count(): void
    {
        $this->pending();
        $this->actingAs($this->admin)->get('/')->assertSee('طلبات الإجازة والأعذار (1)');
        $this->actingAs($this->emp)->get('/')->assertSee('طلباتي');
    }

    public function test_employee_page_whatsapp_to_manager_when_configured(): void
    {
        $this->pending();
        $this->actingAs($this->emp)->get('/my-requests')->assertOk()->assertSee('سفر')->assertDontSee('تنبيه المدير عبر واتساب');
        SystemSetting::where('key', 'manager_whatsapp')->update(['value' => '0923456789']);
        \Illuminate\Support\Facades\Cache::flush();
        $this->actingAs($this->emp)->get('/my-requests')->assertSee('تنبيه المدير عبر واتساب')->assertSee('https://wa.me/218923456789', false);
    }

    public function test_purge_removes_requests(): void
    {
        $this->pending();
        app(\App\Services\UserPurgeService::class)->purge($this->emp, $this->makeUser(Role::SUPER_ADMIN));
        $this->assertSame(0, LeaveRequest::count());
    }
}
