@if ($paginator->hasPages())
    <nav role="navigation" aria-label="التنقل بين الصفحات" class="flex flex-col sm:flex-row items-center justify-between gap-3 px-5 py-4 border-t border-slate-100">
        <p class="text-sm text-slate-500">
            عرض <span class="font-bold text-navy-800">{{ $paginator->firstItem() }}</span>
            إلى <span class="font-bold text-navy-800">{{ $paginator->lastItem() }}</span>
            من أصل <span class="font-bold text-navy-800">{{ $paginator->total() }}</span>
        </p>
        <div class="flex items-center gap-1">
            @if ($paginator->onFirstPage())
                <span class="btn btn-light btn-sm opacity-40">السابق</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" class="btn btn-light btn-sm" rel="prev">السابق</a>
            @endif
            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="px-2 text-slate-400">…</span>
                @endif
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="btn btn-primary btn-sm min-w-8">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}" class="btn btn-light btn-sm min-w-8 hidden sm:inline-flex">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach
            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" class="btn btn-light btn-sm" rel="next">التالي</a>
            @else
                <span class="btn btn-light btn-sm opacity-40">التالي</span>
            @endif
        </div>
    </nav>
@endif
