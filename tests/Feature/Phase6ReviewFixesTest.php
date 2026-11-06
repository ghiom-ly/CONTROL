<?php

namespace Tests\Feature;

use App\Models\ContactAttempt;
use App\Models\DailyReport;
use App\Models\Issue;
use App\Models\IssueUpdate;
use App\Models\ReportStore;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\Store;
use App\Notifications\IssueNotification;
use App\Services\ContactStatsService;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class Phase6ReviewFixesTest extends TestCase
{
    public function test_partial_code_search_renders(): void
    {
        $s = Store::factory()->create();
        Shipment::create(['code' => '4749558', 'store_id' => $s->id, 'registered_at' => today(), 'processing_status' => 'open']);
        $this->actingAs($this->makeUser(Role::ADMIN))->get('/search?q=4749')->assertOk()
            ->assertSee('4749558')->assertSee('https://backoffice.nawris.algoriza.com/orders/4749558', false);
    }

    public function test_comment_only_update_does_not_reassign_or_renotify(): void
    {
        Notification::fake();
        $admin = $this->makeUser(Role::ADMIN);
        $other = $this->makeUser(Role::ADMIN);
        $i = Issue::create(['store_id' => Store::factory()->create()->id, 'reported_by' => $admin->id, 'reported_at' => now(),
            'type' => 'other', 'title' => 't', 'description' => 'd', 'status' => 'in_progress', 'assigned_to' => $other->id]);

        // قيم النموذج تصل نصوصاً
        $this->actingAs($admin)->post("/issues/{$i->id}/updates", ['status' => 'in_progress', 'priority' => 'medium',
            'assigned_to' => (string) $other->id, 'comment' => 'تعليق فقط', 'action_taken' => ''])->assertSessionHasNoErrors();

        $u = IssueUpdate::sole();
        $this->assertNull($u->new_assignee_id);
        $this->assertNull($u->new_status);
        Notification::assertNotSentTo($other, IssueNotification::class);
    }

    public function test_contacts_page_survives_deleted_employee(): void
    {
        $emp = $this->makeUser(Role::EMPLOYEE, ['name' => 'موظف محذوف']);
        ContactAttempt::create(['party_type' => 'customer', 'user_id' => $emp->id, 'contacted_at' => now(), 'attempt_number' => 1, 'result' => 'answered']);
        $emp->delete();

        $this->actingAs($this->makeUser(Role::ADMIN))->get('/contacts')->assertOk()->assertSee('موظف محذوف');
    }

    public function test_party_columns_count_all_calls_rate_uses_rated_only(): void
    {
        $emp = $this->makeUser();
        foreach (['no_answer', 'wrong_number', 'answered'] as $r) {
            ContactAttempt::create(['party_type' => 'customer', 'user_id' => $emp->id, 'contacted_at' => now(), 'attempt_number' => 1, 'result' => $r]);
        }
        $row = app(ContactStatsService::class)->perEmployee(today(), today())->firstWhere('user.id', $emp->id);
        $this->assertSame(3, $row['total']);
        $this->assertSame(3, $row['customer']);
        $this->assertSame(50.0, $row['customer_rate']);
    }

    public function test_approving_report_of_deleted_employee_with_issue_request(): void
    {
        $emp = $this->makeUser();
        $store = Store::factory()->create();
        $r = DailyReport::factory()->pendingReview()->for($emp)->create([
            'issue_requests' => [['key' => 'k', 'store_id' => $store->id, 'type' => 'other', 'title' => 'x', 'description' => 'y']],
        ]);
        ReportStore::create(['daily_report_id' => $r->id, 'store_id' => $store->id, 'shipments_count' => 1]);
        $emp->delete();

        $this->actingAs($this->makeUser(Role::ADMIN))->post("/reports/{$r->id}/approve")->assertRedirect();
        $this->assertSame($emp->id, Issue::sole()->reported_by);
    }
}
