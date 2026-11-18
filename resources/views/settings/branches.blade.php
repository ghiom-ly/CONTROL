<x-layouts.app title="الفروع">
    <x-page-header title="الإعدادات" subtitle="فروع شركة التوصيل — لإرسال أكواد المناديب اللي ما ردوش لمسؤول مناديب كل فرع"/>
    @include('settings._tabs')

    @php $fields = ['name' => 'اسم الفرع', 'manager_name' => 'المسؤول', 'manager_phone' => 'رقم المسؤول', 'couriers_manager_name' => 'مسؤول المناديب', 'couriers_manager_phone' => 'رقم مسؤول المناديب']; @endphp

    <section class="card mb-5 p-5">
        <h2 class="card-title mb-4">إضافة فرع</h2>
        <form method="POST" action="{{ route('settings.branches.store') }}" class="grid gap-3 md:grid-cols-5 md:items-end">
            @csrf
            @foreach($fields as $f => $label)
                <div>
                    <label class="label" for="new-{{ $f }}">{{ $label }}</label>
                    <input id="new-{{ $f }}" name="{{ $f }}" value="{{ old($f) }}" class="input {{ str_ends_with($f, 'phone') ? 'ltr' : '' }}" maxlength="{{ str_ends_with($f, 'phone') ? 30 : 100 }}" @if($f === 'name') required @endif>
                    @error($f)<p class="field-error">{{ $message }}</p>@enderror
                </div>
            @endforeach
            <div class="md:col-span-5"><button class="btn btn-primary">إضافة الفرع</button></div>
        </form>
    </section>

    <section class="card">
        <div class="card-header"><h2 class="card-title">الفروع ({{ $branches->count() }})</h2></div>
        @if($branches->isEmpty())
            <x-empty-state title="ما فيش فروع مسجلة">أضف أول فرع من النموذج اللي فوق.</x-empty-state>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr>@foreach($fields as $label)<th>{{ $label }}</th>@endforeach<th></th></tr></thead>
                    @foreach($branches as $b)
                        <tbody x-data="{ edit: false }">
                            <tr x-show="!edit">
                                <td class="font-bold">{{ $b->name }}</td>
                                <td>{{ $b->manager_name ?? '—' }}</td>
                                <td class="ltr text-right">{{ $b->manager_phone ?? '—' }}</td>
                                <td>{{ $b->couriers_manager_name ?? '—' }}</td>
                                <td class="ltr text-right">{{ $b->couriers_manager_phone ?? '—' }}</td>
                                <td class="whitespace-nowrap">
                                    <button type="button" class="btn btn-light btn-sm" x-on:click="edit = true">تعديل</button>
                                    <x-confirm-form :action="route('settings.branches.destroy', $b)" method="DELETE" :message="'حذف '.$b->name.'؟'" class="btn btn-light btn-sm text-red-600">حذف</x-confirm-form>
                                </td>
                            </tr>
                            <tr x-show="edit" x-cloak>
                                <td colspan="6">
                                    <form method="POST" action="{{ route('settings.branches.update', $b) }}" class="grid gap-2 md:grid-cols-6 md:items-end">
                                        @csrf @method('PUT')
                                        @foreach($fields as $f => $label)
                                            <div>
                                                <label class="label">{{ $label }}</label>
                                                <input name="{{ $f }}" value="{{ $b->$f }}" class="input {{ str_ends_with($f, 'phone') ? 'ltr' : '' }}" maxlength="{{ str_ends_with($f, 'phone') ? 30 : 100 }}" @if($f === 'name') required @endif>
                                            </div>
                                        @endforeach
                                        <div class="flex gap-2">
                                            <button class="btn btn-primary btn-sm">حفظ</button>
                                            <button type="button" class="btn btn-light btn-sm" x-on:click="edit = false">إلغاء</button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        </tbody>
                    @endforeach
                </table>
            </div>
        @endif
    </section>
</x-layouts.app>
