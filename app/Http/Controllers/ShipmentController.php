<?php

namespace App\Http\Controllers;

use App\Models\Issue;
use App\Models\Shipment;
use App\Models\StoreMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ShipmentController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $this->authorize('lookup', Shipment::class);
        $code = Shipment::normalizeCode($request->validate(['code' => ['nullable', 'string', 'max:80']])['code'] ?? null);

        return $code ? redirect()->route('shipments.show', $code) : view('shipments.index');
    }

    public function show(Request $request, string $code): View
    {
        $this->authorize('lookup', Shipment::class);
        $code = Shipment::normalizeCode($code) ?? abort(404);
        $user = $request->user();
        $full = $user->can('viewHistory', Shipment::class);

        $shipment = Shipment::withTrashed()->with(['store:id,name,code', 'lastFollowupUser:id,name'])->where('code', $code)->first();

        $data = ['code' => $code, 'shipment' => $shipment, 'full' => $full, 'followups' => collect(), 'issues' => collect(), 'messages' => collect()];
        if ($shipment) {
            $contacts = $shipment->contactAttempts()->with('user:id,name')->latest('contacted_at');
            $data['contacts'] = ($full ? $contacts : $contacts->where('user_id', $user->id))->get();
            if ($full) {
                $data['followups'] = $shipment->followups()->whereHas('report')->with(['user:id,name', 'report:id,report_number,status'])->orderByDesc('followup_at')->get();
                $data['issues'] = Issue::where('shipment_id', $shipment->id)->latest('reported_at')->get();
                $data['messages'] = StoreMessage::with('sender:id,name')->where('store_id', $shipment->store_id)
                    ->whereJsonContains('meta->codes', $code)->latest()->get();
            }
        } else {
            $data['contacts'] = collect();
        }

        // سجل تأكيد الطلبيات لنفس الكود (تقرير التأكيد)
        $data['confirmations'] = \App\Models\ConfirmationOrder::with(['store:id,name', 'user:id,name', 'report:id,report_number,status,work_date'])
            ->where('code', $code)->whereHas('report')->latest('id')->limit(50)->get();

        return view('shipments.show', $data);
    }
}
