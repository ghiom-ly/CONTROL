<?php

namespace Tests\Feature;

use App\Models\ImportBatch;
use App\Models\ImportMapping;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreAlias;
use App\Models\User;
use App\Services\Imports\StoreListImporter;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesSpreadsheets;
use Tests\TestCase;

class StoreImportTest extends TestCase
{
    use MakesSpreadsheets;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        $this->admin = $this->makeUser(Role::ADMIN);
    }

    private function upload(array $rows): ImportBatch
    {
        $this->actingAs($this->admin)->post('/stores/import', ['file' => $this->xlsx($rows, 'stores.xlsx')])->assertRedirect();

        return ImportBatch::latest('id')->first();
    }

    private function map(ImportBatch $b): void
    {
        $this->actingAs($this->admin)->post("/stores/import/{$b->id}/mapping", ['columns' => ['name' => 'اسم المتجر', 'phone' => 'الهاتف', 'owner' => 'صاحب المتجر']])
            ->assertRedirect(route('stores.import.show', $b));
    }

    public function test_preview_classifies_rows(): void
    {
        Store::factory()->create(['name' => 'متجر الأمل', 'phone' => '0911111111', 'owner_name' => 'علي']);
        Store::factory()->create(['name' => 'متجر النجمة', 'phone' => '0922222222', 'owner_name' => 'سالم']);
        $b = $this->upload([
            ['اسم المتجر', 'الهاتف', 'صاحب المتجر'],
            ['متجر جديد', '0933333333', 'خالد'],
            ['متجر الأمل', '0944444444', null],
            ['متجر النجمة', '0922222222', 'سالم'],
            [null, '0955555555', 'x'],
        ]);
        $this->map($b);

        $p = app(StoreListImporter::class)->preview($b->fresh());
        $this->assertSame(['new' => 1, 'update' => 1, 'same' => 1, 'rejected' => 1], $p['counts']);
        $this->assertSame(['new', 'update', 'same', 'rejected'], array_column($p['rows'], 'action'));
        $this->assertSame('اسم المتجر فارغ', $p['rows'][3]['reason']);
        $this->actingAs($this->admin)->get("/stores/import/{$b->id}")->assertOk()->assertSee('متجر جديد');
    }

    public function test_apply_creates_and_updates_without_clearing(): void
    {
        $amal = Store::factory()->create(['name' => 'متجر الأمل', 'phone' => '0911111111', 'owner_name' => 'علي']);
        $b = $this->upload([['اسم المتجر', 'الهاتف', 'صاحب المتجر'], ['متجر جديد', '٠٩٣٣٣٣٣٣٣٣', 'خالد'], ['متجر الأمل', '0944444444', null]]);
        $this->map($b);
        $this->actingAs($this->admin)->post("/stores/import/{$b->id}/apply")->assertRedirect(route('stores.index'));

        $new = Store::where('name', 'متجر جديد')->sole();
        $this->assertSame('0933333333', $new->phone);
        $this->assertSame('active', $new->status->value);
        $this->assertNull($new->assigned_user_id);
        $amal->refresh();
        $this->assertSame('0944444444', $amal->phone);
        $this->assertSame('علي', $amal->owner_name, 'الخلية الفارغة لا تمسح القيمة');
        $this->assertSame('approved', $b->fresh()->status->value);
        $this->actingAs($this->admin)->post("/stores/import/{$b->id}/apply")->assertForbidden();
    }

    public function test_duplicate_spellings_in_file_rejected(): void
    {
        $b = $this->upload([['اسم المتجر', 'الهاتف', 'صاحب المتجر'], ['متجر الأمل', null, null], ['متجر الامل', null, null]]);
        $this->map($b);
        $p = app(StoreListImporter::class)->preview($b->fresh());
        $this->assertSame(['new', 'rejected'], array_column($p['rows'], 'action'));
        $this->assertSame('مكرر في الملف', $p['rows'][1]['reason']);
    }

    public function test_alias_match_updates_existing_store(): void
    {
        $s = Store::factory()->create(['name' => 'متجر الأمل']);
        StoreAlias::create(['store_id' => $s->id, 'alias' => 'الأمل فرع', 'normalized_alias' => 'الامل فرع']);
        $b = $this->upload([['اسم المتجر', 'الهاتف', 'صاحب المتجر'], ['الأمل فرع', '0912345678', null]]);
        $this->map($b);
        $this->assertSame($s->id, app(StoreListImporter::class)->preview($b->fresh())['rows'][0]['store_id']);
    }

    public function test_archived_store_updated_stays_archived(): void
    {
        $s = Store::factory()->create(['name' => 'متجر قديم', 'status' => 'archived']);
        $b = $this->upload([['اسم المتجر', 'الهاتف', 'صاحب المتجر'], ['متجر قديم', '0912345678', null]]);
        $this->map($b);
        $row = app(StoreListImporter::class)->preview($b->fresh())['rows'][0];
        $this->assertSame('update', $row['action']);
        $this->assertTrue($row['archived']);
        $this->actingAs($this->admin)->post("/stores/import/{$b->id}/apply");
        $this->assertSame('archived', $s->fresh()->status->value);
        $this->assertSame('0912345678', $s->fresh()->phone);
    }

    public function test_bad_phone_rejected(): void
    {
        $b = $this->upload([['اسم المتجر', 'الهاتف', 'صاحب المتجر'], ['متجر', 'abc', null]]);
        $this->map($b);
        $this->assertSame('رقم الهاتف غير صالح', app(StoreListImporter::class)->preview($b->fresh())['rows'][0]['reason']);
    }

    public function test_employee_forbidden(): void
    {
        $this->actingAs($this->makeUser())->get('/stores/import')->assertForbidden();
        $this->actingAs($this->makeUser())->post('/stores/import', ['file' => $this->xlsx([['a'], ['b']])])->assertForbidden();
    }

    public function test_saved_mapping_reused(): void
    {
        $b = $this->upload([['اسم المتجر', 'الهاتف', 'صاحب المتجر'], ['أ', null, null]]);
        $this->map($b);
        $this->actingAs($this->admin)->post("/stores/import/{$b->id}/apply");
        $this->assertSame('store_list', ImportMapping::sole()->layout);

        $b2 = $this->upload([['صاحب المتجر', 'اسم المتجر', 'الهاتف'], [null, 'ب', null]]);
        $this->assertNotNull(app(StoreListImporter::class)->preview($b2));
    }
}
