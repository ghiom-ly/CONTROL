<?php

namespace App\Http\Controllers;

use App\Services\Analytics\AnalyticsService;
use App\Support\ComparePeriod;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AnalyticsController extends Controller
{
    public function index(Request $request, AnalyticsService $analytics): View
    {
        abort_unless($request->user()->hasPermission('analytics.view'), 403);
        $p = ComparePeriod::fromRequest($request);

        $declining = $analytics->decliningStores($p);
        $employees = $analytics->employees($p);
        $issues = $analytics->issueStats($p);

        return view('analytics.index', [
            'p' => $p,
            'compare' => $analytics->compare($p),
            'declining' => $declining,
            'rateDrops' => $analytics->rateDropStores($p),
            'stopped' => $analytics->stoppedStores($p),
            'employees' => $employees,
            'issues' => $issues,
            'charts' => $analytics->charts($p, $declining, $employees, $issues),
        ]);
    }
}
