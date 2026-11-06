<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use App\Support\Period;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardService $dashboard, \App\Services\AttendanceService $attendance): View
    {
        $period = Period::fromRequest($request, 'today');
        $user = $request->user();
        // الموظف يرى ملخص حضوره للشهر الحالي ضمن إحصائياته
        $isEmployee = $attendance->employees()->whereKey($user->id)->exists();

        return view('dashboard', [
            'period' => $period,
            'stats' => $dashboard->summary($period, $user),
            'myAttendance' => $isEmployee ? $attendance->month($user, \Carbon\CarbonImmutable::today()->startOfMonth())['summary'] : null,
            // الرسوم الستة لمن يرى التحليلات — بنفس فترة اللوحة مقابل السابقة لها
            'charts' => $user->hasPermission('analytics.view')
                ? app(\App\Services\Analytics\AnalyticsService::class)->charts(\App\Support\ComparePeriod::clamped($period->from, $period->to, $period->preset))
                : null,
        ]);
    }
}
