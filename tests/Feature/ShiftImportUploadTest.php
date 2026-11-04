<?php

namespace Tests\Feature;

use App\Models\DailyReport;
use App\Models\ImportBatch;
use App\Models\ImportMapping;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\Imports\HeaderSignature;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesSpreadsheets;
use Tests\TestCase;

class ShiftImportUploadTest extends TestCase
{
    use MakesSpreadsheets;

    private User $emp;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        $this->emp = $this->makeUser();
        Store::factory()->create(['name' => 'متجر الأمل']);
    }

    private function sample(): UploadedFile
    {
        return $this->xlsx([
            ['الكود', 'المتجر', 'الحالة'],
            ['C1', 'متجر الأمل', 'تم التسليم'],
            ['C2', 'متجر الامل', 'جاري التوصيل'],
            ['C3', 'متجر مجهول', 'تم التسليم'],
        ]);
    }

    private function upload(array $extra = [], ?UploadedFile $file = null)
    {
        return $this->actingAs($this->emp)->post('/imports/shift', ['file' => $file ?? $this->sample(), 'kind' => 'shift_start'] + $extra);
    }

    private function mapping(ImportBatch $b, array $choices = [])
    {
        return $this->actingAs($this->emp)->post("/imports/{$b->id}/mapping", [
            'layout' => 'orders', 'columns' => ['store' => 'المتجر', 'status' => 'الحالة', 'code' => 'الكود'],
            'delivered_values' => ['تم التسليم'], 'store_choices' => $choices,
        ]);
    }

    public function test_upload_creates_batch_and_rows(): void
    {
        $this->upload()->assertRedirect();
        $b = ImportBatch::sole();
        $this->assertSame('shift_start', $b->report_type);
        $this->assertSame('xlsx', $b->file_type);
        $this->assertSame('uploaded', $b->status->value);
        $this->assertSame(3, $b->total_rows);
        $this->assertSame(3, $b->rows()->count());
        $this->assertSame(['الكود', 'المتجر', 'الحالة'], $b->meta['headers']);
        Storage::disk('private')->assertExists($b->stored_path);
        $this->actingAs($this->emp)->get("/imports/{$b->id}")->assertOk()->assertSee('مطابقة الأعمدة');
    }

    public function test_rejects_non_spreadsheet(): void
    {
        $this->upload([], UploadedFile::fake()->create('x.pdf', 5, 'application/pdf'))->assertSessionHasErrors('file');
        $this->assertSame(0, ImportBatch::count());
    }

    public function test_mapping_shows_preview_with_unmatched_store(): void
    {
        $this->upload();
        $b = ImportBatch::sole();
        $this->mapping($b)->assertRedirect(route('imports.show', $b));
        $this->actingAs($this->emp)->get("/imports/{$b->id}")->assertOk()
            ->assertSee('متجر مجهول')->assertSee('50%')->assertSee('تطبيق على التقرير');
    }

    public function test_unmatched_store_choice_kept(): void
    {
        $this->upload();
        $b = ImportBatch::sole();
        $amal = Store::where('name', 'متجر الأمل')->value('id');
        $this->mapping($b, ['متجر مجهول' => $amal])->assertSessionHasNoErrors();
        $this->assertSame((string) $amal, (string) $b->fresh()->meta['store_choices']['متجر مجهول']);
        $preview = app(\App\Services\Imports\ShiftFileImporter::class)->preview($b->fresh());
        $this->assertSame(3, $preview['total']);
        $this->assertSame([], $preview['unmatched']);
    }

    public function test_saved_mapping_auto_applied_by_signature(): void
    {
        ImportMapping::create(['signature' => HeaderSignature::of(['الحالة', 'الكود', 'المتجر']), 'layout' => 'orders',
            'columns' => ['store' => 'المتجر', 'status' => 'الحالة', 'code' => 'الكود'], 'delivered_values' => ['تم التسليم']]);
        $this->upload();
        $b = ImportBatch::sole();
        $this->assertSame('saved', $b->meta['mapping_source']);
        $this->assertNotNull(app(\App\Services\Imports\ShiftFileImporter::class)->preview($b));
    }

    public function test_same_file_twice_warns(): void
    {
        $r = DailyReport::factory()->draft()->for($this->emp)->create();
        $file = $this->sample();
        $copy = new UploadedFile($file->getRealPath(), 'again.xlsx', null, null, true);
        $this->upload(['report_id' => $r->id], $file);
        $this->upload(['report_id' => $r->id], $copy)->assertSessionHas('warning');
    }

    public function test_mapping_with_unknown_column_rejected(): void
    {
        $this->upload();
        $b = ImportBatch::sole();
        $this->actingAs($this->emp)->post("/imports/{$b->id}/mapping", [
            'layout' => 'orders', 'columns' => ['store' => 'غير موجود', 'status' => 'الحالة'],
        ])->assertSessionHasErrors('columns.store');
    }

    public function test_other_employee_cannot_view_batch(): void
    {
        $this->upload();
        $this->actingAs($this->makeUser())->get('/imports/'.ImportBatch::value('id'))->assertForbidden();
        $this->actingAs($this->makeUser(Role::ADMIN))->get('/imports/'.ImportBatch::value('id'))->assertOk();
    }

    public function test_end_file_requires_report(): void
    {
        $this->actingAs($this->emp)->post('/imports/shift', ['file' => $this->sample(), 'kind' => 'shift_end'])
            ->assertSessionHasErrors('report_id');
    }

    public function test_cannot_upload_to_others_report(): void
    {
        $r = DailyReport::factory()->draft()->for($this->makeUser())->create();
        $this->upload(['report_id' => $r->id])->assertForbidden();
    }

    public function test_start_without_report_attaches_to_open_draft(): void
    {
        $r = DailyReport::factory()->draft()->for($this->emp)->create();
        $this->upload();
        $this->assertSame($r->id, ImportBatch::sole()->daily_report_id);
    }

    public function test_viewer_cannot_upload(): void
    {
        $this->actingAs($this->makeUser(Role::VIEWER))->post('/imports/shift', ['file' => $this->sample(), 'kind' => 'shift_start'])
            ->assertForbidden();
    }

    public function test_cancel_marks_cancelled(): void
    {
        $this->upload();
        $b = ImportBatch::sole();
        $this->actingAs($this->emp)->post("/imports/{$b->id}/cancel")->assertRedirect();
        $this->assertSame('cancelled', $b->fresh()->status->value);
        $this->mapping($b)->assertForbidden();
    }
}
