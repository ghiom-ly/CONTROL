<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use App\Support\Period;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardService $dashboard): View
    {
        $period = Period::fromRequest($request, 'today');

        return view('dashboard', [
            'period' => $period,
            'stats' => $dashboard->summary($period, $request->user()),
        ]);
    }
}
