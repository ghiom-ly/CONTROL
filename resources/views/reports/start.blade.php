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
</x-layouts.app>
