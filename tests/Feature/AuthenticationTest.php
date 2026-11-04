<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
        $this->get('/users')->assertRedirect(route('login'));
    }

    public function test_user_can_login_with_username_and_login_is_audited(): void
    {
        $user = $this->makeUser(Role::EMPLOYEE, ['username' => 'ahmed', 'password' => 'Secret123']);

        $this->post('/login', ['username' => 'AHMED', 'password' => 'Secret123'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertDatabaseHas('audit_logs', ['event' => 'login', 'user_id' => $user->id]);
    }

    public function test_wrong_password_fails_and_is_logged(): void
    {
        $this->makeUser(Role::EMPLOYEE, ['username' => 'ahmed', 'password' => 'Secret123']);
        $this->post('/login', ['username' => 'ahmed', 'password' => 'wrong'])->assertSessionHasErrors('username');
        $this->assertGuest();
        $this->assertSame(1, AuditLog::where('event', 'login_failed')->count());
    }

    public function test_inactive_user_cannot_login(): void
    {
        $this->makeUser(Role::EMPLOYEE, ['username' => 'off', 'password' => 'Secret123', 'status' => 'inactive']);
        $this->post('/login', ['username' => 'off', 'password' => 'Secret123'])->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_login_is_rate_limited(): void
    {
        $this->makeUser(Role::EMPLOYEE, ['username' => 'ahmed', 'password' => 'Secret123']);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['username' => 'ahmed', 'password' => 'bad']);
        }
        // حتى بكلمة المرور الصحيحة يُرفض الطلب (429 من middleware أو رسالة خطأ)
        $response = $this->post('/login', ['username' => 'ahmed', 'password' => 'Secret123']);
        $this->assertTrue($response->status() === 429 || session()->has('errors'));
        $this->assertGuest();
    }

    public function test_user_deactivated_during_session_is_logged_out(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user)->get('/')->assertOk();
        $user->update(['status' => 'suspended']);
        $this->actingAs($user->fresh())->get('/')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_temporary_password_must_be_changed_first(): void
    {
        $user = $this->makeUser(Role::EMPLOYEE, ['password' => 'Temp12345', 'must_change_password' => true]);
        $this->actingAs($user)->get('/')->assertRedirect(route('password.edit'));

        $this->put('/password', [
            'current_password' => 'Temp12345', 'password' => 'Fresh12345', 'password_confirmation' => 'Fresh12345',
        ])->assertRedirect(route('dashboard'));

        $this->assertFalse($user->fresh()->must_change_password);
        $this->get('/')->assertOk();
    }

    public function test_password_is_never_stored_in_plain_text(): void
    {
        $user = $this->makeUser(Role::EMPLOYEE, ['password' => 'Secret123']);
        $this->assertNotSame('Secret123', $user->getRawOriginal('password'));
        $this->assertTrue(password_verify('Secret123', $user->getRawOriginal('password')));
        $this->assertStringNotContainsString('Secret123', (string) AuditLog::pluck('new_values')->implode(' '));
    }

    public function test_logout(): void
    {
        $this->actingAs($this->makeUser())->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
