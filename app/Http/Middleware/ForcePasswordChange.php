<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** يلزم المستخدم بتغيير كلمة المرور المؤقتة قبل استخدام النظام */
class ForcePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $user->must_change_password && ! $request->routeIs('password.edit', 'password.update', 'logout')) {
            return redirect()->route('password.edit')->with('warning', 'يجب تغيير كلمة المرور المؤقتة قبل المتابعة.');
        }

        return $next($request);
    }
}
