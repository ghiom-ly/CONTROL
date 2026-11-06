<?php

namespace App\Services\Reports;

use App\Enums\ReportStatus;
use App\Models\DailyReport;
use App\Models\ReportStore;
use App\Models\Store;
use App\Models\User;
use App\Support\StartTotals;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShiftService
{
    /**
     * بدء الشفت = حضور: تقرير مسودة بطلبيات ونسبة تسليم كل متجر. شفت مفتوح واحد فقط لكل موظف.
     *
     * @param  list<array{store_id:int, start_orders:?int, start_rate:?float}>  $stores
     */
    public function start(User $user, array $stores, string $source = 'manual'): DailyReport
    {
        $stores = array_values(array_filter($stores, fn ($s) => ($s['start_orders'] ?? null) !== null || ($s['start_rate'] ?? null) !== null));
        $confirmation = $user->isConfirmation();
        if ($confirmation) {
            $stores = [];   // تقرير التأكيد: لا متاجر بداية
        } elseif ($stores === []) {
            throw ValidationException::withMessages(['stores' => 'أدخل طلبيات ونسبة تسليم متجر واحد على الأقل.']);
        }

        return DB::transaction(function () use ($user, $stores, $source, $confirmation) {
            User::whereKey($user->id)->lockForUpdate()->first();

            if (DailyReport::where('user_id', $user->id)->openDraft()->exists()) {
                throw ValidationException::withMessages(['shift' => 'لديك شفت مفتوح بالفعل.']);
            }

            $report = DailyReport::create([
                'work_date' => today(),
                'user_id' => $user->id,
                'status' => ReportStatus::Draft,
                'source' => 'manual',
                'kind' => $confirmation ? 'confirmation' : 'followup',
                'shift_started_at' => now(),
                'created_by' => $user->id,
            ]);

            $assigned = Store::whereIn('id', array_column($stores, 'store_id'))->pluck('assigned_user_id', 'id');
            foreach ($stores as $s) {
                ReportStore::create([
                    'daily_report_id' => $report->id,
                    'store_id' => $s['store_id'],
                    'is_outside_assignment' => ($assigned[$s['store_id']] ?? null) !== $user->id,
                    'start_orders' => $s['start_orders'] ?? null,
                    'start_rate' => $s['start_rate'] ?? null,
                    'start_source' => $source,
                ]);
            }
            StartTotals::refresh($report);

            return $report->fresh();
        });
    }
}
