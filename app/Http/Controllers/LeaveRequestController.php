<?php

namespace App\Http\Controllers;

use App\Models\Leave;
use App\Models\LeaveRequest;
use App\Models\SystemSetting;
use App\Notifications\LeaveRequestNotification;
use App\Services\AttendanceService;
use App\Services\LeaveRequestService;
use App\Support\WhatsAppNumber;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** طلبات الإجازة والأعذار: «طلباتي» للموظف و«طلبات الإجازة والأعذار» للمدير/المسؤول */
class LeaveRequestController extends Controller
{
    public const MAX_LEAVE_DAYS = 30;

    public const EXCUSE_DAYS_BACK = 30;

    public function __construct(private readonly LeaveRequestService $service) {}

    private function ensureEmployee(Request $request): void
    {
        abort_unless(app(AttendanceService::class)->employees()->whereKey($request->user()->id)->exists(), 403);
    }

    public function mine(Request $request): View
    {
        $this->ensureEmployee($request);
        $phone = WhatsAppNumber::normalize((string) SystemSetting::get('manager_whatsapp'));

        return view('leave-requests.mine', [
            'requests' => LeaveRequest::where('user_id', $request->user()->id)->latest()->paginate(20),
            'managerPhone' => $phone,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->ensureEmployee($request);
        $today = CarbonImmutable::today();
        $kind = (string) $request->input('kind');
        if ($kind !== 'leave') {
            $request->merge(['to_date' => $request->input('from_date')]);   // العذر ليوم واحد
        }
        $rawFrom = (string) $request->input('from_date');
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawFrom) && strtotime($rawFrom) ? CarbonImmutable::parse($rawFrom) : null;

        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(LeaveRequest::KINDS))],
            'leave_type' => [Rule::requiredIf($kind === 'leave'), 'nullable', Rule::in(array_keys(Leave::TYPES))],
            'from_date' => $kind === 'leave'
                ? ['required', 'date_format:Y-m-d', 'after_or_equal:'.$today->toDateString()]
                : ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today->toDateString(), 'after_or_equal:'.$today->subDays(self::EXCUSE_DAYS_BACK)->toDateString()],
            'to_date' => array_filter(['required', 'date_format:Y-m-d', 'after_or_equal:from_date',
                $from ? 'before_or_equal:'.$from->addDays(self::MAX_LEAVE_DAYS - 1)->toDateString() : null]),
            'reason' => ['required', 'string', 'max:1000'],
            'attachment' => ['nullable', 'file', 'max:5120', 'mimes:jpg,jpeg,png,webp,pdf'],
        ], [
            'from_date.after_or_equal' => $kind === 'leave' ? 'الإجازة تبدأ من اليوم أو بعده.' : 'الأعذار لآخر '.self::EXCUSE_DAYS_BACK.' يوماً فقط.',
            'from_date.before_or_equal' => 'العذر يكون عن يوم مضى أو اليوم.',
            'to_date.before_or_equal' => 'أقصى مدة للإجازة '.self::MAX_LEAVE_DAYS.' يوماً.',
            'attachment.mimes' => 'المرفق صورة أو PDF فقط.', 'attachment.max' => 'حجم المرفق حتى 5 ميغا.',
        ], ['kind' => 'نوع الطلب', 'leave_type' => 'نوع الإجازة', 'from_date' => 'التاريخ', 'to_date' => 'إلى تاريخ', 'reason' => 'السبب', 'attachment' => 'المرفق']);

        $user = $request->user();
        $r = DB::transaction(function () use ($data, $user, $request) {
            // قفل صفوف الموظف ثم فحص التداخل مع طلب معلّق أو مقبول
            LeaveRequest::where('user_id', $user->id)->lockForUpdate()->get(['id']);
            if (LeaveRequest::where('user_id', $user->id)->whereIn('status', ['pending', 'approved'])
                ->where('from_date', '<=', $data['to_date'])->where('to_date', '>=', $data['from_date'])->exists()) {
                throw ValidationException::withMessages(['from_date' => 'لديك طلب آخر معلّق أو مقبول لنفس الأيام.']);
            }
            $file = $request->file('attachment');

            return LeaveRequest::create([
                'user_id' => $user->id, 'kind' => $data['kind'], 'leave_type' => $data['kind'] === 'leave' ? $data['leave_type'] : null,
                'from_date' => $data['from_date'], 'to_date' => $data['to_date'], 'reason' => $data['reason'], 'status' => 'pending',
                'attachment_path' => $file?->store('leave-requests/'.$user->id, LeaveRequest::DISK),
                'attachment_name' => $file ? mb_substr($file->getClientOriginalName(), 0, 190) : null,
            ]);
        });
        Notification::send(LeaveRequestService::managers(), new LeaveRequestNotification($r->load('user'), true));

        return redirect()->route('my-requests.index')->with('success', 'تم إرسال طلبك للمراجعة.');
    }

    public function cancel(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        abort_unless($leaveRequest->user_id === $request->user()->id, 403);
        $done = LeaveRequest::whereKey($leaveRequest->id)->where('status', 'pending')->update(['status' => 'cancelled']);
        if (! $done) {
            throw ValidationException::withMessages(['request' => 'لا يمكن إلغاء طلب تم البت فيه.']);
        }

        return back()->with('success', 'تم إلغاء الطلب.');
    }

    public function attachment(Request $request, LeaveRequest $leaveRequest)
    {
        abort_unless($leaveRequest->user_id === $request->user()->id || $request->user()->hasPermission('attendance.manage'), 403);
        abort_unless($leaveRequest->attachment_path && Storage::disk(LeaveRequest::DISK)->exists($leaveRequest->attachment_path), 404);

        return Storage::disk(LeaveRequest::DISK)->response($leaveRequest->attachment_path, $leaveRequest->attachment_name,
            ['X-Content-Type-Options' => 'nosniff']);
    }

    public function index(Request $request): View
    {
        $this->authorize('attendance.manage');
        $status = in_array($request->query('status'), array_keys(LeaveRequest::STATUSES), true) ? $request->query('status') : 'pending';

        return view('leave-requests.index', [
            'status' => $status,
            'counts' => LeaveRequest::selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status'),
            'requests' => LeaveRequest::with(['user:id,name,phone', 'decider:id,name'])->where('status', $status)
                ->orderBy($status === 'pending' ? 'created_at' : 'decided_at', $status === 'pending' ? 'asc' : 'desc')
                ->paginate(30)->withQueryString(),
        ]);
    }

    public function approve(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $this->authorize('attendance.manage');
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $this->service->approve($leaveRequest, $request->user(), $data['note'] ?? null);

        return back()->with('success', 'تم قبول الطلب وتحديث الحضور.');
    }

    public function reject(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $this->authorize('attendance.manage');
        $data = $request->validate(['note' => ['required', 'string', 'max:500']], ['note.required' => 'اكتب سبب الرفض.']);
        $this->service->reject($leaveRequest, $request->user(), $data['note']);

        return back()->with('success', 'تم رفض الطلب.');
    }

    /** رسالة واتساب جاهزة بالقرار للموظف */
    public static function decisionWhatsApp(LeaveRequest $r): ?string
    {
        $phone = WhatsAppNumber::normalize($r->user?->phone);
        if (! $phone || $r->isPending() || $r->status === 'cancelled') {
            return null;
        }
        $body = 'السلام عليكم '.$r->user->name."،\n".($r->status === 'approved' ? 'تم قبول' : 'تم رفض').' طلبك: '.$r->summary()
            .($r->decision_note ? "\n".($r->status === 'approved' ? 'ملاحظة: ' : 'السبب: ').$r->decision_note : '')
            ."\n— ".SystemSetting::get('company_name', 'شركة النورس');

        return 'https://wa.me/'.$phone.'?text='.rawurlencode($body);
    }

    /** رسالة واتساب جاهزة من الموظف للمدير */
    public static function managerWhatsApp(LeaveRequest $r, ?string $phone): ?string
    {
        if (! $phone || ! $r->isPending()) {
            return null;
        }
        $body = 'السلام عليكم، قدّمت طلب '.$r->summary().' في المنظومة.'."\nالسبب: ".$r->reason."\n".route('leave-requests.index');

        return 'https://wa.me/'.$phone.'?text='.rawurlencode($body);
    }
}
