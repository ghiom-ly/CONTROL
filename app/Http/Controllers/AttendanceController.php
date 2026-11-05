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
        ]);
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
