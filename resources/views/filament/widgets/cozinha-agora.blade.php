<x-filament-widgets::widget>
    <x-filament::section heading="Cozinha agora" icon="heroicon-o-fire">
        <div wire:poll.15s class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @forelse ($setores as $linha)
                @php $setor = $linha['setor']; @endphp
                <div class="rounded-lg p-3 ring-1 ring-gray-200 dark:ring-white/10" style="border-left: 4px solid {{ $setor->cor }}">
                    <div class="flex items-center justify-between gap-2">
                        <span class="font-semibold text-gray-950 dark:text-white">{{ $setor->nome }}</span>
                        @if ($linha['tablets'] === 0)
                            <x-cozinha.status-chip rotulo="Sem tablet" cor="gray" />
                        @elseif ($linha['tablets_online'] === 0)
                            <x-cozinha.status-chip rotulo="Tablet offline" cor="danger" />
                        @else
                            <x-cozinha.status-chip :rotulo="$linha['tablets_online'].'/'.$linha['tablets'].' online'" cor="success" />
                        @endif
                    </div>
                    <dl class="mt-2 grid grid-cols-3 gap-2 text-center">
                        <div>
                            <dt class="text-[11px] uppercase text-gray-500 dark:text-gray-400">Fila</dt>
                            <dd class="text-xl font-bold tabular-nums text-gray-950 dark:text-white">{{ $linha['fila'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-[11px] uppercase text-gray-500 dark:text-gray-400">Preparo</dt>
                            <dd class="text-xl font-bold tabular-nums text-warning-600 dark:text-warning-400">{{ $linha['em_preparo'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-[11px] uppercase text-gray-500 dark:text-gray-400">Atrasados</dt>
                            <dd @class(['text-xl font-bold tabular-nums', 'text-danger-600 dark:text-danger-400' => $linha['atrasados'] > 0, 'text-gray-400' => $linha['atrasados'] === 0])>{{ $linha['atrasados'] }}</dd>
                        </div>
                    </dl>
                </div>
            @empty
                <p class="text-sm text-gray-500 dark:text-gray-400">Nenhum setor de produção cadastrado.</p>
            @endforelse
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
