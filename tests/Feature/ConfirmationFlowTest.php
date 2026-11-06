<?php

namespace Tests\Feature;

use App\Models\DailyReport;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreDailyMetric;
use App\Models\User;
use App\Services\AttendanceService;
use App\Support\ConfirmationWhatsappFormatter;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class ConfirmationFlowTest extends TestCase
{
    private User $emp;

    private User $admin;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->emp = $this->makeUser(Role::EMPLOYEE, ['work_type' => 'confirmation', 'name' => 'ريماس']);
        $this->admin = $this->makeUser(Role::ADMIN);
        $this->store = Store::factory()->create(['name' => 'GSMSTOOK']);
    }

    private function report(bool $withOrders = true): DailyReport
    {
        $this->actingAs($this->emp)->post('/reports/start', [])->assertRedirect();
        $r = DailyReport::where('user_id', $this->emp->id)->latest('id')->firstOrFail();
        if ($withOrders) {
            $this->actingAs($this->emp)->put("/reports/{$r->id}/confirmation", ['stores' => [[
                'store_id' => $this->store->id, 'confirmed' => "SX2611050714\nSX2611050638", 'no_answer' => 'N1 N2 N3 N4 N5',
                'cancelled' => [['code' => 'SX2611050700', 'reason' => 'ماطلبتش']],
            ]], 'notes' => 'لا يوجد'])->assertSessionHasNoErrors();
        }

        return $r->fresh();
    }

    private function submit(DailyReport $r)
    {
        return $this->actingAs($this->emp)->put("/reports/{$r->id}/confirmation", ['action' => 'submit',
            'stores' => [['store_id' => $this->store->id, 'confirmed' => "SX2611050714\nSX2611050638", 'no_answer' => 'N1 N2 N3 N4 N5',
                'cancelled' => [['code' => 'SX2611050700', 'reason' => 'ماطلبتش']]]], 'notes' => 'لا يوجد']);
    }

    public function test_submit_requires_orders(): void
    {
        $r = $this->report(false);
        $this->actingAs($this->emp)->put("/reports/{$r->id}/confirmation", ['action' => 'submit', 'stores' => []])->assertSessionHasErrors('orders');
        $this->assertSame('draft', $r->fresh()->status->value);
    }

    public function test_submit_without_end_rate(): void
    {
        $r = $this->report();
        $this->submit($r)->assertSessionHasNoErrors()->assertRedirect(route('reports.show', $r));
        $r->refresh();
        $this->assertSame('pending_review', $r->status->value);
        $this->assertNull($r->end_delivery_rate);
        $this->assertNotNull($r->first_submitted_at);
    }

    public function test_approval_does_not_touch_metrics(): void
    {
        $r = $this->report();
        $this->submit($r);
        $this->actingAs($this->admin)->post("/reports/{$r->id}/approve")->assertRedirect();
        $this->assertSame('approved', $r->fresh()->status->value);
        $this->assertSame(0, StoreDailyMetric::count());
        $this->assertSame(1, $this->emp->notifications()->count());
    }

    public function test_reject_and_resubmit(): void
    {
        $r = $this->report();
        $this->submit($r);
        $this->actingAs($this->admin)->post("/reports/{$r->id}/reject", ['rejection_reason' => 'ناقص'])->assertRedirect();
        $this->assertSame('rejected', $r->fresh()->status->value);
        $this->submit($r->fresh())->assertSessionHasNoErrors();
        $this->assertSame('pending_review', $r->fresh()->status->value);
    }

    public function test_show_page_and_whatsapp_text(): void
    {
        $r = $this->report();
        $text = ConfirmationWhatsappFormatter::format($r->fresh());
        $this->assertStringContainsString('اسم الموظفه:ريماس', $text);
        $this->assertStringContainsString('إجمالي الأوردرات التي عملت عليها اليوم: [8]', $text);
        $this->assertStringContainsString('الأوردرات المؤكدة : [2]', $text);
        $this->assertStringContainsString('الأوردرات الملغاه: [1]', $text);
        $this->assertStringContainsString('لا يرد:[5]', $text);
        $this->assertStringContainsString("GSMSTOOK(2)\nSX2611050714", $text);
        $this->assertStringContainsString('SX2611050700 — ماطلبتش', $text);

        $this->actingAs($this->admin)->get("/reports/{$r->id}")->assertOk()
            ->assertSee('SX2611050714')->assertSee('ماطلبتش')->assertSee('المؤكدة')->assertSee('نسخ كنص واتساب');
    }

    public function test_attendance_counts_confirmation_shift(): void
    {
        $r = $this->report();
        $this->submit($r);
        $day = collect(app(AttendanceService::class)->month($this->emp, CarbonImmutable::today()->startOfMonth())['days'])->firstWhere('date', today()->toDateString());
        $this->assertContains($day['status'], ['present', 'late']);
        $this->assertNotNull($day['in']);
    }
}
