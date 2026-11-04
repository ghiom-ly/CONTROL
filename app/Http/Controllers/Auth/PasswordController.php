<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class PasswordController extends Controller
{
    public function edit(): View
    {
        return view('auth.change-password');
    }

    public function update(ChangePasswordRequest $request): RedirectResponse
    {
        $user = $request->user();
        $user->forceFill(['password' => $request->validated('password'), 'must_change_password' => false])->save();
        Auth::logoutOtherDevices($request->validated('password'));
        $request->session()->regenerate();
        AuditLogger::log('password_reset', 'تغيير كلمة المرور الشخصية', $user);

        return redirect()->route('dashboard')->with('success', 'تم تغيير كلمة المرور بنجاح.');
    }
}
