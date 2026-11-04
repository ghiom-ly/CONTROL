<?php

namespace App\Services\Imports;

use App\Enums\ImportStatus;
use App\Enums\StoreStatus;
use App\Models\ImportBatch;
use App\Models\ImportMapping;
use App\Models\ImportRow;
use App\Models\Store;
use App\Models\User;
use App\Support\ArabicNormalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * استيراد قائمة المتاجر من Excel: يضيف الجديد ويحدّث الموجود (الخلايا غير الفارغة فقط).
 * لا يحذف ولا يؤرشف شيئاً.
 */
class StoreListImporter
{
    public const DISK = 'private';

    private const PHONE = '/^[0-9+\-\s]{6,30}$/';

    public function __construct(
        private readonly SpreadsheetReader $reader,
        private readonly StoreMatcher $matcher,
    ) {}

    public function upload(UploadedFile $file, User $actor): ImportBatch
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $ext = in_array($ext, ['xlsx', 'xls'], true) ? $ext : 'csv';
        $path = $file->storeAs('imports/'.now()->format('Y/m'), Str::uuid().'.'.$ext, self::DISK);

        try {
            $data = $this->reader->read(Storage::disk(self::DISK)->path($path));
        } catch (ImportException $e) {
            Storage::disk(self::DISK)->delete($path);
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        return DB::transaction(function () use ($file, $ext, $path, $data, $actor) {
            $saved = ImportMapping::where('signature', HeaderSignature::of($data['headers']))->where('layout', 'store_list')->first();
            $fits = $saved && array_diff(array_values(array_filter($saved->columns)), $data['headers']) === [];

            $batch = ImportBatch::create([
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255), 'disk' => self::DISK, 'stored_path' => $path,
                'file_hash' => hash_file('sha256', $file->getRealPath()), 'file_size' => $file->getSize(), 'file_type' => $ext,
                'report_type' => 'stores', 'work_date' => today(), 'uploaded_by' => $actor->id, 'status' => ImportStatus::Uploaded,
                'total_rows' => count($data['rows']),
                'column_mapping' => $fits ? ['layout' => 'store_list', 'columns' => $saved->columns] : null,
                'meta' => ['headers' => $data['headers'], 'mapping_source' => $fits ? 'saved' : null],
            ]);
            foreach (array_chunk($data['rows'], 500) as $chunk) {
                ImportRow::insert(array_map(fn ($r) => [
                    'import_batch_id' => $batch->id, 'row_number' => $r['row'], 'status' => 'pending',
                    'raw_data' => json_encode($r['cells'], JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now(),
                ], $chunk));
            }

            return $batch;
        });
    }

    public function saveMapping(ImportBatch $batch, array $columns): void
    {
        $headers = $batch->meta['headers'] ?? [];
        $errors = [];
        if (empty($columns['name'])) {
            $errors['columns.name'] = 'عمود اسم المتجر مطلوب.';
        }
        foreach (array_filter($columns) as $k => $col) {
            if (! in_array($col, $headers, true)) {
                $errors["columns.{$k}"] = 'العمود غير موجود في الملف.';
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $batch->update(['column_mapping' => ['layout' => 'store_list', 'columns' => array_filter($columns)],
            'meta' => array_merge($batch->meta ?? [], ['mapping_source' => 'manual'])]);
    }

    public function preview(ImportBatch $batch): ?array
    {
        $cols = $batch->column_mapping['columns'] ?? null;
        if (! $cols) {
            return null;
        }
        $rows = [];
        $seen = [];
        $counts = ['new' => 0, 'update' => 0, 'same' => 0, 'rejected' => 0];
        $existing = [];

        foreach ($batch->rows()->orderBy('row_number')->get(['row_number', 'raw_data']) as $r) {
            $cell = fn ($k) => isset($cols[$k]) ? $this->text($r->raw_data[$cols[$k]] ?? null) : null;
            $name = $cell('name');
            $phone = $cell('phone');
            $phone = $phone === null ? null : ArabicNormalizer::digits($phone);
            $owner = $cell('owner');
            $row = ['row' => $r->row_number, 'name' => $name, 'phone' => $phone, 'owner' => $owner,
                'action' => 'rejected', 'store_id' => null, 'reason' => null, 'archived' => false];

            $key = ArabicNormalizer::normalize($name);
            if ($name === null || $key === '') {
                $row['reason'] = 'اسم المتجر فارغ';
            } elseif (isset($seen[$key])) {
                $row['reason'] = 'مكرر في الملف';
            } elseif ($phone !== null && ! preg_match(self::PHONE, $phone)) {
                $row['reason'] = 'رقم الهاتف غير صالح';
            } else {
                $seen[$key] = true;
                $id = $this->matcher->matchAny($name);
                if ($id === null) {
                    $row['action'] = 'new';
                } else {
                    $store = $existing[$id] ??= Store::withTrashed()->find($id);
                    $row['store_id'] = $id;
                    $row['archived'] = $store->status === StoreStatus::Archived;
                    $changes = ($phone !== null && $phone !== $store->phone) || ($owner !== null && $owner !== $store->owner_name);
                    $row['action'] = $changes ? 'update' : 'same';
                }
            }
            $counts[$row['action']]++;
            $rows[] = $row;
        }

        return ['rows' => $rows, 'counts' => $counts];
    }

    /** @return array{created: int, updated: int} */
    public function apply(ImportBatch $batch, User $actor): array
    {
        $preview = $this->preview($batch);
        if ($preview === null) {
            throw ValidationException::withMessages(['apply' => 'أكمل مطابقة الأعمدة أولاً.']);
        }

        return DB::transaction(function () use ($batch, $actor, $preview) {
            $batch = ImportBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if ($batch->status !== ImportStatus::Uploaded) {
                throw ValidationException::withMessages(['apply' => 'طُبّق هذا الملف سابقاً.']);
            }
            $created = $updated = 0;
            foreach ($preview['rows'] as $row) {
                if ($row['action'] === 'new') {
                    Store::create(['name' => $row['name'], 'phone' => $row['phone'], 'owner_name' => $row['owner'],
                        'status' => StoreStatus::Active, 'created_by' => $actor->id]);
                    $created++;
                } elseif ($row['action'] === 'update') {
                    $store = Store::withTrashed()->find($row['store_id']);
                    $store->fill(array_filter(['phone' => $row['phone'], 'owner_name' => $row['owner']], fn ($v) => $v !== null)
                        + ['updated_by' => $actor->id])->save();
                    $updated++;
                }
            }

            $rejected = array_column(array_filter($preview['rows'], fn ($r) => $r['action'] === 'rejected'), 'reason', 'row');
            foreach ($batch->rows()->get() as $r) {
                $r->update(isset($rejected[$r->row_number]) ? ['status' => 'rejected', 'errors' => [$rejected[$r->row_number]]] : ['status' => 'accepted']);
            }
            ImportMapping::updateOrCreate(['signature' => HeaderSignature::of($batch->meta['headers'] ?? [])],
                ['layout' => 'store_list', 'columns' => $batch->column_mapping['columns'], 'delivered_values' => [], 'created_by' => $actor->id]);
            $batch->update(['status' => ImportStatus::Approved, 'approved_by' => $actor->id, 'approved_at' => now(),
                'accepted_rows' => $batch->total_rows - count($rejected), 'rejected_rows' => count($rejected),
                'meta' => array_merge($batch->meta, ['result' => compact('created', 'updated')])]);

            return compact('created', 'updated');
        });
    }

    private function text(?string $v): ?string
    {
        $v = $v === null ? null : trim(preg_replace('/\s+/u', ' ', $v));

        return $v === '' ? null : $v;
    }
}
