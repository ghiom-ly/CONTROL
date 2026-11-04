<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** استخدام: ->middleware('permission:users.manage') أو 'permission:a|b' (أي منهما) */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        $user = $request->user();
        foreach (explode('|', $permissions) as $permission) {
            if ($user && $user->hasPermission($permission)) {
                return $next($request);
            }
        }
        abort(403, 'ليس لديك صلاحية للوصول إلى هذه الصفحة.');
    }
}
