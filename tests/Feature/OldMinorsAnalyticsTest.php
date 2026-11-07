<?php

namespace Tests\Feature;

use App\Alerts\AlertEngine;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreDailyMetric;
use App\Services\Analytics\AnalyticsService;
use App\Support\ComparePeriod;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class OldMinorsAnalyticsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\AlertRulesSeeder::class);
    }

    private function only(string $key, ?CarbonImmutable $now = null): void
    {
        AlertRule::query()->update(['is_enabled' => false]);
        AlertRule::where('key', $key)->update(['is_enabled' => true]);
        app(AlertEngine::class)->run($now ?? CarbonImmutable::now());
    }

    public function test_failing_rule_does_not_stop_others(): void
    {
        $this->app->bind(\App\Alerts\Rules\ReportApprovalDelay::class, fn () => new class implements \App\Alerts\AlertRule
        {
            public function key(): string
            {
                return 'report_approval_delay';
            }

            public function evaluate(array $p, CarbonImmutable $now): array
            {
                throw new \RuntimeException('boom');
            }
        });
        $admin = $this->makeUser(Role::ADMIN);
        \App\Models\Issue::create(['store_id' => Store::factory()->create()->id, 'reported_by' => $admin->id, 'reported_at' => now()->subDays(5),
            'type' => 'other', 'title' => 't', 'description' => 'd', 'status' => 'new']);
        AlertRule::query()->update(['is_enabled' => false]);
        AlertRule::whereIn('key', ['report_approval_delay', 'issue_resolution_delay'])->update(['is_enabled' => true]);
        app(AlertEngine::class)->run(CarbonImmutable::now());
        $this->assertSame(1, Alert::where('rule_key', 'issue_resolution_delay')->count());
    }

    public function test_missing_report_skips_future_hires(): void
    {
        $this->makeUser(Role::EMPLOYEE, ['hired_at' => today()->addDays(3)]);
        $this->only('employee_missing_report', CarbonImmutable::today()->setTime(19, 0));
        $this->assertSame(0, Alert::count());
    }

    public function test_long_stopped_store_alert_stays_open(): void
    {
        $s = Store::factory()->create();
        StoreDailyMetric::create(['store_id' => $s->id, 'metric_date' => today()->subDays(40), 'shipments_count' => 10]);
        $this->only('store_stopped_reporting');
        $this->assertSame(1, Alert::where('status', 'open')->count());
    }

    public function test_old_closed_alerts_cleaned(): void
    {
        Alert::create(['rule_key' => 'x', 'level' => 'info', 'title' => 'قديم', 'fingerprint' => 'a', 'status' => 'closed',
            'first_seen_at' => now()->subDays(120), 'last_seen_at' => now()->subDays(120), 'closed_at' => now()->subDays(100), 'close_reason' => 'auto']);
        Alert::create(['rule_key' => 'x', 'level' => 'info', 'title' => 'حديث', 'fingerprint' => 'b', 'status' => 'closed',
            'first_seen_at' => now(), 'last_seen_at' => now(), 'closed_at' => now()->subDays(5), 'close_reason' => 'auto']);
        $this->artisan('nours:cleanup')->assertSuccessful();
        $this->assertSame(['حديث'], Alert::pluck('title')->all());
    }

    public function test_percent_param_capped_at_100(): void
    {
        $rule = AlertRule::where('key', 'shipments_below_average')->sole();
        $this->actingAs($this->makeUser(Role::SUPER_ADMIN))->put('/settings/alert-rules', ['rules' => [$rule->id => ['is_enabled' => '1', 'level' => 'warning',
            'parameters' => ['percent' => '500', 'compare_days' => '14']]]]);
        $this->assertSame(100, $rule->fresh()->parameters['percent']);
    }

    public function test_from_error_is_displayed(): void
    {
        $admin = $this->makeUser(Role::ADMIN);
        // لا حلقة إعادة توجيه: يعود لصفحة التحليلات بلا معاملات ويعرض خطأ «من»
        $this->actingAs($admin)->get('/analytics?period=custom&to=2026-11-01')
            ->assertRedirect(route('analytics.index'))->assertSessionHasErrors('from');
        $this->actingAs($admin)->withSession(['errors' => (new \Illuminate\Support\ViewErrorBag)->put('default', new \Illuminate\Support\MessageBag(['from' => ['خطأ تاريخ البداية']]))])
            ->get('/analytics')->assertSee('خطأ تاريخ البداية');
    }

    public function test_flush_increments_version(): void
    {
        Cache::forget('analytics:version');
        AnalyticsService::flush();
        AnalyticsService::flush();
        $this->assertSame(3, (int) Cache::get('analytics:version'));
    }

    public function test_signed_link_to_missing_store_shows_expired_page(): void
    {
        $url = URL::temporarySignedRoute('store-report.public', now()->addDay(), ['store' => 999999, 'from' => today()->toDateString(), 'to' => today()->toDateString()]);
        $this->get($url)->assertForbidden()->assertSee('انتهت صلاحية الرابط');
    }

    public function test_store_page_pdf_button_has_no_csrf_in_url(): void
    {
        $s = Store::factory()->create();
        $html = $this->actingAs($this->makeUser(Role::ADMIN))->get("/stores/{$s->id}")->getContent();
        preg_match('~<form method="GET" action="[^"]*reports-center/store/pdf"[^>]*>(.*?)</form>~s', $html, $m);
        $this->assertNotEmpty($m);
        $this->assertStringNotContainsString('_token', $m[1]);
    }

    public function test_reports_center_has_whatsapp_share(): void
    {
        $this->actingAs($this->makeUser(Role::ADMIN))->get('/reports-center')->assertSee('إرسال للمتجر عبر واتساب');
    }

    public function test_share_by_code_redirects_to_whatsapp(): void
    {
        $s = Store::factory()->create(['phone' => '0912345678']);
        $admin = $this->makeUser(Role::ADMIN);
        $res = $this->actingAs($admin)->post('/reports-center/store-share', ['store_code' => strtolower($s->code), 'period' => 'month']);
        $this->assertStringStartsWith('https://wa.me/', $res->headers->get('Location'));
        $this->actingAs($admin)->post('/reports-center/store-share', ['store_code' => 'NOPE', 'period' => 'month'])->assertSessionHasErrors('store_code');
    }
}
