<?php

namespace App\Http\Controllers;

use App\Enums\StoreStatus;
use App\Models\MessageTemplate;
use App\Models\Store;
use App\Services\AttendanceService;
use App\Services\Messages\MessageContext;
use App\Services\Messages\MessageRenderer;
use App\Support\WhatsAppNumber;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** رسالة من قالب لعدة متاجر: قائمة بزر واتساب لكل متجر */
class BulkMessageController extends Controller
{
    public const MAX = 300;

    public function create(AttendanceService $attendance): View
    {
        $this->authorize('messages.send');

        return view('messages.bulk', [
            'templates' => MessageTemplate::where('status', 'approved')->whereIn('type', MessageTemplate::BULK_TYPES)->orderBy('name')->get(),
            'stores' => Store::where('status', '!=', StoreStatus::Archived)->orderBy('name')->get(['id', 'name']),
            'employees' => $attendance->employees()->get(['id', 'name']),
            'rows' => null, 'template' => null, 'period' => '7d',
        ]);
    }

    public function preview(Request $request, MessageContext $context, AttendanceService $attendance): View
    {
        $this->authorize('messages.send');
        $data = $request->validate([
            'template_id' => ['required', 'integer'],
            'store_ids' => ['array', 'max:'.self::MAX], 'store_ids.*' => ['integer'],
            'all_active' => ['nullable', 'boolean'],
            'employee_id' => ['nullable', 'integer'],
            'period' => ['nullable', 'in:'.implode(',', array_keys(MessageContext::PERIODS))],
        ], ['store_ids.max' => 'الحد الأقصى '.self::MAX.' متجر في المرة.'], ['store_ids' => 'المتاجر']);

        $template = MessageTemplate::find($data['template_id']);
        if (! $template || ! $template->isApproved() || ! in_array($template->type, MessageTemplate::BULK_TYPES, true)) {
            throw ValidationException::withMessages(['template_id' => 'اختر قالباً معتمداً من نوع رسالة عامة أو أكواد أو ملخص أداء.']);
        }

        $query = Store::where('status', '!=', StoreStatus::Archived)->orderBy('name');
        $query = match (true) {
            $request->boolean('all_active') => $query,
            ! empty($data['employee_id']) => $query->where('assigned_user_id', $data['employee_id']),
            default => $query->whereIn('id', $data['store_ids'] ?? [0]),
        };
        $stores = $query->limit(self::MAX + 1)->get();
        if ($stores->count() > self::MAX) {
            throw ValidationException::withMessages(['store_ids' => 'الحد الأقصى '.self::MAX.' متجر في المرة.']);
        }

        $period = $data['period'] ?? '7d';
        $rows = $stores->map(function (Store $s) use ($template, $context, $request, $period) {
            $codes = MessageContext::openCodes($s);
            $skip = $template->type === 'open_codes' && $codes === [] ? 'لا توجد أكواد مفتوحة — استُبعد' : null;

            return [
                'store' => $s, 'phone' => WhatsAppNumber::normalize($s->phone), 'codes' => $codes, 'skipped' => $skip,
                'text' => $skip ? '' : MessageRenderer::render($template->body, $context->forStore($s, $request->user(), ['period' => $period]))['text'],
            ];
        });

        return view('messages.bulk', [
            'templates' => MessageTemplate::where('status', 'approved')->whereIn('type', MessageTemplate::BULK_TYPES)->orderBy('name')->get(),
            'stores' => Store::where('status', '!=', StoreStatus::Archived)->orderBy('name')->get(['id', 'name']),
            'employees' => $attendance->employees()->get(['id', 'name']),
            'rows' => $rows, 'template' => $template, 'period' => $period,
        ]);
    }
}
