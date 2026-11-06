<?php

namespace App\Services;

use App\Enums\ContactParty;
use App\Enums\ContactResult;
use App\Models\ContactAttempt;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ContactService
{
    /** تسجيل اتصال يدوي. رقم المحاولة: لنفس المستخدم والكود والطرف، وللمتجر: لنفس المستخدم والمتجر في اليوم */
    public function log(User $actor, ContactParty $party, ContactResult $result, ?Shipment $shipment, ?Store $store, ?string $notes): ContactAttempt
    {
        return DB::transaction(function () use ($actor, $party, $result, $shipment, $store, $notes) {
            $storeId = $shipment?->store_id ?? $store?->id;
            $previous = ContactAttempt::where('user_id', $actor->id)->where('party_type', $party)
                ->when($shipment, fn ($q) => $q->where('shipment_code', $shipment->code),
                    fn ($q) => $q->where('store_id', $storeId)->whereDate('contacted_at', today()))
                ->lockForUpdate()->count();

            return ContactAttempt::create([
                'party_type' => $party, 'store_id' => $storeId, 'shipment_id' => $shipment?->id, 'shipment_code' => $shipment?->code,
                'user_id' => $actor->id, 'contacted_at' => now(), 'attempt_number' => $previous + 1,
                'result' => $result, 'notes' => $notes,
            ]);
        });
    }
}
