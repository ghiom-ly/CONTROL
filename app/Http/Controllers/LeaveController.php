<?php

namespace App\Http\Controllers;

use App\Models\Leave;
use App\Services\AttendanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** إجازات الموظفين — أيامها لا تُحسب غياباً */
class LeaveController extends Controller
{
    public function store(Request $request, AttendanceService $attendance): RedirectResponse
    {
        $this->authorize('attendance.manage');
        $data = $request->validate([
            'user_id' => ['required', 'integer', function ($a, $v, $fail) use ($attendance) {
                if (! $attendance->employees()->whereKey($v)->exists()) {
                    $fail('اختر موظفاً نشطاً.');
                }
            }],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'type' => ['required', Rule::in(array_keys(Leave::TYPES))],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [], ['user_id' => 'الموظف', 'from_date' => 'من تاريخ', 'to_date' => 'إلى تاريخ', 'type' => 'نوع الإجازة']);

        Leave::create($data + ['created_by' => $request->user()->id]);

        return back()->with('success', 'سُجّلت الإجازة.');
    }

    public function destroy(Leave $leave): RedirectResponse
    {
        $this->authorize('attendance.manage');
        $leave->delete();

        return back()->with('success', 'حُذفت الإجازة.');
    }
}
