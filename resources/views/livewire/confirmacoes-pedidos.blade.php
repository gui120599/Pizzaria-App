<div wire:poll.15000ms x-data @novo-pedido.window="playNotification()">

    {{-- ══════════════════════════════════════════════════ --}}
    {{-- MODO EDIÇÃO: tela de seleção de produtos          --}}
    {{-- ══════════════════════════════════════════════════ --}}
    @if($pedidoEditandoId)

        <div>
            {{-- Cabeçalho da edição --}}
            <div class="flex items-center gap-3 mb-4 flex-wrap">
                <button wire:click="fecharEdicao"
                        class="flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-800 transition font-medium">
                    <i class='bx bx-arrow-back'></i> Voltar às confirmações
                </button>
                <span class="text-gray-300">|</span>
                <i class='bx bx-edit-alt text-teal-600'></i>
                <h2 class="text-lg font-bold text-gray-800">
                    Editando Pedido <span class="text-teal-600">#{{ $pedidoEditandoId }}</span>
                </h2>
                <span class="px-2 py-0.5 rounded-full text-xs font-bold bg-yellow-100 text-yellow-700 animate-pulse">
                    PENDENTE
                </span>
            </div>

            {{-- Grid: seletor (esq) + entrega/pagamento (dir) --}}
            <div class="grid grid-cols-1 xl:grid-cols-5 gap-4">

                {{-- Coluna esquerda: seletor de produtos --}}
                <div class="xl:col-span-3">
                    @livewire('pedido-produto-selector', [
                        'pedidoId'        => $pedidoEditandoId,
                        'saveButtonLabel' => 'Salvar Alterações',
                    ], key('conf-edit-'.$pedidoEditandoId))
                </div>

                {{-- Coluna direita: entrega + pagamento --}}
                <div class="xl:col-span-2">
                    <form id="pedido-form" wire:submit.prevent="salvarAlteracoes" class="space-y-4">
                        @csrf

                        {{-- Card: Entrega --}}
                        @php
                            $opcaoAtual = $opcoesEntregas->firstWhere('id', $editOpcaoEntregaId);
                            $nomeAtual  = $opcaoAtual?->opcaoentrega_nome ?? '';
                        @endphp
                        <div class="bg-white shadow-sm rounded-xl p-4 space-y-3"
                             x-data="{
                                 opcaoNome: @js($nomeAtual),
                                 get requerEndereco() {
                                     const n = this.opcaoNome.toLowerCase();
                                     return n.includes('entrega') || n.includes('deliver');
                                 }
                             }">
                            <p class="flex items-center gap-2 text-sm font-bold text-teal-700">
                                <i class='bx bx-map-pin'></i> Entrega
                            </p>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Opção de entrega</label>
                                <select wire:model="editOpcaoEntregaId"
                                        @change="opcaoNome = $event.target.options[$event.target.selectedIndex].text"
                                        class="w-full border-gray-300 rounded-lg shadow-sm text-sm focus:ring-teal-500 focus:border-teal-500">
                                    <option value="">— Selecione —</option>
                                    @foreach($opcoesEntregas as $opcao)
                                        <option value="{{ $opcao->id }}" @selected($editOpcaoEntregaId == $opcao->id)>
                                            {{ $opcao->opcaoentrega_nome }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div x-show="requerEndereco" x-cloak>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Endereço de entrega</label>
                                <textarea wire:model="editEndereco" rows="2"
                                          placeholder="Rua, número, bairro..."
                                          class="w-full border-gray-300 rounded-lg shadow-sm text-sm focus:ring-teal-500 focus:border-teal-500"></textarea>
                            </div>
                        </div>

                        {{-- Card: Pagamento --}}
                        <div class="bg-white shadow-sm rounded-xl p-4 space-y-3">
                            <p class="flex items-center gap-2 text-sm font-bold text-teal-700">
                                <i class='bx bx-credit-card'></i> Pagamento
                            </p>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Forma de pagamento</label>
                                <select wire:model="editPagamentoNome"
                                        class="w-full border-gray-300 rounded-lg shadow-sm text-sm focus:ring-teal-500 focus:border-teal-500">
                                    <option value="">— Selecione —</option>
                                    @foreach($opcoesPagamento as $pag)
                                        <option value="{{ $pag->opcaopag_nome }}" @selected($editPagamentoNome === $pag->opcaopag_nome)>
                                            {{ $pag->opcaopag_nome }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Observação (ex: troco)</label>
                                <input type="text" wire:model="editObsPagamento"
                                       placeholder="Ex: Troco para R$ 50,00"
                                       class="w-full border-gray-300 rounded-lg shadow-sm text-sm focus:ring-teal-500 focus:border-teal-500">
                            </div>
                        </div>

                        <div class="pb-24"></div>
                    </form>
                </div>

            </div>
        </div>

    {{-- ══════════════════════════════════════════════════ --}}
    {{-- MODO NORMAL: lista de confirmações                --}}
    {{-- ══════════════════════════════════════════════════ --}}
    @else

        {{-- Cabeçalho --}}
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center gap-3">
                <h1 class="text-xl font-bold text-gray-800">Pedidos Online Pendentes</h1>
                @if($pedidos->count() > 0)
                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-red-500 text-white text-sm font-bold animate-pulse">
                        {{ $pedidos->count() }}
                    </span>
                @endif
            </div>
            <div class="flex items-center gap-2 text-gray-400 text-xs">
                <i class='bx bx-refresh animate-spin' wire:loading></i>
                <span>Atualiza a cada 15s</span>
            </div>
        </div>

        {{-- Estado vazio --}}
        @if($pedidos->isEmpty())
            <div class="flex flex-col items-center justify-center py-24 text-gray-400">
                <i class='bx bx-check-circle text-6xl text-green-400 mb-4'></i>
                <p class="text-lg font-semibold">Nenhum pedido pendente</p>
                <p class="text-sm mt-1">Novos pedidos do cardápio aparecerão aqui automaticamente</p>
            </div>
        @else
            {{-- Grid de cards --}}
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                @foreach($pedidos as $pedido)
                    @php
                        $minutos  = (int) $pedido->created_at->diffInMinutes(now());
                        $isNovo   = $minutos < 5;
                        $urgente  = $minutos >= 15;
                        $headerBg = $urgente ? 'bg-red-600' : ($isNovo ? 'bg-green-600' : 'bg-gray-700');
                    @endphp

                    <div wire:key="{{ $pedido->id }}" class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden flex flex-col">

                        {{-- Header do card --}}
                        <div class="{{ $headerBg }} px-4 py-3 flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="text-white font-bold text-lg">#{{ $pedido->id }}</span>
                                @if($isNovo)
                                    <span class="bg-yellow-400 text-gray-900 text-[10px] font-bold px-2 py-0.5 rounded-full uppercase tracking-wide animate-pulse">
                                        Novo
                                    </span>
                                @endif
                            </div>
                            <div class="flex items-center gap-1.5 text-white/80 text-sm">
                                <i class='bx bx-time-five'></i>
                                <span>
                                    @if($minutos < 1) agora
                                    @elseif($minutos < 60) há {{ $minutos }}min
                                    @else há {{ floor($minutos/60) }}h{{ $minutos % 60 > 0 ? ($minutos % 60).'min' : '' }}
                                    @endif
                                </span>
                                <span class="text-white/50 mx-1">·</span>
                                <span>{{ $pedido->created_at->format('H:i') }}</span>
                            </div>
                        </div>

                        <div class="flex-1 p-4 space-y-3">

                            {{-- Cliente --}}
                            <div class="flex items-start gap-2">
                                <i class='bx bx-user text-gray-400 text-lg mt-0.5'></i>
                                <div>
                                    <p class="font-semibold text-gray-800 leading-tight">
                                        {{ $pedido->cliente?->cliente_nome ?? 'Cliente não identificado' }}
                                    </p>
                                    @if($pedido->cliente?->cliente_celular)
                                        <p class="text-gray-500 text-sm">{{ $pedido->cliente->cliente_celular }}</p>
                                    @endif
                                </div>
                            </div>

                            {{-- Entrega --}}
                            <div class="flex items-start gap-2">
                                <i class='bx bx-map text-gray-400 text-lg mt-0.5'></i>
                                <div>
                                    <p class="text-gray-700 text-sm font-medium">
                                        {{ $pedido->opcaoEntrega?->opcaoentrega_nome ?? '—' }}
                                    </p>
                                    @if($pedido->pedido_endereco_entrega)
                                        <p class="text-gray-500 text-sm">{{ $pedido->pedido_endereco_entrega }}</p>
                                    @endif
                                </div>
                            </div>

                            {{-- Itens --}}
                            <div class="border-t border-gray-100 pt-3">
                                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">Itens do pedido</p>
                                <div class="space-y-2">
                                    @foreach($pedido->item_pedido_pedido_id as $item)
                                        @php
                                            $qtd = (float) $item->item_pedido_quantidade;
                                            $qtdFormatada = match(true) {
                                                abs($qtd - 0.5) < 0.01 => '½',
                                                abs($qtd - 1/3) < 0.01 => '⅓',
                                                abs($qtd - 2/3) < 0.01 => '⅔',
                                                $qtd == (int)$qtd       => (string)(int)$qtd,
                                                default                 => number_format($qtd, 2, ',', ''),
                                            };
                                            $nomeProduto = $item->produto?->produto_descricao ?? '—';
                                            $obs         = $item->item_pedido_observacao;
                                            $mostrarObs  = $obs && $obs !== $nomeProduto;
                                        @endphp
                                        <div class="flex items-start justify-between gap-2">
                                            <div class="flex-1 min-w-0">
                                                {{-- Categoria --}}
                                                @if($item->produto?->categoria)
                                                    <span class="inline-block px-1.5 py-0.5 bg-orange-50 text-orange-600 border border-orange-200 rounded text-[10px] font-semibold leading-tight mb-0.5">
                                                        {{ $item->produto->categoria->categoria_nome }}
                                                    </span>
                                                @endif
                                                {{-- Quantidade + nome --}}
                                                <span class="flex items-center gap-1 text-sm text-gray-800">
                                                    <span class="font-semibold text-green-600">{{ $qtdFormatada }}×</span>
                                                    <span class="truncate">{{ $nomeProduto }}</span>
                                                </span>
                                                @if($mostrarObs)
                                                    <p class="text-gray-400 text-xs truncate ml-5">{{ $obs }}</p>
                                                @endif
                                            </div>
                                            <span class="text-gray-600 text-sm font-medium shrink-0">
                                                R$ {{ number_format($item->item_pedido_valor, 2, ',', '.') }}
                                            </span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            {{-- Totais --}}
                            <div class="border-t border-gray-100 pt-3 space-y-1">
                                @if($pedido->pedido_valor_desconto > 0)
                                    <div class="flex justify-between text-sm text-gray-400">
                                        <span>Subtotal</span>
                                        <span>R$ {{ number_format($pedido->pedido_valor_itens, 2, ',', '.') }}</span>
                                    </div>
                                    <div class="flex justify-between text-sm text-orange-500 font-medium">
                                        <span class="flex items-center gap-1"><i class='bx bxs-purchase-tag text-sm'></i> Desconto</span>
                                        <span>- R$ {{ number_format($pedido->pedido_valor_desconto, 2, ',', '.') }}</span>
                                    </div>
                                @endif
                                @if(isset($pedido->pedido_valor_frete) && $pedido->pedido_valor_frete > 0)
                                    <div class="flex justify-between text-sm text-yellow-600 font-medium">
                                        <span class="flex items-center gap-1"><i class='bx bx-cycling text-sm'></i> Taxa de entrega</span>
                                        <span>+ R$ {{ number_format($pedido->pedido_valor_frete, 2, ',', '.') }}</span>
                                    </div>
                                @endif
                                <div class="flex justify-between font-bold text-base text-gray-900">
                                    <span>Total</span>
                                    <span>R$ {{ number_format($pedido->pedido_valor_total, 2, ',', '.') }}</span>
                                </div>
                            </div>

                            {{-- Pagamento --}}
                            <div class="flex items-center gap-2 bg-gray-50 rounded-lg px-3 py-2">
                                <i class='bx bx-credit-card text-gray-400'></i>
                                <div class="text-sm">
                                    <span class="text-gray-700 font-medium">{{ $pedido->pedido_descricao_pagamento ?? '—' }}</span>
                                    @if($pedido->pedido_observacao_pagamento)
                                        <span class="text-gray-400 ml-1">· {{ $pedido->pedido_observacao_pagamento }}</span>
                                    @endif
                                </div>
                            </div>

                            {{-- Link de acompanhamento --}}
                            <a href="{{ route('pedido.acompanhar', $pedido->id) }}" target="_blank"
                               class="flex items-center justify-center gap-1.5 text-xs text-teal-600 hover:text-teal-800 transition-colors py-1">
                                <i class='bx bx-link-external'></i>
                                Link de acompanhamento do cliente
                            </a>

                        </div>

                        {{-- Ações --}}
                        <div class="px-4 pb-4 pt-2 space-y-2 border-t border-gray-100">

                            {{-- Editar --}}
                            <button wire:click="abrirEdicao({{ $pedido->id }})"
                                    class="w-full py-2 bg-gray-100 hover:bg-gray-200 active:scale-95 text-gray-600 font-semibold rounded-lg text-sm flex items-center justify-center gap-1.5 transition-all">
                                <i class='bx bx-edit-alt text-base'></i> Editar pedido
                            </button>

                            {{-- Confirmar / Cancelar --}}
                            <div class="flex gap-2">
                                <button wire:click="confirmar({{ $pedido->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="confirmar({{ $pedido->id }})"
                                        class="flex-1 py-2.5 bg-green-500 hover:bg-green-600 active:scale-95 text-white font-bold rounded-lg text-sm flex items-center justify-center gap-1.5 transition-all">
                                    <i class='bx bx-check-circle text-base'></i>
                                    <span wire:loading.remove wire:target="confirmar({{ $pedido->id }})">Confirmar</span>
                                    <span wire:loading wire:target="confirmar({{ $pedido->id }})">Confirmando…</span>
                                </button>
                                <button wire:click="cancelar({{ $pedido->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="cancelar({{ $pedido->id }})"
                                        wire:confirm="Cancelar o pedido #{{ $pedido->id }}? Esta ação não pode ser desfeita."
                                        class="flex-1 py-2.5 bg-red-100 hover:bg-red-200 active:scale-95 text-red-600 font-bold rounded-lg text-sm flex items-center justify-center gap-1.5 transition-all">
                                    <i class='bx bx-x-circle text-base'></i>
                                    <span wire:loading.remove wire:target="cancelar({{ $pedido->id }})">Cancelar</span>
                                    <span wire:loading wire:target="cancelar({{ $pedido->id }})">Cancelando…</span>
                                </button>
                            </div>
                        </div>

                    </div>
                @endforeach
            </div>
        @endif

    @endif

</div>

<script>
    function playNotification() {
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            [0, 150, 300].forEach((delay, i) => {
                const osc  = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.frequency.value = i === 0 ? 880 : (i === 1 ? 1100 : 880);
                gain.gain.setValueAtTime(0.3, ctx.currentTime + delay / 1000);
                gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + delay / 1000 + 0.2);
                osc.start(ctx.currentTime + delay / 1000);
                osc.stop(ctx.currentTime + delay / 1000 + 0.2);
            });
        } catch (_) {}
    }
</script>
