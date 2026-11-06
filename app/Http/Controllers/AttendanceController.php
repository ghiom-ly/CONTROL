<?php

namespace App\Http\Controllers;

use App\Exports\AttendanceExport;
use App\Models\Leave;
use App\Models\User;
use App\Services\AttendanceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** صفحات الحضور والانصراف والتقرير الشهري */
class AttendanceController extends Controller
{
    public function __construct(private readonly AttendanceService $attendance) {}

    public function index(Request $request): View
    {
        $this->authorize('users.view');
        $month = self::month($request);

        return view('attendance.index', [
            'month' => $month,
            'rows' => $this->attendance->summaryForAll($month),
            'employees' => $this->attendance->employees()->get(['id', 'name']),
        ]);
    }

    public function show(Request $request, User $user): View
    {
        $this->authorize('users.view');
        $this->ensureEmployee($user);
        $month = self::month($request);

        return view('attendance.show', [
            'user' => $user, 'month' => $month, 'data' => $this->attendance->month($user, $month),
            'leaves' => Leave::where('user_id', $user->id)->latest('from_date')->limit(30)->get(),
            'overrides' => \App\Models\AttendanceOverride::where('user_id', $user->id)
                ->whereBetween('date', [$month->startOfMonth()->toDateString(), $month->endOfMonth()->toDateString()])
                ->get()->groupBy(fn ($o) => $o->date->toDateString()),
        ]);
    }

    /** المدير العام: غياب بعذر / حاضر / إلغاء تأخير ليوم */
    public function storeOverride(Request $request, User $user): \Illuminate\Http\RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'type' => ['required', \Illuminate\Validation\Rule::in(array_keys(\App\Models\AttendanceOverride::TYPES))],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        // «بعذر» و«حاضر» لنفس اليوم لا يجتمعان — الأحدث يحل محل الآخر
        if (in_array($data['type'], ['excused', 'present'], true)) {
            \App\Models\AttendanceOverride::where('user_id', $user->id)->whereDate('date', $data['date'])
                ->where('type', $data['type'] === 'excused' ? 'present' : 'excused')->delete();
        }
        \App\Models\AttendanceOverride::updateOrCreate(['user_id' => $user->id, 'date' => $data['date'], 'type' => $data['type']],
            ['reason' => $data['reason'] ?? null, 'created_by' => $request->user()->id]);
        \App\Services\Analytics\AnalyticsService::flush();

        return back()->with('success', 'تم تعديل حضور يوم '.$data['date'].'.');
    }

    public function destroyOverride(Request $request, \App\Models\AttendanceOverride $override): \Illuminate\Http\RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $override->delete();
        \App\Services\Analytics\AnalyticsService::flush();

        return back()->with('success', 'تم التراجع عن تعديل الحضور.');
    }

    public function storeHoliday(Request $request): \Illuminate\Http\RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d', 'unique:holidays,date'], 'name' => ['required', 'string', 'max:120']],
            ['date.unique' => 'هذا اليوم مسجل عطلة بالفعل.']);
        \App\Models\Holiday::create($data + ['created_by' => $request->user()->id]);
        \App\Services\Analytics\AnalyticsService::flush();

        return back()->with('success', 'تمت إضافة العطلة الرسمية.');
    }

    public function destroyHoliday(Request $request, \App\Models\Holiday $holiday): \Illuminate\Http\RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $holiday->delete();
        \App\Services\Analytics\AnalyticsService::flush();

        return back()->with('success', 'تم حذف العطلة.');
    }

    public function print(Request $request, User $user): View
    {
        $this->authorize('users.view');
        $this->ensureEmployee($user);
        $month = self::month($request);

        return view('attendance.print', ['user' => $user, 'month' => $month, 'data' => $this->attendance->month($user, $month)]);
    }

    public function export(Request $request, AttendanceExport $export): StreamedResponse
    {
        $this->authorize('users.view');
        $month = self::month($request);
        $users = $request->filled('user_id')
            ? $this->attendance->employees()->whereKey($request->integer('user_id'))->get()
            : $this->attendance->employees()->get();

        return $export->download($users, $month, 'حضور-'.$month->format('Y-m').'.xlsx');
    }

    /** الشهر من ?month=YYYY-MM، والافتراضي الشهر الحالي */
    public static function month(Request $request): CarbonImmutable
    {
        $m = (string) $request->query('month', '');

        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)
            ? CarbonImmutable::createFromFormat('Y-m-d', $m.'-01')->startOfDay()
            : CarbonImmutable::today()->startOfMonth();
    }

    private function ensureEmployee(User $user): void
    {
        abort_unless($this->attendance->employees()->whereKey($user->id)->exists(), 404);
    }
}
