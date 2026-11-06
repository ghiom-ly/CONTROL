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
