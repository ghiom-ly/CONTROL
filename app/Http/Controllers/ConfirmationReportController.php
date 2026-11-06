<?php

namespace App\Http\Controllers;

use App\Enums\ReportStatus;
use App\Enums\StoreStatus;
use App\Http\Requests\Reports\SaveConfirmationRequest;
use App\Models\DailyReport;
use App\Models\Store;
use App\Services\Reports\ConfirmationDraftService;
use App\Services\Reports\ReportApprovalService;
use App\Services\Reports\ReportRevisionService;
use App\Services\Reports\ReportSubmissionService;
use App\Support\CancelReasons;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** تقرير تأكيد الطلبيات: التعديل والحفظ — نفس دورة التقرير العادي (تصحيح ثم اعتماد، تعديل إداري، إعادة تقديم) */
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
            'approveAfter' => $request->boolean('approve_after') && $report->status === ReportStatus::PendingReview,
        ]);
    }

    public function update(SaveConfirmationRequest $request, DailyReport $report, ConfirmationDraftService $drafts,
        ReportSubmissionService $submissions, ReportRevisionService $revisions, ReportApprovalService $approvals): RedirectResponse
    {
        abort_unless($report->isConfirmation(), 404);
        $actor = $request->user();
        $submit = $request->validated('action') === 'submit';
        $adminEdit = $report->status === ReportStatus::Approved;
        $correcting = $report->status === ReportStatus::PendingReview;
        $approveAfter = $adminEdit || ($correcting && $request->boolean('approve_after'));
        if ($submit) {
            $this->authorize('submit', $report);
        }
        if ($approveAfter) {
            if (! $adminEdit) {
                $this->authorize('review', $report);
            }
            abort_if($report->user_id === $actor->id, 403, 'لا يمكنك اعتماد تقريرك.');
        }

        DB::transaction(function () use ($request, $report, $drafts, $submissions, $revisions, $approvals, $actor, $submit, $adminEdit, $correcting, $approveAfter) {
            if ($adminEdit || $correcting) {
                $revisions->snapshot($report, $adminEdit ? 'تعديل إداري بعد الاعتماد' : 'تصحيح المسؤول', $actor);
                $report->increment('version');
            } elseif ($report->status === ReportStatus::Rejected) {
                $revisions->snapshotOnce($report, 'إعادة تقديم بعد الإرجاع', $actor);
            }
            $drafts->save($report, $request->validated(), $actor);
            if (($adminEdit || $approveAfter) && $report->confirmationOrders()->count() === 0) {
                throw ValidationException::withMessages(['orders' => 'التقرير المعتمد يجب أن يحتوي طلباً واحداً على الأقل.']);
            }
            if ($submit) {
                $submissions->submit($report, null, $actor);
            }
            if ($approveAfter) {
                $approvals->approve($report, $actor, null, [$adminEdit ? ReportStatus::Approved : ReportStatus::PendingReview]);
            }
        });

        return match (true) {
            $approveAfter => redirect()->route('reports.show', $report)->with('success', 'تم حفظ التعديلات واعتماد التقرير.'),
            $submit => redirect()->route('reports.show', $report)->with('success', 'تم حفظ التقرير وإرساله للمراجعة. انتهى الشفت.'),
            default => redirect()->route('reports.edit', $report)->with('success', 'تم حفظ التقرير.'),
        };
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
