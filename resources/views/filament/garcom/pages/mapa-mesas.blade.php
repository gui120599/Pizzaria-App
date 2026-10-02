{{--
    Mapa de mesas do Painel do Garçom. Um único wire:poll: re-renderiza o mapa
    (2 queries agregadas, ver MapaMesasService) e avisa rodadas prontas.
--}}
<x-filament-panels::page>
    <div wire:poll.{{ (int) config('pizzaria.salao.polling_mapa_segundos') }}s="verificarProntas"
         class="space-y-5">

        @include('filament.garcom.partials.sem-conexao')
        @include('filament.garcom.partials.alerta-pronto')

        @if ($this->temComandas())
            <div class="grid grid-cols-2 gap-2 rounded-2xl bg-gray-100 p-1 dark:bg-white/5">
                @foreach (\App\Enums\TipoMesaEnum::cases() as $opcao)
                    <button type="button" wire:click="$set('tipo', '{{ $opcao->value }}')"
                            @class([
                                'rounded-xl py-3 text-base font-semibold transition',
                                'bg-white text-gray-900 shadow dark:bg-gray-800 dark:text-white' => $tipo === $opcao->value,
                                'text-gray-500 dark:text-gray-400' => $tipo !== $opcao->value,
                            ])>
                        {{ $opcao->getLabel() }}s
                    </button>
                @endforeach
            </div>
        @endif

        @forelse ($this->mesasPorArea() as $area => $mesas)
            <section class="space-y-2">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $area }}</h2>

                <div class="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-6 lg:grid-cols-8">
                    @foreach ($mesas as $mesa)
                        <button type="button"
                                wire:key="mesa-{{ $mesa['id'] }}"
                                wire:click="tocarMesa({{ $mesa['id'] }})"
                                @disabled($mesa['status'] === \App\Enums\StatusMapaMesaEnum::INATIVA)
                                @class([
                                    'relative flex aspect-square flex-col items-center justify-center gap-0.5 rounded-2xl p-2 text-center ring-2 transition active:scale-95',
                                    $mesa['status']->classes(),
                                ])>
                            @if ($mesa['parada'])
                                <span class="absolute right-1.5 top-1.5 flex h-5 w-5 items-center justify-center rounded-full bg-red-600 text-xs font-bold text-white" title="Mesa parada">!</span>
                            @endif

                            <span class="text-2xl font-bold leading-none">{{ $mesa['numero'] ?? $mesa['nome'] }}</span>

                            @if ($mesa['numero'] !== null)
                                <span class="max-w-full truncate text-[11px] opacity-80">{{ $mesa['nome'] }}</span>
                            @endif

                            @if ($mesa['sessao_id'])
                                <span class="text-xs font-medium">{{ $mesa['minutos_ocupada'] }} min · {{ $mesa['pessoas'] }}p</span>
                                <span class="max-w-full truncate text-[11px] opacity-90">{{ $mesa['status']->label() }}</span>
                            @else
                                <span class="text-xs opacity-70">{{ $mesa['status']->label() }}</span>
                            @endif
                        </button>
                    @endforeach
                </div>
            </section>
        @empty
            <div class="rounded-2xl border border-dashed border-gray-300 p-8 text-center text-gray-500 dark:border-white/10">
                Nenhuma {{ $tipo === 'COMANDA' ? 'comanda' : 'mesa' }} cadastrada.
            </div>
        @endforelse

        <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
            @foreach ([\App\Enums\StatusMapaMesaEnum::LIVRE, \App\Enums\StatusMapaMesaEnum::AGUARDANDO_PEDIDO, \App\Enums\StatusMapaMesaEnum::EM_PREPARO, \App\Enums\StatusMapaMesaEnum::PRONTO, \App\Enums\StatusMapaMesaEnum::ATENDIDA, \App\Enums\StatusMapaMesaEnum::CONTA_SOLICITADA] as $legenda)
                <span class="flex items-center gap-1.5">
                    <span @class(['h-3 w-3 rounded-full ring-1', $legenda->classes()])></span>
                    {{ $legenda->label() }}
                </span>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
