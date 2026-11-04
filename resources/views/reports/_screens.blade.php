{{-- السكرينات حسب التصنيف. $editable = يسمح بالرفع والحذف --}}
@php $attachments = $report->attachments()->latest()->get()->groupBy('category'); @endphp
<section class="card p-5 mt-5 space-y-4">
    <h2 class="card-title">السكرينات</h2>
    @foreach(\App\Models\Attachment::REPORT_CATEGORIES as $cat => $label)
        <div>
            <p class="label">{{ $label }} ({{ ($attachments[$cat] ?? collect())->count() }})</p>
            <div class="flex flex-wrap gap-3">
                @foreach($attachments[$cat] ?? [] as $a)
                    <div class="relative">
                        <a href="{{ route('attachments.show', $a) }}" target="_blank">
                            <img src="{{ route('attachments.show', $a) }}" alt="{{ $a->original_name }}" class="h-24 w-24 rounded-lg border object-cover">
                        </a>
                        @if($editable ?? false)
                            <form method="POST" action="{{ route('attachments.destroy', $a) }}" class="absolute top-1 left-1">
                                @csrf @method('DELETE')
                                <button class="rounded bg-white/90 p-0.5 text-red-600" aria-label="حذف"><x-icon name="x" class="size-4"/></button>
                            </form>
                        @endif
                    </div>
                @endforeach
            </div>
            @if($editable ?? false)
                <form method="POST" action="{{ route('reports.attachments.store', $report) }}" enctype="multipart/form-data" class="mt-2 flex flex-wrap items-center gap-2">
                    @csrf
                    <input type="hidden" name="category" value="{{ $cat }}">
                    <input type="file" name="files[]" accept="image/png,image/jpeg,image/webp" multiple required class="text-sm">
                    <button class="btn btn-light btn-sm"><x-icon name="upload" class="size-4"/> رفع</button>
                </form>
            @endif
        </div>
    @endforeach
    @error('files')<p class="field-error">{{ $message }}</p>@enderror
    @error('files.*')<p class="field-error">{{ $message }}</p>@enderror
</section>
