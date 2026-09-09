<x-filament-panels::page>
    <div
        x-data="{
            vendaId: @js($this->vendaId),
            urlCancelarVazia: @js($this->vendaId ? route('venda.cancelar_vazia', ['venda' => $this->vendaId]) : null),
            csrfToken: @js(csrf_token()),
        }"
        x-init="
            window.addEventListener('beforeunload', () => {
                if (urlCancelarVazia) {
                    navigator.sendBeacon(urlCancelarVazia, new URLSearchParams({ _token: csrfToken }));
                }
            });
        "
        @keydown.window.alt.c.prevent="$wire.abrirModalCliente()"
        @keydown.window.alt.p.prevent="$wire.abrirModalPagamento()"
        @keydown.window.alt.e.prevent="$wire.set('abaAtiva', 'pedidos')"
        @keydown.window.alt.m.prevent="$wire.set('abaAtiva', 'mesas')"
        @keydown.window.alt.o.prevent="$wire.set('abaAtiva', 'produtos')"
        @keydown.window.escape="$wire.fecharModalCliente(); $wire.fecharModalPagamento(); $wire.fecharModalCancelar(); $wire.fecharModalDividirConta()"
    >
    <div class="grid grid-cols-1 gap-6 xl:grid-cols-5">
        {{-- Coluna de fontes de itens: abas Pedidos / Mesas / Produtos --}}
        <div class="xl:col-span-3 space-y-4">
            <div class="flex items-center gap-2">
                @foreach (['pedidos' => 'Pedidos', 'mesas' => 'Mesas', 'produtos' => 'Produtos', 'pendentes' => 'Pendentes'] as $aba => $label)
                    <button
                        x-on:click="$wire.set('abaAtiva', '{{ $aba }}').then(() => $nextTick(() => document.querySelector('[data-busca-input]')?.focus()))"
                        type="button"
                        class="px-4 py-2 rounded-lg text-sm font-semibold border transition-colors
                            {{ $abaAtiva === $aba ? 'bg-primary-600 border-primary-600 text-white' : 'border-gray-300 dark:border-white/10 text-gray-600 dark:text-gray-300 hover:border-gray-400 dark:hover:border-white/20' }}"
                    >
                        {{ $label }}
                    </button>
                @endforeach

                @if ($abaAtiva !== $this->abaPadraoAtual())
                    <button
                        wire:click="definirAbaPadrao"
                        type="button"
                        title="Definir '{{ ucfirst($abaAtiva) }}' como aba padrão ao abrir esta página"
                        class="ml-auto flex items-center gap-1 text-xs font-medium text-gray-500 dark:text-gray-400 hover:underline shrink-0"
                    >
                        <x-filament::icon icon="heroicon-o-bookmark" class="h-3.5 w-3.5" />
                        Definir como padrão
                    </button>
                @endif

                @if (in_array($abaAtiva, ['mesas', 'pedidos']))
                    <button
                        wire:click="alternarPadraoCards"
                        type="button"
                        class="{{ $abaAtiva === $this->abaPadraoAtual() ? 'ml-auto' : '' }} text-xs font-medium text-gray-500 dark:text-gray-400 hover:underline shrink-0"
                    >
                        {{ $this->abrirCardsPorPadrao ? 'Ocultar itens por padrão' : 'Mostrar itens por padrão' }}
                    </button>
                @endif
            </div>

            @if ($abaAtiva === 'produtos')
                @if ($erroEstoque)
                    <div class="flex items-start gap-2 px-4 py-3 bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/30 rounded-xl text-red-700 dark:text-red-400 text-sm">
                        <x-filament::icon icon="heroicon-o-exclamation-circle" class="h-4 w-4 mt-0.5 shrink-0" />
                        <span class="flex-1">{{ $erroEstoque }}</span>
                        <button wire:click="fecharToastEstoque" type="button" class="text-red-400 hover:text-red-600 shrink-0">
                            <x-filament::icon icon="heroicon-o-x-mark" class="h-4 w-4" />
                        </button>
                    </div>
                @endif
                @if ($avisoEstoque)
                    <div class="flex items-start gap-2 px-4 py-3 bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/30 rounded-xl text-amber-700 dark:text-amber-400 text-sm">
                        <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-4 w-4 mt-0.5 shrink-0" />
                        <span class="flex-1">{{ $avisoEstoque }}</span>
                        <button wire:click="fecharToastEstoque" type="button" class="text-amber-400 hover:text-amber-600 shrink-0">
                            <x-filament::icon icon="heroicon-o-x-mark" class="h-4 w-4" />
                        </button>
                    </div>
                @endif
                @livewire('venda-produto-selector')
            @elseif ($abaAtiva === 'mesas')
                <div class="relative">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 dark:text-gray-500 pointer-events-none" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                    </svg>
                    <input
                        wire:model.live.debounce.300ms="buscaMesa"
                        type="text"
                        data-busca-input
                        placeholder="Buscar mesa ou cliente..."
                        class="w-full pl-9 pr-4 py-2 text-sm border border-gray-300 dark:border-white/10 rounded-xl bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-200 placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                    />
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-white/10 divide-y divide-gray-100 dark:divide-white/10 overflow-y-auto" style="max-height: clamp(20rem, 65vh, 52rem)">
                    @forelse ($this->mesas as $sessaoMesa)
                        @php
                            $itensDaMesa = $sessaoMesa->pedidos->flatMap(fn ($p) => $p->item_pedido_pedido_id);
                            $qtdItensPendentes = $itensDaMesa->where('item_pedido_venda_id', '!==', $this->vendaId)->count();
                            $mesaLancada = $this->itensEstaoLancadosNestaVenda($itensDaMesa);
                        @endphp
                        <div wire:key="mesa-{{ $sessaoMesa->id }}" class="p-4 space-y-2" x-data="{ aberto: @js($this->abrirCardsPorPadrao) }">
                            <div class="flex items-center justify-between gap-2">
                                <button type="button" x-on:click="aberto = !aberto" class="flex items-center gap-2 text-left min-w-0">
                                    <x-filament::icon icon="heroicon-o-chevron-right" x-bind:class="aberto ? 'rotate-90' : ''" class="h-3.5 w-3.5 text-gray-400 dark:text-gray-500 transition-transform shrink-0" />
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-gray-800 dark:text-gray-200 truncate">{{ $sessaoMesa->mesa->mesa_nome }}</p>
                                        <p class="text-xs text-gray-400 dark:text-gray-500">{{ $sessaoMesa->cliente->cliente_nome }} · {{ $qtdItensPendentes }} {{ Str::plural('item', $qtdItensPendentes) }} pendente(s)</p>
                                    </div>
                                </button>
                                <button type="button"
                                    onclick="window.open('{{ route('sessaoMesa.imprimir', ['id' => $sessaoMesa->id]) }}', '_blank', 'width=600,height=400')"
                                    title="Imprimir comanda"
                                    class="text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 shrink-0">
                                    <x-filament::icon icon="heroicon-o-printer" class="h-4 w-4" />
                                </button>
                                <label class="flex items-center gap-2 shrink-0 cursor-pointer select-none">
                                    <span class="text-xs font-medium text-gray-600 dark:text-gray-300">Lançada</span>
                                    <input
                                        type="checkbox"
                                        wire:loading.attr="disabled"
                                        @checked($mesaLancada)
                                        x-on:change="$event.target.checked ? $wire.lancarItensDaMesa({{ $sessaoMesa->id }}) : $wire.removerItensDaMesa({{ $sessaoMesa->id }})"
                                        class="h-4 w-4 rounded border-gray-300 dark:border-white/20 dark:bg-gray-900 text-primary-600 focus:ring-primary-500 disabled:opacity-50"
                                    />
                                </label>
                            </div>

                            <div x-show="aberto" x-cloak class="pl-6 space-y-1">
                                @forelse ($sessaoMesa->pedidos as $pedido)
                                    @foreach ($pedido->item_pedido_pedido_id as $itemPedido)
                                        <div class="flex items-center justify-between gap-2 text-xs text-gray-500 dark:text-gray-400">
                                            <span class="truncate">{{ rtrim(rtrim(number_format((float) $itemPedido->item_pedido_quantidade, 3, ',', '.'), '0'), ',') }}x {{ $itemPedido->produto?->produto_descricao ?? '—' }}</span>
                                            <span class="shrink-0">R$ {{ number_format((float) $itemPedido->item_pedido_valor, 2, ',', '.') }}</span>
                                        </div>
                                    @endforeach
                                @empty
                                    <p class="text-xs text-gray-400 dark:text-gray-500">Nenhum item pendente.</p>
                                @endforelse
                            </div>
                        </div>
                    @empty
                        <div class="p-10 text-center text-sm text-gray-400 dark:text-gray-500">Nenhuma mesa aberta no momento.</div>
                    @endforelse
                </div>
            @elseif ($abaAtiva === 'pedidos')
                <div class="relative">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 dark:text-gray-500 pointer-events-none" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                    </svg>
                    <input
                        wire:model.live.debounce.300ms="buscaPedido"
                        type="text"
                        data-busca-input
                        placeholder="Buscar por número do pedido ou cliente..."
                        class="w-full pl-9 pr-4 py-2 text-sm border border-gray-300 dark:border-white/10 rounded-xl bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-200 placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                    />
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-white/10 divide-y divide-gray-100 dark:divide-white/10 overflow-y-auto" style="max-height: clamp(20rem, 65vh, 52rem)">
                    @forelse ($this->pedidosAvulsos as $pedido)
                        @php $pedidoLancado = $this->itensEstaoLancadosNestaVenda($pedido->item_pedido_pedido_id); @endphp
                        <div wire:key="pedido-avulso-{{ $pedido->id }}" class="p-4 space-y-2" x-data="{ aberto: @js($this->abrirCardsPorPadrao) }">
                            <div class="flex items-center justify-between gap-2">
                                <button type="button" x-on:click="aberto = !aberto" class="flex items-center gap-2 text-left min-w-0">
                                    <x-filament::icon icon="heroicon-o-chevron-right" x-bind:class="aberto ? 'rotate-90' : ''" class="h-3.5 w-3.5 text-gray-400 dark:text-gray-500 transition-transform shrink-0" />
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-gray-800 dark:text-gray-200 truncate">Pedido #{{ $pedido->id }}</p>
                                        <p class="text-xs text-gray-400 dark:text-gray-500">{{ $pedido->cliente->cliente_nome }} · R$ {{ number_format((float) $pedido->pedido_valor_total, 2, ',', '.') }}</p>
                                    </div>
                                </button>
                                <button type="button"
                                    onclick="window.open('{{ route('pedido.imprimir', ['id' => $pedido->id]) }}', '_blank', 'width=600,height=400')"
                                    title="Imprimir pedido"
                                    class="text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 shrink-0">
                                    <x-filament::icon icon="heroicon-o-printer" class="h-4 w-4" />
                                </button>
                                <label class="flex items-center gap-2 shrink-0 cursor-pointer select-none">
                                    <span class="text-xs font-medium text-gray-600 dark:text-gray-300">Lançado</span>
                                    <input
                                        type="checkbox"
                                        wire:loading.attr="disabled"
                                        @checked($pedidoLancado)
                                        x-on:change="$event.target.checked ? $wire.lancarPedidoAvulso({{ $pedido->id }}) : $wire.removerPedidoAvulso({{ $pedido->id }})"
                                        class="h-4 w-4 rounded border-gray-300 dark:border-white/20 dark:bg-gray-900 text-primary-600 focus:ring-primary-500 disabled:opacity-50"
                                    />
                                </label>
                            </div>

                            <div x-show="aberto" x-cloak class="pl-6 space-y-1">
                                @foreach ($pedido->item_pedido_pedido_id as $itemPedido)
                                    <div class="flex items-center justify-between gap-2 text-xs text-gray-500 dark:text-gray-400">
                                        <span class="truncate">{{ rtrim(rtrim(number_format((float) $itemPedido->item_pedido_quantidade, 3, ',', '.'), '0'), ',') }}x {{ $itemPedido->produto?->produto_descricao ?? '—' }}</span>
                                        <span class="shrink-0">R$ {{ number_format((float) $itemPedido->item_pedido_valor, 2, ',', '.') }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @empty
                        <div class="p-10 text-center text-sm text-gray-400 dark:text-gray-500">Nenhum pedido avulso pendente.</div>
                    @endforelse
                </div>
            @elseif ($abaAtiva === 'pendentes')
                <div class="relative">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 dark:text-gray-500 pointer-events-none" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                    </svg>
                    <input
                        wire:model.live.debounce.300ms="buscaPendentes"
                        type="text"
                        data-busca-input
                        placeholder="Buscar por número da venda ou cliente..."
                        class="w-full pl-9 pr-4 py-2 text-sm border border-gray-300 dark:border-white/10 rounded-xl bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-200 placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                    />
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-white/10 divide-y divide-gray-100 dark:divide-white/10 overflow-y-auto" style="max-height: clamp(20rem, 65vh, 52rem)">
                    @forelse ($this->pendentes as $lancamento)
                        <div wire:key="pendente-{{ $lancamento->id }}" class="p-4 flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-gray-800 dark:text-gray-200 truncate">{{ $lancamento->cliente?->cliente_nome ?? '—' }}</p>
                                <p class="text-xs text-gray-400 dark:text-gray-500">
                                    Venda #{{ $lancamento->venda_id }} ·
                                    Vencimento {{ $lancamento->vencimento?->format('d/m/Y') }}
                                    @if ($lancamento->esta_vencido)
                                        <span class="text-red-600 dark:text-red-400 font-semibold">· vencido</span>
                                    @endif
                                </p>
                            </div>
                            <div class="flex items-center gap-3 shrink-0">
                                <span class="text-sm font-semibold text-gray-800 dark:text-gray-200">R$ {{ number_format($lancamento->valor_restante, 2, ',', '.') }}</span>
                                <button type="button"
                                    onclick="window.open('{{ route('lancamento.imprimir_fiado', ['id' => $lancamento->id]) }}', '_blank', 'width=600,height=600')"
                                    title="Imprimir comprovante"
                                    class="text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300">
                                    <x-filament::icon icon="heroicon-o-printer" class="h-4 w-4" />
                                </button>
                                <button wire:click="abrirModalRecebimento({{ $lancamento->id }})" type="button" class="text-xs font-semibold text-primary-700 dark:text-primary-400 hover:underline">
                                    Receber
                                </button>
                            </div>
                        </div>
                    @empty
                        <div class="p-10 text-center text-sm text-gray-400 dark:text-gray-500">Nenhuma venda fiado pendente de recebimento.</div>
                    @endforelse
                </div>
            @endif
        </div>

        {{-- Coluna da venda atual: carrinho, cliente, pagamento, totais --}}
        <div class="xl:col-span-2 space-y-4">
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-white/10 overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-100 dark:border-white/10">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">{{ $this->vendaId ? 'Itens da venda #'.$this->vendaId : 'Nova venda' }}</h3>
                </div>

                <div class="divide-y divide-gray-100 dark:divide-white/10 overflow-y-auto" style="max-height: clamp(16rem, 45vh, 36rem)">
                    @forelse ($this->itensCarrinho as $item)
                        <div wire:key="item-carrinho-{{ $item->id }}" class="px-4 py-3 space-y-2">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-gray-800 dark:text-gray-200 truncate">{{ $item->produto?->produto_descricao ?? '—' }}</p>
                                    <p class="text-xs text-gray-400 dark:text-gray-500">{{ $item->produto?->categoria?->categoria_nome }}</p>
                                </div>
                                <button wire:click="removerItemCarrinho({{ $item->id }})" type="button" class="text-gray-400 dark:text-gray-500 hover:text-red-600 dark:hover:text-red-400 shrink-0">
                                    <x-filament::icon icon="heroicon-o-trash" class="h-4 w-4" />
                                </button>
                            </div>

                            <div class="flex items-center justify-between gap-3">
                                <div class="flex items-center gap-2">
                                    <button
                                        wire:click="atualizarQtdItem({{ $item->id }}, {{ max(0, (float) $item->item_venda_quantidade - 1) }})"
                                        type="button"
                                        class="w-7 h-7 rounded-full border border-gray-300 dark:border-white/10 text-gray-600 dark:text-gray-300 hover:border-gray-400 dark:hover:border-white/20 flex items-center justify-center"
                                    >
                                        <x-filament::icon icon="heroicon-o-minus" class="h-3.5 w-3.5" />
                                    </button>
                                    <span class="text-sm font-semibold w-8 text-center text-gray-800 dark:text-gray-200">{{ rtrim(rtrim(number_format((float) $item->item_venda_quantidade, 3, ',', '.'), '0'), ',') }}</span>
                                    <button
                                        wire:click="atualizarQtdItem({{ $item->id }}, {{ (float) $item->item_venda_quantidade + 1 }})"
                                        type="button"
                                        class="w-7 h-7 rounded-full border border-gray-300 dark:border-white/10 text-gray-600 dark:text-gray-300 hover:border-gray-400 dark:hover:border-white/20 flex items-center justify-center"
                                    >
                                        <x-filament::icon icon="heroicon-o-plus" class="h-3.5 w-3.5" />
                                    </button>
                                </div>

                                <div class="flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                                    <span>Desc. R$</span>
                                    <input
                                        type="text"
                                        inputmode="decimal"
                                        value="{{ number_format((float) $item->item_venda_desconto, 2, ',', '.') }}"
                                        x-on:input="$el.value = Currency.masking($el.value, {locales:'pt-BR'})"
                                        x-on:blur="$wire.atualizarDescontoItem({{ $item->id }}, Currency.unmaskedValue)"
                                        class="w-16 text-xs border border-gray-300 dark:border-white/10 dark:bg-gray-900 dark:text-gray-100 rounded-md px-1.5 py-1 text-right focus:outline-none focus:ring-1 focus:ring-primary-500"
                                    />
                                </div>

                                <span class="text-sm font-semibold text-gray-800 dark:text-gray-200">
                                    R$ {{ number_format((float) $item->item_venda_valor, 2, ',', '.') }}
                                </span>
                            </div>
                        </div>
                    @empty
                        <div class="px-4 py-10 text-center text-sm text-gray-400 dark:text-gray-500">
                            Nenhum item lançado ainda.
                        </div>
                    @endforelse
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-white/10 p-4 flex items-center justify-between gap-3">
                @if ($this->venda?->cliente)
                    <div class="min-w-0">
                        <p class="text-xs text-gray-400 dark:text-gray-500">Cliente</p>
                        <p class="text-sm font-semibold text-gray-800 dark:text-gray-200 truncate">{{ $this->venda->cliente->cliente_nome }}</p>
                    </div>
                    <div class="flex gap-2 shrink-0">
                        <button wire:click="abrirModalCliente" type="button" class="text-xs text-primary-700 dark:text-primary-400 hover:underline">Trocar</button>
                        <button wire:click="removerClienteDaVenda" type="button" class="text-xs text-red-600 dark:text-red-400 hover:underline">Remover</button>
                    </div>
                @else
                    <span class="text-sm text-gray-400 dark:text-gray-500">Nenhum cliente vinculado</span>
                    <button wire:click="abrirModalCliente" type="button" class="text-xs font-semibold text-primary-700 dark:text-primary-400 hover:underline shrink-0">Selecionar cliente</button>
                @endif
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-white/10 p-4 space-y-3">
                <div class="flex items-center justify-between gap-3">
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Desconto percentual</span>
                    @if ($this->venda?->venda_desconto_percentual)
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-semibold text-gray-800 dark:text-gray-200">{{ rtrim(rtrim(number_format((float) $this->venda->venda_desconto_percentual, 2, ',', '.'), '0'), ',') }}%</span>
                            <button wire:click="desfazerDescontoPercentual" type="button" class="text-xs text-red-600 dark:text-red-400 hover:underline">Desfazer</button>
                        </div>
                    @else
                        <form wire:submit="aplicarDescontoPercentual($refs.percentual.value)" class="flex items-center gap-2">
                            <input x-ref="percentual" type="number" step="0.01" min="0" max="100" placeholder="%" class="w-16 text-xs border border-gray-300 dark:border-white/10 dark:bg-gray-900 dark:text-gray-100 rounded-md px-1.5 py-1 text-right focus:outline-none focus:ring-1 focus:ring-primary-500" />
                            <button type="submit" class="text-xs font-semibold text-primary-700 dark:text-primary-400 hover:underline">Aplicar</button>
                        </form>
                    @endif
                </div>

                <div class="flex items-center justify-between gap-3">
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Frete</span>
                    <div class="flex items-center gap-1">
                        <span class="text-xs text-gray-400 dark:text-gray-500">R$</span>
                        <input
                            type="text"
                            inputmode="decimal"
                            value="{{ number_format((float) $this->venda?->venda_valor_frete, 2, ',', '.') }}"
                            x-on:input="$el.value = Currency.masking($el.value, {locales:'pt-BR'})"
                            x-on:blur="$wire.atualizarFrete(Currency.unmaskedValue)"
                            class="w-20 text-xs border border-gray-300 dark:border-white/10 dark:bg-gray-900 dark:text-gray-100 rounded-md px-1.5 py-1 text-right focus:outline-none focus:ring-1 focus:ring-primary-500"
                        />
                    </div>
                </div>

                <div class="border-t border-gray-100 dark:border-white/10 pt-3 flex items-center justify-between">
                    <span class="text-sm font-semibold text-gray-700 dark:text-gray-200">Total</span>
                    <span class="text-lg font-bold text-gray-900 dark:text-white">R$ {{ number_format((float) $this->venda?->venda_valor_total, 2, ',', '.') }}</span>
                </div>
                <div class="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                    <span>Pago</span>
                    <span>R$ {{ number_format((float) $this->venda?->venda_valor_pago, 2, ',', '.') }}</span>
                </div>
                <div class="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                    <span>Troco</span>
                    <span>R$ {{ number_format((float) $this->venda?->venda_valor_troco, 2, ',', '.') }}</span>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-white/10 overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-100 dark:border-white/10 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Pagamentos</h3>
                    <div class="flex items-center gap-3">
                        @if ($this->temFormaStone && $this->maquininhasStone->isNotEmpty() && $this->valorRestante > 0)
                            <button wire:click="abrirModalStoneTotal" type="button" class="text-xs font-semibold text-primary-700 dark:text-primary-400 hover:underline">Lançar total na maquininha</button>
                        @endif
                        <button wire:click="abrirModalDividirConta" type="button" class="text-xs font-semibold text-gray-500 dark:text-gray-400 hover:underline">Dividir conta</button>
                        <button wire:click="abrirModalPagamento" type="button" class="text-xs font-semibold text-primary-700 dark:text-primary-400 hover:underline">Adicionar</button>
                    </div>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-white/10">
                    @forelse ($this->pagamentosLancados as $pagamento)
                        <div wire:key="pagamento-{{ $pagamento->id }}" class="px-4 py-2 flex items-center justify-between gap-2">
                            <span class="text-sm text-gray-700 dark:text-gray-300">{{ $pagamento->opcaoPagamento?->opcaopag_nome }}</span>
                            <div class="flex items-center gap-3">
                                <span class="text-sm font-semibold text-gray-800 dark:text-gray-200">R$ {{ number_format((float) $pagamento->pg_venda_valor_pagamento, 2, ',', '.') }}</span>
                                <button wire:click="editarPagamento({{ $pagamento->id }})" type="button" class="text-gray-400 dark:text-gray-500 hover:text-primary-600 dark:hover:text-primary-400">
                                    <x-filament::icon icon="heroicon-o-pencil" class="h-4 w-4" />
                                </button>
                                <button wire:click="removerPagamento({{ $pagamento->id }})" type="button" class="text-gray-400 dark:text-gray-500 hover:text-red-600 dark:hover:text-red-400">
                                    <x-filament::icon icon="heroicon-o-trash" class="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                    @empty
                        <div class="px-4 py-6 text-center text-sm text-gray-400 dark:text-gray-500">Nenhum pagamento lançado.</div>
                    @endforelse
                </div>
            </div>

            @error('finalizar')
                <div class="bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/30 text-red-700 dark:text-red-400 text-sm rounded-lg px-4 py-3">{{ $message }}</div>
            @enderror

            @if ($this->nfeIoDisponivel)
                <label class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300 cursor-pointer select-none">
                    <input type="checkbox" wire:model="emitirNfeAoFinalizar" class="h-4 w-4 rounded border-gray-300 dark:border-white/20 dark:bg-gray-900 text-primary-600 focus:ring-primary-500" />
                    Emitir NFC-e ao finalizar
                </label>
            @endif

            @if ($this->venda?->venda_cliente_id)
                <div class="space-y-2">
                    <label class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300 cursor-pointer select-none">
                        <input type="checkbox" wire:model.live="vendaFiado" class="h-4 w-4 rounded border-gray-300 dark:border-white/20 dark:bg-gray-900 text-primary-600 focus:ring-primary-500" />
                        Vender fiado / a prazo (saldo em aberto vira título a receber)
                    </label>

                    @if ($vendaFiado)
                        <div class="pl-6 space-y-1">
                            <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Vencimento do saldo</label>
                            <input wire:model="fiadoVencimento" type="date" class="w-full text-sm border border-gray-300 dark:border-white/10 dark:bg-gray-900 dark:text-gray-100 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500" />

                            @php $limiteDisponivel = $this->limiteCreditoDisponivel; @endphp
                            <p class="text-xs {{ $limiteDisponivel === null ? 'text-red-600 dark:text-red-400' : 'text-gray-400 dark:text-gray-500' }}">
                                @if ($limiteDisponivel === null)
                                    Cliente sem limite de crédito configurado — cadastre um limite antes de vender fiado.
                                @else
                                    Crédito disponível do cliente: R$ {{ number_format($limiteDisponivel, 2, ',', '.') }}
                                @endif
                            </p>
                        </div>
                    @endif
                </div>
            @endif

            <div class="flex gap-2">
                <button wire:click="finalizarVenda" wire:loading.attr="disabled" type="button" class="flex-1 py-3 rounded-lg text-sm font-semibold bg-primary-600 text-white hover:bg-primary-700 disabled:opacity-50">
                    Finalizar venda
                </button>
                <button wire:click="abrirModalCancelar" type="button" class="px-4 py-3 rounded-lg text-sm font-semibold border border-red-300 dark:border-red-500/30 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10">
                    Cancelar
                </button>
            </div>
        </div>
    </div>

    {{-- Modal Cancelar --}}
    @if ($modalCancelarAberta)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" wire:click.self="fecharModalCancelar">
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl w-full max-w-sm">
                <div class="px-4 py-3 border-b border-gray-100 dark:border-white/10 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Cancelar venda</h3>
                    <button wire:click="fecharModalCancelar" type="button" class="text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300">
                        <x-filament::icon icon="heroicon-o-x-mark" class="h-5 w-5" />
                    </button>
                </div>
                <div class="p-4 space-y-3">
                    <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Motivo do cancelamento</label>
                    <select wire:model="motivoCancelamento" class="w-full text-sm border border-gray-300 dark:border-white/10 dark:bg-gray-900 dark:text-gray-100 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500">
                        <option value="">Selecione...</option>
                        @foreach (\App\Enums\MotivoCancelamentoEnum::paraSelect() as $valor => $label)
                            <option value="{{ $valor }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <button wire:click="confirmarCancelamento" type="button" class="w-full py-2 rounded-lg text-sm font-semibold bg-red-600 text-white hover:bg-red-700">
                        Confirmar cancelamento
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Modal Emissão de NFC-e --}}
    @if ($modalNfeAberta)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl w-full max-w-sm">
                <div class="px-4 py-3 border-b border-gray-100 dark:border-white/10">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Emissão de NFC-e</h3>
                </div>
                <div class="p-4 space-y-3">
                    @if ($nfeStatusModal === 'processando')
                        <div wire:poll.3s="verificarStatusNfe" class="flex flex-col items-center gap-3 py-4 text-center">
                            <svg class="animate-spin h-6 w-6 text-primary-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            <p class="text-sm text-gray-600 dark:text-gray-300">Emitindo NFC-e, aguarde...</p>
                            <p class="text-xs text-gray-400 dark:text-gray-500">A emissão é processada de forma assíncrona pela NFe.io — isso pode levar alguns segundos.</p>
                        </div>
                        <button wire:click="fecharModalNfe" type="button" class="w-full py-2 rounded-lg text-sm font-semibold border border-gray-300 dark:border-white/10 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5">
                            Continuar mesmo assim
                        </button>
                    @elseif ($nfeStatusModal === 'erro')
                        <div class="bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/30 text-red-700 dark:text-red-400 text-sm rounded-lg px-3 py-2">
                            {{ $nfeErroModal }}
                        </div>
                        <div class="flex gap-2">
                            <button wire:click="iniciarEmissaoNfe" type="button" class="flex-1 py-2 rounded-lg text-sm font-semibold bg-primary-600 text-white hover:bg-primary-700">
                                Tentar novamente
                            </button>
                            <button wire:click="fecharModalNfe" type="button" class="flex-1 py-2 rounded-lg text-sm font-semibold border border-gray-300 dark:border-white/10 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5">
                                Continuar sem NFC-e
                            </button>
                        </div>
                    @elseif ($nfeStatusModal === 'emitido')
                        <div class="flex flex-col items-center gap-2 py-2 text-center">
                            <x-filament::icon icon="heroicon-o-check-circle" class="h-8 w-8 text-emerald-500" />
                            <p class="text-sm font-medium text-gray-700 dark:text-gray-200">NFC-e emitida com sucesso!</p>
                        </div>
                        <div class="flex gap-2">
                            <button type="button"
                                onclick="window.open('{{ route('venda.imprimir_NFE', ['id_nfe' => $nfeInvoiceId]) }}', '_blank')"
                                class="flex-1 py-2 rounded-lg text-sm font-semibold border border-gray-300 dark:border-white/10 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5">
                                Ver DANFE
                            </button>
                            <button wire:click="fecharModalNfe" type="button" class="flex-1 py-2 rounded-lg text-sm font-semibold bg-primary-600 text-white hover:bg-primary-700">
                                Concluir
                            </button>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- Modal Cobrança na maquininha Stone --}}
    @if ($modalStoneAberta)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl w-full max-w-sm">
                <div class="px-4 py-3 border-b border-gray-100 dark:border-white/10">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Cobrança na maquininha</h3>
                </div>
                <div class="p-4 space-y-3">
                    @if ($stoneStatusModal === 'aguardando')
                        <div wire:poll.3s="verificarStatusStone" class="flex flex-col items-center gap-3 py-4 text-center">
                            <svg class="animate-spin h-6 w-6 text-primary-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            <p class="text-sm text-gray-600 dark:text-gray-300">Aguardando o pagamento na maquininha…</p>
                            <p class="text-xs text-gray-400 dark:text-gray-500">No Pedido Direto a maquininha já abriu a tela de pagamento. No Listado, selecione o pedido na lista do POS.</p>
                        </div>
                        <div class="flex gap-2">
                            <button wire:click="cancelarCobrancaStone" wire:loading.attr="disabled" type="button" class="flex-1 py-2 rounded-lg text-sm font-semibold border border-red-300 dark:border-red-500/30 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10 disabled:opacity-50">
                                Cancelar cobrança
                            </button>
                            <button wire:click="fecharModalStone" type="button" class="flex-1 py-2 rounded-lg text-sm font-semibold border border-gray-300 dark:border-white/10 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5">
                                Fechar
                            </button>
                        </div>
                    @elseif ($stoneStatusModal === 'pago')
                        <div class="flex flex-col items-center gap-2 py-2 text-center">
                            <x-filament::icon icon="heroicon-o-check-circle" class="h-8 w-8 text-emerald-500" />
                            <p class="text-sm font-medium text-gray-700 dark:text-gray-200">Pagamento confirmado!</p>
                        </div>
                        <button wire:click="fecharModalStone" type="button" class="w-full py-2 rounded-lg text-sm font-semibold bg-primary-600 text-white hover:bg-primary-700">
                            Concluir
                        </button>
                    @elseif ($stoneStatusModal === 'erro')
                        <div class="bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/30 text-red-700 dark:text-red-400 text-sm rounded-lg px-3 py-2">
                            {{ $stoneErroModal }}
                        </div>
                        <div class="flex gap-2">
                            <button wire:click="fecharModalStone" type="button" class="flex-1 py-2 rounded-lg text-sm font-semibold border border-gray-300 dark:border-white/10 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5">
                                Fechar
                            </button>
                        </div>
                    @elseif ($stoneStatusModal === 'cancelado')
                        <div class="flex flex-col items-center gap-2 py-2 text-center">
                            <x-filament::icon icon="heroicon-o-x-circle" class="h-8 w-8 text-gray-400" />
                            <p class="text-sm text-gray-600 dark:text-gray-300">Cobrança cancelada.</p>
                        </div>
                        <button wire:click="fecharModalStone" type="button" class="w-full py-2 rounded-lg text-sm font-semibold border border-gray-300 dark:border-white/10 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5">
                            Fechar
                        </button>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- Modal "Lançar pedido total na maquininha" (modelo Listado) --}}
    @if ($modalStoneTotalAberta)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" wire:click.self="fecharModalStoneTotal">
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl w-full max-w-sm">
                <div class="px-4 py-3 border-b border-gray-100 dark:border-white/10 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Lançar pedido na maquininha</h3>
                    <button wire:click="fecharModalStoneTotal" type="button" class="text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300">
                        <x-filament::icon icon="heroicon-o-x-mark" class="h-5 w-5" />
                    </button>
                </div>
                <div class="p-4 space-y-3">
                    <div>
                        <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Maquininha Stone</label>
                        <select wire:model="stoneTotalMaquininhaId" class="w-full text-sm border border-gray-300 dark:border-white/10 dark:bg-gray-900 dark:text-gray-100 rounded-lg px-3 py-2 mt-1 focus:outline-none focus:ring-2 focus:ring-primary-500">
                            <option value="">Selecione...</option>
                            @foreach ($this->maquininhasStone as $id => $nome)
                                <option value="{{ $id }}">{{ $nome }}</option>
                            @endforeach
                        </select>
                    </div>

                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        O pedido vai para a lista do POS com o valor restante
                        (<strong class="text-gray-700 dark:text-gray-200">R$ {{ number_format($this->valorRestante, 2, ',', '.') }}</strong>).
                        A forma (crédito, débito ou PIX) é escolhida na maquininha.
                    </p>

                    @error('stoneTotal')
                        <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror

                    <div class="flex gap-2">
                        <button wire:click="lancarPedidoTotalStone" wire:loading.attr="disabled" type="button" class="flex-1 py-2 rounded-lg text-sm font-semibold bg-primary-600 text-white hover:bg-primary-700 disabled:opacity-50">
                            Lançar pedido
                        </button>
                        <button wire:click="fecharModalStoneTotal" type="button" class="px-4 py-2 rounded-lg text-sm font-semibold border border-gray-300 dark:border-white/10 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5">
                            Cancelar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Modal Cliente --}}
    @if ($modalClienteAberta)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" wire:click.self="fecharModalCliente">
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl w-full max-w-lg max-h-[85vh] overflow-y-auto">
                <div class="px-4 py-3 border-b border-gray-100 dark:border-white/10 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Selecionar cliente</h3>
                    <button wire:click="fecharModalCliente" type="button" class="text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300">
                        <x-filament::icon icon="heroicon-o-x-mark" class="h-5 w-5" />
                    </button>
                </div>

                <div class="p-4 space-y-4">
                    <div>
                        <input
                            wire:model.live.debounce.300ms="buscaCliente"
                            type="text"
                            placeholder="Buscar por nome, CPF, CNPJ ou telefone..."
                            class="w-full text-sm border border-gray-300 dark:border-white/10 dark:bg-gray-900 dark:text-gray-100 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500"
                        />

                        @if ($this->clientesEncontrados->isNotEmpty())
                            <div class="mt-2 border border-gray-100 dark:border-white/10 rounded-lg divide-y divide-gray-100 dark:divide-white/10 max-h-48 overflow-y-auto">
                                @foreach ($this->clientesEncontrados as $cliente)
                                    <button
                                        wire:click="selecionarCliente({{ $cliente->id }})"
                                        type="button"
                                        class="w-full text-left px-3 py-2 text-sm hover:bg-gray-50 dark:hover:bg-white/5"
                                    >
                                        <span class="font-medium text-gray-800 dark:text-gray-200">{{ $cliente->cliente_nome }}</span>
                                        <span class="text-xs text-gray-400 dark:text-gray-500">{{ $cliente->cliente_cpf ?? $cliente->cliente_cnpj ?? $cliente->cliente_celular }}</span>
                                    </button>
                                @endforeach
                            </div>
                        @elseif (trim($buscaCliente) !== '')
                            <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">Nenhum cliente encontrado.</p>
                        @endif
                    </div>

                    <div class="border-t border-gray-100 dark:border-white/10 pt-4 space-y-3">
                        <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Ou cadastre rápido por CPF/CNPJ</p>

                        <div class="grid grid-cols-2 gap-3">
                            <div class="col-span-2">
                                <input wire:model="clienteAdHocNome" type="text" placeholder="Nome" class="w-full text-sm border border-gray-300 dark:border-white/10 dark:bg-gray-900 dark:text-gray-100 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500" />
                                @error('cliente_nome') <span class="text-xs text-red-600 dark:text-red-400">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <input wire:model="clienteAdHocCpf" type="text" placeholder="CPF" class="w-full text-sm border border-gray-300 dark:border-white/10 dark:bg-gray-900 dark:text-gray-100 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500" />
                            </div>
                            <div>
                                <input wire:model="clienteAdHocCnpj" type="text" placeholder="CNPJ" class="w-full text-sm border border-gray-300 dark:border-white/10 dark:bg-gray-900 dark:text-gray-100 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500" />
                            </div>
                            <div>
                                <input wire:model="clienteAdHocTelefone" type="text" placeholder="Telefone" class="w-full text-sm border border-gray-300 dark:border-white/10 dark:bg-gray-900 dark:text-gray-100 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500" />
                            </div>
                            <div>
                                <input wire:model="clienteAdHocEmail" type="email" placeholder="E-mail" class="w-full text-sm border border-gray-300 dark:border-white/10 dark:bg-gray-900 dark:text-gray-100 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500" />
                                @error('cliente_email') <span class="text-xs text-red-600 dark:text-red-400">{{ $message }}</span> @enderror
                            </div>
                        </div>

                        <button wire:click="salvarClienteAdHoc" type="button" class="w-full py-2 rounded-lg text-sm font-semibold bg-primary-600 text-white hover:bg-primary-700">
                            Cadastrar e vincular
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Modal Pagamento --}}
    @if ($modalPagamentoAberta)
        @php
            $opcaoSelecionada = $this->opcoesPagamento->firstWhere('id', $opcaoPagamentoSelecionadaId);
            $emEdicaoPagamento = (bool) $pagamentoEmEdicaoId;
            $ehStone = (bool) $opcaoSelecionada?->ehIntegracaoStone();
        @endphp
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" wire:click.self="fecharModalPagamento">
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl w-full max-w-md max-h-[85vh] overflow-y-auto">
                <div class="px-4 py-3 border-b border-gray-100 dark:border-white/10 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">{{ $emEdicaoPagamento ? 'Editar pagamento' : 'Registrar pagamento' }}</h3>
                    <button wire:click="fecharModalPagamento" type="button" class="text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300">
                        <x-filament::icon icon="heroicon-o-x-mark" class="h-5 w-5" />
                    </button>
                </div>

                <div class="p-4 space-y-3">
                    @php $sugestaoPagamento = $this->sugestaoPagamentoPedido; @endphp
                    @if ($sugestaoPagamento)
                        <div class="flex items-start gap-2 bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/20 text-amber-800 dark:text-amber-300 text-xs rounded-lg p-2">
                            <x-filament::icon icon="heroicon-o-information-circle" class="h-4 w-4 shrink-0 mt-0.5" />
                            <div class="space-y-0.5">
                                @if ($sugestaoPagamento['descricao'])
                                    <p>Forma de pagamento indicada no pedido: <span class="font-semibold">{{ $sugestaoPagamento['descricao'] }}</span></p>
                                @endif
                                @foreach ($sugestaoPagamento['observacoes'] as $observacao)
                                    <p>Obs.: {{ $observacao }}</p>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <div>
                        <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Forma de pagamento</label>
                        <div class="grid grid-cols-2 gap-2 mt-1">
                            @foreach ($this->opcoesPagamento as $opcao)
                                <label class="relative flex items-center justify-center px-3 py-2 rounded-lg border text-sm font-medium text-center cursor-pointer transition-colors
                                    border-gray-300 dark:border-white/10 text-gray-600 dark:text-gray-300
                                    has-[:checked]:border-primary-500 has-[:checked]:bg-primary-50 has-[:checked]:text-primary-700
                                    dark:has-[:checked]:bg-primary-500/10 dark:has-[:checked]:text-primary-400">
                                    <input type="radio" wire:model.live="opcaoPagamentoSelecionadaId" value="{{ $opcao->id }}" class="sr-only" />
                                    {{ $opcao->opcaopag_nome }}
                                </label>
                            @endforeach
                        </div>
                    </div>

                    @if ($opcaoSelecionada?->ehIntegracaoStone())
                        <div>
                            <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Maquininha Stone</label>
                            <select wire:model="stoneMaquininhaId" class="w-full text-sm border border-gray-300 dark:border-white/10 dark:bg-gray-900 dark:text-gray-100 rounded-lg px-3 py-2 mt-1 focus:outline-none focus:ring-2 focus:ring-primary-500">
                                <option value="">Selecione...</option>
                                @foreach ($this->maquininhasStone as $id => $nome)
                                    <option value="{{ $id }}">{{ $nome }}</option>
                                @endforeach
                            </select>
                            @if ($this->maquininhasStone->isEmpty())
                                <p class="text-xs text-amber-600 dark:text-amber-400 mt-1">Nenhuma maquininha Stone com número de série cadastrada.</p>
                            @endif
                        </div>
                    @endif

                    @if ($opcaoSelecionada?->opcaopag_requer_bandeira && ! $opcaoSelecionada?->ehIntegracaoStone())
                        <div>
                            <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Cartão / bandeira</label>
                            <select wire:model="cartaoId" class="w-full text-sm border border-gray-300 dark:border-white/10 dark:bg-gray-900 dark:text-gray-100 rounded-lg px-3 py-2 mt-1 focus:outline-none focus:ring-2 focus:ring-primary-500">
                                <option value="">Selecione...</option>
                                @foreach ($this->cartoes as $cartao)
                                    <option value="{{ $cartao->id }}">{{ $cartao->cartao_bandeira }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    @if ($opcaoSelecionada?->opcaopag_requer_autorizacao && ! $ehStone)
                        <div>
                            <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Número de autorização</label>
                            <input
                                wire:model="numeroAutorizacaoCartao"
                                type="text"
                                x-on:keydown.enter.prevent="$wire.registrarPagamento()"
                                class="w-full text-sm border border-gray-300 dark:border-white/10 dark:bg-gray-900 dark:text-gray-100 rounded-lg px-3 py-2 mt-1 focus:outline-none focus:ring-2 focus:ring-primary-500"
                            />
                        </div>
                    @endif

                    <div
                        class="grid grid-cols-2 gap-3"
                        x-data="{
                            recebido: @js(number_format($this->valorPagamento, 2, ',', '.')),
                            pagoCliente: @js($this->valorPagoPeloCliente > 0 ? number_format($this->valorPagoPeloCliente, 2, ',', '.') : ''),
                            parseValor(v) { return parseFloat(String(v).replace(/\./g, '').replace(',', '.')) || 0; },
                            get troco() { return Math.max(0, this.parseValor(this.pagoCliente) - this.parseValor(this.recebido)); },
                        }"
                    >
                        <div @class(['col-span-2' => $ehStone])>
                            <label class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $ehStone ? 'Valor a cobrar (R$)' : 'Valor recebido (R$)' }}</label>
                            <input
                                type="text"
                                inputmode="decimal"
                                x-bind:value="recebido"
                                x-on:input="$el.value = Currency.masking($el.value, {locales:'pt-BR'}); recebido = $el.value"
                                x-on:blur="$wire.set('valorPagamento', parseValor(recebido))"
                                class="w-full text-sm border border-gray-300 dark:border-white/10 dark:bg-gray-900 dark:text-gray-100 rounded-lg px-3 py-2 mt-1 text-right focus:outline-none focus:ring-2 focus:ring-primary-500"
                            />
                        </div>
                        @unless ($ehStone)
                            <div>
                                <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Pago pelo cliente (R$)</label>
                                <input
                                    type="text"
                                    inputmode="decimal"
                                    x-bind:value="pagoCliente"
                                    x-on:input="$el.value = Currency.masking($el.value, {locales:'pt-BR'}); pagoCliente = $el.value"
                                    x-on:blur="$wire.set('valorPagoPeloCliente', parseValor(pagoCliente))"
                                    x-on:keydown.enter.prevent="$wire.set('valorPagoPeloCliente', parseValor(pagoCliente)).then(() => $wire.registrarPagamento())"
                                    class="w-full text-sm border border-gray-300 dark:border-white/10 dark:bg-gray-900 dark:text-gray-100 rounded-lg px-3 py-2 mt-1 text-right focus:outline-none focus:ring-2 focus:ring-primary-500"
                                />
                            </div>
                        @endunless
                        <div class="col-span-2 flex items-center justify-between text-xs px-1">
                            <span class="text-gray-500 dark:text-gray-400">Restante: <strong class="text-gray-700 dark:text-gray-200">R$ {{ number_format($this->valorRestante, 2, ',', '.') }}</strong></span>
                            @unless ($ehStone)
                                <span class="text-gray-500 dark:text-gray-400">Troco: <strong class="text-green-600 dark:text-green-400" x-text="'R$ ' + troco.toLocaleString('pt-BR', {minimumFractionDigits:2, maximumFractionDigits:2})"></strong></span>
                            @endunless
                        </div>
                    </div>

                    @error('valorPagamento')
                        <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror

                    @if ($opcaoSelecionada && (float) $opcaoSelecionada->opcaopag_valor_percentual_taxa > 0)
                        <p class="text-xs text-gray-400 dark:text-gray-500">
                            {{ $opcaoSelecionada->opcaopag_tipo_taxa === 'ACRESCENTAR' ? 'Acréscimo' : 'Desconto' }}
                            de {{ rtrim(rtrim(number_format((float) $opcaoSelecionada->opcaopag_valor_percentual_taxa, 2, ',', '.'), '0'), ',') }}%
                            aplicado automaticamente sobre o valor recebido.
                        </p>
                    @endif

                    <button wire:click="registrarPagamento" wire:loading.attr="disabled" type="button" class="w-full py-2 rounded-lg text-sm font-semibold bg-primary-600 text-white hover:bg-primary-700 disabled:opacity-50">
                        {{ $ehStone ? 'Enviar cobrança para a maquininha' : ($emEdicaoPagamento ? 'Salvar alterações' : 'Confirmar pagamento') }}
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Modal Dividir conta --}}
    @if ($modalDividirContaAberta)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" wire:click.self="fecharModalDividirConta">
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl w-full max-w-sm">
                <div class="px-4 py-3 border-b border-gray-100 dark:border-white/10 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Dividir conta</h3>
                    <button wire:click="fecharModalDividirConta" type="button" class="text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300">
                        <x-filament::icon icon="heroicon-o-x-mark" class="h-5 w-5" />
                    </button>
                </div>
                <div class="p-4 space-y-3">
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        Restante a pagar: <strong class="text-gray-700 dark:text-gray-200">R$ {{ number_format($this->valorRestante, 2, ',', '.') }}</strong>
                    </p>
                    <div>
                        <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Quantidade de pessoas</label>
                        <input wire:model.live="dividirContaQtdPessoas" type="number" min="1" step="1" class="w-full text-sm border border-gray-300 dark:border-white/10 dark:bg-gray-900 dark:text-gray-100 rounded-lg px-3 py-2 mt-1 focus:outline-none focus:ring-2 focus:ring-primary-500" />
                    </div>
                    <div>
                        <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Forma de pagamento padrão</label>
                        <select wire:model="dividirContaOpcaoPagamentoId" class="w-full text-sm border border-gray-300 dark:border-white/10 dark:bg-gray-900 dark:text-gray-100 rounded-lg px-3 py-2 mt-1 focus:outline-none focus:ring-2 focus:ring-primary-500">
                            <option value="">Selecione...</option>
                            @foreach ($this->opcoesPagamento as $opcao)
                                <option value="{{ $opcao->id }}">{{ $opcao->opcaopag_nome }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if ($dividirContaQtdPessoas > 0)
                        <p class="text-xs text-gray-400 dark:text-gray-500">
                            {{ $dividirContaQtdPessoas }}x de aproximadamente R$ {{ number_format($this->valorRestante / max(1, $dividirContaQtdPessoas), 2, ',', '.') }}
                        </p>
                    @endif
                    <button wire:click="dividirConta($wire.dividirContaQtdPessoas, $wire.dividirContaOpcaoPagamentoId)" type="button" class="w-full py-2 rounded-lg text-sm font-semibold bg-primary-600 text-white hover:bg-primary-700">
                        Confirmar divisão
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Modal Registrar recebimento (aba Pendentes) --}}
    @if ($modalRecebimentoAberto)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" wire:click.self="fecharModalRecebimento">
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl w-full max-w-sm">
                <div class="px-4 py-3 border-b border-gray-100 dark:border-white/10 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Registrar recebimento</h3>
                    <button wire:click="fecharModalRecebimento" type="button" class="text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300">
                        <x-filament::icon icon="heroicon-o-x-mark" class="h-5 w-5" />
                    </button>
                </div>
                <div class="p-4 space-y-3">
                    @if ($this->pagamentosDoLancamentoEmRecebimento->isNotEmpty())
                        <div class="border border-gray-100 dark:border-white/10 rounded-lg divide-y divide-gray-100 dark:divide-white/10">
                            <p class="px-3 py-1.5 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Pagamentos já registrados</p>
                            @foreach ($this->pagamentosDoLancamentoEmRecebimento as $pagamento)
                                <div class="px-3 py-1.5 flex items-center justify-between text-xs">
                                    <span class="text-gray-500 dark:text-gray-400">
                                        {{ $pagamento->data_pagamento?->format('d/m/Y') }}
                                        @if ($pagamento->forma_pagamento)
                                            · {{ $pagamento->forma_pagamento->getLabel() }}
                                        @endif
                                        @if ($pagamento->cartao)
                                            · {{ $pagamento->cartao->cartao_bandeira }}
                                        @endif
                                        @if ($pagamento->numero_autorizacao_cartao)
                                            · Aut. {{ $pagamento->numero_autorizacao_cartao }}
                                        @endif
                                    </span>
                                    <span class="font-semibold text-gray-700 dark:text-gray-200">R$ {{ number_format((float) $pagamento->valor, 2, ',', '.') }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                    <div>
                        <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Valor recebido (R$)</label>
                        <input
                            type="text"
                            inputmode="decimal"
                            value="{{ number_format($valorRecebimento, 2, ',', '.') }}"
                            x-on:input="$el.value = Currency.masking($el.value, {locales:'pt-BR'})"
                            x-on:blur="$wire.set('valorRecebimento', Currency.unmaskedValue)"
                            class="w-full text-sm border border-gray-300 dark:border-white/10 dark:bg-gray-900 dark:text-gray-100 rounded-lg px-3 py-2 mt-1 text-right focus:outline-none focus:ring-2 focus:ring-primary-500"
                        />
                    </div>
                    <div>
                        <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Forma de recebimento</label>
                        <select wire:model.live="formaRecebimento" class="w-full text-sm border border-gray-300 dark:border-white/10 dark:bg-gray-900 dark:text-gray-100 rounded-lg px-3 py-2 mt-1 focus:outline-none focus:ring-2 focus:ring-primary-500">
                            <option value="">Selecione...</option>
                            @foreach (\App\Enums\FormaPagamento::cases() as $forma)
                                @continue($forma === \App\Enums\FormaPagamento::Compensacao)
                                <option value="{{ $forma->value }}">{{ $forma->getLabel() }}</option>
                            @endforeach
                        </select>
                    </div>

                    @if (in_array($formaRecebimento, [\App\Enums\FormaPagamento::CartaoCredito->value, \App\Enums\FormaPagamento::CartaoDebito->value], true))
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Bandeira (opcional)</label>
                                <select wire:model="cartaoRecebimentoId" class="w-full text-sm border border-gray-300 dark:border-white/10 dark:bg-gray-900 dark:text-gray-100 rounded-lg px-3 py-2 mt-1 focus:outline-none focus:ring-2 focus:ring-primary-500">
                                    <option value="">Selecione...</option>
                                    @foreach ($this->cartoes as $cartao)
                                        <option value="{{ $cartao->id }}">{{ $cartao->cartao_bandeira }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Nº autorização (opcional)</label>
                                <input wire:model="numeroAutorizacaoRecebimento" type="text" class="w-full text-sm border border-gray-300 dark:border-white/10 dark:bg-gray-900 dark:text-gray-100 rounded-lg px-3 py-2 mt-1 focus:outline-none focus:ring-2 focus:ring-primary-500" />
                            </div>
                        </div>
                        <p class="text-xs text-gray-400 dark:text-gray-500">Só pra conciliar com a operadora — a nota fiscal desta venda, se emitida, já foi declarada sem depender desse recebimento.</p>
                    @endif

                    <button wire:click="confirmarRecebimento" type="button" class="w-full py-2 rounded-lg text-sm font-semibold bg-primary-600 text-white hover:bg-primary-700">
                        Confirmar recebimento
                    </button>
                </div>
            </div>
        </div>
    @endif
    </div>
</x-filament-panels::page>
