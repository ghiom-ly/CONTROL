<div class="mb-5 flex flex-wrap gap-2">
    @foreach(['messages.templates.index' => 'القوالب', 'messages.bulk' => 'رسالة لعدة متاجر', 'messages.log' => 'سجل الرسائل'] as $route => $label)
        @if(Route::has($route))
            <a href="{{ route($route) }}" class="btn {{ request()->routeIs($route) ? 'btn-primary' : 'btn-light' }}">{{ $label }}</a>
        @endif
    @endforeach
</div>
