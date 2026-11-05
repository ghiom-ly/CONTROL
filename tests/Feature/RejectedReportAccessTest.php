<?php

namespace Tests\Feature;

use App\Models\DailyReport;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Notifications\ReportReviewedNotification;
use Tests\TestCase;

class RejectedReportAccessTest extends TestCase
{
    private function rejected(): DailyReport
    {
        return DailyReport::factory()->for($this->makeUser())->create(['status' => 'rejected', 'rejection_reason' => 'ناقص أكواد']);
    }

    public function test_rejection_notification_opens_report_page_not_edit(): void
    {
        $r = $this->rejected();
        $data = (new ReportReviewedNotification($r))->toArray($r->user);
        $this->assertSame(route('reports.show', $r), $data['url']);
    }

    public function test_owner_sees_edit_button_when_allowed(): void
    {
        $r = $this->rejected();
        SystemSetting::where('key', 'employee_can_edit_rejected')->update(['value' => '1']);
        cache()->flush();
        $this->actingAs($r->user)->get("/reports/{$r->id}")->assertOk()
            ->assertSee('ناقص أكواد')->assertSee(route('reports.edit', $r));
    }

    public function test_owner_sees_explanation_when_editing_disabled(): void
    {
        $r = $this->rejected();
        SystemSetting::where('key', 'employee_can_edit_rejected')->update(['value' => '0']);
        cache()->flush();
        $this->actingAs($r->user)->get("/reports/{$r->id}")->assertOk()
            ->assertSee('ناقص أكواد')
            ->assertSee('تعديل التقارير المرجعة موقوف من الإعدادات')
            ->assertDontSee(route('reports.edit', $r));
    }
}
