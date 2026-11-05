<?php

namespace App\Http\Controllers;

use App\Enums\StoreStatus;
use App\Http\Requests\Stores\StoreRequest;
use App\Models\Store;
use App\Models\User;
use App\Queries\OpenCodesQuery;
use App\Services\StoreInsightsService;
use App\Services\StoreService;
use App\Support\ArabicNormalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class StoreController extends Controller
{
    public function __construct(private readonly StoreService $stores) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Store::class);

        $filters = $request->validate($this->filterRules() + [
            'per_page' => ['nullable', 'integer', Rule::in(self::PER_PAGE)],
        ]);

        $stores = $this->filtered($filters)
            ->with('assignedUser:id,name')
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? config('nours.per_page'))
            ->withQueryString();

        $counts = Store::query()->selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status');

        return view('stores.index', [
            'stores' => $stores,
            'filters' => $filters,
            'counts' => $counts,
            'employees' => $this->assignableEmployees(),
        ]);
    }

    public function store(StoreRequest $request): RedirectResponse
    {
        $store = $this->stores->create($request->validated(), $request->user());

        return redirect()->route('stores.show', $store)->with('success', "تم إضافة المتجر {$store->name} ({$store->code}).");
    }

    public function show(Store $store, StoreInsightsService $insights): View
    {
        $this->authorize('view', $store);
        $store->load('assignedUser:id,name');
        $today = today();

        return view('stores.show', [
            'store' => $store,
            'employees' => $this->assignableEmployees(),
            'insights' => [
                'daily' => $insights->dailyOrders($store, $today->copy()->subDays(13), $today),
                'week' => $insights->weekComparison($store, $today),
                'notes' => $insights->recentNotes($store),
                'openCodes' => OpenCodesQuery::forStore($store->id)->with('lastFollowupUser:id,name')
                    ->orderByDesc('last_followup_at')->limit(100)->get(),
            ],
        ]);
    }

    public function update(StoreRequest $request, Store $store): RedirectResponse
    {
        $this->stores->update($store, $request->validated(), $request->user());

        return back()->with('success', 'تم حفظ بيانات المتجر.');
    }

    public function archive(Request $request, Store $store): RedirectResponse
    {
        $this->authorize('archive', $store);
        $this->stores->archive($store, $request->user());

        return back()->with('success', 'تمت أرشفة المتجر مع الاحتفاظ بتقاريره.');
    }

    /** حذف جماعي للمتاجر المحددة، أو لكل المتاجر المطابقة للتصفية الحالية */
    public function bulkDestroy(Request $request): RedirectResponse
    {
        $this->authorize('bulkDelete', Store::class);

        $data = $request->validate($this->filterRules() + [
            'all_matching' => ['nullable', 'boolean'],
            'store_ids' => ['required_without:all_matching', 'array', 'max:2000'],
            'store_ids.*' => ['integer'],
        ], ['store_ids.required_without' => 'حدّد متجراً واحداً على الأقل.']);

        $ids = ($data['all_matching'] ?? false)
            ? $this->filtered($data)->pluck('id')
            : collect($data['store_ids']);

        $r = $this->stores->bulkDelete($ids, $request->user());

        $msg = "تم حذف {$r['deleted']} متجر.";
        if ($r['archived'] > 0) {
            $msg .= " وتمت أرشفة {$r['archived']} متجر لأن لها تقارير أو سجلات سابقة.";
        }

        return redirect()->route('stores.index', array_filter($request->only('q', 'status', 'assigned_user_id', 'per_page')))
            ->with('success', $msg);
    }

    private const PER_PAGE = [20, 50, 100, 200, 500];

    private function filterRules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(StoreStatus::class)],
            'assigned_user_id' => ['nullable', 'integer'],
        ];
    }

    private function filtered(array $filters)
    {
        return Store::query()
            ->when($filters['q'] ?? null, function ($q, $term) {
                $normalized = ArabicNormalizer::normalize($term);
                $q->where(fn ($w) => $w->where('name', 'like', "%{$term}%")
                    ->orWhere('normalized_name', 'like', "%{$normalized}%")
                    ->orWhere('code', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', '%'.ArabicNormalizer::digits($term).'%'));
            })
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['assigned_user_id'] ?? null, fn ($q, $u) => $q->where('assigned_user_id', $u));
    }

    /** الموظفون النشطون الذين يمكنهم إدخال التقارير */
    private function assignableEmployees()
    {
        return User::query()->with('role')->where('status', 'active')->orderBy('name')->get()
            ->filter(fn (User $u) => $u->hasPermission('reports.create'))->values();
    }
}
