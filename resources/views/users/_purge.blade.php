{{-- حذف نهائي للموظف — المدير العام فقط، بتأكيد كتابة اسم المستخدم --}}
@if(auth()->user()->isSuperAdmin() && ! auth()->user()->is($u) && $u->hasRole(\App\Models\Role::EMPLOYEE, \App\Models\Role::VIEWER))
    <div x-data="{ open: false }" class="inline-block">
        <button type="button" class="btn btn-sm bg-red-600 text-white hover:bg-red-700" x-on:click="open = !open">حذف نهائي</button>
        <form x-show="open" x-cloak method="POST" action="{{ route('users.purge', $u->id) }}" class="mt-2 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800 space-y-2">
            @csrf @method('DELETE')
            <p><b>تحذير:</b> سيُحذف {{ $u->name }} <b>نهائياً</b> مع كل تقاريره وحضوره واتصالاته ومشكلاته المبلّغ عنها، ولا يمكن التراجع.</p>
            <p>للتأكيد اكتب اسم المستخدم: <b class="ltr">{{ $u->username }}</b></p>
            <input name="confirm" class="input ltr" autocomplete="off" required>
            @error('confirm')<p class="field-error">{{ $message }}</p>@enderror
            <button class="btn btn-sm bg-red-600 text-white">حذف نهائي الآن</button>
        </form>
    </div>
@endif
