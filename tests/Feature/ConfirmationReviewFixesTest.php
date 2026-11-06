<?php

namespace Tests\Feature;

use App\Models\ConfirmationOrder;
use App\Models\DailyReport;
use App\Models\ReportRevision;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\Analytics\AnalyticsService;
use App\Support\ComparePeriod;
use Illuminate\Http\Request;
use Tests\TestCase;

class ConfirmationReviewFixesTest extends TestCase
{
    private User $emp;

    private User $admin;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->emp = $this->makeUser(Role::EMPLOYEE, ['work_type' => 'confirmation']);
        $this->admin = $this->makeUser(Role::ADMIN);
        $this->store = Store::factory()->create();
    }

    private function payload(string $confirmed = "A1\nA2", array $extra = []): array
    {
        return array_merge(['stores' => [['store_id' => $this->store->id, 'confirmed' => $confirmed, 'no_answer' => '', 'cancelled' => []]]], $extra);
    }

    private function pending(): DailyReport
    {
        $this->actingAs($this->emp)->post('/reports/start', []);
        $r = DailyReport::where('user_id', $this->emp->id)->latest('id')->first();
        $this->actingAs($this->emp)->put("/reports/{$r->id}/confirmation", $this->payload(extra: ['action' => 'submit']))->assertSessionHasNoErrors();

        return $r->fresh();
    }

    public function test_correct_then_approve(): void
    {
        $r = $this->pending();
        $this->actingAs($this->admin)->get("/reports/{$r->id}/edit?approve_after=1")->assertOk()->assertSee('name="approve_after"', false);
        $this->actingAs($this->admin)->put("/reports/{$r->id}/confirmation", $this->payload("A1\nA2\nA3", ['approve_after' => 1]))
            ->assertSessionHasNoErrors()->assertRedirect(route('reports.show', $r));
        $r->refresh();
        $this->assertSame('approved', $r->status->value);
        $this->assertSame(2, $r->version);
        $this->assertSame(3, ConfirmationOrder::where('daily_report_id', $r->id)->count());
        $this->assertSame(1, ReportRevision::where('daily_report_id', $r->id)->count());
    }

    public function test_admin_edit_of_approved_is_versioned_and_cannot_empty(): void
    {
        $r = $this->pending();
        $this->actingAs($this->admin)->post("/reports/{$r->id}/approve");
        $boss = $this->makeUser(Role::SUPER_ADMIN);
        $this->actingAs($boss)->put("/reports/{$r->id}/confirmation", ['stores' => []])->assertSessionHasErrors('orders');
        $this->assertSame(2, ConfirmationOrder::where('daily_report_id', $r->id)->count());

        $this->actingAs($boss)->put("/reports/{$r->id}/confirmation", $this->payload('A1'))->assertSessionHasNoErrors();
        $r->refresh();
        $this->assertSame('approved', $r->status->value);
        $this->assertSame(2, $r->version);
        $snap = ReportRevision::where('daily_report_id', $r->id)->sole()->snapshot;
        $this->assertCount(2, $snap['confirmation_orders']);
    }

    public function test_rejected_snapshot_keeps_orders(): void
    {
        $r = $this->pending();
        $this->actingAs($this->admin)->post("/reports/{$r->id}/reject", ['rejection_reason' => 'x']);
        $this->actingAs($this->emp)->put("/reports/{$r->id}/confirmation", $this->payload('B1'))->assertSessionHasNoErrors();
        $snap = ReportRevision::where('daily_report_id', $r->id)->sole()->snapshot;
        $this->assertSame(['A1', 'A2'], array_column($snap['confirmation_orders'], 'code'));
    }

    public function test_confirmation_does_not_pollute_followup_kpis(): void
    {
        $r = $this->pending();
        $this->actingAs($this->admin)->post("/reports/{$r->id}/approve");
        $p = ComparePeriod::fromRequest(Request::create('/'));
        $a = app(AnalyticsService::class);
        $this->assertEquals(0, collect($a->compare($p))->firstWhere('key', 'reports_approved')['current']);
        $this->assertNull($a->employees($p)->firstWhere('user.id', $this->emp->id));
    }

    public function test_duplicate_inside_one_box_is_error(): void
    {
        $this->actingAs($this->emp)->post('/reports/start', []);
        $r = DailyReport::where('user_id', $this->emp->id)->latest('id')->first();
        $this->actingAs($this->emp)->put("/reports/{$r->id}/confirmation", $this->payload("A1\nA1\na1"))->assertSessionHasErrors('orders');
        $this->assertSame(0, ConfirmationOrder::count());
    }
}
