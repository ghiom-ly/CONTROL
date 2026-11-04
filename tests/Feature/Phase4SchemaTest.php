<?php

namespace Tests\Feature;

use App\Models\AttendanceAlert;
use App\Models\DailyReport;
use App\Models\Leave;
use App\Models\ReportStore;
use App\Models\Role;
use App\Models\Store;
use App\Models\SystemSetting;
use Illuminate\Database\QueryException;
use Tests\TestCase;

class Phase4SchemaTest extends TestCase
{
    public function test_columns_and_tables_exist(): void
    {
        $u = $this->makeUser();
        $r = DailyReport::factory()->draft()->for($u)->create();
        $rs = ReportStore::create(['daily_report_id' => $r->id, 'store_id' => Store::factory()->create()->id,
            'start_orders' => 40, 'start_rate' => 72.5, 'start_source' => 'manual']);
        $this->assertSame(40, $rs->fresh()->start_orders);
        $this->assertSame('72.50', $rs->fresh()->start_rate);

        $l = Leave::create(['user_id' => $u->id, 'from_date' => '2026-11-01', 'to_date' => '2026-11-03', 'type' => 'sick']);
        $this->assertTrue($l->covers(\Illuminate\Support\Carbon::parse('2026-11-02')));
        $this->assertFalse($l->covers(\Illuminate\Support\Carbon::parse('2026-11-04')));
        $this->assertSame('مرضية', Leave::TYPES['sick']);

        AttendanceAlert::create(['user_id' => $u->id, 'alert_date' => today(), 'kind' => 'not_started']);
        $this->expectException(QueryException::class);
        AttendanceAlert::create(['user_id' => $u->id, 'alert_date' => today(), 'kind' => 'not_started']);
    }

    public function test_first_submitted_at_kept_on_resubmit(): void
    {
        $emp = $this->makeUser();
        $r = DailyReport::factory()->draft()->for($emp)->create();
        ReportStore::create(['daily_report_id' => $r->id, 'store_id' => Store::factory()->create()->id, 'shipments_count' => 3]);
        $this->actingAs($emp)->post("/reports/{$r->id}/submit", ['end_delivery_rate' => 50]);
        $first = $r->fresh()->first_submitted_at;
        $this->assertNotNull($first);

        $r->update(['status' => 'rejected']);
        $this->travel(1)->days();
        $this->actingAs($emp)->post("/reports/{$r->id}/submit", ['end_delivery_rate' => 55]);
        $r->refresh();
        $this->assertEquals($first->toDateTimeString(), $r->first_submitted_at->toDateTimeString());
        $this->assertNotEquals($first->toDateTimeString(), $r->submitted_at->toDateTimeString());
    }

    public function test_attendance_settings_seeded_and_editable(): void
    {
        $this->assertSame('10:00', SystemSetting::get('attendance_late_after'));
        $this->assertSame(0, SystemSetting::get('attendance_grace_minutes'));
        $this->assertNotNull(SystemSetting::get('attendance_start_date'));

        $payload = SystemSetting::all()->mapWithKeys(fn ($s) => [$s->key => $s->value])->all();
        $payload = array_merge($payload, ['attendance_late_after' => '09:30', 'attendance_grace_minutes' => 10, 'attendance_start_date' => '2026-11-01',
            'employee_can_edit_rejected' => 1]);
        $this->actingAs($this->makeUser(Role::SUPER_ADMIN))->put('/settings', ['settings' => $payload])->assertSessionHasNoErrors();
        $this->assertSame('09:30', SystemSetting::get('attendance_late_after'));
        $this->assertSame(10, SystemSetting::get('attendance_grace_minutes'));
        $this->assertSame('2026-11-01', SystemSetting::get('attendance_start_date'));
    }

    public function test_admin_has_attendance_manage(): void
    {
        $this->assertTrue($this->makeUser(Role::ADMIN)->hasPermission('attendance.manage'));
        $this->assertFalse($this->makeUser()->hasPermission('attendance.manage'));
    }
}
