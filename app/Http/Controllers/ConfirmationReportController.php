<?php

namespace App\Http\Controllers;

use App\Enums\ReportStatus;
use App\Enums\StoreStatus;
use App\Http\Requests\Reports\SaveConfirmationRequest;
use App\Models\DailyReport;
use App\Models\Store;
use App\Services\Reports\ConfirmationDraftService;
use App\Services\Reports\ReportRevisionService;
use App\Services\Reports\ReportSubmissionService;
use App\Support\CancelReasons;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** تقرير تأكيد الطلبيات: التعديل والحفظ (الإرسال والاعتماد مشتركة مع التقرير العادي) */
class ConfirmationReportController extends Controller
{
    public function edit(Request $request, DailyReport $report): View
    {
        $this->authorize('update', $report);
        abort_unless($report->isConfirmation(), 404);
        $report->load(['user:id,name', 'confirmationOrders']);

        return view('reports.edit-confirmation', [
            'report' => $report,
            'state' => $this->state($report, $request),
            'storeOptions' => Store::where('status', '!=', StoreStatus::Archived)->orderBy('name')->get(['id', 'name', 'code'])
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'code' => $s->code])->values(),
            'reasons' => [...CancelReasons::all(), CancelReasons::OTHER],
        ]);
    }

    public function update(SaveConfirmationRequest $request, DailyReport $report, ConfirmationDraftService $drafts,
        ReportSubmissionService $submissions, ReportRevisionService $revisions): RedirectResponse
    {
        abort_unless($report->isConfirmation(), 404);
        $actor = $request->user();
        $submit = $request->validated('action') === 'submit';
        if ($submit) {
            $this->authorize('submit', $report);
        }

        DB::transaction(function () use ($request, $report, $drafts, $submissions, $revisions, $actor, $submit) {
            if ($report->status === ReportStatus::Rejected) {
                $revisions->snapshotOnce($report, 'إعادة تقديم بعد الإرجاع', $actor);
            }
            $drafts->save($report, $request->validated(), $actor);
            if ($submit) {
                $submissions->submit($report, null, $actor);
            }
        });

        return $submit
            ? redirect()->route('reports.show', $report)->with('success', 'تم حفظ التقرير وإرساله للمراجعة. انتهى الشفت.')
            : redirect()->route('reports.edit', $report)->with('success', 'تم حفظ التقرير.');
    }

    /** حالة النموذج: المدخلات السابقة عند خطأ التحقق، وإلا الطلبات المحفوظة مجمّعة بالمتجر */
    private function state(DailyReport $report, Request $request): array
    {
        if ($request->session()->hasOldInput()) {
            return ['stores' => array_values(old('stores', [])), 'attempt_times' => array_values(old('attempt_times', [])), 'notes' => old('notes')];
        }
        $byStore = $report->confirmationOrders->groupBy('store_id');

        return [
            'stores' => $byStore->map(fn ($orders, $sid) => [
                'store_id' => (int) $sid,
                'confirmed' => $orders->where('result.value', 'confirmed')->pluck('code')->implode("\n"),
                'no_answer' => $orders->where('result.value', 'no_answer')->pluck('code')->implode("\n"),
                'cancelled' => $orders->where('result.value', 'cancelled')->map(function ($o) {
                    $isOther = str_starts_with((string) $o->cancel_reason, CancelReasons::OTHER.': ');

                    return ['code' => $o->code, 'reason' => $isOther ? CancelReasons::OTHER : $o->cancel_reason,
                        'other' => $isOther ? mb_substr($o->cancel_reason, mb_strlen(CancelReasons::OTHER) + 2) : ''];
                })->values()->all(),
            ])->values()->all(),
            'attempt_times' => $report->attempt_times ?? [],
            'notes' => $report->notes,
        ];
    }
}
