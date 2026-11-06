<?php

namespace App\Http\Controllers;

use App\Enums\IssuePriority;
use App\Enums\IssueStatus;
use App\Enums\IssueType;
use App\Http\Requests\Issues\StoreIssueRequest;
use App\Http\Requests\Issues\UpdateIssueRequest;
use App\Models\Issue;
use App\Models\Shipment;
use App\Models\Store;
use App\Services\IssueService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class IssueController extends Controller
{
    public function __construct(private readonly IssueService $issues) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Issue::class);
        $f = $request->validate([
            'status' => ['nullable', Rule::enum(IssueStatus::class)], 'priority' => ['nullable', Rule::enum(IssuePriority::class)],
            'type' => ['nullable', Rule::enum(IssueType::class)], 'store_id' => ['nullable', 'integer'],
            'assigned_to' => ['nullable', 'integer'], 'overdue' => ['nullable', 'boolean'],
        ]);

        $issues = Issue::with(['store:id,name', 'assignee:id,name', 'reporter:id,name'])
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['priority'] ?? null, fn ($q, $v) => $q->where('priority', $v))
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($f['store_id'] ?? null, fn ($q, $v) => $q->where('store_id', $v))
            ->when($f['assigned_to'] ?? null, fn ($q, $v) => $q->where('assigned_to', $v))
            ->when($f['overdue'] ?? false, fn ($q) => $q->overdue())
            ->latest('reported_at')->paginate(30)->withQueryString();

        return view('issues.index', [
            'issues' => $issues, 'filters' => $f,
            'counts' => Issue::selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status'),
            'overdueCount' => Issue::overdue()->count(),
            'managers' => IssueService::managers(),
        ]);
    }

    public function show(Issue $issue): View
    {
        $this->authorize('view', $issue);
        $issue->load(['store:id,name', 'shipment:id,code', 'report:id,report_number', 'reporter:id,name', 'assignee:id,name',
            'updates' => fn ($q) => $q->with(['user:id,name', 'newAssignee:id,name'])->latest('id')]);

        return view('issues.show', ['issue' => $issue, 'managers' => IssueService::managers()]);
    }

    public function store(StoreIssueRequest $request, Store $store): RedirectResponse
    {
        $data = $request->validated();
        $shipmentId = filled($data['shipment_code'] ?? null)
            ? Shipment::where('store_id', $store->id)->where('code', Shipment::normalizeCode($data['shipment_code']))->value('id')
            : null;
        if (filled($data['shipment_code'] ?? null) && ! $shipmentId) {
            throw \Illuminate\Validation\ValidationException::withMessages(['shipment_code' => 'الكود غير موجود لهذا المتجر — اتركه فارغاً أو صحّحه.']);
        }
        unset($data['shipment_code']);

        $issue = $this->issues->create($data + ['store_id' => $store->id, 'shipment_id' => $shipmentId], $request->user());

        return redirect()->route('issues.show', $issue)->with('success', "تم فتح المشكلة {$issue->issue_number}.");
    }

    public function update(UpdateIssueRequest $request, Issue $issue): RedirectResponse
    {
        $this->issues->update($issue, $request->validated(), $request->user());

        return redirect()->route('issues.show', $issue)->with('success', 'تم حفظ التحديث.');
    }
}
