<?php

namespace Tests\Feature;

use App\Alerts\AlertEngine;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Issue;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Notifications\AlertNotification;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AlertEngineTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\AlertRulesSeeder::class);
        AlertRule::where('key', '!=', 'issue_resolution_delay')->update(['is_enabled' => false]);
        $this->admin = $this->makeUser(Role::ADMIN);
    }

    private function lateIssue(string $title = 'مشكلة متأخرة'): Issue
    {
        return Issue::create(['store_id' => Store::factory()->create()->id, 'reported_by' => $this->admin->id, 'reported_at' => now()->subHours(60),
            'type' => 'other', 'title' => $title, 'description' => 'd', 'status' => 'new', 'due_at' => now()->subHours(12)]);
    }

    private function runEngine(): array
    {
        return app(AlertEngine::class)->run(CarbonImmutable::now());
    }

    public function test_engine_opens_alert_and_notifies(): void
    {
        Notification::fake();
        $this->lateIssue();
        $emp = $this->makeUser();
        $viewer = $this->makeUser(Role::VIEWER);

        $this->assertSame(['opened' => 1, 'closed' => 0], $this->runEngine());
        $a = Alert::sole();
        $this->assertSame('open', $a->status);
        $this->assertSame('critical', $a->level->value);
        $this->assertStringContainsString('مشكلة متأخرة', $a->title);
        Notification::assertSentTo($this->admin, AlertNotification::class);
        Notification::assertNotSentTo($emp, AlertNotification::class);
        Notification::assertNotSentTo($viewer, AlertNotification::class);
    }

    public function test_engine_is_idempotent(): void
    {
        Notification::fake();
        $this->lateIssue();
        $this->runEngine();
        $first = Alert::sole()->last_seen_at;
        $this->travel(5)->minutes();
        $this->assertSame(['opened' => 0, 'closed' => 0], $this->runEngine());
        $this->assertSame(1, Alert::count());
        $this->assertTrue(Alert::sole()->last_seen_at->gt($first));
        Notification::assertSentToTimes($this->admin, AlertNotification::class, 1);
    }

    public function test_auto_close_and_manual_close_same_day(): void
    {
        $i = $this->lateIssue();
        $this->runEngine();
        $i->update(['status' => 'resolved', 'resolved_at' => now(), 'action_taken' => 'x']);
        $this->assertSame(['opened' => 0, 'closed' => 1], $this->runEngine());
        $this->assertSame('auto', Alert::sole()->close_reason);

        $other = $this->lateIssue('ثانية');
        $this->runEngine();
        $open = Alert::where('status', 'open')->sole();
        $this->actingAs($this->admin)->patch("/alerts/{$open->id}/close")->assertRedirect();
        $this->assertSame('manual', $open->fresh()->close_reason);
        $this->runEngine();
        $this->assertSame(0, Alert::where('status', 'open')->count());

        $this->travel(1)->days();
        $this->runEngine();
        $this->assertSame(1, Alert::where('status', 'open')->count());
    }

    public function test_disabled_rule_closes_its_alerts(): void
    {
        $this->lateIssue();
        $this->runEngine();
        AlertRule::where('key', 'issue_resolution_delay')->update(['is_enabled' => false]);
        $this->runEngine();
        $this->assertSame('closed', Alert::sole()->status);
    }

    public function test_alerts_page_and_permissions(): void
    {
        $this->lateIssue();
        $this->runEngine();
        $a = Alert::sole();
        $this->actingAs($this->admin)->get('/alerts')->assertOk()->assertSee('مشكلة متأخرة')->assertSee('إغلاق');
        $viewer = $this->makeUser(Role::VIEWER);
        $this->actingAs($viewer)->get('/alerts')->assertOk()->assertDontSee('إغلاق</button>', false);
        $this->actingAs($viewer)->patch("/alerts/{$a->id}/close")->assertForbidden();
        $this->actingAs($this->makeUser())->get('/alerts')->assertForbidden();
    }

    public function test_whatsapp_summary(): void
    {
        $this->lateIssue();
        $this->runEngine();
        $res = $this->actingAs($this->admin)->get('/alerts/whatsapp');
        $res->assertRedirect();
        $url = $res->headers->get('Location');
        $this->assertStringStartsWith('https://wa.me/?text=', $url);
        $this->assertStringContainsString(rawurlencode('مشكلة متأخرة'), $url);
    }

    public function test_command_scheduled_hourly(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command ?? '', 'nours:alerts'));
        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
        $this->artisan('nours:alerts')->assertSuccessful();
    }
}
