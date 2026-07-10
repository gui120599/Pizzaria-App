<div class="flex items-center gap-3 py-1.5 cursor-pointer">
    @if ($image)
        <img src="{{ $image }}" alt="{{ $name }}"
            class="w-12 h-12 rounded-lg object-cover border border-gray-200 dark:border-gray-700 shrink-0" />
    @else
        <div class="w-12 h-12 rounded-lg bg-amber-500 flex items-center justify-center text-white font-bold text-lg shrink-0">
            {{ mb_strtoupper(mb_substr($name, 0, 1)) }}
        </div>
    @endif

    <div class="flex-1 min-w-0">
        @if ($category)
            <div class="text-[10px] font-medium text-gray-400 dark:text-gray-500 uppercase tracking-wide leading-none mb-0.5">
                {{ $category }}
            </div>
        @endif
        <div class="font-semibold text-gray-900 dark:text-white text-sm truncate">
            {{ $name }}
        </div>
        @if (! is_null($saldo ?? null))
            <div class="flex items-center gap-1 mt-0.5">
                <span class="text-xs font-medium {{ (float) $saldo_raw > 0 ? 'text-blue-600 dark:text-blue-400' : 'text-red-500 dark:text-red-400' }}">
                    Saldo: {{ $saldo }}{{ $unidade ? ' ' . $unidade : '' }}
                </span>
            </div>
        @endif
    </div>
</div>
