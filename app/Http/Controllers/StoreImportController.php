<?php

namespace App\Http\Controllers;

use App\Enums\ImportStatus;
use App\Models\ImportBatch;
use App\Services\Imports\StoreListImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StoreImportController extends Controller
{
    public function __construct(private readonly StoreListImporter $importer) {}

    public function create(): View
    {
        $this->authorize('stores.manage');

        return view('stores.import', ['batch' => null, 'preview' => null]);
    }

    public function upload(Request $request): RedirectResponse
    {
        $this->authorize('stores.manage');
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:'.config('nours.uploads.max_size_kb')]],
            ['file.mimes' => 'يُقبل ملف Excel أو CSV فقط.'], ['file' => 'الملف']);
        $batch = $this->importer->upload($request->file('file'), $request->user());

        return redirect()->route('stores.import.show', $batch);
    }

    public function show(ImportBatch $batch): View
    {
        $this->guard($batch, false);

        return view('stores.import', ['batch' => $batch, 'preview' => $this->importer->preview($batch)]);
    }

    public function mapping(Request $request, ImportBatch $batch): RedirectResponse
    {
        $this->guard($batch, true);
        $data = $request->validate(['columns' => ['required', 'array'], 'columns.*' => ['nullable', 'string', 'max:200']]);
        $this->importer->saveMapping($batch, $data['columns']);

        return redirect()->route('stores.import.show', $batch);
    }

    public function apply(Request $request, ImportBatch $batch): RedirectResponse
    {
        $this->guard($batch, true);
        ['created' => $c, 'updated' => $u] = $this->importer->apply($batch, $request->user());

        return redirect()->route('stores.index')->with('success', "تم الاستيراد: {$c} متجر جديد، {$u} متجر حُدّث.");
    }

    private function guard(ImportBatch $batch, bool $editing): void
    {
        $this->authorize('stores.manage');
        abort_unless($batch->report_type === 'stores', 404);
        abort_if($editing && $batch->status !== ImportStatus::Uploaded, 403);
    }
}
