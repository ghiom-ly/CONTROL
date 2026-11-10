<?php

namespace App\Http\Controllers;

use App\Models\PayrollAdjustment;
use App\Models\PayrollItem;
use App\Models\PayrollMonth;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PayrollService;
use App\Support\WhatsAppNumber;
use App\Support\WhatsAppText;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** الرواتب — للمدير العام فقط */
class PayrollController extends Controller
{
    public function __construct(private readonly PayrollService $payroll) {}

    public function index(Request $request): View
    {
        $this->ensureBoss($request);
        $month = self::month($request);
        $run = PayrollService::run($month);

        return view('payroll.index', [
            'month' => $month,
            'run' => $run,
            'items' => $run ? PayrollItem::with('user:id,name,phone')->where('payroll_month_id', $run->id)->get()->sortBy('user.name')->values() : collect(),
            'adjustments' => $run ? PayrollAdjustment::where('payroll_month_id', $run->id)->orderBy('id')->get()->groupBy('user_id') : collect(),
            'missing' => $this->payroll->missingSalary($month),
            'canApprove' => $month->lessThan(CarbonImmutable::today()->startOfMonth()),
        ]);
    }

    public function compute(Request $request): RedirectResponse
    {
        $this->ensureBoss($request);
        $month = self::month($request);
        $this->payroll->compute($month, $request->user());

        return redirect()->route('payroll.index', ['month' => $month->format('Y-m')])->with('success', 'تم حساب الكشف.');
    }

    public function approve(Request $request): RedirectResponse
    {
        $this->ensureBoss($request);
        $month = self::month($request);
        $this->payroll->approve($month, $request->user());

        return redirect()->route('payroll.index', ['month' => $month->format('Y-m')])->with('success', 'تم اعتماد الكشف وقفله.');
    }

    public function reopen(Request $request): RedirectResponse
    {
        $this->ensureBoss($request);
        $month = self::month($request);
        $this->payroll->reopen($month, $request->user());

        return redirect()->route('payroll.index', ['month' => $month->format('Y-m')])->with('success', 'تمت إعادة فتح الكشف.');
    }

    public function storeAdjustment(Request $request): RedirectResponse
    {
        $this->ensureBoss($request);
        $month = self::month($request);
        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'kind' => ['required', Rule::in(array_keys(PayrollAdjustment::KINDS))],
            'label' => ['required', 'string', 'max:150'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:1000000'],
        ], [], ['label' => 'البند', 'amount' => 'المبلغ']);

        DB::transaction(function () use ($month, $data, $request) {
            $run = $this->draftRun($month);
            PayrollAdjustment::create($data + ['payroll_month_id' => $run->id, 'created_by' => $request->user()->id]);
            $item = PayrollItem::where('payroll_month_id', $run->id)->where('user_id', $data['user_id'])->first();
            if ($item) {
                $this->payroll->refreshNet($item);
            }
            $name = User::withTrashed()->find($data['user_id'])?->name;
            AuditLogger::log('payroll_adjusted', PayrollAdjustment::KINDS[$data['kind']].' على راتب '.$name.' ('.$month->format('Y-m').'): '.$data['label'], $run);
        });

        return back()->with('success', 'تمت الإضافة.');
    }

    public function destroyAdjustment(Request $request, PayrollAdjustment $adjustment): RedirectResponse
    {
        $this->ensureBoss($request);
        DB::transaction(function () use ($adjustment) {
            $run = PayrollMonth::whereKey($adjustment->payroll_month_id)->lockForUpdate()->firstOrFail();
            if ($run->isApproved()) {
                throw ValidationException::withMessages(['payroll' => 'الكشف معتمد — أعد فتحه أولاً.']);
            }
            $adjustment->delete();
            $item = PayrollItem::where('payroll_month_id', $run->id)->where('user_id', $adjustment->user_id)->first();
            if ($item) {
                $this->payroll->refreshNet($item);
            }
            AuditLogger::log('payroll_adjusted', 'حذف بند «'.$adjustment->label.'» من راتب '.$adjustment->user?->name, $run);
        });

        return back()->with('success', 'تم الحذف.');
    }

    public function slip(Request $request, PayrollItem $item): View
    {
        $this->ensureBoss($request);
        $item->load(['user:id,name,phone', 'payrollMonth']);
        $adjustments = PayrollAdjustment::where('payroll_month_id', $item->payroll_month_id)->where('user_id', $item->user_id)->orderBy('id')->get();
        $phone = WhatsAppNumber::normalize((string) $item->user?->phone);

        return view('payroll.slip', [
            'item' => $item, 'adjustments' => $adjustments,
            'whatsapp' => $phone ? WhatsAppText::url($phone, self::slipText($item, $adjustments)) : null,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->ensureBoss($request);
        $month = self::month($request);
        $run = PayrollService::run($month);
        $items = $run ? PayrollItem::with('user:id,name')->where('payroll_month_id', $run->id)->get()->sortBy('user.name') : collect();

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('كشف الرواتب')->setRightToLeft(true);
        $sheet->fromArray([['الموظف', 'الراتب الأساسي', 'قيمة اليوم', 'أيام الاستحقاق', 'المستحق', 'أيام الغياب', 'تواريخ الغياب', 'خصم الغياب',
            'شفتات غير مكتملة', 'خصم الشفتات', 'مرات التأخير', 'دقائق التأخير', 'التقارير المعتمدة', 'إجمالي التقارير', 'الإضافات', 'الخصومات اليدوية', 'الصافي']]);
        $r = 2;
        foreach ($items as $i) {
            $sheet->fromArray([[$i->user?->name, (float) $i->base_salary, (float) $i->day_value, $i->paid_days, (float) $i->base_due,
                $i->absent_days, implode('، ', $i->absent_dates ?? []), (float) $i->absence_deduction, $i->incomplete_days, (float) $i->incomplete_deduction,
                $i->late_count, $i->late_minutes, $i->reports_approved, $i->reports_total, (float) $i->additions, (float) $i->deductions, (float) $i->net]], null, 'A'.$r++);
        }
        $sheet->fromArray([['الإجمالي', null, null, null, (float) $items->sum('base_due'), null, null, (float) $items->sum('absence_deduction'), null,
            (float) $items->sum('incomplete_deduction'), null, null, null, null, (float) $items->sum('additions'), (float) $items->sum('deductions'), (float) $items->sum('net')]], null, 'A'.$r);

        $name = 'رواتب-'.$month->format('Y-m').'.xlsx';

        return new StreamedResponse(fn () => IOFactory::createWriter($book, 'Xlsx')->save('php://output'), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="payroll-'.$month->format('Y-m').'.xlsx"; filename*=UTF-8\'\''.rawurlencode($name),
        ]);
    }

    public static function slipText(PayrollItem $i, $adjustments): string
    {
        $m = fn ($v) => PayrollItem::money($v).' د.ل';
        $lines = [
            '*قسيمة راتب شهر '.$i->payrollMonth->month->format('Y-m').'*',
            'الموظف: '.$i->user?->name,
            'الراتب الأساسي: '.$m($i->base_salary).($i->paid_days < PayrollService::DAYS ? ' — المستحق عن '.$i->paid_days.' يوماً: '.$m($i->base_due) : ''),
        ];
        if ($i->absent_days) {
            $lines[] = 'خصم غياب '.$i->absent_days.' يوم ('.implode('، ', $i->absent_dates ?? []).'): '.$m($i->absence_deduction);
        }
        if ($i->incomplete_days) {
            $lines[] = 'خصم شفت غير مكتمل '.$i->incomplete_days.' يوم ('.implode('، ', $i->incomplete_dates ?? []).'): '.$m($i->incomplete_deduction);
        }
        foreach ($adjustments as $a) {
            $lines[] = PayrollAdjustment::KINDS[$a->kind].': '.$a->label.' — '.$m($a->amount);
        }
        $lines[] = '*الصافي: '.$m($i->net).'*';

        return implode("\n", $lines);
    }

    private function draftRun(CarbonImmutable $month): PayrollMonth
    {
        $run = PayrollMonth::whereDate('month', $month->toDateString())->lockForUpdate()->first();
        if (! $run) {
            throw ValidationException::withMessages(['payroll' => 'احسب كشف الشهر أولاً.']);
        }
        if ($run->isApproved()) {
            throw ValidationException::withMessages(['payroll' => 'الكشف معتمد — أعد فتحه أولاً.']);
        }

        return $run;
    }

    /** الشهر من month=YYYY-MM (رابط أو نموذج)، والافتراضي الشهر السابق */
    public static function month(Request $request): CarbonImmutable
    {
        $m = (string) $request->input('month', '');

        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)
            ? CarbonImmutable::createFromFormat('Y-m-d', $m.'-01')->startOfDay()
            : CarbonImmutable::today()->subMonthNoOverflow()->startOfMonth();
    }

    private function ensureBoss(Request $request): void
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
    }
}
