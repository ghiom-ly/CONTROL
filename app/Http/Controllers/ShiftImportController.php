<?php

namespace App\Http\Controllers;

use App\Enums\StoreStatus;
use App\Http\Requests\Imports\SaveMappingRequest;
use App\Http\Requests\Imports\UploadShiftFileRequest;
use App\Models\DailyReport;
use App\Models\ImportBatch;
use App\Models\Store;
use App\Services\Imports\ShiftFileImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ShiftImportController extends Controller
{
    public function __construct(private readonly ShiftFileImporter $importer) {}

    public function upload(UploadShiftFileRequest $request): RedirectResponse
    {
        $actor = $request->user();
        $report = $request->filled('report_id')
            ? DailyReport::find($request->validated('report_id'))
            : DailyReport::where('user_id', $actor->id)->openDraft()->latest('id')->first();

        ['batch' => $batch, 'warnings' => $warnings] = $this->importer->upload($request->file('file'), $request->validated('kind'), $report, $actor);

        $redirect = redirect()->route('imports.show', $batch);

        return $warnings ? $redirect->with('warning', implode("\n", $warnings)) : $redirect;
    }

    public function show(ImportBatch $batch): View
    {
        $this->authorize('view', $batch);
        $batch->load('report:id,report_number,start_total_orders,start_delivery_rate,end_delivery_rate,value_sources');

        return view('imports.shift', [
            'batch' => $batch,
            'preview' => $this->importer->preview($batch),
            'stores' => Store::where('status', '!=', StoreStatus::Archived)->orderBy('name')->get(['id', 'name']),
            'editable' => request()->user()->can('update', $batch),
        ]);
    }

    public function mapping(SaveMappingRequest $request, ImportBatch $batch): RedirectResponse
    {
        $this->importer->saveMapping($batch, $request->validated(), $request->validated('store_choices', []));

        return redirect()->route('imports.show', $batch);
    }

    public function apply(Request $request, ImportBatch $batch): RedirectResponse
    {
        $this->authorize('update', $batch);
        $report = $this->importer->apply($batch, $request->user(), $request->boolean('replace_end_rate'));

        return redirect()->route('reports.edit', $report)->with('success', 'طُبّق ملف المنظومة على التقرير.');
    }

    public function cancel(Request $request, ImportBatch $batch): RedirectResponse
    {
        $this->authorize('update', $batch);
        $this->importer->cancel($batch);

        return $batch->daily_report_id
            ? redirect()->route('reports.edit', $batch->daily_report_id)->with('success', 'أُلغي الملف.')
            : redirect()->route('reports.create')->with('success', 'أُلغي الملف.');
    }
}
