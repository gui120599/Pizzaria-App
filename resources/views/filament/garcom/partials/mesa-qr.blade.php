{{-- O que chegou pelo QR da mesa: pedidos do cliente para decidir, chamados e
     celulares na conta. Fica no topo, no celular e no desktop. --}}
@php
    $pedidosCliente = $this->pedidosDoCliente();
    $chamadosMesa = $this->chamadosPendentes();
    $celulares = $this->participantes();
@endphp

@if ($chamadosMesa->isNotEmpty())
    <div class="space-y-2">
        @foreach ($chamadosMesa as $chamado)
            <div wire:key="chamado-{{ $chamado->id }}"
                 class="flex items-center gap-3 rounded-2xl bg-yellow-100 px-4 py-3 ring-1 ring-yellow-300 dark:bg-yellow-500/15 dark:ring-yellow-500/30">
                <x-filament::icon icon="heroicon-o-bell-alert" class="h-7 w-7 shrink-0 text-yellow-700 dark:text-yellow-300" />
                <div class="min-w-0 flex-1">
                    <p class="font-semibold text-yellow-950 dark:text-yellow-200">{{ $chamado->mc_tipo->label() }}</p>
                    <p class="text-xs text-yellow-900/80 dark:text-yellow-200/70">
                        {{ $chamado->participante?->mp_nome ?? 'Cliente' }} · há {{ (int) $chamado->created_at->diffInMinutes(now()) }} min
                    </p>
                </div>
                <button type="button" wire:click="atenderChamado({{ $chamado->id }})"
                        class="rounded-xl bg-yellow-400 px-4 py-2.5 text-sm font-semibold text-yellow-950 active:scale-95">
                    Atendido
                </button>
            </div>
        @endforeach
    </div>
@endif

@if ($pedidosCliente->isNotEmpty())
    <div class="space-y-3">
        @foreach ($pedidosCliente as $pedidoCliente)
            @php $editando = $editandoPedidoId === $pedidoCliente->id; @endphp
            <div wire:key="pedido-cliente-{{ $pedidoCliente->id }}"
                 @class([
                     'overflow-hidden rounded-2xl bg-white ring-2 dark:bg-gray-900',
                     'ring-orange-400' => ! $editando,
                     'ring-primary-500' => $editando,
                 ])>
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 bg-orange-50 px-4 py-3 dark:border-white/10 dark:bg-orange-500/10">
                    <div>
                        <p class="font-semibold text-gray-900 dark:text-white">
                            Pedido de {{ $pedidoCliente->participante?->mp_nome ?? 'cliente' }} pelo celular
                        </p>
                        <p class="text-xs text-gray-600 dark:text-gray-300">
                            {{ $pedidoCliente->created_at?->format('H:i') }} ·
                            {{ collect($pedidoCliente->pedido_aprovacao_motivos ?? [])->map->label()->implode(' · ') }}
                        </p>
                    </div>
                    <span class="text-base font-bold text-gray-900 dark:text-white">R$ {{ number_format((float) $pedidoCliente->pedido_valor_total, 2, ',', '.') }}</span>
                </div>

                <ul class="divide-y divide-gray-100 dark:divide-white/10">
                    @foreach ($pedidoCliente->item_pedido_pedido_id as $item)
                        <li wire:key="pedido-cliente-item-{{ $item->id }}" class="px-4 py-2">
                            <p class="text-sm font-medium text-gray-900 dark:text-white">
                                {{ \App\Support\FormatoQuantidade::item($item->item_pedido_quantidade) }}× {{ $item->nomeProduto() }}
                            </p>
                            @foreach ($item->linhasRespostas() as $resposta)
                                <p class="text-xs text-gray-600 dark:text-gray-300">{{ $resposta }}</p>
                            @endforeach
                            @if ($item->adicionaisItemPedido->isNotEmpty())
                                <p class="text-xs text-gray-500">+ {{ $item->adicionaisItemPedido->map(fn ($a) => $a->adicional?->adicional_nome)->filter()->implode(', ') }}</p>
                            @endif
                            @if ($item->item_pedido_observacao)
                                <p class="text-xs italic text-gray-500">{{ $item->item_pedido_observacao }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>

                <div class="grid grid-cols-3 gap-2 border-t border-gray-100 p-3 dark:border-white/10">
                    <button type="button" wire:click="aprovarPedido({{ $pedidoCliente->id }})" wire:loading.attr="disabled"
                            class="rounded-xl bg-primary-600 py-3 text-sm font-semibold text-white active:scale-95">
                        Aprovar
                    </button>
                    @if ($editando)
                        <button type="button" wire:click="cancelarEdicao"
                                class="rounded-xl py-3 text-sm font-semibold text-gray-700 ring-1 ring-gray-300 active:scale-95 dark:text-gray-200 dark:ring-white/20">
                            Parar edição
                        </button>
                    @else
                        <button type="button" wire:click="editarPedido({{ $pedidoCliente->id }})"
                                class="rounded-xl py-3 text-sm font-semibold text-gray-700 ring-1 ring-gray-300 active:scale-95 dark:text-gray-200 dark:ring-white/20">
                            Editar
                        </button>
                    @endif
                    <button type="button" wire:click="mountAction('recusarPedido', { pedido: {{ $pedidoCliente->id }} })"
                            class="rounded-xl py-3 text-sm font-semibold text-red-600 ring-1 ring-red-200 active:scale-95 dark:text-red-400 dark:ring-red-500/30">
                        Recusar
                    </button>
                </div>
            </div>
        @endforeach
    </div>
@endif

@if ($celulares->isNotEmpty())
    <details class="rounded-2xl bg-white ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-white/10">
        <summary class="cursor-pointer px-4 py-3 text-sm font-semibold text-gray-700 dark:text-gray-200">
            Celulares na mesa ({{ $celulares->count() }})
        </summary>
        <ul class="divide-y divide-gray-100 border-t border-gray-100 dark:divide-white/10 dark:border-white/10">
            @foreach ($celulares as $celular)
                <li wire:key="celular-{{ $celular->id }}" class="flex items-center gap-3 px-4 py-2.5">
                    <div class="min-w-0 flex-1">
                        <p @class(['text-sm font-medium text-gray-900 dark:text-white', 'line-through opacity-60' => $celular->estaBloqueado()])>{{ $celular->mp_nome }}</p>
                        <p class="text-xs text-gray-500">{{ $celular->mp_celular }}</p>
                    </div>
                    @if ($celular->estaBloqueado())
                        <button type="button" wire:click="desbloquearParticipante({{ $celular->id }})"
                                class="text-xs font-semibold text-primary-600 dark:text-primary-400">Desbloquear</button>
                    @else
                        <button type="button" wire:click="bloquearParticipante({{ $celular->id }})"
                                wire:confirm="Bloquear {{ $celular->mp_nome }}? O celular não poderá mais pedir nesta mesa."
                                class="text-xs font-semibold text-red-600 dark:text-red-400">Bloquear celular</button>
                    @endif
                </li>
            @endforeach
        </ul>
    </details>
@endif
