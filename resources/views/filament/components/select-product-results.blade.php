<div class="flex items-center gap-3 py-2 cursor-pointer">
    @if ($image)
        <img src="{{ Storage::disk('public')->url($image) }}" alt="{{ $name }}"
            class="w-16 h-16 rounded-full object-cover border-2 border-gray-200" />
    @else
        <div class="w-10 h-10 rounded-full bg-blue-500 flex items-center justify-center text-white font-bold">
            {{ substr($name, 0, 1) }}
        </div>
    @endif

    <div class="flex-1">
        <div class="font-semibold text-gray-900 dark:text-white"><span class="uppercase">{{ $category }}</span> {{ $name }}</div>
        <div class="text-[8px] text-gray-600">
            {{ $codimentacao }}
        </div>
        <div class="text-sm text-green-600">
            R$ {{ number_format($preco, 2, ',', '.') }}
        </div>
    </div>
</div>
