<?php

namespace App\Services;

use App\Models\DailyReport;
use App\Models\User;
use App\Services\Reports\ReportDeletionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** حذف نهائي لموظف مع كل بياناته من كل مكان (مثل موظف تجريبي) — للمدير العام فقط */
class UserPurgeService
{
    public function __construct(private readonly ReportDeletionService $reports) {}

    public function purge(User $user, User $actor): void
    {
        if (DB::table('store_messages')->where('sent_by', $user->id)->exists()) {
            throw ValidationException::withMessages(['confirm' => 'هذا الحساب أرسل رسائل لمتاجر — لا يمكن حذفه نهائياً. استخدم الحذف العادي.']);
        }
        $name = $user->name;
        $username = $user->username;

        foreach (DailyReport::withTrashed()->where('user_id', $user->id)->get() as $report) {
            $this->reports->forceDelete($report, $actor);
        }

        DB::transaction(function () use ($user) {
            $id = $user->id;
            DB::table('contact_attempts')->where('user_id', $id)->delete();
            DB::table('confirmation_orders')->where('user_id', $id)->delete();
            DB::table('shipment_followups')->where('user_id', $id)->delete();
            DB::table('issue_updates')->where('user_id', $id)->delete();
            $issueIds = DB::table('issues')->where('reported_by', $id)->pluck('id');
            DB::table('issue_updates')->whereIn('issue_id', $issueIds)->delete();
            DB::table('issues')->whereIn('id', $issueIds)->delete();
            DB::table('import_batches')->where('uploaded_by', $id)->delete();
            DB::table('leaves')->where('user_id', $id)->delete();
            DB::table('attendance_overrides')->where('user_id', $id)->delete();
            DB::table('notifications')->where('notifiable_type', User::class)->where('notifiable_id', $id)->delete();
            DB::table('stores')->where('assigned_user_id', $id)->update(['assigned_user_id' => null]);
            DB::table('sessions')->where('user_id', $id)->delete();
            User::withTrashed()->whereKey($id)->first()?->forceDelete();
        });

        AuditLogger::log('user_purged', "حذف نهائي للموظف {$name} ({$username}) مع كل بياناته", null, [], [], $actor->id);
    }
}
