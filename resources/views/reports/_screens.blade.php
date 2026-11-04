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
        </div>
    @endforeach
    @error('files')<p class="field-error">{{ $message }}</p>@enderror
    @error('files.*')<p class="field-error">{{ $message }}</p>@enderror
</section>
