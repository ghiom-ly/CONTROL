<?php

namespace App\Http\Controllers;

use App\Enums\StoreStatus;
use App\Models\MessageTemplate;
use App\Models\Store;
use App\Services\Messages\MessageContext;
use App\Services\Messages\MessageRenderer;
use App\Support\MessageVariables;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** قوالب رسائل المتاجر: المسؤول يكتب، المدير العام يعتمد */
class MessageTemplateController extends Controller
{
    public function index(): View
    {
        $this->authorize('messages.send');

        return view('messages.templates.index', [
            'templates' => MessageTemplate::with('approver:id,name')->orderByRaw("FIELD(status,'pending','approved','draft','rejected','disabled')")->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('messages.send');

        return $this->form(new MessageTemplate(['type' => 'general']));
    }

    public function edit(MessageTemplate $template): View
    {
        $this->authorize('messages.send');

        return $this->form($template);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('messages.send');
        $t = MessageTemplate::create($this->validated($request) + ['status' => 'draft', 'created_by' => $request->user()->id]);

        return redirect()->route('messages.templates.index')->with('success', "حُفظ القالب «{$t->name}» كمسودة. أرسله للاعتماد عندما يجهز.");
    }

    public function update(Request $request, MessageTemplate $template): RedirectResponse
    {
        $this->authorize('messages.send');
        // تعديل قالب معتمد يعيده مسودة حتى يُعتمد من جديد
        $template->update($this->validated($request) + ['status' => 'draft', 'updated_by' => $request->user()->id]);

        return redirect()->route('messages.templates.index')->with('success', 'حُفظ القالب كمسودة — يحتاج اعتماداً جديداً قبل الإرسال.');
    }

    public function submit(MessageTemplate $template): RedirectResponse
    {
        $this->authorize('messages.send');
        $this->expect($template, ['draft', 'rejected']);
        $template->update(['status' => 'pending']);

        return back()->with('success', 'أُرسل القالب للمدير للاعتماد.');
    }

    public function destroy(MessageTemplate $template): RedirectResponse
    {
        $this->authorize('messages.send');
        abort_unless(in_array($template->status, ['draft', 'rejected'], true), 403);
        $template->delete();

        return back()->with('success', 'حُذف القالب.');
    }

    public function approve(Request $request, MessageTemplate $template): RedirectResponse
    {
        $this->authorize('messages.approve');
        $this->expect($template, ['pending']);
        $template->update(['status' => 'approved', 'version' => $template->version + 1, 'review_note' => null,
            'approved_by' => $request->user()->id, 'approved_at' => now()]);

        return back()->with('success', 'اعتُمد القالب وصار متاحاً للإرسال.');
    }

    public function reject(Request $request, MessageTemplate $template): RedirectResponse
    {
        $this->authorize('messages.approve');
        $data = $request->validate(['review_note' => ['required', 'string', 'max:1000']], [], ['review_note' => 'ملاحظة الإرجاع']);
        $this->expect($template, ['pending']);
        $template->update(['status' => 'rejected', 'review_note' => $data['review_note']]);

        return back()->with('success', 'أُرجع القالب للمسؤول مع الملاحظة.');
    }

    public function disable(MessageTemplate $template): RedirectResponse
    {
        $this->authorize('messages.approve');
        $this->expect($template, ['approved']);
        $template->update(['status' => 'disabled']);

        return back()->with('success', 'أُوقف القالب.');
    }

    public function enable(MessageTemplate $template): RedirectResponse
    {
        $this->authorize('messages.approve');
        $this->expect($template, ['disabled']);
        $template->update(['status' => 'approved']);

        return back()->with('success', 'أُعيد تفعيل القالب.');
    }

    /** معاينة حيّة على متجر */
    public function preview(Request $request, MessageContext $context): JsonResponse
    {
        $this->authorize('messages.send');
        $data = $request->validate(['type' => ['required', Rule::in(array_keys(MessageTemplate::TYPES))], 'body' => ['nullable', 'string', 'max:2000'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id']]);
        $store = isset($data['store_id']) ? Store::find($data['store_id']) : Store::where('status', '!=', StoreStatus::Archived)->first();
        $body = (string) ($data['body'] ?? '');
        $text = $store ? MessageRenderer::render($body, $context->forStore($store, $request->user()))['text'] : $body;

        return response()->json(['text' => $text, 'unknown' => MessageVariables::unknownIn($body, $data['type'])]);
    }

    private function form(MessageTemplate $t): View
    {
        return view('messages.templates.form', [
            'template' => $t,
            'variables' => collect(MessageTemplate::TYPES)->mapWithKeys(fn ($l, $type) => [$type => MessageVariables::allowed($type)]),
            'stores' => Store::where('status', '!=', StoreStatus::Archived)->orderBy('name')->limit(500)->get(['id', 'name']),
        ]);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::in(array_keys(MessageTemplate::TYPES))],
            'body' => ['required', 'string', 'max:2000'],
        ], [], ['name' => 'اسم القالب', 'type' => 'النوع', 'body' => 'نص الرسالة']);

        if ($unknown = MessageVariables::unknownIn($data['body'], $data['type'])) {
            throw ValidationException::withMessages(['body' => 'متغيرات غير متاحة لهذا النوع: {'.implode('}، {', $unknown).'}']);
        }

        return $data;
    }

    private function expect(MessageTemplate $t, array $statuses): void
    {
        if (! in_array($t->status, $statuses, true)) {
            throw ValidationException::withMessages(['status' => 'لا يمكن تنفيذ هذا الإجراء على قالب حالته «'.MessageTemplate::STATUSES[$t->status][0].'».']);
        }
    }
}
