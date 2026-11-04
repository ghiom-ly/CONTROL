<?php

namespace App\Http\Controllers;

use App\Http\Requests\Reports\UploadAttachmentRequest;
use App\Models\Attachment;
use App\Models\DailyReport;
use App\Services\AttachmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    public function store(UploadAttachmentRequest $request, DailyReport $report, AttachmentService $service): RedirectResponse
    {
        foreach ($request->file('files') as $file) {
            $service->storeForReport($report, $file, $request->validated('category'), $request->user());
        }

        return back()->with('success', 'تم رفع السكرينات.');
    }

    public function show(Request $request, Attachment $attachment): StreamedResponse
    {
        $this->authorize('view', $this->parentReport($attachment));

        return Storage::disk($attachment->disk)->response($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type ?? 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Request $request, Attachment $attachment): RedirectResponse
    {
        $this->authorize('update', $this->parentReport($attachment));
        $attachment->delete();

        return back()->with('success', 'تم حذف الصورة.');
    }

    private function parentReport(Attachment $attachment): DailyReport
    {
        abort_unless($attachment->attachable_type === DailyReport::class, 404);

        return DailyReport::findOrFail($attachment->attachable_id);
    }
}
