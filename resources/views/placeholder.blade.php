<x-layouts.app :title="$title">
    <x-page-header :title="$title"/>
    <div class="card p-10 text-center">
        <div class="mx-auto grid size-16 place-items-center rounded-2xl bg-brand-50 text-brand-600"><x-icon name="clock" class="size-8"/></div>
        <h2 class="mt-5 text-xl font-extrabold text-navy-900">هذه الوحدة مجدولة في المرحلة {{ $phase }}</h2>
        <p class="mx-auto mt-2 max-w-lg text-sm leading-7 text-slate-500">
            قاعدة البيانات والعلاقات والصلاحيات الخاصة بـ«{{ $title }}» جاهزة منذ المرحلة الأولى.
            ستُفعّل الواجهة والوظائف بالكامل عند تنفيذ المرحلة {{ $phase }}، ولن تُعرض أي بيانات أو أزرار وهمية قبل ذلك.
        </p>
    </div>
</x-layouts.app>
