<?php

namespace Tests\Feature;

use App\Models\Role;
use Tests\TestCase;

class Phase4NavTest extends TestCase
{
    public function test_admin_sees_new_links(): void
    {
        $this->actingAs($this->makeUser(Role::ADMIN))->get('/')->assertOk()
            ->assertSee(route('stores.assignment'))->assertSee(route('attendance.index'));
    }

    public function test_employee_sees_none_of_them(): void
    {
        $this->actingAs($this->makeUser())->get('/')->assertOk()
            ->assertDontSee(route('stores.assignment'))->assertDontSee(route('attendance.index'))->assertDontSee(route('stores.import'));
    }
}
