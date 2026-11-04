<?php

namespace App\Http\Controllers;

use App\Enums\StoreStatus;
use App\Models\Store;
use App\Models\User;
use App\Services\Imports\LegacyReportImporter;
use App\Services\Imports\LegacyReportParser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** لصق تقارير واتساب القديمة ← معاينة ← إنشاء تقرير لكل واحد */
class LegacyImportController extends Controller
{
    private const SESSION = 'legacy_import_items';

    public function __construct(private readonly LegacyReportImporter $importer) {}

    public function create(): View
    {
        $this->authorize('imports.create');

        return view('imports.legacy-paste');
    }

    public function preview(Request $request, LegacyReportParser $parser): RedirectResponse
    {
        $this->authorize('imports.create');
        $data = $request->validate(['text' => ['required', 'string', 'max:200000']], [], ['text' => 'نص التقارير']);

        $items = $parser->parse($data['text']);
        if ($items === []) {
            return back()->withInput()->withErrors(['text' => 'لم يُعثر على أي تقرير — كل تقرير يبدأ بسطر «ريبورت تاريخ:».']);
        }
        $request->session()->put(self::SESSION, $items);

        return redirect()->route('imports.legacy.review');
    }

    public function review(Request $request): View|RedirectResponse
    {
        $this->authorize('imports.create');
        $items = $request->session()->get(self::SESSION, []);
        if ($items === []) {
            return redirect()->route('imports.legacy.create');
        }
        $actor = $request->user();
        $canOthers = $actor->hasPermission('reports.view_all');
        $users = $canOthers ? User::where('status', 'active')->orderBy('name')->get(['id', 'name']) : collect([$actor]);

        $annotated = [];
        foreach ($items as $i => $item) {
            $suggested = $canOthers ? ($users->firstWhere('name', $item['employee_name'])?->id ?? null) : $actor->id;
            $annotated[$i] = $this->importer->annotate($item, $suggested) + ['suggested_user_id' => $suggested];
        }

        return view('imports.legacy-preview', [
            'items' => $annotated,
            'users' => $users,
            'stores' => Store::where('status', '!=', StoreStatus::Archived)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('imports.create');
        $data = $request->validate([
            'index' => ['required', 'integer', 'min:0'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'store_choices' => ['array'],
            'store_choices.*' => ['nullable'],
            'list_stores.customer' => ['nullable', 'integer', 'exists:stores,id'],
            'list_stores.courier' => ['nullable', 'integer', 'exists:stores,id'],
        ]);
        $actor = $request->user();
        abort_if((int) $data['user_id'] !== $actor->id && ! $actor->hasPermission('reports.view_all'), 403);

        $items = $request->session()->get(self::SESSION, []);
        abort_unless(isset($items[$data['index']]), 404);

        $result = $this->importer->create($items[$data['index']], User::findOrFail($data['user_id']), $actor,
            array_filter($data['store_choices'] ?? [], fn ($v) => $v !== null && $v !== ''), array_filter($data['list_stores'] ?? []));

        unset($items[$data['index']]);
        $request->session()->put(self::SESSION, $items);

        $msg = 'أُنشئ التقرير '.$result['report']->report_number.' وأُرسل للمراجعة.';
        $redirect = $items ? redirect()->route('imports.legacy.review') : redirect()->route('reports.show', $result['report']);

        return $result['warnings'] ? $redirect->with('success', $msg)->with('warning', implode("\n", $result['warnings'])) : $redirect->with('success', $msg);
    }
}
