<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Tests\TestCase;

class InternationalPhoneTest extends TestCase
{
    public function test_store_accepts_any_international_format(): void
    {
        $boss = $this->makeUser(Role::SUPER_ADMIN);
        foreach (['+1 (555) 123.4567', '+966 (0) 50-123-4567', '00216 55 767 672', '+٢٠١١٤٨٠٥٢٤٠٤'] as $i => $phone) {
            $this->actingAs($boss)->post('/stores', ['name' => "متجر دولي {$i}", 'phone' => $phone, 'status' => 'active'])
                ->assertSessionHasNoErrors();
        }
        $this->assertSame('+201148052404', Store::where('name', 'متجر دولي 3')->value('phone'));
    }

    public function test_store_still_rejects_garbage(): void
    {
        $this->actingAs($this->makeUser(Role::SUPER_ADMIN))
            ->post('/stores', ['name' => 'متجر', 'phone' => 'abc-def', 'status' => 'active'])
            ->assertSessionHasErrors('phone');
    }

    public function test_user_phone_accepts_parentheses(): void
    {
        $boss = $this->makeUser(Role::SUPER_ADMIN);
        $u = $this->makeUser();
        $this->actingAs($boss)->put("/users/{$u->id}", ['name' => $u->name, 'username' => $u->username, 'role_id' => $u->role_id, 'status' => 'active', 'phone' => '+44 (0) 7911 123456'])
            ->assertSessionDoesntHaveErrors('phone');
    }
}
