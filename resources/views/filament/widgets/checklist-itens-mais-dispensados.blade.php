<x-filament-widgets::widget>
    <x-filament::section heading="Itens mais dispensados" description='Respostas "Tinha estoque" nos últimos 30 dias — item dispensado quase todo dia pede frequência menor ou critério revisto'>
        @if ($itens === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">Nenhum item dispensado no período.</p>
        @else
            <ol class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($itens as $posicao => $item)
                    <li class="flex items-center gap-3 py-2">
                        <span class="w-6 text-sm tabular-nums text-gray-500 dark:text-gray-400">{{ $posicao + 1 }}.</span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-gray-950 dark:text-white">{{ $item->titulo }}</p>
                            <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $item->modelo_nome }}</p>
                        </div>
                        <span class="text-sm font-semibold tabular-nums text-info-600 dark:text-info-400">
                            dispensado {{ $item->dispensadas }} de {{ $item->execucoes }} {{ $item->execucoes == 1 ? 'execução' : 'execuções' }}
                        </span>
                    </li>
                @endforeach
            </ol>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
