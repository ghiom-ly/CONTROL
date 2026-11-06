<?php

namespace Tests\Feature;

use App\Models\DailyReport;
use App\Models\ReportStore;
use App\Models\Role;
use App\Models\Store;
use App\Services\Analytics\AnalyticsService;
use App\Support\ComparePeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AnalyticsCacheTest extends TestCase
{
    private function orders(): ?float
    {
        $p = ComparePeriod::fromRequest(Request::create('/'));

        return collect(app(AnalyticsService::class)->compare($p))->firstWhere('key', 'orders')['current'];
    }

    public function test_compare_is_cached(): void
    {
        $this->orders();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->orders();
        $this->assertSame([], collect(DB::getQueryLog())->pluck('query')->reject(fn ($q) => str_contains($q, 'cache'))->values()->all());
    }

    public function test_cache_flushed_on_approval(): void
    {
        $this->assertSame(0.0, $this->orders());
        $s = Store::factory()->create();
        $r = DailyReport::factory()->pendingReview()->for($this->makeUser())->create(['work_date' => today()]);
        ReportStore::create(['daily_report_id' => $r->id, 'store_id' => $s->id, 'shipments_count' => 12]);
        $this->actingAs($this->makeUser(Role::ADMIN))->post("/reports/{$r->id}/approve")->assertRedirect();
        $this->assertSame(12.0, $this->orders());
    }
}
