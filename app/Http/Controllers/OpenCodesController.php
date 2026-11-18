<?php

namespace App\Http\Controllers;

use App\Enums\FollowupResult;
use App\Enums\StoreStatus;
use App\Models\Attachment;
use App\Models\Branch;
use App\Models\DailyReport;
use App\Models\ShipmentFollowup;
use App\Models\Store;
use App\Support\WhatsAppNumber;
use App\Support\WhatsAppText;
use App\Models\User;
use App\Services\OpenCodesService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OpenCodesController extends Controller
{
    public function __construct(private readonly OpenCodesService $codes) {}

    public function index(Request $request): View
    {
        $this->authorize('shipments.view');

        $filters = $request->validate([
            'tab' => ['nullable', Rule::in(array_keys(OpenCodesService::TABS))],
            'store_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
        $tab = $filters['tab'] ?? 'customer';
        $viewer = $request->user();

        $shipments = $this->codes->query($tab, $filters, $viewer)
            ->with(['store:id,name,code', 'lastFollowupUser:id,name'])
            ->orderBy('store_id')->orderByDesc('last_followup_at')
            ->limit(2000)->get();

        return view('open-codes.index', [
            'tab' => $tab,
            'filters' => $filters,
            'groups' => $shipments->groupBy('store_id'),
            'counts' => collect(OpenCodesService::TABS)->map(fn ($r, $t) => $this->codes->query($t, $filters, $viewer)->count()),
            'stores' => Store::where('status', '!=', StoreStatus::Archived)->orderBy('name')->get(['id', 'name']),
            'users' => $viewer->hasPermission('reports.view_all') ? User::orderBy('name')->get(['id', 'name']) : collect(),
            'canMark' => $viewer->hasPermission('reports.review'),
            'branches' => $tab === 'courier' ? Branch::orderBy('name')->get(['id', 'name']) : collect(),
        ]);
    }

    /** رسالة واتساب جاهزة لمسؤول مناديب الفرع: الأكواد اللي ما رد عليها المندوب + سكرينات المندوب من تقاريرها */
    public function branchMessage(Request $request): View
    {
        $this->authorize('shipments.view');

        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'shipment_ids' => ['required', 'array', 'min:1', 'max:2000'],
            'shipment_ids.*' => ['integer'],
        ], [], ['branch_id' => 'الفرع', 'shipment_ids' => 'الأكواد']);
        $branch = Branch::findOrFail($data['branch_id']);

        // نفس صلاحية صفحة الأكواد: الموظف يرسل أكواده هو بس
        $shipments = $this->codes->query('courier', [], $request->user())
            ->whereIn('id', $data['shipment_ids'])->with('store:id,name')
            ->orderBy('store_id')->get();
        abort_if($shipments->isEmpty(), 404);

        $lines = ['*أكواد لم يرد عليها المندوب — '.$branch->name.'*'];
        if ($branch->couriers_manager_name) {
            $lines[] = 'السلام عليكم أ. '.$branch->couriers_manager_name;
        }
        foreach ($shipments->groupBy('store_id') as $items) {
            $lines[] = '';
            $lines[] = '*'.$items->first()->store->name.'* ('.$items->count().')';
            foreach ($items as $s) {
                $lines[] = $s->code;
            }
        }
        $lines[] = '';
        $lines[] = 'المجموع: '.$shipments->count().' — نرجو المتابعة مع المناديب، والسكرينات مرفقة.';
        $text = implode("\n", $lines);

        // سكرينات المندوب من التقارير اللي سجلت آخر «لم يرد المندوب» لهذه الأكواد
        $reportIds = ShipmentFollowup::whereIn('shipment_id', $shipments->pluck('id'))
            ->where('result', FollowupResult::CourierNoAnswer)->whereNotNull('daily_report_id')
            ->groupBy('shipment_id')->selectRaw('MAX(daily_report_id) as rid')->pluck('rid')->unique();
        $screens = Attachment::where('attachable_type', DailyReport::class)->whereIn('attachable_id', $reportIds)
            ->where('category', 'courier_screens')->latest()->limit(60)->get()
            ->filter(fn (Attachment $a) => $request->user()->can('view', DailyReport::find($a->attachable_id)));

        $phone = WhatsAppNumber::normalize($branch->couriers_manager_phone);

        return view('open-codes.branch-message', [
            'branch' => $branch,
            'text' => $text,
            'waUrl' => WhatsAppText::url($phone, $text),
            'hasPhone' => $phone !== null,
            'screens' => $screens,
        ]);
    }

    public function mark(Request $request): RedirectResponse
    {
        $this->authorize('reports.review');

        $data = $request->validate([
            'shipment_ids' => ['required', 'array', 'min:1', 'max:2000'],
            'shipment_ids.*' => ['integer', 'exists:shipments,id'],
            'action' => ['required', 'in:forwarded,resolved'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [], ['shipment_ids' => 'الأكواد', 'note' => 'الملاحظة']);

        $n = $this->codes->mark($data['shipment_ids'], $data['action'], $data['note'] ?? null, $request->user());

        return back()->with('success', "تم تحديث {$n} كود.");
    }
}
