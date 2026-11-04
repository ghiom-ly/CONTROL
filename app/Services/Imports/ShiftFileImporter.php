<?php

namespace App\Services\Imports;

use App\Enums\ImportStatus;
use App\Models\DailyReport;
use App\Models\ImportBatch;
use App\Models\ImportMapping;
use App\Models\ImportRow;
use App\Models\ReportStore;
use App\Models\Store;
use App\Models\User;
use App\Services\Reports\ShiftService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ملف منظومة الشحن لشفت الموظف: رفع ← مطابقة أعمدة ← معاينة ← تطبيق على التقرير.
 */
class ShiftFileImporter
{
    public const DISK = 'private';

    public function __construct(
        private readonly SpreadsheetReader $reader,
        private readonly ShiftFileCalculator $calculator,
        private readonly StoreMatcher $matcher,
        private readonly ShiftService $shifts,
    ) {}

    /** @return array{batch: ImportBatch, warnings: list<string>} */
    public function upload(UploadedFile $file, string $kind, ?DailyReport $report, User $actor): array
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $ext = in_array($ext, ['xlsx', 'xls'], true) ? $ext : 'csv';   // .txt المصدَّر كنص يُعامل كـ CSV
        $hash = hash_file('sha256', $file->getRealPath());
        $path = $file->storeAs('imports/'.now()->format('Y/m'), Str::uuid().'.'.$ext, self::DISK);

        try {
            $data = $this->reader->read(Storage::disk(self::DISK)->path($path));
        } catch (ImportException $e) {
            Storage::disk(self::DISK)->delete($path);
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        $warnings = [];
        if ($report && ImportBatch::where('daily_report_id', $report->id)->where('file_hash', $hash)
            ->where('status', '!=', ImportStatus::Cancelled)->exists()) {
            $warnings[] = 'هذا الملف رُفع سابقاً لهذا التقرير.';
        }

        $batch = DB::transaction(function () use ($file, $ext, $hash, $path, $kind, $report, $actor, $data) {
            $saved = ImportMapping::where('signature', HeaderSignature::of($data['headers']))->first();
            $batch = ImportBatch::create([
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'disk' => self::DISK, 'stored_path' => $path, 'file_hash' => $hash, 'file_size' => $file->getSize(),
                'file_type' => $ext, 'report_type' => $kind, 'daily_report_id' => $report?->id,
                'work_date' => $report?->work_date ?? today(), 'uploaded_by' => $actor->id,
                'status' => ImportStatus::Uploaded, 'total_rows' => count($data['rows']),
                'column_mapping' => $saved && $this->mappingFits($saved->toMapping(), $data['headers']) ? $saved->toMapping() : null,
                'meta' => ['headers' => $data['headers'], 'mapping_source' => $saved ? 'saved' : null, 'store_choices' => []],
            ]);

            foreach (array_chunk($data['rows'], 500) as $chunk) {
                ImportRow::insert(array_map(fn ($r) => [
                    'import_batch_id' => $batch->id, 'row_number' => $r['row'],
                    'raw_data' => json_encode($r['cells'], JSON_UNESCAPED_UNICODE), 'status' => 'pending',
                    'created_at' => now(), 'updated_at' => now(),
                ], $chunk));
            }

            return $batch;
        });

        return ['batch' => $batch, 'warnings' => $warnings];
    }

    /** @param array<string, int|string> $storeChoices اسم في الملف => معرّف متجر أو 'ignore' */
    public function saveMapping(ImportBatch $batch, array $mapping, array $storeChoices): void
    {
        $headers = $batch->meta['headers'] ?? [];
        $errors = [];
        foreach (array_filter($mapping['columns']) as $key => $col) {
            if (! in_array($col, $headers, true)) {
                $errors["columns.{$key}"] = 'العمود غير موجود في الملف.';
            }
        }
        $required = $mapping['layout'] === 'orders' ? ['store', 'status'] : ['store', 'total'];
        foreach ($required as $key) {
            if (empty($mapping['columns'][$key])) {
                $errors["columns.{$key}"] = 'هذا العمود مطلوب.';
            }
        }
        if ($mapping['layout'] === 'stores' && empty($mapping['columns']['delivered']) && empty($mapping['columns']['rate'])) {
            $errors['columns.delivered'] = 'اختر عمود «تم التسليم» أو عمود النسبة.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $batch->update([
            'column_mapping' => [
                'layout' => $mapping['layout'],
                'columns' => array_filter($mapping['columns']),
                'delivered_values' => array_values($mapping['delivered_values'] ?? []),
            ],
            'meta' => array_merge($batch->meta ?? [], ['store_choices' => $storeChoices, 'mapping_source' => 'manual']),
        ]);
    }

    /** null إن لم تكتمل المطابقة */
    public function preview(ImportBatch $batch): ?array
    {
        $mapping = $batch->column_mapping;
        if (! $mapping) {
            return null;
        }
        $choices = $batch->meta['store_choices'] ?? [];
        $rows = $batch->rows()->orderBy('row_number')->get(['row_number', 'raw_data'])
            ->map(fn ($r) => ['row' => $r->row_number, 'cells' => $r->raw_data])->all();

        return $this->calculator->compute($rows, $mapping, function (string $name) use ($choices) {
            if (isset($choices[$name]) && $choices[$name] !== '') {
                return $choices[$name] === 'ignore' ? 'ignore' : (int) $choices[$name];
            }

            return $this->matcher->match($name);
        });
    }

    /**
     * يطبّق الملف على التقرير. ملف البداية بلا تقرير يبدأ الشفت.
     * القيم التي مصدرها «يدوي» لا تُستبدل أبداً (إلا نسبة النهاية بتأكيد صريح).
     */
    public function apply(ImportBatch $batch, User $actor, bool $replaceEndRate = false): DailyReport
    {
        $preview = $this->preview($batch);
        if ($preview === null || $preview['total'] === null) {
            throw ValidationException::withMessages(['apply' => 'أكمل مطابقة الأعمدة أولاً — لا توجد أرقام صالحة في الملف.']);
        }

        return DB::transaction(function () use ($batch, $actor, $preview, $replaceEndRate) {
            $batch = ImportBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if ($batch->status !== ImportStatus::Uploaded) {
                throw ValidationException::withMessages(['apply' => 'طُبّق هذا الملف أو أُلغي سابقاً.']);
            }

            $report = $batch->daily_report_id ? DailyReport::whereKey($batch->daily_report_id)->lockForUpdate()->first() : null;
            if (! $report) {
                $report = $this->shifts->start($actor, (int) $preview['total'], (float) ($preview['rate'] ?? 0));
                $report->forceFill(['start_delivery_rate' => $preview['rate'], 'value_sources' => ['start_total_orders' => 'file', 'start_delivery_rate' => 'file']])->save();
            }

            $batch->report_type === 'shift_start'
                ? $this->applyStart($report, $preview)
                : $this->applyEnd($report, $preview, $replaceEndRate);

            $this->rememberChoices($batch, $actor);
            ImportMapping::updateOrCreate(
                ['signature' => HeaderSignature::of($batch->meta['headers'] ?? [])],
                ['layout' => $batch->column_mapping['layout'], 'columns' => $batch->column_mapping['columns'],
                    'delivered_values' => $batch->column_mapping['delivered_values'] ?? [], 'created_by' => $actor->id],
            );

            $rejectedRows = array_column($preview['rejected'], 'reason', 'row');
            $batch->rows()->get()->each(fn (ImportRow $r) => $r->update(isset($rejectedRows[$r->row_number])
                ? ['status' => 'rejected', 'errors' => [$rejectedRows[$r->row_number]]]
                : ['status' => 'accepted']));

            $batch->update([
                'daily_report_id' => $report->id, 'work_date' => $report->work_date, 'status' => ImportStatus::NeedsReview,
                'accepted_rows' => $batch->total_rows - count($preview['rejected']), 'rejected_rows' => count($preview['rejected']),
                'processing_finished_at' => now(),
                'meta' => array_merge($batch->meta, ['result' => ['total' => $preview['total'], 'delivered' => $preview['delivered'], 'rate' => $preview['rate']]]),
            ]);

            return $report->fresh();
        });
    }

    private function applyStart(DailyReport $report, array $preview): void
    {
        $sources = $report->value_sources ?? [];
        $fill = ['file_values' => array_merge($report->file_values ?? [], ['start_total' => $preview['total'], 'start_rate' => $preview['rate']])];
        foreach (['start_total_orders' => $preview['total'], 'start_delivery_rate' => $preview['rate']] as $field => $value) {
            if ($report->{$field} === null || ($sources[$field] ?? 'manual') === 'file') {
                $fill[$field] = $value;
                $sources[$field] = 'file';
            }
        }
        $report->fill($fill + ['value_sources' => $sources])->save();

        foreach ($this->matchedStores($preview) as $storeId => $s) {
            $rs = ReportStore::firstOrNew(['daily_report_id' => $report->id, 'store_id' => $storeId]);
            if (! $rs->exists) {
                $rs->is_outside_assignment = Store::find($storeId)?->assigned_user_id !== $report->user_id;
            }
            $rs->file_orders_count = $s['total'];
            $rs->file_delivered_count = $s['delivered'];
            if ($rs->shipments_count === null || $rs->shipments_count_source === 'file') {
                $rs->shipments_count = $s['total'];
                $rs->shipments_count_source = 'file';
            }
            $rs->save();
        }
    }

    private function applyEnd(DailyReport $report, array $preview, bool $replace): void
    {
        $sources = $report->value_sources ?? [];
        $fill = ['file_values' => array_merge($report->file_values ?? [], ['end_rate' => $preview['rate']])];
        if ($report->end_delivery_rate === null || ($sources['end_delivery_rate'] ?? 'manual') === 'file' || $replace) {
            $fill['end_delivery_rate'] = $preview['rate'];
            $sources['end_delivery_rate'] = 'file';
        }
        $report->fill($fill + ['value_sources' => $sources])->save();

        $existing = $report->stores()->get()->keyBy('store_id');
        foreach ($this->matchedStores($preview) as $storeId => $s) {
            $existing->get($storeId)?->update(['end_file_orders_count' => $s['total'], 'end_file_delivered_count' => $s['delivered']]);
        }
    }

    /** @return array<int, array{total:int, delivered:?int}> */
    private function matchedStores(array $preview): array
    {
        $out = [];
        foreach ($preview['stores'] as $s) {
            if ($s['store_id'] !== null) {
                $out[$s['store_id']] = $s;
            }
        }

        return $out;
    }

    private function rememberChoices(ImportBatch $batch, User $actor): void
    {
        foreach ($batch->meta['store_choices'] ?? [] as $name => $choice) {
            if ($choice !== null && $choice !== '' && $choice !== 'ignore') {
                $this->matcher->remember((string) $name, (int) $choice, $actor);
            }
        }
    }

    public function cancel(ImportBatch $batch): void
    {
        $batch->update(['status' => ImportStatus::Cancelled]);
    }

    private function mappingFits(array $mapping, array $headers): bool
    {
        return array_diff(array_values(array_filter($mapping['columns'])), $headers) === [];
    }
}
