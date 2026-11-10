<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\TopupRequest;
use App\Models\User;
use App\Notifications\TopupRequestNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TopupRequestTest extends TestCase
{
    private User $emp;

    private User $boss;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00'));
        $this->emp = $this->makeUser(Role::EMPLOYEE, ['name' => 'سالم']);
        $this->boss = $this->makeUser(Role::SUPER_ADMIN);
    }

    private function request(array $data = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->emp)->post('/my-topups', $data + ['operator' => 'libyana', 'amount' => 5]);
    }

    private function issued(string $card = '12345678901234', ?User $emp = null, string $operator = 'libyana', int $amount = 5): TopupRequest
    {
        $this->request(['operator' => $operator, 'amount' => $amount], $emp)->assertSessionHasNoErrors();
        $r = TopupRequest::latest('id')->firstOrFail();
        $this->actingAs($this->boss)->patch("/topups/{$r->id}/approve", ['card_number' => $card])->assertSessionHasNoErrors();

        return $r->fresh();
    }

    public function test_employee_requests_card_and_boss_is_notified(): void
    {
        Notification::fake();
        $this->actingAs($this->emp)->get('/my-topups')->assertOk()->assertSee('ليبيانا')->assertSee('مدار');
        $this->request(['operator' => 'madar', 'amount' => 3, 'note' => 'للاتصال بالمندوبين'])->assertRedirect()->assertSessionHasNoErrors();

        $r = TopupRequest::sole();
        $this->assertSame(['madar', 3, 'pending', $this->emp->id], [$r->operator, $r->amount, $r->status, $r->user_id]);
        Notification::assertSentTo($this->boss, TopupRequestNotification::class);
    }

    public function test_only_allowed_operators_and_amounts(): void
    {
        $this->request(['amount' => 10])->assertSessionHasErrors('amount');
        $this->request(['operator' => 'almadar'])->assertSessionHasErrors('operator');
        $this->assertSame(0, TopupRequest::count());
    }

    public function test_pending_requests_are_capped(): void
    {
        foreach (range(1, TopupRequest::MAX_PENDING) as $i) {
            $this->request()->assertSessionHasNoErrors();
        }
        $this->request()->assertSessionHasErrors('request');
        $this->assertSame(TopupRequest::MAX_PENDING, TopupRequest::count());
    }

    public function test_boss_approves_with_card_number_employee_sees_it(): void
    {
        Notification::fake();
        $r = $this->issued('1234 5678-9012 34');
        $this->assertSame('issued', $r->status);
        $this->assertSame('12345678901234', $r->card_number);
        $this->assertSame($this->boss->id, $r->decided_by);
        $this->assertNotSame('12345678901234', DB::table('topup_requests')->where('id', $r->id)->value('card_number'));   // مشفّر
        Notification::assertSentTo($this->emp, TopupRequestNotification::class);

        $this->actingAs($this->emp)->get('/my-topups')->assertSee('12345678901234');
        $this->actingAs($this->makeUser(Role::EMPLOYEE))->get('/my-topups')->assertDontSee('12345678901234');
    }

    public function test_card_number_validation_and_duplicates(): void
    {
        $this->request();
        $r = TopupRequest::sole();
        $this->actingAs($this->boss)->patch("/topups/{$r->id}/approve", ['card_number' => 'abc12'])->assertSessionHasErrors('card_number');
        $this->actingAs($this->boss)->patch("/topups/{$r->id}/approve", ['card_number' => '1234'])->assertSessionHasErrors('card_number');
        $this->assertSame('pending', $r->fresh()->status);

        $this->issued('99998888777766');
        $this->request();
        $second = TopupRequest::latest('id')->first();
        $this->actingAs($this->boss)->patch("/topups/{$second->id}/approve", ['card_number' => '9999 8888 7777 66'])->assertSessionHasErrors('card_number');
        $this->assertSame('pending', $second->fresh()->status);
    }

    public function test_arabic_digits_are_accepted(): void
    {
        $r = $this->issued('١٢٣٤٥٦٧٨٩٠١٢');
        $this->assertSame('123456789012', $r->card_number);
    }

    public function test_only_super_admin_manages(): void
    {
        $this->request();
        $r = TopupRequest::sole();
        $admin = $this->makeUser(Role::ADMIN);
        $this->actingAs($admin)->get('/topups')->assertForbidden();
        $this->actingAs($admin)->patch("/topups/{$r->id}/approve", ['card_number' => '12345678901234'])->assertForbidden();
        $this->actingAs($this->emp)->patch("/topups/{$r->id}/approve", ['card_number' => '12345678901234'])->assertForbidden();
        $this->actingAs($admin)->get('/topups/costs')->assertForbidden();
        $this->assertSame('pending', $r->fresh()->status);

        $this->actingAs($this->boss)->get('/topups')->assertOk()->assertSee('سالم')->assertSee('ليبيانا');
    }

    public function test_boss_rejects_with_note(): void
    {
        $this->request();
        $r = TopupRequest::sole();
        $this->actingAs($this->boss)->patch("/topups/{$r->id}/reject", ['decision_note' => 'طلبت أمس'])->assertSessionHasNoErrors();
        $this->assertSame(['rejected', 'طلبت أمس'], [$r->fresh()->status, $r->fresh()->decision_note]);
        $this->actingAs($this->boss)->patch("/topups/{$r->id}/approve", ['card_number' => '12345678901234'])->assertSessionHasErrors('request');
    }

    public function test_employee_confirms_used_or_faulty(): void
    {
        $a = $this->issued('11112222333344');
        $this->actingAs($this->emp)->patch("/my-topups/{$a->id}/used")->assertSessionHasNoErrors();
        $this->assertSame('used', $a->fresh()->status);
        $this->assertNotNull($a->fresh()->used_at);

        $b = $this->issued('55556666777788');
        $this->actingAs($this->emp)->patch("/my-topups/{$b->id}/faulty", ['note' => 'الرقم مستخدم'])->assertSessionHasNoErrors();
        $this->assertSame(['faulty', 'الرقم مستخدم'], [$b->fresh()->status, $b->fresh()->employee_note]);
        $this->actingAs($this->boss)->get('/topups?status=faulty')->assertSee('الرقم مستخدم');
    }

    public function test_employee_cannot_act_on_others_or_wrong_status(): void
    {
        $this->request();
        $pending = TopupRequest::sole();
        $this->actingAs($this->emp)->patch("/my-topups/{$pending->id}/used")->assertSessionHasErrors('request');

        $issued = $this->issued('11112222333344');
        $this->actingAs($this->makeUser(Role::EMPLOYEE))->patch("/my-topups/{$issued->id}/used")->assertForbidden();
        $this->actingAs($this->emp)->patch("/my-topups/{$issued->id}/cancel")->assertSessionHasErrors('request');
        $this->assertSame('issued', $issued->fresh()->status);

        $this->actingAs($this->emp)->patch("/my-topups/{$pending->id}/cancel")->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $pending->fresh()->status);
    }

    public function test_card_number_never_written_to_audit_log(): void
    {
        $r = $this->issued('43215678909876');
        $this->actingAs($this->emp)->patch("/my-topups/{$r->id}/used");
        $this->assertTrue(AuditLog::where('event', 'topup_issued')->exists());
        foreach (AuditLog::all() as $log) {
            $this->assertStringNotContainsString('43215678909876', json_encode([$log->old_values, $log->new_values, $log->description], JSON_UNESCAPED_UNICODE));
        }
    }

    public function test_monthly_costs_count_issued_used_and_faulty(): void
    {
        $other = $this->makeUser(Role::EMPLOYEE, ['name' => 'هند']);
        $this->issued('10000000000001', null, 'libyana', 5);
        $used = $this->issued('10000000000002', null, 'madar', 3);
        $this->actingAs($this->emp)->patch("/my-topups/{$used->id}/used");
        $faulty = $this->issued('10000000000003', $other, 'libyana', 3);
        $this->actingAs($other)->patch("/my-topups/{$faulty->id}/faulty", ['note' => 'لا يعمل']);
        $this->request();                                                         // معلّق: لا يُحسب
        $rej = TopupRequest::latest('id')->first();
        $this->actingAs($this->boss)->patch("/topups/{$rej->id}/reject");
        $this->request();                                                         // معلّق آخر

        $this->travelTo(CarbonImmutable::parse('2026-12-02 10:00'));
        $this->issued('10000000000004');                                          // الشهر التالي

        $res = $this->actingAs($this->boss)->get('/topups/costs?month=2026-11')->assertOk();
        $c = $res->viewData('costs');
        $this->assertSame(11, $c['total']);
        $this->assertSame(3, $c['count']);
        $this->assertSame(3, $c['faulty_amount']);
        $this->assertSame(['سالم' => 8, 'هند' => 3], $c['by_user']->pluck('total', 'name')->all());
        $this->assertSame(8, $c['by_type']['libyana'][5] + $c['by_type']['libyana'][3]);
        $this->assertSame(3, $c['by_type']['madar'][3]);

        $x = $this->actingAs($this->boss)->get('/topups/costs/export?month=2026-11')->assertOk();
        $this->assertStringContainsString('spreadsheetml', $x->headers->get('Content-Type'));
    }

    public function test_sidebar_links(): void
    {
        $this->request();
        $this->actingAs($this->emp)->get('/my-topups')->assertSee(route('my-topups.index'), false)->assertDontSee(route('topups.index'), false);
        $this->actingAs($this->boss)->get('/topups')->assertSee('طلبات كروت التعبئة (1)');
    }
}
