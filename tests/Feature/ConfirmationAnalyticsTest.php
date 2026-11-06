<?php

namespace Tests\Feature;

use App\Models\ConfirmationOrder;
use App\Models\DailyReport;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\Analytics\ConfirmationStatsService;
use App\Support\ComparePeriod;
use Illuminate\Http\Request;
use Tests\TestCase;

class ConfirmationAnalyticsTest extends TestCase
{
    private User $emp;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->emp = $this->makeUser(Role::EMPLOYEE, ['work_type' => 'confirmation', 'name' => 'ريماس']);
        $this->store = Store::factory()->create(['name' => 'متجر التأكيد']);
    }

    private function orders(string $status, array $results): DailyReport
    {
        $r = DailyReport::factory()->{$status}()->for($this->emp)->create(['kind' => 'confirmation', 'work_date' => today()]);
        foreach ($results as $i => [$result, $reason]) {
            ConfirmationOrder::create(['daily_report_id' => $r->id, 'store_id' => $this->store->id, 'user_id' => $this->emp->id,
                'code' => "C{$r->id}X{$i}", 'result' => $result, 'cancel_reason' => $reason]);
        }

        return $r;
    }

    private function p(): ComparePeriod
    {
        return ComparePeriod::fromRequest(Request::create('/'));
    }

    public function test_rates_from_approved_only(): void
    {
        $this->orders('approved', [['confirmed', null], ['confirmed', null], ['cancelled', 'سعر غالي'], ['no_answer', null]]);
        $this->orders('pendingReview', [['cancelled', 'ماطلبتش'], ['cancelled', 'ماطلبتش']]);
        $row = app(ConfirmationStatsService::class)->perEmployee($this->p())->firstWhere('user.id', $this->emp->id);
        $this->assertSame([4, 2, 1, 1, 50.0], [$row['total'], $row['confirmed'], $row['cancelled'], $row['no_answer'], $row['rate']]);
        $store = app(ConfirmationStatsService::class)->perStore($this->p())->firstWhere('store.id', $this->store->id);
        $this->assertSame(50.0, $store['rate']);
    }

    public function test_reasons_sorted(): void
    {
        $this->orders('approved', [['cancelled', 'سعر غالي'], ['cancelled', 'ماطلبتش'], ['cancelled', 'ماطلبتش']]);
        $this->assertSame(['ماطلبتش' => 2, 'سعر غالي' => 1], app(ConfirmationStatsService::class)->reasons($this->p()));
    }

    public function test_analytics_page_section(): void
    {
        $this->orders('approved', [['confirmed', null]]);
        $this->actingAs($this->makeUser(Role::ADMIN))->get('/analytics')->assertOk()->assertSee('تأكيد الطلبيات')->assertSee('ريماس')->assertSee('100%');
    }

    public function test_code_lookup_shows_confirmation_history(): void
    {
        $r = $this->orders('approved', [['cancelled', 'الطلب مكرر']]);
        $code = "C{$r->id}X0";
        $this->actingAs($this->makeUser(Role::ADMIN))->get("/shipments/{$code}")->assertOk()
            ->assertSee('سجل التأكيد')->assertSee('الطلب مكرر')->assertSee('ريماس')->assertDontSee('لا يوجد كود بهذا الرقم');
        $this->actingAs($this->makeUser())->get("/shipments/{$code}")->assertOk()->assertSee('الطلب مكرر')->assertDontSee('ريماس');
    }

    public function test_confirmation_report_in_center(): void
    {
        $this->orders('approved', [['confirmed', null], ['cancelled', 'سعر غالي']]);
        $admin = $this->makeUser(Role::ADMIN);
        $this->actingAs($admin)->get('/reports-center')->assertSee('تقرير التأكيد');
        foreach (['pdf', 'excel', 'print'] as $f) {
            $this->actingAs($admin)->get("/reports-center/confirmation/{$f}")->assertOk();
        }
        $this->actingAs($admin)->get('/reports-center/confirmation/print')->assertSee('ريماس')->assertSee('سعر غالي');
    }
}
