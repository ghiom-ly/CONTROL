<?php

namespace Tests\Feature;

use App\Models\DailyReport;
use App\Models\ReportStore;
use App\Models\Role;
use App\Models\Store;
use Tests\TestCase;

class StoreManagementTest extends TestCase
{
    private function payload(array $override = []): array
    {
        return array_merge(['name' => 'متجر الأمل', 'phone' => '٠٩١٢٣٤٥٦٧٨', 'status' => 'active'], $override);
    }

    public function test_admin_creates_store_with_generated_code(): void
    {
        $this->actingAs($this->makeUser(Role::SUPER_ADMIN))
            ->post('/stores', $this->payload())->assertRedirect();

        $store = Store::firstOrFail();
        $this->assertMatchesRegularExpression('/^STR-\d{5}$/', $store->code);
        $this->assertNotEmpty($store->normalized_name);
        $this->assertSame('0912345678', $store->phone);
        $this->assertDatabaseHas('audit_logs', ['event' => 'created', 'auditable_type' => Store::class, 'auditable_id' => $store->id]);
    }

    public function test_admin_role_can_manage_stores(): void
    {
        $this->actingAs($this->makeUser(Role::ADMIN))->post('/stores', $this->payload())->assertRedirect();
        $this->assertSame(1, Store::count());
    }

    public function test_duplicate_normalized_name_rejected(): void
    {
        $this->actingAs($this->makeUser(Role::SUPER_ADMIN));
        $this->post('/stores', $this->payload(['name' => 'متجر الأمل']));
        $this->post('/stores', $this->payload(['name' => 'متجر الامل']))->assertSessionHasErrors('name');
        $this->assertSame(1, Store::count());

        // تعديل المتجر نفسه باسمه لا يُعد تكراراً
        $store = Store::first();
        $this->put("/stores/{$store->id}", $this->payload(['name' => 'متجر الأمل', 'notes' => 'x']))->assertSessionHasNoErrors();
    }

    public function test_employee_cannot_create_or_update_store(): void
    {
        $store = Store::factory()->create();
        $this->actingAs($this->makeUser(Role::EMPLOYEE));
        $this->post('/stores', $this->payload())->assertForbidden();
        $this->put("/stores/{$store->id}", $this->payload())->assertForbidden();
        $this->patch("/stores/{$store->id}/archive")->assertForbidden();
        $this->get('/stores')->assertOk();
    }

    public function test_archive_keeps_store_with_reports(): void
    {
        $store = Store::factory()->create();
        $report = DailyReport::factory()->approved()->for($this->makeUser())->create();
        ReportStore::factory()->create(['daily_report_id' => $report->id, 'store_id' => $store->id]);

        $this->actingAs($this->makeUser(Role::SUPER_ADMIN))
            ->patch("/stores/{$store->id}/archive")->assertRedirect();

        $this->assertSame('archived', $store->fresh()->status->value);
        $this->assertNull($store->fresh()->deleted_at);
    }

    public function test_assigned_user_must_be_active_employee(): void
    {
        $this->actingAs($this->makeUser(Role::SUPER_ADMIN));
        $viewer = $this->makeUser(Role::VIEWER);
        $inactive = $this->makeUser(Role::EMPLOYEE, ['status' => 'inactive']);
        $ok = $this->makeUser(Role::EMPLOYEE);

        $this->post('/stores', $this->payload(['assigned_user_id' => $viewer->id]))->assertSessionHasErrors('assigned_user_id');
        $this->post('/stores', $this->payload(['assigned_user_id' => $inactive->id]))->assertSessionHasErrors('assigned_user_id');
        $this->post('/stores', $this->payload(['assigned_user_id' => $ok->id]))->assertSessionHasNoErrors();
        $this->assertSame($ok->id, Store::first()->assigned_user_id);
    }

    public function test_index_filters_by_assigned_user_and_status(): void
    {
        $emp = $this->makeUser();
        Store::factory()->assignedTo($emp->id)->create(['name' => 'متجر السلام']);
        Store::factory()->create(['name' => 'متجر النجمة', 'status' => 'stopped']);

        $this->actingAs($this->makeUser(Role::SUPER_ADMIN));
        $this->get('/stores?assigned_user_id='.$emp->id)->assertSee('متجر السلام')->assertDontSee('متجر النجمة');
        $this->get('/stores?status=stopped')->assertSee('متجر النجمة')->assertDontSee('متجر السلام');
        $this->get('/stores?q='.urlencode('السلام'))->assertSee('متجر السلام')->assertDontSee('متجر النجمة');
    }
}
