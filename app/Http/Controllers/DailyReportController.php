<?php

namespace App\Http\Controllers;

use App\Enums\FollowupResult;
use App\Enums\IssueType;
use App\Enums\StoreStatus;
use App\Http\Requests\Reports\SaveReportRequest;
use App\Http\Requests\Reports\StartShiftRequest;
use App\Http\Requests\Reports\SubmitReportRequest;
use App\Models\DailyReport;
use App\Models\Store;
use App\Enums\ReportStatus;
use App\Services\Reports\ReportApprovalService;
use App\Services\Reports\ReportDraftService;
use App\Services\Reports\ReportRevisionService;
use App\Services\Reports\ReportSubmissionService;
use App\Services\Reports\ShiftService;
use App\Support\ReportWhatsappFormatter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DailyReportController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', DailyReport::class);
        $user = $request->user();
        $all = $user->hasPermission('reports.view_all');

        $filters = $request->validate([
            'status' => ['nullable', \Illuminate\Validation\Rule::enum(ReportStatus::class)],
            'user_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'trashed' => ['nullable', 'boolean'],
        ]);
        $trashed = ($filters['trashed'] ?? false) && $user->isSuperAdmin();   // «المحذوفة» للمدير العام فقط

        $reports = DailyReport::query()->with('user:id,name')->withCount('stores')
            ->when($trashed, fn ($q) => $q->onlyTrashed())
            ->when(! $all, fn ($q) => $q->where('user_id', $user->id))
            ->when($all && ($filters['user_id'] ?? null), fn ($q) => $q->where('user_id', $filters['user_id']))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->whereDate('work_date', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->whereDate('work_date', '<=', $d))
            ->orderByDesc('work_date')->orderByDesc('id')
            ->paginate(config('nours.per_page'))->withQueryString();

        return view('reports.index', [
            'reports' => $reports,
            'filters' => $filters,
            'all' => $all, 'trashed' => $trashed,
            'users' => $all ? \App\Models\User::orderBy('name')->get(['id', 'name']) : collect(),
            'openDraft' => DailyReport::where('user_id', $user->id)->openDraft()->latest('id')->first(),
        ]);
    }

    public function create(Request $request): View|RedirectResponse
    {
        $this->authorize('create', DailyReport::class);

        $open = DailyReport::where('user_id', $request->user()->id)->openDraft()->latest('id')->first();
        if ($open) {
            return redirect()->route('reports.edit', $open);
        }

        if ($request->user()->isConfirmation()) {
            return view('reports.start-confirmation');
        }

        $active = fn ($q) => $q->where('status', '!=', StoreStatus::Archived)->whereIn('service_type', ['both', 'followup']);

        return view('reports.start', [
            'myStores' => Store::where('assigned_user_id', $request->user()->id)->where($active)->orderBy('name')->get(['id', 'name', 'code']),
            'otherStores' => Store::where(fn ($q) => $q->whereNull('assigned_user_id')->orWhere('assigned_user_id', '!=', $request->user()->id))
                ->where($active)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function start(StartShiftRequest $request, ShiftService $shifts): RedirectResponse
    {
        $report = $shifts->start($request->user(), array_map(fn ($r) => [
            'store_id' => (int) $r['store_id'],
            'start_orders' => $r['start_orders'] === null ? null : (int) $r['start_orders'],
            'start_rate' => $r['start_rate'] === null ? null : (float) $r['start_rate'],
        ], $request->validated('stores') ?? []));

        return redirect()->route('reports.edit', $report)->with('success', 'بدأ الشفت. أضف المتاجر وأكمل التقرير ثم أرسله في نهاية الشفت.');
    }

    public function edit(Request $request, DailyReport $report): View
    {
        $this->authorize('update', $report);
        if ($report->isConfirmation()) {
            return app(ConfirmationReportController::class)->edit($request, $report);
        }
        $report->load(['user:id,name', 'stores.store:id,name,code,assigned_user_id', 'stores.followups']);

        $stores = Store::query()->where('status', '!=', StoreStatus::Archived)->whereIn('service_type', ['both', 'followup'])
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

    public function update(
        SaveReportRequest $request, DailyReport $report, ReportDraftService $drafts,
        ReportSubmissionService $submissions, ReportRevisionService $revisions, ReportApprovalService $approvals,
        \App\Services\AttachmentService $attachments,
    ): RedirectResponse {
        abort_if($report->isConfirmation(), 404);   // تقرير التأكيد يُحفظ من مساره الخاص
        $actor = $request->user();
        $submit = $request->validated('action') === 'submit';
        $adminEdit = $report->status === ReportStatus::Approved;
        $correcting = $report->status === ReportStatus::PendingReview;   // policy: مراجِع وليس صاحب التقرير
        $approveAfter = $adminEdit || ($correcting && $request->boolean('approve_after'));
        if ($submit) {
            $this->authorize('submit', $report);
        }
        if ($approveAfter) {
            if (! $adminEdit) {
                $this->authorize('review', $report);
            }
            // لا أحد يعتمد تقريره بنفسه — ولا عبر التعديل الإداري
            abort_if($report->user_id === $actor->id, 403, 'لا يمكنك اعتماد تقريرك.');
        }

        $warnings = DB::transaction(function () use ($request, $report, $drafts, $submissions, $revisions, $approvals, $attachments, $actor, $submit, $adminEdit, $correcting, $approveAfter) {
            $previous = $adminEdit ? $approvals->affectedKeys($report) : null;
            if ($adminEdit || $correcting) {
                $revisions->snapshot($report, $adminEdit ? 'تعديل إداري بعد الاعتماد' : 'تصحيح المسؤول', $actor);
                $report->increment('version');
            } elseif ($report->status === ReportStatus::Rejected) {
                // حفظ النسخة التي رُفضت قبل أول تعديل عليها
                $revisions->snapshotOnce($report, 'إعادة تقديم بعد الإرجاع', $actor);
            }

            $w = $drafts->save($report, $request->validated(), $actor)['warnings'];
            foreach ($request->file('screens', []) as $category => $files) {
                foreach ($files as $file) {
                    $attachments->storeForReport($report, $file, $category, $actor);
                }
            }
            if ($submit) {
                $w = array_merge($w, $submissions->submit($report, self::rate($request->validated('end_delivery_rate')), $actor)['warnings']);
            }
            if ($approveAfter) {
                $approvals->approve($report, $actor, $previous, [$adminEdit ? ReportStatus::Approved : ReportStatus::PendingReview]);
            }

            return $w;
        });

        $redirect = match (true) {
            $approveAfter => redirect()->route('reports.show', $report)->with('success', 'تم حفظ التعديلات واعتماد التقرير.'),
            $submit => redirect()->route('reports.show', $report)->with('success', 'تم حفظ التقرير وإرساله للمراجعة. انتهى الشفت.'),
            default => redirect()->route('reports.edit', $report)->with('success', 'تم حفظ التقرير.'),
        };

        return $warnings ? $redirect->with('warning', implode("\n", $warnings)) : $redirect;
    }

    public function show(DailyReport $report): View
    {
        $this->authorize('view', $report);
        if ($report->isConfirmation()) {
            $report->load(['user:id,name', 'confirmationOrders.store:id,name']);

            return view('reports.show-confirmation', ['report' => $report, 'whatsapp' => \App\Support\ConfirmationWhatsappFormatter::format($report)]);
        }
        $report->load(['user:id,name', 'stores.store:id,name,code', 'followups.store:id,name', 'revisions.changer:id,name', 'approver:id,name', 'reviewer:id,name']);

        return view('reports.show', [
            'report' => $report,
            'flags' => $report->warningFlags(),
            'whatsapp' => ReportWhatsappFormatter::format($report),
        ]);
    }

    public function submit(SubmitReportRequest $request, DailyReport $report, ReportSubmissionService $submissions): RedirectResponse
    {
        $result = $submissions->submit($report, self::rate($request->validated('end_delivery_rate')), $request->user());

        $redirect = redirect()->route('reports.show', $report)->with('success', 'تم إرسال التقرير للمراجعة. انتهى الشفت.');

        return $result['warnings'] ? $redirect->with('warning', implode("\n", $result['warnings'])) : $redirect;
    }

    /** نسبة مُدخلة أو null (التقارير القديمة بلا نسب) */
    private static function rate(mixed $v): ?float
    {
        return $v === null || $v === '' ? null : (float) $v;
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
                'start_orders' => $rs->start_orders, 'start_rate' => $rs->start_rate, 'start_source' => $rs->start_source,
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
