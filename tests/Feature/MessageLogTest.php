<?php

namespace Tests\Feature;

use App\Models\MessageTemplate;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreMessage;
use Tests\TestCase;

class MessageLogTest extends TestCase
{
    public function test_log_lists_and_filters(): void
    {
        $admin = $this->makeUser(Role::ADMIN);
        $a = Store::factory()->create(['name' => 'متجر السجل أ']);
        $b = Store::factory()->create(['name' => 'متجر السجل ب']);
        $t = MessageTemplate::first();
        foreach ([[$a, 'نص للمتجر أ'], [$b, 'نص للمتجر ب']] as [$s, $body]) {
            StoreMessage::create(['store_id' => $s->id, 'message_template_id' => $t->id, 'type' => $t->type, 'body' => $body, 'phone' => '218912345678', 'sent_by' => $admin->id]);
        }
        $this->actingAs($admin)->get('/messages/log')->assertOk()->assertSee('نص للمتجر أ')->assertSee('نص للمتجر ب');
        $this->actingAs($admin)->get('/messages/log?store_id='.$a->id)->assertSee('نص للمتجر أ')->assertDontSee('نص للمتجر ب');
        $this->actingAs($admin)->get('/messages/log?from='.today()->addDay()->toDateString())->assertDontSee('نص للمتجر أ');
    }

    public function test_nav_for_admin_not_employee(): void
    {
        $this->actingAs($this->makeUser(Role::ADMIN))->get('/')->assertSee(route('messages.templates.index'));
        $this->actingAs($this->makeUser())->get('/')->assertDontSee(route('messages.templates.index'));
        $this->actingAs($this->makeUser())->get('/messages/log')->assertForbidden();
    }
}
