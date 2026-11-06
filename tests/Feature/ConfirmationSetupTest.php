<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ConfirmationSetupTest extends TestCase
{
    private function payload(array $o = []): array
    {
        return array_merge(['name' => 'ريماس', 'username' => 'rimas', 'role_id' => Role::where('name', Role::EMPLOYEE)->value('id'),
            'status' => 'active', 'password' => 'Rimas1234', 'password_confirmation' => 'Rimas1234'], $o);
    }

    public function test_schema_and_setting(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'work_type'));
        $this->assertTrue(Schema::hasColumn('daily_reports', 'kind'));
        $this->assertTrue(Schema::hasTable('confirmation_orders'));
        $this->assertSame(['ماطلبتش', 'سعر غالي', 'الطلب مكرر', 'الزبون لا يرد'], \App\Support\CancelReasons::all());
        $this->assertSame('followup', $this->makeUser()->fresh()->work_type);
    }

    public function test_create_confirmation_employee(): void
    {
        $boss = $this->makeUser(Role::SUPER_ADMIN);
        $this->actingAs($boss)->post('/users', $this->payload(['work_type' => 'confirmation']))->assertSessionHasNoErrors();
        $u = User::where('username', 'rimas')->sole();
        $this->assertSame('confirmation', $u->work_type);
        $this->assertTrue($u->isConfirmation());
        $this->actingAs($boss)->get("/users/{$u->id}")->assertOk()->assertSee('تأكيد طلبيات');
    }

    public function test_invalid_work_type_rejected(): void
    {
        $this->actingAs($this->makeUser(Role::SUPER_ADMIN))->post('/users', $this->payload(['work_type' => 'hacker']))
            ->assertSessionHasErrors('work_type');
    }

    public function test_cancel_reasons_editable_in_settings(): void
    {
        $payload = SystemSetting::all()->mapWithKeys(fn ($s) => [$s->key => $s->value])->all();
        $payload['confirmation_cancel_reasons'] = "ماطلبتش\n  \nالعنوان بعيد";
        $this->actingAs($this->makeUser(Role::SUPER_ADMIN))->put('/settings', ['settings' => $payload])->assertSessionHasNoErrors();
        $this->assertSame(['ماطلبتش', 'العنوان بعيد'], \App\Support\CancelReasons::all());
    }
}
