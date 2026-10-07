{{-- Rodadas enviadas da mesa: aba Rodadas no celular e painel lateral no desktop. --}}
<div class="space-y-3">
    @php
        $podeMoverPedidos = $this->podeFecharMesa();
        $idsRemoviveis = $podeMoverPedidos ? $this->idsRemoviveis() : [];
    @endphp
    @if ($podeMoverPedidos)
        <button type="button" wire:click="mountAction('adicionarPedido')"
                class="w-full rounded-xl py-2.5 text-sm font-semibold text-primary-600 ring-1 ring-primary-200 active:scale-[.98] dark:text-primary-400 dark:ring-primary-500/30">
            + Trazer pedido existente
        </button>
    @endif

    @forelse ($this->rodadas() as $rodada)
        @php $statusRodada = \App\Enums\StatusPedidoEnum::tryFrom($rodada->pedido_status); @endphp
        <div wire:key="rodada-{{ $rodada->id }}"
             class="overflow-hidden rounded-2xl bg-white ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-white/10">
            <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-4 py-3 dark:border-white/10">
                <div>
                    <span class="font-semibold text-gray-900 dark:text-white">Rodada #{{ $rodada->id }}</span>
                    <span class="ml-2 text-xs text-gray-500">{{ $rodada->pedido_datahora_abertura ? \Illuminate\Support\Carbon::parse($rodada->pedido_datahora_abertura)->format('H:i') : '' }} @if ($rodada->pedido_usuario_garcom_id) · {{ $rodada->garcom?->name_first ?: $rodada->garcom?->name }} @endif</span>
                    @if (in_array($rodada->id, $idsRemoviveis, true))
                        <button type="button" wire:click="mountAction('removerPedido', { pedido: {{ $rodada->id }} })"
                                class="ml-2 text-xs font-semibold text-red-600 underline-offset-2 hover:underline dark:text-red-400">
                            Tirar da mesa
                        </button>
                    @endif
                </div>
                <span @class([
                    'rounded-full px-2.5 py-1 text-xs font-semibold',
                    'bg-sky-100 text-sky-800' => $statusRodada === \App\Enums\StatusPedidoEnum::ABERTO,
                    'bg-amber-100 text-amber-800' => $statusRodada === \App\Enums\StatusPedidoEnum::PREPARANDO,
                    'bg-emerald-500 text-white' => $statusRodada === \App\Enums\StatusPedidoEnum::PRONTO,
                    'bg-gray-100 text-gray-700' => in_array($statusRodada, [\App\Enums\StatusPedidoEnum::ENTREGUE, \App\Enums\StatusPedidoEnum::FINALIZADO], true),
                    'bg-red-100 text-red-700' => $statusRodada === \App\Enums\StatusPedidoEnum::CANCELADO,
                ])>
                    {{ match ($statusRodada) {
                        \App\Enums\StatusPedidoEnum::ABERTO => 'Enviado',
                        \App\Enums\StatusPedidoEnum::PREPARANDO => 'Em preparo',
                        \App\Enums\StatusPedidoEnum::PRONTO => 'Pronto',
                        \App\Enums\StatusPedidoEnum::ENTREGUE => 'Entregue',
                        \App\Enums\StatusPedidoEnum::FINALIZADO => 'Pago',
                        \App\Enums\StatusPedidoEnum::CANCELADO => 'Cancelado',
                        default => $rodada->pedido_status,
                    } }}
                </span>
            </div>

            <ul class="divide-y divide-gray-100 dark:divide-white/10">
                @foreach ($rodada->item_pedido_pedido_id as $item)
                    <li wire:key="item-{{ $item->id }}" class="flex items-start gap-3 px-4 py-2.5">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-white">
                                {{ \App\Support\FormatoQuantidade::item($item->item_pedido_quantidade) }}× {{ $item->nomeProduto() }}
                            </p>
                            @if ($item->cliente)
                                <p class="text-xs font-semibold text-primary-600 dark:text-primary-400">{{ $item->cliente->cliente_nome }}</p>
                            @endif
                            @if ($item->adicionaisItemPedido->isNotEmpty())
                                <p class="text-xs text-gray-500">+ {{ $item->adicionaisItemPedido->map(fn ($a) => $a->adicional?->adicional_nome)->filter()->implode(', ') }}</p>
                            @endif
                            @if ($item->item_pedido_observacao)
                                <p class="text-xs italic text-amber-700 dark:text-amber-400">{{ $item->item_pedido_observacao }}</p>
                            @endif
                        </div>
                        <span class="text-sm text-gray-700 dark:text-gray-300">R$ {{ number_format((float) $item->item_pedido_valor, 2, ',', '.') }}</span>
                        @if (! in_array($statusRodada, [\App\Enums\StatusPedidoEnum::CANCELADO, \App\Enums\StatusPedidoEnum::FINALIZADO], true) && $item->item_pedido_venda_id === null)
                            <button type="button"
                                    wire:click="mountAction('cancelarItem', { item: {{ $item->id }} })"
                                    class="rounded-lg px-2 py-1 text-xs font-semibold text-red-600 ring-1 ring-red-200 active:scale-95 dark:ring-red-500/30">
                                Cancelar
                            </button>
                        @endif
                    </li>
                @endforeach
            </ul>

            @if ($statusRodada === \App\Enums\StatusPedidoEnum::PRONTO)
                <div class="p-3">
                    <button type="button" wire:click="marcarEntregue({{ $rodada->id }})" wire:loading.attr="disabled"
                            class="w-full rounded-xl bg-emerald-600 py-3 text-base font-semibold text-white active:scale-[.98]">
                        Servido na mesa
                    </button>
                </div>
            @endif
        </div>
    @empty
        <div class="rounded-2xl border border-dashed border-gray-300 p-8 text-center text-gray-500 dark:border-white/10">
            Nenhuma rodada enviada ainda.
        </div>
    @endforelse
</div>
