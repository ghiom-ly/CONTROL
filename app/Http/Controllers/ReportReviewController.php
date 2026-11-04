<?php

namespace App\Http\Controllers;

use App\Enums\ReportStatus;
use App\Models\DailyReport;
use App\Services\Reports\ReportApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReportReviewController extends Controller
{
    public function __construct(private readonly ReportApprovalService $approvals) {}

    public function queue(Request $request): View
    {
        $this->authorize('reports.review');

        $reports = DailyReport::query()->with('user:id,name')->withCount('stores')
            ->where('status', ReportStatus::PendingReview)
            ->orderBy('submitted_at')->paginate(config('nours.per_page'));

        return view('reports.review-queue', ['reports' => $reports]);
    }

    public function approve(Request $request, DailyReport $report): RedirectResponse
    {
        $this->authorize('review', $report);
        abort_if($report->user_id === $request->user()->id, 403, 'لا يمكنك مراجعة تقريرك.');
        $this->approvals->ensurePending($report);
        $this->approvals->approve($report, $request->user());

        return redirect()->route('reports.show', $report)->with('success', 'تم اعتماد التقرير وأصبحت بياناته رسمية.');
    }

    public function reject(Request $request, DailyReport $report): RedirectResponse
    {
        $this->authorize('review', $report);
        abort_if($report->user_id === $request->user()->id, 403, 'لا يمكنك مراجعة تقريرك.');
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:1000']], [], ['rejection_reason' => 'سبب الإرجاع']);
        $this->approvals->reject($report, $data['rejection_reason'], $request->user());

        return redirect()->route('reports.review.queue')->with('success', 'تم إرجاع التقرير للموظف مع السبب.');
    }
}
