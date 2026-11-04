<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Store;
use Tests\TestCase;

/** الموظف يدخل تقريره ويرسله، ولا يرى إلا إحصائيات تقاريره */
class EmployeeScopeTest extends TestCase
{
    public function test_employee_menu_shows_only_report_screens(): void
    {
        $page = $this->actingAs($this->makeUser())->get('/')->assertOk();
        $page->assertSee(route('reports.create'))->assertSee(route('reports.index'));
        foreach (['stores.index', 'open-codes.index', 'imports.index'] as $hidden) {
            $page->assertDontSee(route($hidden));
        }
        $page->assertDontSee('/modules/shipments')->assertDontSee('/modules/contacts')->assertDontSee('/modules/issues');
    }

    public function test_employee_cannot_open_followup_screens(): void
    {
        $store = Store::factory()->create();
        $this->actingAs($this->makeUser());
        foreach (['/stores', "/stores/{$store->id}", '/open-codes', '/imports', '/imports/legacy'] as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_employee_dashboard_shows_only_own_report_stats(): void
    {
        $this->actingAs($this->makeUser())->get('/')->assertOk()
            ->assertSee('تقارير الفترة')->assertDontSee('إجمالي المتاجر')->assertDontSee('المشكلات المفتوحة');
        $this->actingAs($this->makeUser(Role::ADMIN))->get('/')->assertSee('إجمالي المتاجر');
    }

    public function test_employee_can_still_start_shift_from_file(): void
    {
        $this->actingAs($this->makeUser())->get('/reports/new')->assertOk()->assertSee('رفع ملف البداية');
    }
}
