<?php

namespace Tests\Feature;

use App\Models\DailyReport;
use App\Models\Issue;
use App\Models\IssueUpdate;
use App\Models\ReportStore;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Notifications\IssueNotification;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class IssueManagementTest extends TestCase
{
    private User $admin;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeUser(Role::ADMIN, ['name' => 'المسؤول الأول']);
        $this->store = Store::factory()->create(['name' => 'متجر المشكلات']);
    }

    private function issue(array $attrs = []): Issue
    {
        return Issue::create($attrs + ['store_id' => $this->store->id, 'reported_by' => $this->admin->id, 'reported_at' => now(),
            'type' => 'other', 'title' => 'مشكلة اختبار', 'description' => 'وصف', 'status' => 'new', 'due_at' => now()->addDay()]);
    }

    private function update(Issue $i, array $data, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->post("/issues/{$i->id}/updates", $data);
    }

    public function test_approved_report_issue_gets_due_and_notifies(): void
    {
        Notification::fake();
        $emp = $this->makeUser();
        $r = DailyReport::factory()->pendingReview()->for($emp)->create([
            'issue_requests' => [['key' => 'k1', 'store_id' => $this->store->id, 'type' => 'communication', 'title' => 'لا يرد', 'description' => 'x']],
        ]);
        ReportStore::create(['daily_report_id' => $r->id, 'store_id' => $this->store->id, 'shipments_count' => 1]);
        $other = $this->makeUser(Role::ADMIN);
        $this->actingAs($this->admin)->post("/reports/{$r->id}/approve")->assertRedirect();

        $issue = Issue::sole();
        $this->assertEquals(48, (int) round($issue->reported_at->diffInHours($issue->due_at)));
        Notification::assertSentTo($other, IssueNotification::class);
        Notification::assertNotSentTo($emp, IssueNotification::class);
    }

    public function test_admin_creates_issue_from_store_page(): void
    {
        $this->actingAs($this->admin)->post("/stores/{$this->store->id}/issues", [
            'title' => 'تأخر الاستلام', 'type' => 'pickup', 'description' => 'المندوب لم يستلم', 'priority' => 'high', 'shipment_code' => '',
        ])->assertRedirect();
        $i = Issue::sole();
        $this->assertMatchesRegularExpression('/^ISS-\d{4}-\d{6}$/', $i->issue_number);
        $this->assertSame('new', $i->status->value);
        $this->assertSame('high', $i->priority->value);
        $this->assertNotNull($i->due_at);
    }

    public function test_update_logs_history_and_assigns(): void
    {
        Notification::fake();
        $i = $this->issue();
        $admin2 = $this->makeUser(Role::ADMIN, ['name' => 'المسؤول الثاني']);
        $this->update($i, ['status' => 'in_progress', 'assigned_to' => $admin2->id, 'comment' => 'اتصلت بالمتجر'])->assertRedirect();

        $u = IssueUpdate::sole();
        $this->assertSame('new', $u->old_status);
        $this->assertSame('in_progress', $u->new_status);
        $this->assertSame($admin2->id, $u->new_assignee_id);
        $this->assertSame('اتصلت بالمتجر', $u->comment);
        Notification::assertSentTo($admin2, IssueNotification::class);
        $this->actingAs($this->admin)->get("/issues/{$i->id}")->assertOk()->assertSee('اتصلت بالمتجر')->assertSee('المسؤول الثاني');
    }

    public function test_resolve_requires_action_taken(): void
    {
        $i = $this->issue();
        $this->update($i, ['status' => 'resolved'])->assertSessionHasErrors('action_taken');
        $this->update($i, ['status' => 'resolved', 'action_taken' => 'تم التواصل وحُلّت'])->assertSessionHasNoErrors();
        $this->assertNotNull($i->fresh()->resolved_at);
        $this->assertSame('تم التواصل وحُلّت', $i->fresh()->action_taken);
    }

    public function test_reopen_clears_dates(): void
    {
        $i = $this->issue(['status' => 'closed', 'resolved_at' => now(), 'closed_at' => now(), 'action_taken' => 'x']);
        $this->update($i, ['status' => 'in_progress', 'comment' => 'إعادة فتح'])->assertSessionHasNoErrors();
        $i->refresh();
        $this->assertNull($i->resolved_at);
        $this->assertNull($i->closed_at);
        $this->assertSame('closed', IssueUpdate::sole()->old_status);
    }

    public function test_assignee_must_manage_issues(): void
    {
        $this->update($this->issue(), ['assigned_to' => $this->makeUser()->id])->assertSessionHasErrors('assigned_to');
    }

    public function test_overdue_filter(): void
    {
        $late = $this->issue(['title' => 'متأخرة جداً', 'due_at' => now()->subHour()]);
        $this->issue(['title' => 'محلولة قديمة', 'due_at' => now()->subHour(), 'status' => 'resolved', 'resolved_at' => now(), 'action_taken' => 'x']);
        $this->issue(['title' => 'في الوقت']);
        $this->assertTrue($late->isOverdue());

        $this->actingAs($this->admin)->get('/issues?overdue=1')->assertOk()
            ->assertSee('متأخرة جداً')->assertDontSee('محلولة قديمة')->assertDontSee('في الوقت');
    }

    public function test_employee_permissions(): void
    {
        $emp = $this->makeUser();
        $r = DailyReport::factory()->approved()->for($emp)->create();
        $i = $this->issue(['daily_report_id' => $r->id, 'title' => 'مشكلة من تقريري', 'status' => 'in_progress']);

        $this->actingAs($emp)->get('/issues')->assertForbidden();
        $this->actingAs($emp)->get("/issues/{$i->id}")->assertForbidden();
        $this->update($i, ['comment' => 'x'], $emp)->assertForbidden();
        $this->actingAs($emp)->get("/reports/{$r->id}")->assertOk()->assertSee('مشكلة من تقريري')->assertSee('قيد المعالجة');
    }

    public function test_viewer_can_view_but_not_manage(): void
    {
        $i = $this->issue();
        $viewer = $this->makeUser(Role::VIEWER);
        $this->actingAs($viewer)->get('/issues')->assertOk()->assertSee('مشكلة اختبار');
        $this->update($i, ['comment' => 'x'], $viewer)->assertForbidden();
    }
}
