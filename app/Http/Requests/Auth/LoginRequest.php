<?php

namespace App\Http\Requests\Auth;

use App\Services\AuditLogger;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:60'],
            'password' => ['required', 'string', 'max:255'],
            'remember' => ['nullable', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['username' => 'اسم المستخدم', 'password' => 'كلمة المرور'];
    }

    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $credentials = ['username' => Str::lower(trim($this->input('username'))), 'password' => $this->input('password')];

        if (! Auth::attempt($credentials + ['status' => 'active'], $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey(), 60);
            AuditLogger::log('login_failed', 'محاولة دخول فاشلة باسم المستخدم: '.mb_substr($credentials['username'], 0, 60));

            throw ValidationException::withMessages([
                'username' => 'بيانات الدخول غير صحيحة أو الحساب غير نشط.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }
        event(new Lockout($this));
        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'username' => "محاولات كثيرة. حاول مرة أخرى بعد {$seconds} ثانية.",
        ]);
    }

    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower((string) $this->input('username')).'|'.$this->ip());
    }
}
