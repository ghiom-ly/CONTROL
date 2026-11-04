<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\DailyReport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class AttachmentService
{
    public const DISK = 'private';

    /** يُخزَّن على القرص الخاص (storage/app/private/nours) ولا يُخدم إلا عبر مسار محمي */
    public function storeForReport(DailyReport $report, UploadedFile $file, string $category, User $actor): Attachment
    {
        $ext = strtolower($file->extension() ?: $file->getClientOriginalExtension());
        $path = $file->storeAs('attachments/'.now()->format('Y/m'), Str::uuid().'.'.$ext, self::DISK);

        return $report->attachments()->create([
            'category' => $category,
            'disk' => self::DISK,
            'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()),
            'uploaded_by' => $actor->id,
        ]);
    }
}
