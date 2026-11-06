<?php

namespace Tests\Feature;

use App\Models\ConfirmationOrder;
use App\Models\DailyReport;
use App\Models\Store;
use App\Models\User;
use Tests\TestCase;

class ConfirmationDraftTest extends TestCase
{
    private User $emp;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->emp = $this->makeUser(\App\Models\Role::EMPLOYEE, ['work_type' => 'confirmation', 'name' => 'ريماس']);
        $this->store = Store::factory()->create(['name' => 'GSM STOOK']);
    }

    private function start(): DailyReport
    {
        $this->actingAs($this->emp)->post('/reports/start', [])->assertRedirect();

        return DailyReport::where('user_id', $this->emp->id)->latest('id')->firstOrFail();
    }

    private function payload(array $cancelled = [['code' => 'SX2611050700', 'reason' => 'سعر غالي']], array $extra = []): array
    {
        return array_merge(['stores' => [[
            'store_id' => $this->store->id,
            'confirmed' => "SX2611050714\nsx2611050638",
            'no_answer' => 'SX1 SX2 ٣٣',
            'cancelled' => $cancelled,
        ]], 'attempt_times' => ['10:30'], 'notes' => 'لا يوجد'], $extra);
    }

    public function test_start_creates_confirmation_shift_without_stores(): void
    {
        $this->actingAs($this->emp)->get('/reports/new')->assertOk()->assertSee('بدء الشفت')->assertDontSee('نسبة التسليم %');
        $r = $this->start();
        $this->assertSame('confirmation', $r->kind);
        $this->assertSame('draft', $r->status->value);
        $this->assertNotNull($r->shift_started_at);
        $this->assertSame(0, $r->stores()->count());
        $this->actingAs($this->emp)->get("/reports/{$r->id}/edit")->assertOk()->assertSee('المؤكدة')->assertSee('سعر غالي');
    }

    public function test_save_creates_orders_with_results_and_reasons(): void
    {
        $r = $this->start();
        $this->actingAs($this->emp)->put("/reports/{$r->id}/confirmation", $this->payload())->assertSessionHasNoErrors();

        $orders = ConfirmationOrder::where('daily_report_id', $r->id)->orderBy('code')->get();
        $this->assertSame(['33', 'SX1', 'SX2', 'SX2611050638', 'SX2611050700', 'SX2611050714'], $orders->pluck('code')->all());
        $this->assertSame(2, $orders->where('result.value', 'confirmed')->count());
        $this->assertSame(3, $orders->where('result.value', 'no_answer')->count());
        $c = $orders->firstWhere('code', 'SX2611050700');
        $this->assertSame('cancelled', $c->result->value);
        $this->assertSame('سعر غالي', $c->cancel_reason);
        $this->assertSame(['10:30'], $r->fresh()->attempt_times);

        // إعادة الحفظ تستبدل ولا تكرر
        $this->actingAs($this->emp)->put("/reports/{$r->id}/confirmation", $this->payload([]))->assertSessionHasNoErrors();
        $this->assertSame(5, ConfirmationOrder::where('daily_report_id', $r->id)->count());
    }

    public function test_duplicate_code_rejected(): void
    {
        $r = $this->start();
        $this->actingAs($this->emp)->put("/reports/{$r->id}/confirmation", $this->payload([['code' => 'SX2611050714', 'reason' => 'سعر غالي']]))
            ->assertSessionHasErrors('orders');
        $this->assertSame(0, ConfirmationOrder::count());
    }

    public function test_cancelled_requires_reason(): void
    {
        $r = $this->start();
        $this->actingAs($this->emp)->put("/reports/{$r->id}/confirmation", $this->payload([['code' => 'C1', 'reason' => '']]))
            ->assertSessionHasErrors('stores.0.cancelled.0.reason');
        $this->actingAs($this->emp)->put("/reports/{$r->id}/confirmation", $this->payload([['code' => 'C1', 'reason' => 'سبب غريب']]))
            ->assertSessionHasErrors('stores.0.cancelled.0.reason');
    }

    public function test_other_reason_requires_text(): void
    {
        $r = $this->start();
        $this->actingAs($this->emp)->put("/reports/{$r->id}/confirmation", $this->payload([['code' => 'C1', 'reason' => 'أخرى', 'other' => '']]))
            ->assertSessionHasErrors('stores.0.cancelled.0.other');
        $this->actingAs($this->emp)->put("/reports/{$r->id}/confirmation", $this->payload([['code' => 'C1', 'reason' => 'أخرى', 'other' => 'العنوان بعيد']]))
            ->assertSessionHasNoErrors();
        $this->assertSame('أخرى: العنوان بعيد', ConfirmationOrder::where('code', 'C1')->value('cancel_reason'));
    }

    public function test_followup_report_ignores_confirmation_payload(): void
    {
        $followup = $this->makeUser();
        $r = DailyReport::factory()->draft()->for($followup)->create();
        $this->actingAs($followup)->put("/reports/{$r->id}/confirmation", $this->payload())->assertNotFound();
        $this->assertSame(0, ConfirmationOrder::count());

        $conf = $this->start();
        $this->actingAs($this->emp)->put("/reports/{$conf->id}", ['stores' => [['store_id' => $this->store->id, 'shipments_count' => 5]]])->assertNotFound();
    }

    public function test_other_employee_cannot_edit(): void
    {
        $r = $this->start();
        $other = $this->makeUser(\App\Models\Role::EMPLOYEE, ['work_type' => 'confirmation']);
        $this->actingAs($other)->put("/reports/{$r->id}/confirmation", $this->payload())->assertForbidden();
    }
}
