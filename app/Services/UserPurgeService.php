<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\DailyReport;
use App\Models\User;
use App\Services\Reports\ReportDeletionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * حذف نهائي لموظف مع كل بياناته من كل مكان (مثل موظف تجريبي) — للمدير العام فقط.
 * كله معاملة واحدة: إن فشل أي جزء لا يُحذف شيء. الملفات تُحذف من القرص بعد نجاح المعاملة فقط.
 */
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
        $files = [];   // [disk, path] تُحذف بعد نجاح المعاملة

        DB::transaction(function () use ($user, $actor, $name, $username, &$files) {
            $id = $user->id;
            User::withTrashed()->whereKey($id)->lockForUpdate()->first();

            foreach (DailyReport::withTrashed()->where('user_id', $id)->get() as $report) {
                foreach (Attachment::where('attachable_type', DailyReport::class)->where('attachable_id', $report->id)->get() as $a) {
                    $files[] = [$a->disk, $a->path];
                    $a->delete();
                }
                $this->reports->forceDelete($report, $actor);
            }

            DB::table('contact_attempts')->where('user_id', $id)->delete();
            DB::table('confirmation_orders')->where('user_id', $id)->delete();
            DB::table('shipment_followups')->where('user_id', $id)->delete();
            DB::table('issue_updates')->where('user_id', $id)->delete();
            $issueIds = DB::table('issues')->where('reported_by', $id)->pluck('id');
            DB::table('issue_updates')->whereIn('issue_id', $issueIds)->delete();
            DB::table('issues')->whereIn('id', $issueIds)->delete();
            foreach (DB::table('import_batches')->where('uploaded_by', $id)->get(['disk', 'stored_path']) as $b) {
                $files[] = [$b->disk ?: 'private', $b->stored_path];
            }
            DB::table('import_batches')->where('uploaded_by', $id)->delete();
            DB::table('leaves')->where('user_id', $id)->delete();
            DB::table('attendance_overrides')->where('user_id', $id)->delete();
            DB::table('alerts')->where('subject_type', 'user')->where('subject_id', $id)->delete();
            DB::table('notifications')->where('notifiable_type', User::class)->where('notifiable_id', $id)->delete();
            DB::table('stores')->where('assigned_user_id', $id)->update(['assigned_user_id' => null]);
            DB::table('sessions')->where('user_id', $id)->delete();
            User::withTrashed()->whereKey($id)->first()?->forceDelete();

            AuditLogger::log('user_purged', "حذف نهائي للموظف {$name} ({$username}) مع كل بياناته", null, [], [], $actor->id);
        });

        foreach ($files as [$disk, $path]) {
            if ($path) {
                Storage::disk($disk)->delete($path);
            }
        }
    }
}
