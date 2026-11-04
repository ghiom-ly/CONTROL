<x-layouts.app title="بدء الشفت">
    <x-page-header title="بدء الشفت" subtitle="قبل أي اتصال: سجّل إجمالي الطلبيات ونسبة التسليم الحالية"/>

    <div class="card p-5 max-w-xl">
        <form method="POST" action="{{ route('reports.start') }}" class="space-y-4">
            @csrf
            <div>
                <label class="label">إجمالي الطلبيات المكلّف بمتابعتها <span class="text-red-500">*</span></label>
                <input name="start_total_orders" inputmode="numeric" value="{{ old('start_total_orders') }}" required class="input @error('start_total_orders') input-error @enderror">
                @error('start_total_orders')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="label">نسبة التسليم الحالية % <span class="text-red-500">*</span></label>
                <input name="start_delivery_rate" inputmode="decimal" value="{{ old('start_delivery_rate') }}" required class="input @error('start_delivery_rate') input-error @enderror" placeholder="72">
                @error('start_delivery_rate')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            @error('shift')<p class="field-error">{{ $message }}</p>@enderror
            <button class="btn btn-accent w-full">بدء الشفت</button>
        </form>
    </div>

    @can('imports.create')
        <div class="card p-5 max-w-xl mt-5">
            <h2 class="card-title mb-1">أو: ابدأ الشفت من ملف منظومة الشحن</h2>
            <p class="hint mb-3">يقرأ النظام إجمالي الطلبيات ونسبة التسليم والمتاجر وأوردراتها من الملف.</p>
            <form method="POST" action="{{ route('imports.shift.upload') }}" enctype="multipart/form-data" class="flex flex-wrap items-center gap-2">
                @csrf
                <input type="hidden" name="kind" value="shift_start">
                <input type="file" name="file" accept=".xlsx,.xls,.csv" required class="text-sm">
                <button class="btn btn-primary"><x-icon name="upload" class="size-4"/> رفع ملف البداية</button>
            </form>
            @error('file')<p class="field-error">{{ $message }}</p>@enderror
        </div>
    @endcan
</x-layouts.app>
