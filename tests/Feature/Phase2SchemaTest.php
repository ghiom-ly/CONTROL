<?php

namespace Tests\Feature;

use App\Models\DailyReport;
use App\Models\Issue;
use App\Models\ShipmentFollowup;
use App\Models\Store;
use App\Models\SystemSetting;
use Illuminate\Database\QueryException;
use Tests\TestCase;

class Phase2SchemaTest extends TestCase
{
    public function test_followup_code_unique_per_report(): void
    {
        $user = $this->makeUser();
        $store = Store::factory()->create();
        $report = DailyReport::factory()->draft()->for($user)->create();

        $row = ['daily_report_id' => $report->id, 'store_id' => $store->id, 'shipment_code' => 'NR1',
            'user_id' => $user->id, 'followup_at' => now(), 'result' => 'courier_no_answer'];
        $first = ShipmentFollowup::create($row);
        $this->assertNull($first->shipment_id);

        $this->expectException(QueryException::class);
        ShipmentFollowup::create($row);
    }

    public function test_issue_source_key_unique_per_report(): void
    {
        $user = $this->makeUser();
        $report = DailyReport::factory()->approved()->for($user)->create();
        $row = ['daily_report_id' => $report->id, 'source_key' => 'n1', 'reported_by' => $user->id,
            'reported_at' => now(), 'type' => 'other', 'title' => 't', 'description' => 'd'];
        Issue::create($row);

        $this->expectException(QueryException::class);
        Issue::create($row);
    }

    public function test_max_attempts_setting_seeded(): void
    {
        $this->assertSame(2, SystemSetting::get('reports.max_attempts_per_shift'));
    }

    public function test_report_shift_fields_cast(): void
    {
        $report = DailyReport::factory()->draft()->for($this->makeUser())->create([
            'shift_started_at' => now(), 'start_total_orders' => 140, 'start_delivery_rate' => 72.5,
            'attempt_times' => ['10:30', '14:15'],
        ]);
        $report->refresh();
        $this->assertSame(['10:30', '14:15'], $report->attempt_times);
        $this->assertSame('72.50', $report->start_delivery_rate);
        $this->assertNotNull($report->shift_started_at);
    }
}
