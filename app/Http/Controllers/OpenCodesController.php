<?php

namespace App\Http\Controllers;

use App\Enums\StoreStatus;
use App\Models\Store;
use App\Models\User;
use App\Services\OpenCodesService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OpenCodesController extends Controller
{
    public function __construct(private readonly OpenCodesService $codes) {}

    public function index(Request $request): View
    {
        $this->authorize('shipments.view');

        $filters = $request->validate([
            'tab' => ['nullable', Rule::in(array_keys(OpenCodesService::TABS))],
            'store_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
        $tab = $filters['tab'] ?? 'customer';
        $viewer = $request->user();

        $shipments = $this->codes->query($tab, $filters, $viewer)
            ->with(['store:id,name,code', 'lastFollowupUser:id,name'])
            ->orderBy('store_id')->orderByDesc('last_followup_at')
            ->limit(2000)->get();

        return view('open-codes.index', [
            'tab' => $tab,
            'filters' => $filters,
            'groups' => $shipments->groupBy('store_id'),
            'counts' => collect(OpenCodesService::TABS)->map(fn ($r, $t) => $this->codes->query($t, $filters, $viewer)->count()),
            'stores' => Store::where('status', '!=', StoreStatus::Archived)->orderBy('name')->get(['id', 'name']),
            'users' => $viewer->hasPermission('reports.view_all') ? User::orderBy('name')->get(['id', 'name']) : collect(),
            'canMark' => $viewer->hasPermission('reports.review'),
        ]);
    }

    public function mark(Request $request): RedirectResponse
    {
        $this->authorize('reports.review');

        $data = $request->validate([
            'shipment_ids' => ['required', 'array', 'min:1', 'max:2000'],
            'shipment_ids.*' => ['integer', 'exists:shipments,id'],
            'action' => ['required', 'in:forwarded,resolved'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [], ['shipment_ids' => 'الأكواد', 'note' => 'الملاحظة']);

        $n = $this->codes->mark($data['shipment_ids'], $data['action'], $data['note'] ?? null, $request->user());

        return back()->with('success', "تم تحديث {$n} كود.");
    }
}
