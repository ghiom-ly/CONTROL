<?php

namespace App\Http\Controllers;

use App\Enums\ReportStatus;
use App\Models\DailyReport;
use App\Services\Analytics\AnalyticsService;
use App\Services\Reports\ReportApprovalService;
use App\Services\Reports\ReportDeletionService;
use App\Services\Reports\ReportRevisionService;
use App\Services\Reports\StoreMetricsBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** أدوات المدير العام على التقارير: وقت الشفت، الحذف والاستعادة */
class ReportAdminController extends Controller
{
    /** تعديل وقت بدء الشفت والانصراف — اليوم يتبع وقت البدء، والمقاييس تُعاد لليومين */
    public function shiftTime(Request $request, DailyReport $report, ReportApprovalService $approvals,
        StoreMetricsBuilder $metrics, ReportRevisionService $revisions): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $data = $request->validate([
            'shift_started_at' => ['required', 'date'],
            'first_submitted_at' => ['nullable', 'date', 'after_or_equal:shift_started_at'],
        ], ['first_submitted_at.after_or_equal' => 'وقت الانصراف لا يسبق وقت بدء الشفت.'], [
            'shift_started_at' => 'وقت بدء الشفت', 'first_submitted_at' => 'وقت الانصراف',
        ]);

        DB::transaction(function () use ($report, $data, $approvals, $metrics, $revisions, $request) {
            $before = $approvals->affectedKeys($report)['metric_keys'];
            $revisions->snapshot($report, 'تعديل وقت الشفت', $request->user());
            $report->increment('version');   // كل نسخة برقم جديد (فريد لكل تقرير)
            $start = CarbonImmutable::parse($data['shift_started_at']);
            $report->fill([
                'shift_started_at' => $start,
                'first_submitted_at' => isset($data['first_submitted_at']) ? CarbonImmutable::parse($data['first_submitted_at']) : $report->first_submitted_at,
                'work_date' => $start->toDateString(),
                'updated_by' => $request->user()->id,
            ])->save();

            if ($report->status === ReportStatus::Approved && ! $report->isConfirmation()) {
                $after = $approvals->affectedKeys($report->fresh())['metric_keys'];
                foreach (collect($before)->merge($after)->unique(fn ($k) => $k[0].'|'.$k[1]) as [$storeId, $date]) {
                    $metrics->rebuild($storeId, $date);
                }
            }
            DB::afterCommit(fn () => AnalyticsService::flush());
        });

        return back()->with('success', 'تم تعديل وقت الشفت — الحضور يُحسب حسب الوقت الجديد.');
    }

    public function destroy(Request $request, DailyReport $report, ReportDeletionService $deletion): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $deletion->delete($report, $request->user());

        return redirect()->route('reports.index')->with('success', "تم حذف التقرير {$report->report_number} — يمكنك استعادته من «المحذوفة».");
    }

    public function restore(Request $request, int $id, ReportDeletionService $deletion): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $report = DailyReport::onlyTrashed()->findOrFail($id);
        $deletion->restore($report, $request->user());

        return redirect()->route('reports.show', $report)->with('success', 'تمت استعادة التقرير.');
    }
}
