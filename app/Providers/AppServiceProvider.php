<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Permissions;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // حماية من الإسناد الجماعي غير المقصود واكتشاف N+1 أثناء التطوير
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        Paginator::defaultView('components.pagination');

        // المدير العام يملك جميع الصلاحيات
        Gate::before(fn (User $user) => $user->isSuperAdmin() ? true : null);

        // كل صلاحية مسجلة تصبح Gate يمكن استخدامه عبر @can و authorize()
        foreach (array_keys(Permissions::all()) as $permission) {
            Gate::define($permission, fn (User $user) => $user->hasPermission($permission));
        }

        RateLimiter::for('login', fn ($request) => [
            Limit::perMinute(5)->by(mb_strtolower((string) $request->input('username')).'|'.$request->ip()),
            Limit::perMinute(20)->by($request->ip()),
        ]);
    }
}
