<x-filament-widgets::widget>
    <x-filament::section heading="Itens que mais falham" description="Respostas não conformes nos últimos 30 dias">
        @if ($itens === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">Nenhum item não conforme no período.</p>
        @else
            <ol class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($itens as $posicao => $item)
                    <li class="flex items-center gap-3 py-2">
                        <span class="w-6 text-sm tabular-nums text-gray-500 dark:text-gray-400">{{ $posicao + 1 }}.</span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-gray-950 dark:text-white">{{ $item->titulo }}</p>
                            <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $item->modelo_nome }}</p>
                        </div>
                        <span class="text-sm font-semibold tabular-nums text-danger-600 dark:text-danger-400">
                            {{ $item->falhas }} {{ $item->falhas == 1 ? 'falha' : 'falhas' }}
                        </span>
                    </li>
                @endforeach
            </ol>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
