<?php

namespace App\Http\Controllers;

use App\Services\Analytics\AnalyticsService;
use App\Support\ComparePeriod;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AnalyticsController extends Controller
{
    public function index(Request $request, AnalyticsService $analytics): View|\Illuminate\Http\RedirectResponse
    {
        abort_unless($request->user()->hasPermission('analytics.view'), 403);
        try {
            $p = ComparePeriod::fromRequest($request);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // العودة للصفحة بلا معاملات (لا حلقة إعادة توجيه) مع الخطأ
            return redirect()->route('analytics.index')->withErrors($e->errors());
        }

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
            'confirmation' => [
                'employees' => app(\App\Services\Analytics\ConfirmationStatsService::class)->perEmployee($p),
                'stores' => app(\App\Services\Analytics\ConfirmationStatsService::class)->perStore($p, 10),
                'reasons' => app(\App\Services\Analytics\ConfirmationStatsService::class)->reasons($p),
            ],
            'charts' => $analytics->charts($p, $declining, $employees, $issues),
        ]);
    }
}
