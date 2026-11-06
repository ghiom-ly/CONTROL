<?php

namespace App\Http\Controllers;

use App\Enums\ContactParty;
use App\Enums\ContactResult;
use App\Http\Requests\Contacts\LogContactRequest;
use App\Models\Shipment;
use App\Models\Store;
use App\Services\ContactService;
use Illuminate\Http\RedirectResponse;

class ContactController extends Controller
{
    public function __construct(private readonly ContactService $contacts) {}

    public function index(\Illuminate\Http\Request $request, \App\Services\ContactStatsService $stats): \Illuminate\View\View
    {
        abort_unless($request->user()->hasPermission('contacts.view'), 403);
        $f = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'user_id' => ['nullable', 'integer'], 'store_id' => ['nullable', 'integer'],
            'party_type' => ['nullable', \Illuminate\Validation\Rule::enum(ContactParty::class)],
            'result' => ['nullable', \Illuminate\Validation\Rule::enum(ContactResult::class)],
        ]);
        $from = isset($f['from']) ? \Illuminate\Support\Carbon::parse($f['from']) : today()->subDays(6);
        $to = isset($f['to']) ? \Illuminate\Support\Carbon::parse($f['to']) : today();

        $attempts = \App\Models\ContactAttempt::with(['user:id,name', 'store:id,name'])
            ->whereBetween('contacted_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when($f['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($f['store_id'] ?? null, fn ($q, $v) => $q->where('store_id', $v))
            ->when($f['party_type'] ?? null, fn ($q, $v) => $q->where('party_type', $v))
            ->when($f['result'] ?? null, fn ($q, $v) => $q->where('result', $v))
            ->latest('contacted_at')->paginate(50)->withQueryString();

        return view('contacts.index', [
            'attempts' => $attempts, 'filters' => $f, 'from' => $from, 'to' => $to,
            'employees' => $stats->perEmployee($from, $to), 'stores' => $stats->perStore($from, $to),
            'users' => \App\Models\User::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function storeForShipment(LogContactRequest $request, string $code): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('contacts.create'), 403);
        $shipment = Shipment::where('code', Shipment::normalizeCode($code))->firstOrFail();
        $data = $request->validated();

        $this->contacts->log($request->user(), ContactParty::from($data['party_type']), ContactResult::from($data['result']), $shipment, null, $data['notes'] ?? null);

        return redirect()->route('shipments.show', $shipment->code)->with('success', 'تم تسجيل الاتصال.');
    }

    public function storeForStore(LogContactRequest $request, Store $store): RedirectResponse
    {
        $this->authorize('update', $store);   // stores.manage — الاتصال بالمتجر مهمة الإدارة
        $data = $request->validated();

        $this->contacts->log($request->user(), ContactParty::Store, ContactResult::from($data['result']), null, $store, $data['notes'] ?? null);

        return back()->with('success', 'تم تسجيل الاتصال بالمتجر.');
    }
}
