<x-layouts.app title="الأدوار والصلاحيات">
    <x-page-header title="الإعدادات" subtitle="حدد ما يستطيع كل دور الوصول إليه"/>
    @include('settings._tabs')

    <form method="POST" action="{{ route('settings.roles.update') }}">
        @csrf @method('PUT')
        <div class="card">
            <div class="table-wrap">
                <table class="table">
                    <thead><tr>
                        <th class="min-w-64">الصلاحية</th>
                        @foreach($roles as $role)
                            <th class="text-center">{{ $role->display_name }}<span class="block text-[11px] font-normal text-slate-400">{{ $role->users_count }} مستخدم</span></th>
                        @endforeach
                    </tr></thead>
                    <tbody>
                    @foreach($permissions as $group => $perms)
                        <tr><td colspan="{{ $roles->count() + 1 }}" class="!bg-navy-50/60 !py-2 text-xs font-extrabold text-navy-700">{{ $group }}</td></tr>
                        @foreach($perms as $perm)
                            <tr>
                                <td><span class="font-semibold text-slate-700">{{ $perm->display_name }}</span><span class="block text-[11px] text-slate-400 ltr text-right">{{ $perm->name }}</span></td>
                                @foreach($roles as $role)
                                    <td class="text-center">
                                        @if($role->name === 'super_admin')
                                            <x-icon name="check" class="mx-auto size-5 text-emerald-500"/>
                                        @elseif($perm->name === 'settings.manage')
                                            <span class="text-slate-300" title="محصورة بالمدير العام">—</span>
                                        @else
                                            <input type="checkbox" name="permissions[{{ $role->name }}][]" value="{{ $perm->name }}"
                                                   @checked($role->permissions->contains('name', $perm->name))
                                                   class="size-4 rounded border-slate-300 text-navy-700 focus:ring-navy-500">
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="flex items-center justify-between gap-3 border-t border-slate-100 p-4">
                <p class="text-xs text-slate-500">المدير العام يملك جميع الصلاحيات دائماً. صلاحية الإعدادات محصورة به. كل تعديل يُسجَّل في سجل العمليات.</p>
                <button class="btn btn-primary">حفظ الصلاحيات</button>
            </div>
        </div>
    </form>
</x-layouts.app>
