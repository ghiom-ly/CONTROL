<?php

namespace Tests\Feature;

use App\Models\DailyReport;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class AttendancePagesTest extends TestCase
{
    private User $emp;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-20 12:00'));
        SystemSetting::where('key', 'attendance_start_date')->update(['value' => '2026-11-01']);
        \Illuminate\Support\Facades\Cache::flush();
        $this->emp = $this->makeUser(Role::EMPLOYEE, ['name' => 'سارة الموظفة', 'hired_at' => '2026-01-01']);
        $this->admin = $this->makeUser(Role::ADMIN);
        DailyReport::factory()->approved()->for($this->emp)->create([
            'work_date' => '2026-11-02', 'shift_started_at' => '2026-11-02 10:30', 'first_submitted_at' => '2026-11-02 16:30',
        ]);
    }

    public function test_index_lists_employees_with_summary(): void
    {
        $this->actingAs($this->admin)->get('/attendance?month=2026-11')->assertOk()
            ->assertSee('سارة الموظفة')->assertSee('تسجيل إجازة')->assertDontSee($this->admin->name.'</td>', false);
    }

    public function test_show_days_table(): void
    {
        $this->actingAs($this->admin)->get("/attendance/{$this->emp->id}?month=2026-11")->assertOk()
            ->assertSee('10:30')->assertSee('16:30')->assertSee('متأخر')->assertSee('غائب');
    }

    public function test_admin_user_is_not_in_attendance(): void
    {
        $this->actingAs($this->admin)->get("/attendance/{$this->admin->id}")->assertNotFound();
    }

    public function test_print_page_renders(): void
    {
        $this->actingAs($this->admin)->get("/attendance/{$this->emp->id}/print?month=2026-11")->assertOk()
            ->assertSee('تقرير الحضور الشهري')->assertSee('سارة الموظفة')->assertSee('window.print', false);
    }

    public function test_export_downloads_xlsx_with_summary_sheet(): void
    {
        $res = $this->actingAs($this->admin)->get('/attendance/export?month=2026-11')->assertOk();
        $this->assertStringContainsString('2026-11', $res->headers->get('content-disposition'));
        $path = tempnam(sys_get_temp_dir(), 'att').'.xlsx';
        file_put_contents($path, $res->streamedContent());
        $book = IOFactory::load($path);
        $this->assertSame('ملخص', $book->getSheet(0)->getTitle());
        $this->assertSame('سارة الموظفة', $book->getSheet(0)->getCell('A2')->getValue());
        $this->assertSame(2, $book->getSheetCount());
    }

    public function test_employee_dashboard_shows_own_attendance(): void
    {
        $this->actingAs($this->emp)->get('/')->assertOk()->assertSee('حضوري هذا الشهر');
    }

    public function test_employee_cannot_open_attendance(): void
    {
        $this->actingAs($this->emp)->get('/attendance')->assertForbidden();
        $this->actingAs($this->emp)->get('/attendance/export')->assertForbidden();
    }

    public function test_invalid_month_defaults_to_current(): void
    {
        $this->actingAs($this->admin)->get('/attendance?month=abc')->assertOk()->assertSee('2026-11');
    }
}
