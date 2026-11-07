<x-layouts.app title="لوحة التحكم">
    <x-page-header title="لوحة التحكم" :subtitle="'ملخص '.$period->label().($stats['scope_all'] ? ' — جميع الموظفين' : ' — تقاريري')">
        <x-slot:actions><x-period-filter :period="$period"/></x-slot:actions>
    </x-page-header>

    @if($charts)
        @include('analytics._charts')
        <p class="-mt-3 mb-6 text-left text-sm"><a href="{{ route('analytics.index') }}" class="text-navy-700 underline">مركز التحليلات والمقارنة ←</a></p>
    @endif

    @if($myAttendance)
        <section class="mb-8">
            <h2 class="mb-3 text-sm font-bold text-slate-500">حضوري هذا الشهر</h2>
            <div class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
                <x-stat-card label="أيام الحضور" :value="$myAttendance['present']" icon="check-circle" tone="green"/>
                <x-stat-card label="الغياب" :value="$myAttendance['absent']" icon="user-x" tone="red"/>
                <x-stat-card label="مرات التأخير" :value="$myAttendance['late']" icon="clock" tone="yellow"/>
                <x-stat-card label="الإجازات" :value="$myAttendance['leave']" icon="calendar" tone="blue"/>
            </div>
        </section>
    @endif

    {{-- الموظف يرى إحصائيات تقاريره فقط --}}
    @if($stats['scope_all'])
    <section class="mb-8">
        <h2 class="mb-3 text-sm font-bold text-slate-500">المتاجر</h2>
        <div class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
            <x-stat-card label="إجمالي المتاجر" :value="$stats['stores_total']" icon="store" tone="navy" hint="بدون المؤرشفة"/>
            <x-stat-card label="المتاجر النشطة" :value="$stats['stores_active']" icon="check-circle" tone="green"/>
            <x-stat-card label="المتاجر المتوقفة" :value="$stats['stores_stopped']" icon="power" tone="red"/>
            <x-stat-card label="تحتاج متابعة" :value="$stats['stores_needs_followup']" icon="alert" tone="yellow"/>
        </div>
    </section>
    @endif

    <section class="mb-8">
        <h2 class="mb-3 text-sm font-bold text-slate-500">التقارير ({{ $period->label() }})</h2>
        <div class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
            <x-stat-card label="تقارير الفترة" :value="$stats['reports_total']" icon="report" tone="navy"/>
            <x-stat-card label="قيد المراجعة" :value="$stats['reports_pending']" icon="clock" tone="yellow"/>
            <x-stat-card label="المعتمدة" :value="$stats['reports_approved']" icon="check-circle" tone="green"/>
            <x-stat-card label="المرفوضة" :value="$stats['reports_rejected']" icon="x" tone="red"/>
        </div>
    </section>

    <section class="mb-8">
        <div class="mb-3 flex items-center gap-2">
            <h2 class="text-sm font-bold text-slate-500">الشحنات والمتابعات</h2>
            <span class="badge badge-navy">من التقارير المعتمدة فقط</span>
        </div>
        <div class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
            <x-stat-card label="الشحنات التي تمت مراجعتها" :value="$stats['shipments_reviewed']" icon="box" tone="navy"/>
            <x-stat-card label="سُلّمت بعد المتابعة" :value="$stats['delivered_after_followup']" icon="truck" tone="green"/>
            <x-stat-card label="لم يرد العميل" :value="$stats['customer_no_answer']" icon="phone" tone="orange"/>
            <x-stat-card label="لم يستجب المندوب" :value="$stats['courier_no_answer']" icon="user-x" tone="red"/>
        </div>
    </section>

    @if($stats['scope_all'])
    <section class="mb-8">
        <h2 class="mb-3 text-sm font-bold text-slate-500">المشكلات</h2>
        <div class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
            <x-stat-card label="المشكلات المفتوحة" :value="$stats['issues_open']" icon="alert" tone="red" hint="جديدة + قيد المعالجة"/>
            <x-stat-card label="تم حلها في الفترة" :value="$stats['issues_resolved']" icon="check" tone="green"/>
        </div>
    </section>
    @endif

    <div class="card p-5 flex items-start gap-3 text-sm text-slate-600">
        <x-icon name="info" class="size-5 shrink-0 text-navy-500"/>
        <p class="leading-7">جميع الأرقام أعلاه ناتجة عن استعلامات مباشرة على قاعدة البيانات وتتغير حسب الفترة المختارة. تظهر «غير متوفر» عندما لا توجد بيانات مُدخلة — ولا تُحتسب كصفر.
            @if(auth()->user()->hasPermission('analytics.view'))
                الرسوم البيانية والمقارنات (حركة الشحنات، المتاجر المتراجعة، أداء الموظفين، نتائج المتابعة، المشكلات، نسبة التسليم) في صفحة
                <a href="{{ route('analytics.index') }}" class="font-bold text-navy-700 underline">التحليلات</a>.
            @endif</p>
    </div>
</x-layouts.app>
