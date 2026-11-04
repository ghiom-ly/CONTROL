<?php

namespace App\Services\Reports;

use App\Enums\ReportStatus;
use App\Models\DailyReport;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShiftService
{
    /** بدء الشفت = إنشاء تقرير مسودة ببيانات البداية. شفت مفتوح واحد فقط لكل موظف. */
    public function start(User $user, int $totalOrders, float $deliveryRate): DailyReport
    {
        return DB::transaction(function () use ($user, $totalOrders, $deliveryRate) {
            User::whereKey($user->id)->lockForUpdate()->first();

            if (DailyReport::where('user_id', $user->id)->openDraft()->exists()) {
                throw ValidationException::withMessages(['shift' => 'لديك شفت مفتوح بالفعل.']);
            }

            return DailyReport::create([
                'work_date' => today(),
                'user_id' => $user->id,
                'status' => ReportStatus::Draft,
                'source' => 'manual',
                'shift_started_at' => now(),
                'start_total_orders' => $totalOrders,
                'start_delivery_rate' => $deliveryRate,
                'created_by' => $user->id,
            ]);
        });
    }
}
