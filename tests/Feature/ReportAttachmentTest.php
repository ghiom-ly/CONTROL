<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\DailyReport;
use App\Models\Role;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReportAttachmentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
    }

    public function test_upload_image_to_own_draft(): void
    {
        $emp = $this->makeUser();
        $r = DailyReport::factory()->draft()->for($emp)->create();

        $this->actingAs($emp)->post("/reports/{$r->id}/attachments", [
            'category' => 'courier_screens',
            'files' => [UploadedFile::fake()->image('a.png'), UploadedFile::fake()->image('b.jpg')],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, $r->screens('courier_screens')->count());
        $a = Attachment::first();
        Storage::disk('private')->assertExists($a->path);
        $this->assertStringStartsWith('attachments/', $a->path);
        $this->assertSame(64, strlen($a->sha256));

        $this->actingAs($emp)->get("/attachments/{$a->id}")->assertOk();
    }

    public function test_non_image_rejected(): void
    {
        $emp = $this->makeUser();
        $r = DailyReport::factory()->draft()->for($emp)->create();
        $this->actingAs($emp)->post("/reports/{$r->id}/attachments", [
            'category' => 'courier_screens', 'files' => [UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')],
        ])->assertSessionHasErrors('files.0');
        $this->assertSame(0, Attachment::count());
    }

    public function test_invalid_category_rejected(): void
    {
        $emp = $this->makeUser();
        $r = DailyReport::factory()->draft()->for($emp)->create();
        $this->actingAs($emp)->post("/reports/{$r->id}/attachments", [
            'category' => 'other', 'files' => [UploadedFile::fake()->image('a.png')],
        ])->assertSessionHasErrors('category');
    }

    public function test_other_employee_cannot_download(): void
    {
        $emp = $this->makeUser();
        $r = DailyReport::factory()->draft()->for($emp)->create();
        $this->actingAs($emp)->post("/reports/{$r->id}/attachments", ['category' => 'customer_calls', 'files' => [UploadedFile::fake()->image('a.png')]]);
        $a = Attachment::sole();

        $this->actingAs($this->makeUser())->get("/attachments/{$a->id}")->assertForbidden();
        $this->actingAs($this->makeUser(Role::ADMIN))->get("/attachments/{$a->id}")->assertOk();
    }

    public function test_cannot_upload_to_pending_report_as_owner(): void
    {
        $emp = $this->makeUser();
        $r = DailyReport::factory()->pendingReview()->for($emp)->create();
        $this->actingAs($emp)->post("/reports/{$r->id}/attachments", ['category' => 'courier_screens', 'files' => [UploadedFile::fake()->image('a.png')]])
            ->assertForbidden();
    }

    public function test_owner_deletes_attachment_while_draft(): void
    {
        $emp = $this->makeUser();
        $r = DailyReport::factory()->draft()->for($emp)->create();
        $this->actingAs($emp)->post("/reports/{$r->id}/attachments", ['category' => 'courier_screens', 'files' => [UploadedFile::fake()->image('a.png')]]);
        $a = Attachment::sole();

        $this->actingAs($this->makeUser())->delete("/attachments/{$a->id}")->assertForbidden();
        $this->actingAs($emp)->delete("/attachments/{$a->id}")->assertRedirect();
        $this->assertSoftDeleted($a);
    }
}
