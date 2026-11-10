<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\TopupRequest;
use App\Models\User;
use App\Notifications\TopupRequestNotification;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * كروت التعبئة: الموظف يطلب، المدير العام يشتري خارج المنظومة ويدرج رقم الكرت، والموظف يؤكد التعبئة أو يبلّغ بعطل.
 */
class TopupRequestController extends Controller
{
    // ---------------- الموظف

    public function mine(Request $request): View
    {
        $this->ensureRequester($request);

        return view('topups.mine', [
            'requests' => TopupRequest::with('decider:id,name')->where('user_id', $request->user()->id)->latest('id')->paginate(20),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->ensureRequester($request);
        $data = $request->validate([
            'operator' => ['required', Rule::in(array_keys(TopupRequest::OPERATORS))],
            'amount' => ['required', 'integer', Rule::in(TopupRequest::AMOUNTS)],
            'note' => ['nullable', 'string', 'max:300'],
        ], [], ['operator' => 'الشبكة', 'amount' => 'الفئة', 'note' => 'الملاحظة']);
        $user = $request->user();

        $r = DB::transaction(function () use ($user, $data) {
            User::whereKey($user->id)->lockForUpdate()->first();
            if (TopupRequest::where('user_id', $user->id)->where('status', 'pending')->count() >= TopupRequest::MAX_PENDING) {
                throw ValidationException::withMessages(['request' => 'لديك '.TopupRequest::MAX_PENDING.' طلبات بانتظار الموافقة — انتظر الرد عليها أولاً.']);
            }

            return TopupRequest::create($data + ['user_id' => $user->id, 'status' => 'pending']);
        });
        AuditLogger::log('topup_requested', $user->name.': طلب '.$r->summary(), $r);
        $this->notifyBosses($r, 'new');

        return back()->with('success', 'تم إرسال الطلب للمدير.');
    }

    public function cancel(Request $request, TopupRequest $topup): RedirectResponse
    {
        $this->transition($request, $topup, 'pending', ['status' => 'cancelled'], 'لا يمكن إلغاء طلب تم البت فيه.');
        AuditLogger::log('topup_cancelled', 'إلغاء طلب '.$topup->summary(), $topup);

        return back()->with('success', 'تم إلغاء الطلب.');
    }

    public function used(Request $request, TopupRequest $topup): RedirectResponse
    {
        $this->transition($request, $topup, 'issued', ['status' => 'used', 'used_at' => now()], 'الكرت لم يُدرج بعد أو تم تأكيده سابقاً.');
        AuditLogger::log('topup_used', 'تأكيد تعبئة '.$topup->summary(), $topup);

        return back()->with('success', 'تم تسجيل التعبئة.');
    }

    public function faulty(Request $request, TopupRequest $topup): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:300']]);
        $this->transition($request, $topup, 'issued', ['status' => 'faulty', 'employee_note' => $data['note'] ?? null], 'الكرت لم يُدرج بعد أو تم تأكيده سابقاً.');
        AuditLogger::log('topup_faulty', 'بلاغ كرت لا يعمل: '.$topup->summary(), $topup);
        $this->notifyBosses($topup->fresh(), 'faulty');

        return back()->with('success', 'تم إبلاغ المدير بأن الكرت لا يعمل.');
    }

    // ---------------- المدير العام

    public function index(Request $request): View
    {
        $this->ensureBoss($request);
        $status = array_key_exists((string) $request->query('status'), TopupRequest::STATUSES) ? $request->query('status') : 'pending';

        return view('topups.index', [
            'status' => $status,
            'counts' => TopupRequest::selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status'),
            'requests' => TopupRequest::with(['user:id,name', 'decider:id,name'])->where('status', $status)
                ->orderBy($status === 'pending' ? 'created_at' : 'decided_at', $status === 'pending' ? 'asc' : 'desc')
                ->paginate(30)->withQueryString(),
        ]);
    }

    public function approve(Request $request, TopupRequest $topup): RedirectResponse
    {
        $this->ensureBoss($request);
        $number = TopupRequest::normalizeCard($request->input('card_number'));
        $request->merge(['card_number' => $number]);
        $request->validate(['card_number' => ['required', 'regex:/^\d{10,20}$/'], 'decision_note' => ['nullable', 'string', 'max:300']],
            ['card_number.regex' => 'رقم الكرت يجب أن يكون أرقاماً فقط (من 10 إلى 20 رقماً).'], ['card_number' => 'رقم الكرت']);
        $hash = TopupRequest::hashCard($number);
        if (TopupRequest::where('card_hash', $hash)->exists()) {
            throw ValidationException::withMessages(['card_number' => 'هذا الكرت مُدرج سابقاً لطلب آخر.']);
        }

        try {
            DB::transaction(function () use ($request, $topup, $number, $hash) {
                $r = TopupRequest::whereKey($topup->id)->lockForUpdate()->firstOrFail();
                if ($r->status !== 'pending') {
                    throw ValidationException::withMessages(['request' => 'تم البت في هذا الطلب سابقاً.']);
                }
                $r->update(['status' => 'issued', 'card_number' => $number, 'card_hash' => $hash, 'decided_by' => $request->user()->id,
                    'decided_at' => now(), 'decision_note' => $request->input('decision_note')]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['card_number' => 'هذا الكرت مُدرج سابقاً لطلب آخر.']);
        }
        $topup->refresh();
        AuditLogger::log('topup_issued', 'إدراج كرت لـ '.$topup->user->name.': '.$topup->summary(), $topup);
        $this->notifyUser($topup);

        return back()->with('success', 'تم إدراج الكرت وإبلاغ الموظف.');
    }

    public function reject(Request $request, TopupRequest $topup): RedirectResponse
    {
        $this->ensureBoss($request);
        $data = $request->validate(['decision_note' => ['nullable', 'string', 'max:300']]);
        DB::transaction(function () use ($request, $topup, $data) {
            $r = TopupRequest::whereKey($topup->id)->lockForUpdate()->firstOrFail();
            if ($r->status !== 'pending') {
                throw ValidationException::withMessages(['request' => 'تم البت في هذا الطلب سابقاً.']);
            }
            $r->update(['status' => 'rejected', 'decided_by' => $request->user()->id, 'decided_at' => now(), 'decision_note' => $data['decision_note'] ?? null]);
        });
        $topup->refresh();
        AuditLogger::log('topup_rejected', 'رفض طلب '.$topup->user->name.': '.$topup->summary(), $topup);
        $this->notifyUser($topup);

        return back()->with('success', 'تم رفض الطلب.');
    }

    /** حذف نهائي لطلب أُدخل بالخطأ أو للتجربة — يخرج من التكاليف، والرقم لا يُسجَّل */
    public function destroy(Request $request, TopupRequest $topup): RedirectResponse
    {
        $this->ensureBoss($request);
        $label = $topup->user?->name.': '.$topup->summary().' ('.TopupRequest::STATUSES[$topup->status].')';
        $topup->delete();
        AuditLogger::log('topup_deleted', 'حذف طلب كرت — '.$label);

        return back()->with('success', 'تم حذف الطلب.');
    }

    public function costs(Request $request): View
    {
        $this->ensureBoss($request);
        $month = AttendanceController::month($request);

        return view('topups.costs', ['month' => $month, 'costs' => self::costsFor($month)]);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->ensureBoss($request);
        $month = AttendanceController::month($request);
        $c = self::costsFor($month);

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('الملخص')->setRightToLeft(true);
        $sheet->fromArray([['الموظف', 'عدد الكروت', 'الإجمالي (د.ل)']]);
        $i = 2;
        foreach ($c['by_user'] as $u) {
            $sheet->fromArray([[$u['name'], $u['count'], $u['total']]], null, 'A'.$i++);
        }
        $sheet->fromArray([['الإجمالي', $c['count'], $c['total']]], null, 'A'.$i);

        $detail = $book->createSheet()->setTitle('التفاصيل')->setRightToLeft(true);
        $detail->fromArray([['تاريخ الإدراج', 'الموظف', 'الشبكة', 'الفئة (د.ل)', 'الحالة', 'ملاحظة الموظف']]);
        $i = 2;
        foreach ($c['rows'] as $r) {
            $detail->fromArray([[$r->decided_at->format('Y-m-d H:i'), $r->user?->name, TopupRequest::OPERATORS[$r->operator], $r->amount,
                TopupRequest::STATUSES[$r->status], $r->employee_note]], null, 'A'.$i++);
        }

        $name = 'كروت-التعبئة-'.$month->format('Y-m').'.xlsx';

        return new StreamedResponse(fn () => IOFactory::createWriter($book, 'Xlsx')->save('php://output'), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="topups-'.$month->format('Y-m').'.xlsx"; filename*=UTF-8\'\''.rawurlencode($name),
        ]);
    }

    /** تكاليف الشهر حسب تاريخ إدراج الكرت: كل كرت صُرف (مُدرج/مستخدم/لا يعمل) */
    public static function costsFor(CarbonImmutable $month): array
    {
        $rows = TopupRequest::with('user:id,name')->whereIn('status', TopupRequest::COSTED)
            ->whereBetween('decided_at', [$month->startOfMonth(), $month->endOfMonth()])->orderBy('decided_at')->get();
        $byType = [];
        foreach (TopupRequest::OPERATORS as $op => $_) {
            foreach (TopupRequest::AMOUNTS as $a) {
                $byType[$op][$a] = (int) $rows->where('operator', $op)->where('amount', $a)->sum('amount');
            }
        }

        return [
            'rows' => $rows,
            'total' => (int) $rows->sum('amount'),
            'count' => $rows->count(),
            'faulty_amount' => (int) $rows->where('status', 'faulty')->sum('amount'),
            'by_type' => $byType,
            'by_user' => $rows->groupBy('user_id')->map(fn (Collection $g) => [
                'name' => $g->first()->user?->name ?? '—', 'count' => $g->count(), 'total' => (int) $g->sum('amount'),
            ])->sortByDesc('total')->values(),
        ];
    }

    // ---------------- مساعدات

    /** تغيير حالة طلب الموظف نفسه بقفل، مع التحقق من الحالة الحالية */
    private function transition(Request $request, TopupRequest $topup, string $from, array $changes, string $error): void
    {
        abort_unless($topup->user_id === $request->user()->id, 403);
        DB::transaction(function () use ($topup, $from, $changes, $error) {
            $r = TopupRequest::whereKey($topup->id)->lockForUpdate()->firstOrFail();
            if ($r->status !== $from) {
                throw ValidationException::withMessages(['request' => $error]);
            }
            $r->update($changes);
        });
    }

    private function ensureRequester(Request $request): void
    {
        abort_if($request->user()->isSuperAdmin(), 403, 'المدير العام يدرج الكروت ولا يطلبها.');
    }

    private function ensureBoss(Request $request): void
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
    }

    private function notifyBosses(TopupRequest $r, string $event): void
    {
        try {
            $bosses = User::where('status', 'active')->whereHas('role', fn ($q) => $q->where('name', Role::SUPER_ADMIN))->get();
            Notification::send($bosses, new TopupRequestNotification($r, $event));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function notifyUser(TopupRequest $r): void
    {
        try {
            $r->user?->notify(new TopupRequestNotification($r, 'decision'));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
