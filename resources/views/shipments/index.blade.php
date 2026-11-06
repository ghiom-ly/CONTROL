<x-layouts.app title="البحث بالكود">
    <x-page-header title="البحث بالكود" subtitle="اكتب كود الشحنة (رقم الطلب) لترى حالته ومتابعاته"/>
    <form method="GET" action="{{ route('shipments.index') }}" class="card flex max-w-xl gap-2 p-4" data-no-loading>
        <input name="code" class="input ltr" placeholder="مثال: 4749558" maxlength="80" required autofocus>
        <button class="btn btn-primary"><x-icon name="search" class="size-4"/> بحث</button>
    </form>
</x-layouts.app>
