<div class="flex items-center gap-3 py-1.5 cursor-pointer">
    @if ($image)
        <img src="{{ $image }}" alt="{{ $nome }}"
            class="w-10 h-10 rounded-lg object-cover border border-gray-200 dark:border-gray-700 shrink-0" />
    @else
        <div class="w-10 h-10 rounded-lg bg-amber-500 flex items-center justify-center text-white font-bold text-sm shrink-0">
            {{ mb_strtoupper(mb_substr($nome, 0, 1)) }}
        </div>
    @endif

    <div class="flex-1 min-w-0">
        <div class="font-semibold text-gray-900 dark:text-white text-sm truncate">
            {{ $nome }}
        </div>
    </div>
</div>
