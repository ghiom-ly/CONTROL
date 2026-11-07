<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Reports\ManagementReport;
use App\Reports\Renderers\ExcelRenderer;
use App\Reports\Renderers\PdfRenderer;
use App\Reports\ReportParams;
use App\Reports\ReportRegistry;
use App\Services\AuditLogger;
use App\Support\Branding;
use App\Support\ComparePeriod;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class ReportCenterController extends Controller
{
    public const FORMATS = ['pdf', 'excel', 'print'];

    public function index(Request $request): View
    {
        abort_unless($request->user()->hasPermission('exports.generate'), 403);

        return view('reports-center.index', [
            'reports' => collect(ReportRegistry::all())->map(fn ($c, $key) => ReportRegistry::make($key)),
            'employees' => User::whereHas('role', fn ($q) => $q->where('name', Role::EMPLOYEE))->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function export(Request $request, string $key, string $format): Response
    {
        abort_unless($request->user()->hasPermission('exports.generate'), 403);
        abort_unless(in_array($format, self::FORMATS, true), 404);
        $report = ReportRegistry::make($key);

        $params = self::params($request, $report);
        $data = $report->build($params);
        $file = $report->key().'_'.now()->format('Ymd_His');

        AuditLogger::log('report_exported', 'استخراج تقرير: '.$report->title(), null, [], [
            'report' => $report->key(), 'format' => $format,
            'from' => $params->period->from->toDateString(), 'to' => $params->period->to->toDateString(),
            'store_id' => $params->store?->id, 'user_id' => $params->employee?->id,
        ]);

        return match ($format) {
            'pdf' => response(app(PdfRenderer::class)->render($data), 200, [
                'Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$file.'.pdf"',
            ]),
            'excel' => app(ExcelRenderer::class)->download($data, $file.'.xlsx'),
            default => response()->view('reports-center.document', ['data' => $data, 'brand' => Branding::header(), 'mode' => 'print']),
        };
    }

    /** يبني معاملات التقرير حسب ما يحتاجه (الفترة، الشهر، المتجر، الموظف) */
    public static function params(Request $request, ManagementReport $report): ReportParams
    {
        $needs = $report->needs();
        // المتجر يُختار بكوده (STR-00045) في مركز التقارير — 11 ألف متجر لا تصلح في قائمة
        if ($request->filled('store_code') && ! $request->filled('store_id')) {
            $code = mb_strtoupper(trim((string) $request->input('store_code')));
            $request->merge(['store_id' => Store::withTrashed()->where('code', $code)->value('id') ?? 0]);
        }
        $data = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'store_id' => [in_array('store', $needs, true) ? 'required' : 'nullable', 'integer', Rule::exists('stores', 'id')],
            'user_id' => [in_array('employee', $needs, true) ? 'required' : 'nullable', 'integer',
                Rule::exists('users', 'id')->withoutTrashed()->where(fn ($q) => $q->whereIn('role_id', Role::where('name', Role::EMPLOYEE)->pluck('id')))],
        ], [
            'store_id.required' => 'اكتب كود المتجر.', 'store_id.exists' => 'كود المتجر غير موجود.', 'user_id.required' => 'اختر الموظف.', 'user_id.exists' => 'اختر موظفاً.',
        ]);

        return new ReportParams(
            period: ComparePeriod::fromRequest($request),
            actor: $request->user(),
            month: isset($data['month']) ? CarbonImmutable::parse($data['month'].'-01') : CarbonImmutable::today()->startOfMonth(),
            store: isset($data['store_id']) ? Store::withTrashed()->find($data['store_id']) : null,
            employee: isset($data['user_id']) ? User::find($data['user_id']) : null,
        );
    }
}
