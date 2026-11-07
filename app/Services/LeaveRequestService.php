<?php

namespace App\Services;

use App\Models\AttendanceOverride;
use App\Models\Leave;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\LeaveRequestNotification;
use App\Services\Analytics\AnalyticsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaveRequestService
{
    /** من يقرّر الطلبات: كل نشط لديه إدارة الحضور (المسؤول والمدير العام) */
    public static function managers()
    {
        return User::where('status', 'active')->whereHas('role', fn ($r) => $r->where('name', \App\Models\Role::SUPER_ADMIN)
            ->orWhereHas('permissions', fn ($p) => $p->where('name', 'attendance.manage')))->get(['id', 'name', 'role_id']);
    }

    public function approve(LeaveRequest $request, User $actor, ?string $note): LeaveRequest
    {
        return $this->decide($request, $actor, function (LeaveRequest $r) use ($actor, $note) {
            if ($r->kind === 'leave') {
                // إجازة يدوية تغطي كامل المدة ⇒ تُستخدم هي؛ تداخل جزئي ⇒ يُرفض لتعديلها يدوياً
                $existing = Leave::where('user_id', $r->user_id)->where('from_date', '<=', $r->to_date->toDateString())
                    ->where('to_date', '>=', $r->from_date->toDateString())->orderBy('id')->get();
                $covering = $existing->first(fn ($l) => $l->from_date->lte($r->from_date) && $l->to_date->gte($r->to_date));
                if (! $covering && $existing->isNotEmpty()) {
                    throw ValidationException::withMessages(['request' => 'توجد إجازة مسجلة تتداخل مع هذه الأيام — عدّلها من صفحة الحضور أولاً.']);
                }
                $r->leave_id = $covering?->id ?? Leave::create(['user_id' => $r->user_id, 'from_date' => $r->from_date, 'to_date' => $r->to_date,
                    'type' => $r->leave_type ?? 'leave', 'notes' => 'طلب #'.$r->id.': '.$r->reason, 'created_by' => $actor->id])->id;
            } else {
                $type = $r->kind === 'absence_excuse' ? 'excused' : 'late_excused';
                if ($type === 'excused') {   // «بعذر» و«حاضر» لا يجتمعان
                    AttendanceOverride::where('user_id', $r->user_id)->whereDate('date', $r->from_date)->where('type', 'present')->delete();
                }
                $o = AttendanceOverride::updateOrCreate(['user_id' => $r->user_id, 'date' => $r->from_date->toDateString(), 'type' => $type],
                    ['reason' => mb_substr('طلب #'.$r->id.': '.$r->reason, 0, 255), 'created_by' => $actor->id]);
                $r->override_id = $o->id;
            }
            $r->fill(['status' => 'approved', 'decision_note' => $note]);
        });
    }

    public function reject(LeaveRequest $request, User $actor, string $note): LeaveRequest
    {
        return $this->decide($request, $actor, fn (LeaveRequest $r) => $r->fill(['status' => 'rejected', 'decision_note' => $note]));
    }

    private function decide(LeaveRequest $request, User $actor, \Closure $apply): LeaveRequest
    {
        $r = DB::transaction(function () use ($request, $actor, $apply) {
            $r = LeaveRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if (! $r->isPending()) {
                throw ValidationException::withMessages(['request' => 'هذا الطلب تم البت فيه مسبقاً.']);
            }
            if ($r->user_id === $actor->id) {
                throw ValidationException::withMessages(['request' => 'لا يمكنك البت في طلبك بنفسك.']);
            }
            $apply($r);
            $r->fill(['decided_by' => $actor->id, 'decided_at' => now()])->save();
            AuditLogger::log('leave_request_'.$r->status, ($r->status === 'approved' ? 'قبول' : 'رفض').' طلب '.$r->summary().' — '.$r->user->name, $r);
            DB::afterCommit(fn () => $r->user?->notify(new LeaveRequestNotification($r, false)));

            return $r;
        });
        if ($r->status === 'approved') {
            AnalyticsService::flush();
        }

        return $r;
    }
}
