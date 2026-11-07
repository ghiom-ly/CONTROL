<?php

namespace App\Http\Controllers;

use App\Enums\IssueStatus;
use App\Models\Issue;
use App\Models\MessageTemplate;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\StoreMessage;
use App\Services\Messages\MessageContext;
use App\Services\Messages\MessageRenderer;
use App\Services\OpenCodesService;
use App\Support\WhatsAppNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** رسالة لمتجر من قالب معتمد — تُفتح في واتساب وتُسجَّل */
class StoreMessageController extends Controller
{
    public const LONG = 1500;

    public function create(Request $request, Store $store, MessageContext $context): View
    {
        $this->authorize('messages.send');
        $templates = MessageTemplate::where('status', 'approved')->orderBy('name')->get();
        $template = $templates->firstWhere('id', (int) $request->query('template_id')) ?? $templates->first();
        $issues = Issue::where('store_id', $store->id)->whereIn('status', [IssueStatus::New, IssueStatus::InProgress])->latest('reported_at')->get();
        $issue = $issues->firstWhere('id', (int) $request->query('issue_id')) ?? $issues->first();
        $codes = array_values(array_filter((array) $request->query('codes', []), fn ($c) => is_string($c) && $c !== '' && mb_strlen($c) <= 80));
        $period = array_key_exists((string) $request->query('period'), MessageContext::PERIODS) ? (string) $request->query('period') : '7d';

        $values = $context->forStore($store, $request->user(), array_filter(['codes' => $codes ?: null, 'issue' => $issue, 'period' => $period]));
        $rendered = $template ? MessageRenderer::render($template->body, $values) : ['text' => '', 'missing' => []];

        return view('messages.send', [
            'store' => $store, 'templates' => $templates, 'template' => $template, 'issues' => $issues, 'issue' => $issue,
            'period' => $period, 'codes' => $codes ?: MessageContext::openCodes($store), 'selectedCodes' => $codes, 'rendered' => $rendered,
            'phone' => WhatsAppNumber::normalize($store->phone),
            'history' => StoreMessage::where('store_id', $store->id)->with('sender:id,name')->latest()->limit(5)->get(),
        ]);
    }

    public function store(Request $request, Store $store, OpenCodesService $openCodes): RedirectResponse
    {
        $this->authorize('messages.send');
        $data = $request->validate([
            'template_id' => ['required', 'integer'],
            'body' => ['required', 'string', 'max:4000'],
            'codes' => ['array', 'max:1000'], 'codes.*' => ['string', 'max:80'],
            'issue_id' => ['nullable', 'integer'],
            'period' => ['nullable', Rule::in(array_keys(MessageContext::PERIODS))],
            'mark_forwarded' => ['nullable', 'boolean'],
        ], [], ['body' => 'نص الرسالة']);

        $data['body'] = \App\Support\WhatsAppText::clean($data['body']);
        $template = MessageTemplate::find($data['template_id']);
        if (! $template || ! $template->isApproved()) {
            throw ValidationException::withMessages(['template_id' => 'القالب غير معتمد — لا يمكن الإرسال به.']);
        }
        $phone = WhatsAppNumber::normalize($store->phone);
        if (! $phone) {
            throw ValidationException::withMessages(['phone' => 'رقم المتجر غير صالح لواتساب — عدّله في صفحة المتجر.']);
        }
        $issueId = null;
        if ($template->type === 'issue') {
            $issueId = Issue::where('store_id', $store->id)->whereIn('status', [IssueStatus::New, IssueStatus::InProgress])
                ->whereKey($data['issue_id'] ?? 0)->value('id');
            if (! $issueId) {
                throw ValidationException::withMessages(['issue_id' => 'اختر مشكلة مسجّلة على هذا المتجر.']);
            }
        }

        // الأكواد المرسلة تُقصر على أكواد المتجر المفتوحة فعلاً (لا تُعاد فتح أكواد محلولة)
        if (! empty($data['codes'])) {
            $data['codes'] = array_values(array_intersect($data['codes'], MessageContext::openCodes($store)));
        }

        DB::transaction(function () use ($request, $store, $template, $data, $phone, $issueId, $openCodes) {
            StoreMessage::create([
                'store_id' => $store->id, 'message_template_id' => $template->id, 'template_version' => $template->version,
                'type' => $template->type, 'body' => $data['body'], 'phone' => $phone, 'sent_by' => $request->user()->id,
                'meta' => array_filter(['codes' => $data['codes'] ?? null, 'issue_id' => $issueId, 'period' => $data['period'] ?? null]),
            ]);
            if ($template->type === 'open_codes' && $request->boolean('mark_forwarded') && ! empty($data['codes'])) {
                $ids = Shipment::where('store_id', $store->id)->whereIn('code', $data['codes'])->pluck('id')->all();
                if ($ids) {
                    $openCodes->mark($ids, 'forwarded', 'أُرسلت للمتجر عبر واتساب', $request->user());
                }
            }
        });

        // رسالة طويلة ⇒ نُسخت في المتصفح، والمحادثة تُفتح بلا نص (واتساب يرفض الروابط الطويلة)
        return redirect()->away(\App\Support\WhatsAppText::url($phone, $data['body']));
    }
}
