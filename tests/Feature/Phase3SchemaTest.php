<?php

namespace Tests\Feature;

use App\Models\DailyReport;
use App\Models\ImportBatch;
use App\Models\ShipmentFollowup;
use App\Models\Store;
use App\Models\StoreAlias;
use App\Services\Imports\StoreMatcher;
use Illuminate\Database\QueryException;
use Tests\TestCase;

class Phase3SchemaTest extends TestCase
{
    public function test_alias_unique_by_normalized_value(): void
    {
        $s = Store::factory()->create();
        StoreAlias::create(['store_id' => $s->id, 'alias' => 'الأمل 2', 'normalized_alias' => 'x1']);
        $this->expectException(QueryException::class);
        StoreAlias::create(['store_id' => $s->id, 'alias' => 'الامل 2', 'normalized_alias' => 'x1']);
    }

    public function test_matcher_finds_by_name_then_alias(): void
    {
        $amal = Store::factory()->create(['name' => 'متجر الأمل']);
        $m = app(StoreMatcher::class);
        $this->assertSame($amal->id, $m->match('متجر الامل'));
        $this->assertNull($m->match('الامل فرع 2'));

        $m->remember('الأمل فرع 2', $amal->id, $this->makeUser());
        $this->assertSame($amal->id, $m->match('الامل فرع ٢'));
    }

    public function test_matcher_ignores_archived_store(): void
    {
        Store::factory()->create(['name' => 'متجر قديم', 'status' => 'archived']);
        $this->assertNull(app(StoreMatcher::class)->match('متجر قديم'));
    }

    public function test_remember_is_idempotent_and_skips_own_name(): void
    {
        $s = Store::factory()->create(['name' => 'متجر النجمة']);
        $m = app(StoreMatcher::class);
        $u = $this->makeUser();
        $m->remember('النجمة', $s->id, $u);
        $m->remember('النجمه', $s->id, $u);
        $m->remember('متجر النجمة', $s->id, $u);
        $this->assertSame(1, StoreAlias::count());
    }

    public function test_migration_columns_exist(): void
    {
        $u = $this->makeUser();
        $r = DailyReport::factory()->draft()->for($u)->create([
            'is_legacy' => true, 'file_values' => ['start_total' => 10], 'value_sources' => ['start_total_orders' => 'file'],
        ]);
        $this->assertTrue($r->fresh()->is_legacy);
        $this->assertSame(10, $r->fresh()->file_values['start_total']);

        $s = Store::factory()->create();
        $f = ShipmentFollowup::create(['daily_report_id' => $r->id, 'store_id' => $s->id, 'shipment_code' => 'H1',
            'user_id' => $u->id, 'followup_at' => now(), 'result' => 'other_issue', 'is_historical' => true]);
        $this->assertTrue($f->fresh()->is_historical);

        $b = ImportBatch::create(['batch_number' => 'IMP-T-1', 'original_name' => 'a.csv', 'stored_path' => 'x', 'file_hash' => str_repeat('a', 64),
            'file_type' => 'csv', 'report_type' => 'shift_start', 'uploaded_by' => $u->id, 'daily_report_id' => $r->id]);
        $this->assertSame($r->id, $b->fresh()->daily_report_id);
    }
}
