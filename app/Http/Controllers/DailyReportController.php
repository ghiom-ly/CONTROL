<?php

namespace App\Http\Controllers;

use App\Enums\FollowupResult;
use App\Enums\IssueType;
use App\Enums\StoreStatus;
use App\Http\Requests\Reports\SaveReportRequest;
use App\Http\Requests\Reports\StartShiftRequest;
use App\Models\DailyReport;
use App\Models\Store;
use App\Services\Reports\ReportDraftService;
use App\Services\Reports\ShiftService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DailyReportController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        $this->authorize('create', DailyReport::class);

        $open = DailyReport::where('user_id', $request->user()->id)->openDraft()->latest('id')->first();
        if ($open) {
            return redirect()->route('reports.edit', $open);
        }

        return view('reports.start');
    }

    public function start(StartShiftRequest $request, ShiftService $shifts): RedirectResponse
    {
        $report = $shifts->start($request->user(), (int) $request->validated('start_total_orders'), (float) $request->validated('start_delivery_rate'));

        return redirect()->route('reports.edit', $report)->with('success', 'بدأ الشفت. أضف المتاجر وأكمل التقرير ثم أرسله في نهاية الشفت.');
    }

    public function edit(Request $request, DailyReport $report): View
    {
        $this->authorize('update', $report);
        $report->load(['user:id,name', 'stores.store:id,name,code,assigned_user_id', 'stores.followups']);

        $stores = Store::query()->where('status', '!=', StoreStatus::Archived)
            ->orderByRaw('assigned_user_id = ? desc', [$report->user_id])->orderBy('name')
            ->get(['id', 'name', 'code', 'assigned_user_id']);

        return view('reports.edit', [
            'report' => $report,
            'formState' => $this->formState($report, $request),
            'storeOptions' => $stores->map(fn ($s) => [
                'id' => $s->id, 'name' => $s->name, 'code' => $s->code, 'mine' => $s->assigned_user_id === $report->user_id,
            ])->values(),
            'resultOptions' => FollowupResult::options(),
            'issueTypes' => IssueType::options(),
            'maxAttempts' => ReportDraftService::maxAttempts(),
        ]);
    }

    public function update(SaveReportRequest $request, DailyReport $report, ReportDraftService $drafts): RedirectResponse
    {
        $result = $drafts->save($report, $request->validated(), $request->user());

        $redirect = redirect()->route('reports.edit', $report)->with('success', 'تم حفظ التقرير.');

        return $result['warnings'] ? $redirect->with('warning', implode("\n", $result['warnings'])) : $redirect;
    }

    /** حالة النموذج الأولية: المدخلات السابقة عند خطأ التحقق، وإلا بيانات التقرير المحفوظة */
    private function formState(DailyReport $report, Request $request): array
    {
        if ($request->session()->hasOldInput()) {
            return [
                'stores' => array_values(old('stores', [])),
                'unanswered' => old('unanswered', []),
                'attempt_times' => array_values(old('attempt_times', [])),
                'attempts_over_limit_reason' => old('attempts_over_limit_reason'),
                'notes' => old('notes'), 'inquiries' => old('inquiries'),
                'issue_requests' => array_values(old('issue_requests', [])),
            ];
        }

        return [
            'stores' => $report->stores->map(fn ($rs) => [
                'store_id' => $rs->store_id,
                'shipments_count' => $rs->shipments_count,
                'notes' => $rs->notes,
                'codes' => $rs->followups->map(fn ($f) => ['code' => $f->shipment_code, 'result' => $f->result->value])->values(),
            ])->values(),
            'unanswered' => [],
            'attempt_times' => $report->attempt_times ?? [],
            'attempts_over_limit_reason' => $report->attempts_over_limit_reason,
            'notes' => $report->notes, 'inquiries' => $report->inquiries,
            'issue_requests' => $report->issue_requests ?? [],
        ];
    }
}
