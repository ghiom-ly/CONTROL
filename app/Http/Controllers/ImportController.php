<?php

namespace App\Http\Controllers;

use App\Enums\ImportStatus;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** مركز الاستيراد: كل الملفات والنصوص المستوردة وحالتها */
class ImportController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', ImportBatch::class);
        $actor = $request->user();
        $all = $actor->hasPermission('imports.view_all');

        $filters = $request->validate([
            'report_type' => ['nullable', Rule::in(array_keys(ImportBatch::TYPES))],
            'status' => ['nullable', Rule::enum(ImportStatus::class)],
            'user_id' => ['nullable', 'integer'],
        ]);

        $batches = ImportBatch::query()->with(['uploader:id,name', 'report:id,report_number,status'])
            ->whereIn('report_type', array_keys(ImportBatch::TYPES))
            ->when(! $all, fn ($q) => $q->where('uploaded_by', $actor->id))
            ->when($all && ($filters['user_id'] ?? null), fn ($q) => $q->where('uploaded_by', $filters['user_id']))
            ->when($filters['report_type'] ?? null, fn ($q, $t) => $q->where('report_type', $t))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->latest('id')->paginate(config('nours.per_page'))->withQueryString();

        return view('imports.index', [
            'batches' => $batches, 'filters' => $filters, 'all' => $all,
            'users' => $all ? User::orderBy('name')->get(['id', 'name']) : collect(),
        ]);
    }

    public function download(ImportBatch $batch): StreamedResponse
    {
        $this->authorize('view', $batch);
        abort_unless($batch->stored_path && Storage::disk($batch->disk)->exists($batch->stored_path), 404);

        return Storage::disk($batch->disk)->download($batch->stored_path, $batch->original_name);
    }
}
