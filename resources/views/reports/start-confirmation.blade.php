<x-layouts.app title="بدء الشفت">
    <x-page-header title="بدء الشفت" subtitle="تقرير تأكيد الطلبيات — بدء الشفت يُسجَّل كحضور"/>
    <form method="POST" action="{{ route('reports.start') }}" class="card max-w-xl p-6 space-y-3">
        @csrf
        <p class="text-sm text-slate-600">اضغطي «بدء الشفت» عند بداية العمل. خلال الشفت أضيفي الطلبيات المؤكدة والملغاة ولا يرد لكل متجر، ثم أرسلي التقرير في نهاية الشفت.</p>
        @error('shift')<p class="field-error">{{ $message }}</p>@enderror
        <button class="btn btn-accent">بدء الشفت</button>
    </form>
</x-layouts.app>
