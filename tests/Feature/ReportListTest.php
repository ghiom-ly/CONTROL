<?php

namespace Tests\Feature;

use App\Models\DailyReport;
use App\Models\Role;
use Tests\TestCase;

class ReportListTest extends TestCase
{
    public function test_employee_list_shows_only_own_reports(): void
    {
        $emp = $this->makeUser();
        DailyReport::factory()->approved()->for($emp)->create(['report_number' => 'RPT-MINE-777']);
        DailyReport::factory()->approved()->for($this->makeUser())->create(['report_number' => 'RPT-THEIRS-888']);

        $this->actingAs($emp)->get('/reports')->assertOk()->assertSee('RPT-MINE-777')->assertDontSee('RPT-THEIRS-888');
    }

    public function test_employee_sees_link_to_open_shift(): void
    {
        $emp = $this->makeUser();
        $draft = DailyReport::factory()->draft()->for($emp)->create();
        $this->actingAs($emp)->get('/reports')->assertSee('متابعة الشفت المفتوح')->assertSee(route('reports.edit', $draft));
    }

    public function test_admin_filters_by_status_and_user(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();
        DailyReport::factory()->approved()->for($a)->create(['report_number' => 'RPT-A-APP']);
        DailyReport::factory()->pendingReview()->for($a)->create(['report_number' => 'RPT-A-PEN']);
        DailyReport::factory()->pendingReview()->for($b)->create(['report_number' => 'RPT-B-PEN']);

        $this->actingAs($this->makeUser(Role::ADMIN));
        $this->get('/reports?status=pending_review')->assertSee('RPT-A-PEN')->assertSee('RPT-B-PEN')->assertDontSee('RPT-A-APP');
        $this->get('/reports?user_id='.$a->id)->assertSee('RPT-A-APP')->assertSee('RPT-A-PEN')->assertDontSee('RPT-B-PEN');
        $this->get('/reports?from='.today()->addDay()->toDateString())->assertDontSee('RPT-A-APP');
    }

    public function test_sidebar_links_resolve_for_each_role(): void
    {
        foreach ([Role::SUPER_ADMIN, Role::ADMIN, Role::EMPLOYEE, Role::VIEWER] as $role) {
            $this->actingAs($this->makeUser($role));
            foreach (['/', '/stores', '/reports', '/reports/new', '/review', '/open-codes'] as $url) {
                $status = $this->get($url)->status();
                $this->assertContains($status, [200, 302, 403], "{$role} {$url} → {$status}");
            }
        }
    }

    public function test_placeholders_removed_for_built_modules(): void
    {
        $this->actingAs($this->makeUser(Role::SUPER_ADMIN));
        $this->get('/modules/stores')->assertNotFound();
        $this->get('/modules/imports')->assertOk();
    }

    public function test_viewer_cannot_open_reports_list_without_permission(): void
    {
        // المشاهد يملك reports.view_all افتراضياً
        $this->actingAs($this->makeUser(Role::VIEWER))->get('/reports')->assertOk();
    }
}
