{{--
    Mapa de mesas do Painel do Garçom. Um único wire:poll: re-renderiza o mapa
    (2 queries agregadas, ver MapaMesasService) e avisa rodadas prontas.
--}}
<x-filament-panels::page>
    <div wire:poll.{{ (int) config('pizzaria.salao.polling_mapa_segundos') }}s="verificarProntas"
         class="w-full space-y-5">

        @include('filament.garcom.partials.sem-conexao')
        @include('filament.garcom.partials.alerta-pronto')

        @php
            $abas = ['MESA' => 'Mesas'];
            if ($this->temComandas()) {
                $abas['COMANDA'] = 'Comandas';
            }
            $abas['RETIRADA'] = 'Viagem';
        @endphp
        <div @class(['grid gap-2 rounded-2xl bg-gray-100 p-1 lg:max-w-xl dark:bg-white/5', count($abas) === 3 ? 'grid-cols-3' : 'grid-cols-2'])>
            @foreach ($abas as $valor => $rotulo)
                <button type="button" wire:click="$set('tipo', '{{ $valor }}')"
                        @class([
                            'rounded-xl py-3 text-base font-semibold transition',
                            'bg-white text-gray-900 shadow dark:bg-gray-800 dark:text-white' => $tipo === $valor,
                            'text-gray-500 dark:text-gray-400' => $tipo !== $valor,
                        ])>
                    {{ $rotulo }}
                </button>
            @endforeach
        </div>

        @if ($tipo === 'RETIRADA')
            <a href="{{ \App\Filament\Garcom\Pages\AtenderRetirada::getUrl() }}" wire:navigate
               class="block w-full rounded-2xl bg-primary-600 py-4 text-center text-base font-semibold text-white active:scale-[.98] lg:inline-block lg:w-auto lg:px-10">
                + Retirada ou entrega
            </a>

            <div class="space-y-3 md:grid md:grid-cols-2 md:gap-3 md:space-y-0 xl:grid-cols-3">
                @forelse ($this->retiradas() as $retirada)
                    @php
                        $statusRetirada = \App\Enums\StatusPedidoEnum::tryFrom($retirada->pedido_status);
                        $minhaRetirada = $retirada->pedido_usuario_garcom_id === auth()->id();
                    @endphp
                    <a href="{{ \App\Filament\Garcom\Pages\AtenderRetirada::getUrl(['pedido' => $retirada->id]) }}" wire:navigate
                       wire:key="retirada-{{ $retirada->id }}"
                       @class([
                           'flex items-center gap-3 rounded-2xl p-4 ring-2 transition active:scale-[.98]',
                           \App\Enums\StatusMapaMesaEnum::PRONTO->classes() => $statusRetirada === \App\Enums\StatusPedidoEnum::PRONTO,
                           \App\Enums\StatusMapaMesaEnum::EM_PREPARO->classes() => in_array($statusRetirada, [\App\Enums\StatusPedidoEnum::ABERTO, \App\Enums\StatusPedidoEnum::PREPARANDO], true),
                           \App\Enums\StatusMapaMesaEnum::AGUARDANDO_PEDIDO->classes() => $statusRetirada === \App\Enums\StatusPedidoEnum::EM_TRANSPORTE,
                           \App\Enums\StatusMapaMesaEnum::CONTA_SOLICITADA->classes() => $statusRetirada === \App\Enums\StatusPedidoEnum::ENTREGUE,
                       ])>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-base font-bold">
                                <span class="mr-1 rounded bg-black/15 px-1.5 py-0.5 text-[10px] font-semibold uppercase">{{ $retirada->exigeEntrega() ? 'Entrega' : 'Retirada' }}</span>
                                {{ $retirada->cliente?->cliente_nome }}
                            </p>
                            <p class="text-xs opacity-90">
                                #{{ $retirada->id }} · {{ (int) \Illuminate\Support\Carbon::parse($retirada->pedido_datahora_abertura)->diffInMinutes(now()) }} min
                                @unless ($minhaRetirada)
                                    · {{ $retirada->garcom?->name_first ?: $retirada->garcom?->name }}
                                @endunless
                            </p>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-semibold">
                                {{ match ($statusRetirada) {
                                    \App\Enums\StatusPedidoEnum::ABERTO => 'Enviado',
                                    \App\Enums\StatusPedidoEnum::PREPARANDO => 'Em preparo',
                                    \App\Enums\StatusPedidoEnum::PRONTO => 'Pronto',
                                    \App\Enums\StatusPedidoEnum::EM_TRANSPORTE => 'Saiu para entrega',
                                    \App\Enums\StatusPedidoEnum::ENTREGUE => 'Aguarda pagamento',
                                    default => $retirada->pedido_status,
                                } }}
                            </p>
                            <p class="text-xs opacity-90">R$ {{ number_format((float) $retirada->pedido_valor_total, 2, ',', '.') }}</p>
                        </div>
                    </a>
                @empty
                    <div class="rounded-2xl border border-dashed border-gray-300 p-8 text-center text-gray-500 md:col-span-2 xl:col-span-3 dark:border-white/10">
                        Nenhuma retirada ou entrega em andamento neste turno.
                    </div>
                @endforelse
            </div>
        @else
        @forelse ($this->mesasPorArea() as $area => $mesas)
            <section class="space-y-2">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $area }}</h2>

                <div class="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-6 lg:grid-cols-8 2xl:grid-cols-10">
                    @foreach ($mesas as $mesa)
                        <button type="button"
                                wire:key="mesa-{{ $mesa['id'] }}"
                                wire:click="tocarMesa({{ $mesa['id'] }})"
                                @disabled($mesa['status'] === \App\Enums\StatusMapaMesaEnum::INATIVA)
                                @class([
                                    'relative flex aspect-square flex-col items-center justify-center gap-0.5 rounded-2xl p-2 text-center ring-2 transition active:scale-95 lg:aspect-auto lg:h-36 lg:hover:brightness-95',
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
        @endif

        <div @class(['flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400', 'hidden' => $tipo === 'RETIRADA'])>
            @foreach ([\App\Enums\StatusMapaMesaEnum::LIVRE, \App\Enums\StatusMapaMesaEnum::AGUARDANDO_PEDIDO, \App\Enums\StatusMapaMesaEnum::EM_PREPARO, \App\Enums\StatusMapaMesaEnum::PRONTO, \App\Enums\StatusMapaMesaEnum::ATENDIDA, \App\Enums\StatusMapaMesaEnum::CONTA_SOLICITADA] as $legenda)
                <span class="flex items-center gap-1.5">
                    <span @class(['h-3 w-3 rounded-full ring-1', $legenda->classes()])></span>
                    {{ $legenda->label() }}
                </span>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
