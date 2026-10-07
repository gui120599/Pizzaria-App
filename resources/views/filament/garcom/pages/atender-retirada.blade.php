{{--
    Pedido para viagem do Painel do Garçom (retirada ou entrega). Montando:
    cliente (+ endereço/frete/forma combinada na entrega) e PedidoProdutoSelector
    no rascunho; o botão do selector dispara #pedido-form → enviar(). Enviado:
    status e itens; retirada tem cobrança Stone e "Entregue ao cliente".
--}}
<x-filament-panels::page>
    <div class="w-full space-y-4">
        @include('filament.garcom.partials.sem-conexao')

        <a href="{{ \App\Filament\Garcom\Pages\MapaMesas::getUrl(['tipo' => 'RETIRADA']) }}" wire:navigate
           class="inline-block text-sm font-semibold text-primary-600 dark:text-primary-400">&larr; Viagem</a>

        @if (! $pedidoId)
            {{-- Desktop (≥ lg): cardápio à esquerda, painel fixo à direita com
                 tipo, cliente, carrinho e envio. No celular a ordem é a do DOM. --}}
            <div class="space-y-4 lg:grid lg:grid-cols-12 lg:items-start lg:gap-6 lg:space-y-0">
            <div class="space-y-4 lg:sticky lg:top-4 lg:order-2 lg:col-span-5 lg:max-h-[calc(100vh-2rem)] lg:overflow-y-auto lg:pr-1 2xl:col-span-4">
            <div class="grid grid-cols-2 gap-1 rounded-2xl bg-gray-100 p-1 dark:bg-white/5">
                @foreach (['retirada' => 'Retirada', 'entrega' => 'Entrega'] as $valor => $rotulo)
                    <button type="button" wire:click="usarTipo('{{ $valor }}')"
                            @class([
                                'rounded-xl py-3 text-base font-semibold transition',
                                'bg-white text-gray-900 shadow dark:bg-gray-800 dark:text-white' => $tipo === $valor,
                                'text-gray-500 dark:text-gray-400' => $tipo !== $valor,
                            ])>
                        {{ $rotulo }}
                    </button>
                @endforeach
            </div>

            @if ($tipo === 'retirada')
            {{-- Como o cliente leva: só aparece com mais de uma opção sem endereço --}}
            @php $opcoesRetirada = $this->opcoesRetirada(); @endphp
            @if ($opcoesRetirada->count() > 1)
                <div class="flex flex-wrap gap-2">
                    @foreach ($opcoesRetirada as $opcaoRetirada)
                        <button type="button" wire:click="usarOpcaoRetirada({{ $opcaoRetirada->id }})"
                                @class([
                                    'rounded-full px-4 py-2 text-sm font-semibold ring-1 transition',
                                    'bg-primary-600 text-white ring-primary-600' => $opcaoRetiradaId === $opcaoRetirada->id,
                                    'bg-white text-gray-700 ring-gray-300 dark:bg-gray-900 dark:text-gray-200 dark:ring-white/10' => $opcaoRetiradaId !== $opcaoRetirada->id,
                                ])>
                            {{ $opcaoRetirada->opcaoentrega_nome }}
                        </button>
                    @endforeach
                </div>
            @endif

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

            @else
                {{-- Entrega: mesmos componentes do AtenderPedido (cliente com
                     endereço/CEP e opção de entrega + forma combinada). --}}
                @livewire('cliente-picker', ['inicial' => $clienteData], key('entrega-cliente-'.$rascunhoId))

                @livewire('entrega-pagamento-picker', ['inicial' => $entregaPagamentoData, 'total' => $this->totaisEntrega()['total'], 'somenteComEndereco' => true], key('entrega-pagamento-'.$rascunhoId))

                @php $totaisEntrega = $this->totaisEntrega(); @endphp
                <p class="text-right text-sm text-gray-600 dark:text-gray-300">
                    Itens R$ {{ number_format($totaisEntrega['itens'] - $totaisEntrega['desconto'], 2, ',', '.') }}
                    · Frete {{ $totaisEntrega['frete'] > 0 ? 'R$ '.number_format($totaisEntrega['frete'], 2, ',', '.') : 'grátis' }}
                    · <strong>Total R$ {{ number_format($totaisEntrega['total'], 2, ',', '.') }}</strong>
                </p>
            @endif

            <div class="hidden lg:block">
                @include('filament.garcom.partials.carrinho-lateral', [
                    'acaoEnviar' => 'enviar',
                    'tituloCarrinho' => $tipo === 'entrega' ? 'Itens da entrega' : 'Itens da retirada',
                    'totalExibido' => $tipo === 'entrega' ? $this->totaisEntrega()['total'] : null,
                ])
            </div>
            </div>

            <div class="lg:order-1 lg:col-span-7 2xl:col-span-8">
                @livewire('pedido-produto-selector', [
                    'pedidoId' => $rascunhoId,
                    'saveButtonLabel' => 'Enviar para a cozinha',
                    'layoutDesktop' => true,
                ], key('retirada-'.$rascunhoId))
            </div>
            </div>

            <form id="pedido-form" wire:submit="enviar" class="hidden"></form>
        @else
            @php
                $retirada = $this->retirada();
                $status = \App\Enums\StatusPedidoEnum::tryFrom($retirada->pedido_status);
                $pago = $retirada->pedido_venda_id !== null || $status === \App\Enums\StatusPedidoEnum::FINALIZADO;
            @endphp

            <div wire:poll.{{ (int) config('pizzaria.salao.polling_mesa_segundos') }}s="atualizar"
                 class="w-full max-w-6xl space-y-4 lg:grid lg:grid-cols-2 lg:items-start lg:gap-6 lg:space-y-0">
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
                                \App\Enums\StatusPedidoEnum::EM_TRANSPORTE => 'Saiu para entrega',
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
                                    @foreach ($item->linhasRespostas() as $resposta)
                                        <p class="text-xs text-gray-600 dark:text-gray-300">{{ $resposta }}</p>
                                    @endforeach
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

                <div class="space-y-4">
                @if ($retirada->exigeEntrega())
                    <div class="space-y-1 rounded-2xl bg-white p-4 text-sm ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-white/10">
                        <p class="font-semibold text-gray-900 dark:text-white">Entrega · {{ $retirada->opcaoEntrega?->opcaoentrega_nome }}</p>
                        <p class="text-gray-600 dark:text-gray-300">{{ $retirada->pedido_endereco_entrega }}</p>
                        @foreach ($retirada->pagamentosCombinados as $combinado)
                            <p class="text-gray-600 dark:text-gray-300">
                                {{ $combinado->pg_pedido_opcaopagamento_nome }}: R$ {{ number_format((float) $combinado->pg_pedido_valor, 2, ',', '.') }}
                                @if ($combinado->pg_pedido_valor_troco_para)
                                    (troco para R$ {{ number_format((float) $combinado->pg_pedido_valor_troco_para, 2, ',', '.') }})
                                @endif
                            </p>
                        @endforeach
                        <p class="pt-1 text-xs text-gray-500">O entregador leva e cobra pelo Painel do Entregador.</p>
                    </div>
                @endif

                @if ($status === \App\Enums\StatusPedidoEnum::PRONTO && ! $retirada->exigeEntrega())
                    <button type="button" wire:click="entregarAoCliente" wire:loading.attr="disabled"
                            class="w-full rounded-xl bg-emerald-600 py-4 text-base font-semibold text-white active:scale-[.98]">
                        Entregue ao cliente
                    </button>
                @endif

                @if (! $pago && $status !== \App\Enums\StatusPedidoEnum::CANCELADO && ! $retirada->exigeEntrega())
                    @livewire('mesa-stone-cobranca', ['pedidoId' => $retirada->id], key('stone-retirada-'.$retirada->id))

                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        Dinheiro ou PIX: o caixa lança esta retirada em "Pedidos avulsos" e recebe.
                    </p>
                @elseif ($pago && ! $retirada->exigeEntrega())
                    <p class="rounded-xl bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400">Pagamento registrado.</p>
                @endif
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
