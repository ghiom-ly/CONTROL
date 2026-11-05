<?php

namespace Tests\Feature;

use App\Models\MessageTemplate;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Tests\TestCase;

class MessageTemplateTest extends TestCase
{
    private User $admin;

    private User $boss;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeUser(Role::ADMIN);
        $this->boss = $this->makeUser(Role::SUPER_ADMIN);
    }

    private function create(array $o = [])
    {
        return $this->actingAs($this->admin)->post('/messages/templates', array_merge(
            ['name' => 'تذكير', 'type' => 'general', 'body' => 'مرحبا {اسم_المتجر}'], $o));
    }

    public function test_admin_creates_draft_and_submits(): void
    {
        $this->create()->assertRedirect()->assertSessionHasNoErrors();
        $t = MessageTemplate::where('name', 'تذكير')->sole();
        $this->assertSame('draft', $t->status);
        $this->assertSame($this->admin->id, $t->created_by);
        $this->actingAs($this->admin)->post("/messages/templates/{$t->id}/submit")->assertRedirect();
        $this->assertSame('pending', $t->fresh()->status);
        $this->actingAs($this->admin)->get('/messages/templates')->assertOk()->assertSee('تذكير')->assertSee('بانتظار الاعتماد');
    }

    public function test_unknown_variable_rejected(): void
    {
        $this->create(['body' => 'الأكواد: {الأكواد} و{شيء}'])->assertSessionHasErrors('body');
        $this->assertSame(0, MessageTemplate::where('name', 'تذكير')->count());
    }

    public function test_super_admin_approves_and_version_increments(): void
    {
        $t = MessageTemplate::where('type', 'general')->first();
        $this->actingAs($this->boss)->post("/messages/templates/{$t->id}/approve")->assertRedirect();
        $t->refresh();
        $this->assertSame('approved', $t->status);
        $this->assertSame(1, $t->version);
        $this->assertSame($this->boss->id, $t->approved_by);
    }

    public function test_admin_cannot_approve(): void
    {
        $t = MessageTemplate::where('type', 'general')->first();
        $this->actingAs($this->admin)->post("/messages/templates/{$t->id}/approve")->assertForbidden();
        $this->assertSame('pending', $t->fresh()->status);
    }

    public function test_reject_requires_note_and_returns_to_admin(): void
    {
        $t = MessageTemplate::where('type', 'general')->first();
        $this->actingAs($this->boss)->post("/messages/templates/{$t->id}/reject", [])->assertSessionHasErrors('review_note');
        $this->actingAs($this->boss)->post("/messages/templates/{$t->id}/reject", ['review_note' => 'غيّر الصيغة'])->assertRedirect();
        $this->assertSame('rejected', $t->fresh()->status);
        $this->assertSame('غيّر الصيغة', $t->fresh()->review_note);
    }

    public function test_editing_approved_returns_to_draft(): void
    {
        $t = MessageTemplate::where('type', 'general')->first();
        $this->actingAs($this->boss)->post("/messages/templates/{$t->id}/approve");
        $this->actingAs($this->admin)->put("/messages/templates/{$t->id}", ['name' => $t->name, 'type' => 'general', 'body' => 'نص جديد {اسم_الشركة}'])
            ->assertSessionHasNoErrors();
        $this->assertSame('draft', $t->fresh()->status);
    }

    public function test_cannot_approve_draft(): void
    {
        $this->create();
        $t = MessageTemplate::where('name', 'تذكير')->sole();
        $this->actingAs($this->boss)->post("/messages/templates/{$t->id}/approve")->assertSessionHasErrors('status');
    }

    public function test_disable_and_enable(): void
    {
        $t = MessageTemplate::where('type', 'general')->first();
        $this->actingAs($this->boss)->post("/messages/templates/{$t->id}/approve");
        $this->actingAs($this->boss)->post("/messages/templates/{$t->id}/disable");
        $this->assertSame('disabled', $t->fresh()->status);
        $this->actingAs($this->boss)->post("/messages/templates/{$t->id}/enable");
        $this->assertSame('approved', $t->fresh()->status);
    }

    public function test_delete_only_draft_or_rejected(): void
    {
        $pending = MessageTemplate::where('type', 'general')->first();
        $this->actingAs($this->admin)->delete("/messages/templates/{$pending->id}")->assertForbidden();
        $this->create();
        $draft = MessageTemplate::where('name', 'تذكير')->sole();
        $this->actingAs($this->admin)->delete("/messages/templates/{$draft->id}")->assertRedirect();
        $this->assertSoftDeleted($draft);
    }

    public function test_preview_json(): void
    {
        $s = Store::factory()->create(['name' => 'متجر المعاينة']);
        $t = MessageTemplate::where('type', 'general')->first();
        $this->actingAs($this->admin)->post("/messages/templates/preview", ['type' => 'general', 'body' => 'أهلاً {اسم_المتجر} {غلط}', 'store_id' => $s->id])
            ->assertOk()->assertJson(['text' => 'أهلاً متجر المعاينة {غلط}', 'unknown' => ['غلط']]);
    }

    public function test_employee_forbidden(): void
    {
        $this->actingAs($this->makeUser())->get('/messages/templates')->assertForbidden();
    }
}
