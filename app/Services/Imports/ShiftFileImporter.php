<?php

namespace App\Services\Imports;

use App\Enums\ImportStatus;
use App\Models\DailyReport;
use App\Models\ImportBatch;
use App\Models\ImportMapping;
use App\Models\ImportRow;
use App\Models\User;
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

    public function cancel(ImportBatch $batch): void
    {
        $batch->update(['status' => ImportStatus::Cancelled]);
    }

    private function mappingFits(array $mapping, array $headers): bool
    {
        return array_diff(array_values(array_filter($mapping['columns'])), $headers) === [];
    }
}
