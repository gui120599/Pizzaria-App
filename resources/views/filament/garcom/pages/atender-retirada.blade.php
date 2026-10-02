{{--
    Retirada do Painel do Garçom. Montando: cliente + PedidoProdutoSelector no
    rascunho; o botão do selector dispara #pedido-form → enviar(). Enviada:
    status, itens, cobrança Stone e "Entregue ao cliente", com poll do status.
--}}
<x-filament-panels::page>
    <div class="space-y-4">
        @include('filament.garcom.partials.sem-conexao')

        <a href="{{ \App\Filament\Garcom\Pages\MapaMesas::getUrl(['tipo' => 'RETIRADA']) }}" wire:navigate
           class="inline-block text-sm font-semibold text-primary-600 dark:text-primary-400">&larr; Retiradas</a>

        @if (! $pedidoId)
            {{-- Cliente --}}
            <div class="space-y-3 rounded-2xl bg-white p-4 ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-white/10">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Cliente</h2>
                <div>
                    <label class="text-xs text-gray-500 dark:text-gray-400" for="retirada-celular">Celular</label>
                    <input id="retirada-celular" type="tel" inputmode="tel" autocomplete="off"
                           wire:model.live.debounce.500ms="celular"
                           x-mask:dynamic="$input.replace(/\D/g, '').length > 10 ? '(99) 99999-9999' : '(99) 9999-99999'"
                           placeholder="(00) 00000-0000"
                           class="mt-1 w-full rounded-xl border border-gray-300 bg-white px-3 py-3 text-base text-gray-900 focus:outline-none focus:ring-2 focus:ring-primary-500 dark:border-white/10 dark:bg-gray-800 dark:text-white">
                </div>
                <div>
                    <label class="text-xs text-gray-500 dark:text-gray-400" for="retirada-nome">Nome</label>
                    <input id="retirada-nome" type="text" autocomplete="off" wire:model.blur="nome" maxlength="120"
                           placeholder="Nome para chamar na retirada"
                           class="mt-1 w-full rounded-xl border border-gray-300 bg-white px-3 py-3 text-base text-gray-900 focus:outline-none focus:ring-2 focus:ring-primary-500 dark:border-white/10 dark:bg-gray-800 dark:text-white">
                    @if ($clienteEncontrado)
                        <p class="mt-1 text-xs text-emerald-600 dark:text-emerald-400">Cliente já cadastrado.</p>
                    @endif
                </div>
            </div>

            @livewire('pedido-produto-selector', [
                'pedidoId' => $rascunhoId,
                'saveButtonLabel' => 'Enviar para a cozinha',
            ], key('retirada-'.$rascunhoId))

            <form id="pedido-form" wire:submit="enviar" class="hidden"></form>
        @else
            @php
                $retirada = $this->retirada();
                $status = \App\Enums\StatusPedidoEnum::tryFrom($retirada->pedido_status);
                $pago = $retirada->pedido_venda_id !== null || $status === \App\Enums\StatusPedidoEnum::FINALIZADO;
            @endphp

            <div wire:poll.{{ (int) config('pizzaria.salao.polling_mesa_segundos') }}s="atualizar" class="space-y-4">
                @include('filament.garcom.partials.alerta-pronto')

                <div class="rounded-2xl bg-white p-4 ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-white/10">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-lg font-semibold text-gray-900 dark:text-white">{{ $retirada->cliente?->cliente_nome }}</p>
                            <p class="text-sm text-gray-500">{{ preg_replace('/^(\d{2})(\d{4,5})(\d{4})$/', '($1) $2-$3', (string) $retirada->cliente?->cliente_celular) }} · {{ $retirada->garcom?->name_first ?: $retirada->garcom?->name }}</p>
                        </div>
                        <span @class([
                            'shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold',
                            'bg-sky-100 text-sky-800' => $status === \App\Enums\StatusPedidoEnum::ABERTO,
                            'bg-amber-100 text-amber-800' => $status === \App\Enums\StatusPedidoEnum::PREPARANDO,
                            'bg-emerald-500 text-white' => $status === \App\Enums\StatusPedidoEnum::PRONTO,
                            'bg-gray-100 text-gray-700' => in_array($status, [\App\Enums\StatusPedidoEnum::ENTREGUE, \App\Enums\StatusPedidoEnum::FINALIZADO], true),
                            'bg-red-100 text-red-700' => $status === \App\Enums\StatusPedidoEnum::CANCELADO,
                        ])>
                            {{ match ($status) {
                                \App\Enums\StatusPedidoEnum::ABERTO => 'Enviado',
                                \App\Enums\StatusPedidoEnum::PREPARANDO => 'Em preparo',
                                \App\Enums\StatusPedidoEnum::PRONTO => 'Pronto',
                                \App\Enums\StatusPedidoEnum::ENTREGUE => 'Entregue',
                                \App\Enums\StatusPedidoEnum::FINALIZADO => 'Entregue e pago',
                                \App\Enums\StatusPedidoEnum::CANCELADO => 'Cancelado',
                                default => $retirada->pedido_status,
                            } }}
                        </span>
                    </div>

                    <ul class="mt-3 divide-y divide-gray-100 dark:divide-white/10">
                        @foreach ($retirada->item_pedido_pedido_id as $item)
                            <li wire:key="item-{{ $item->id }}" class="flex items-start gap-3 py-2.5">
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium text-gray-900 dark:text-white">
                                        {{ \App\Support\FormatoQuantidade::item($item->item_pedido_quantidade) }}× {{ $item->nomeProduto() }}
                                    </p>
                                    @if ($item->adicionaisItemPedido->isNotEmpty())
                                        <p class="text-xs text-gray-500">+ {{ $item->adicionaisItemPedido->map(fn ($a) => $a->adicional?->adicional_nome)->filter()->implode(', ') }}</p>
                                    @endif
                                    @if ($item->item_pedido_observacao)
                                        <p class="text-xs italic text-amber-700 dark:text-amber-400">{{ $item->item_pedido_observacao }}</p>
                                    @endif
                                </div>
                                <span class="text-sm text-gray-700 dark:text-gray-300">R$ {{ number_format((float) $item->item_pedido_valor, 2, ',', '.') }}</span>
                                @if (! $pago && $status !== \App\Enums\StatusPedidoEnum::CANCELADO && $item->item_pedido_venda_id === null)
                                    <button type="button" wire:click="mountAction('cancelarItem', { item: {{ $item->id }} })"
                                            class="rounded-lg px-2 py-1 text-xs font-semibold text-red-600 ring-1 ring-red-200 active:scale-95 dark:ring-red-500/30">
                                        Cancelar
                                    </button>
                                @endif
                            </li>
                        @endforeach
                    </ul>

                    <div class="mt-2 flex justify-between border-t border-gray-100 pt-3 text-xl font-bold text-gray-900 dark:border-white/10 dark:text-white">
                        <span>Total</span>
                        <span>R$ {{ number_format((float) $retirada->pedido_valor_total, 2, ',', '.') }}</span>
                    </div>
                </div>

                @if ($status === \App\Enums\StatusPedidoEnum::PRONTO)
                    <button type="button" wire:click="entregarAoCliente" wire:loading.attr="disabled"
                            class="w-full rounded-xl bg-emerald-600 py-4 text-base font-semibold text-white active:scale-[.98]">
                        Entregue ao cliente
                    </button>
                @endif

                @if (! $pago && $status !== \App\Enums\StatusPedidoEnum::CANCELADO)
                    @livewire('mesa-stone-cobranca', ['pedidoId' => $retirada->id], key('stone-retirada-'.$retirada->id))

                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        Dinheiro ou PIX: o caixa lança esta retirada em "Pedidos avulsos" e recebe.
                    </p>
                @elseif ($pago)
                    <p class="rounded-xl bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400">Pagamento registrado.</p>
                @endif
            </div>
        @endif
    </div>
</x-filament-panels::page>
