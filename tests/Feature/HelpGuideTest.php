<?php

namespace Tests\Feature;

use App\Models\Role;
use Tests\TestCase;

class HelpGuideTest extends TestCase
{
    public function test_followup_employee_sees_followup_guide(): void
    {
        $this->actingAs($this->makeUser())->get('/help')->assertOk()
            ->assertSee('دليل موظف متابعة الشحنات')->assertSee('حفظ وإرسال للمراجعة')->assertSee('بدء الشفت')
            ->assertDontSee('دليل موظفة تأكيد الطلبيات');
    }

    public function test_confirmation_employee_sees_confirmation_guide(): void
    {
        $this->actingAs($this->makeUser(Role::EMPLOYEE, ['work_type' => 'confirmation']))->get('/help')->assertOk()
            ->assertSee('دليل موظفة تأكيد الطلبيات')->assertSee('سبب الإلغاء')
            ->assertDontSee('دليل موظف متابعة الشحنات');
    }

    public function test_admin_sees_both_guides(): void
    {
        $this->actingAs($this->makeUser(Role::ADMIN))->get('/help')->assertOk()
            ->assertSee('دليل موظف متابعة الشحنات')->assertSee('دليل موظفة تأكيد الطلبيات');
    }

    public function test_sidebar_link_for_everyone(): void
    {
        $this->actingAs($this->makeUser())->get('/')->assertSee('دليل الاستخدام');
    }
}
