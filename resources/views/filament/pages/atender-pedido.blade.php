<x-filament-panels::page>
    @if ($somenteLeitura)
        <div class="flex items-start gap-2 px-4 py-3 mb-4 bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/30 rounded-xl text-amber-700 dark:text-amber-400 text-sm">
            <x-heroicon-o-lock-closed class="h-5 w-5 shrink-0" />
            <span>Este pedido já está em <strong>{{ $statusSelecionado }}</strong> e não pode mais ter conteúdo editado. Use a tela de origem (OperarVenda/cancelamento) para outras ações.</span>
        </div>
    @endif

    <div class="grid grid-cols-1 xl:grid-cols-5 gap-4" @if ($somenteLeitura) style="opacity: .6; pointer-events: none;" @endif>
        <div class="xl:col-span-3">
            @livewire('pedido-produto-selector', ['pedidoId' => $pedidoId, 'itensIniciais' => $itensCarrinho, 'carrinhoTopo' => true], key('selector-'.($pedidoId ?? 'novo')))
        </div>

        <div class="xl:col-span-2 space-y-4">
            {{-- Carrinho — espelha o AttendOrder do razelfood: card próprio da
                 página, acima do Cliente. As ações (+/-, editar, remover)
                 repassam pro PedidoProdutoSelector via evento (ver
                 incrementarQtd/decrementarQtd/removerItem/abrirEditModal
                 nesta classe), que continua sendo o dono do estado/regra de
                 negócio dos itens. --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-white/10 p-4">
                <h3 class="mb-3 flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    <i class='bx bx-cart text-teal-600'></i>
                    Carrinho
                    <span class="font-normal normal-case text-gray-400 dark:text-gray-500">({{ count($itensCarrinho) }})</span>
                </h3>

                @if (empty($itensCarrinho))
                    <p class="py-6 text-center text-sm text-gray-400 dark:text-gray-500">Nenhum item adicionado ainda.</p>
                @else
                    <div class="divide-y divide-gray-100 dark:divide-white/10 max-h-96 overflow-y-auto">
                        @foreach ($itensCarrinho as $item)
                            @include('livewire.partials.pedido-item-linha', ['item' => $item])
                        @endforeach
                    </div>

                    @php $descontoItensCarrinho = collect($itensCarrinho)->sum('desconto'); @endphp
                    @if ($descontoItensCarrinho > 0)
                        <p class="pt-3 text-xs font-medium text-green-600 dark:text-green-400">Desconto nos itens: −R$ {{ number_format($descontoItensCarrinho, 2, ',', '.') }}</p>
                    @endif
                @endif
            </div>

            @livewire('cliente-picker', ['inicial' => $clienteData], key('cliente-'.($pedidoId ?? 'novo')))

            @livewire('entrega-pagamento-picker', ['inicial' => $entregaPagamentoData, 'total' => $this->totalPreview], key('entrega-'.($pedidoId ?? 'novo')))

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-white/10 p-4 space-y-2">
                <label class="text-xs text-gray-500 dark:text-gray-400">Observação</label>
                <textarea wire:model.blur="observacao" rows="2" placeholder="Alguma observação sobre o pedido..."
                    class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-white/10 rounded-lg bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-200 placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-primary-500"></textarea>
            </div>

            {{-- Resumo financeiro --}}
            @php $resumo = $this->resumoTotais; @endphp
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-white/10 p-4 space-y-3">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Resumo financeiro</h3>

                <dl class="space-y-1.5 text-sm">
                    <div class="flex justify-between text-gray-600 dark:text-gray-300">
                        <dt>Itens</dt>
                        <dd>R$ {{ number_format($resumo['itens'], 2, ',', '.') }}</dd>
                    </div>
                    @if ($resumo['desconto'] > 0)
                        <div class="flex justify-between text-green-600 dark:text-green-400">
                            <dt>Desconto</dt>
                            <dd>− R$ {{ number_format($resumo['desconto'], 2, ',', '.') }}</dd>
                        </div>
                    @endif
                    <div class="flex justify-between text-gray-600 dark:text-gray-300">
                        <dt>Taxa de entrega</dt>
                        <dd>{{ $resumo['frete'] > 0 ? 'R$ '.number_format($resumo['frete'], 2, ',', '.') : 'Grátis' }}</dd>
                    </div>
                    <div class="flex justify-between pt-1.5 mt-1 border-t border-gray-100 dark:border-white/10 text-base font-bold text-gray-800 dark:text-gray-100">
                        <dt>Total</dt>
                        <dd class="text-primary-600 dark:text-primary-400">R$ {{ number_format($resumo['total'], 2, ',', '.') }}</dd>
                    </div>
                </dl>

                <button type="button" wire:click="save" wire:loading.attr="disabled" @if ($somenteLeitura) disabled @endif
                    class="w-full rounded-lg bg-primary-600 py-2.5 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-50">
                    {{ $pedidoId ? 'Salvar alterações' : 'Criar pedido' }}
                </button>

                @if ($this->podeCobrarStone)
                    <button type="button" wire:click="abrirModalStone"
                        class="w-full rounded-lg border border-primary-300 dark:border-primary-500/30 py-2.5 text-sm font-semibold text-primary-600 dark:text-primary-400 hover:bg-primary-50 dark:hover:bg-primary-500/10">
                        Cobrar na maquininha
                    </button>
                @endif
            </div>
        </div>
    </div>

    {{-- Modal Cobrança na maquininha Stone --}}
    @if ($modalStoneAberta)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl w-full max-w-sm">
                <div class="px-4 py-3 border-b border-gray-100 dark:border-white/10">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Cobrança na maquininha</h3>
                </div>
                <div class="p-4 space-y-3">
                    @if ($stoneStatusModal === 'form')
                        <div>
                            <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Maquininha</label>
                            <select wire:model="stoneMaquininhaId" class="w-full text-sm border border-gray-300 dark:border-white/10 dark:bg-gray-900 dark:text-gray-100 rounded-lg px-3 py-2 mt-1 focus:outline-none focus:ring-2 focus:ring-primary-500">
                                <option value="">Selecione a maquininha...</option>
                                @foreach ($this->maquininhasStone as $id => $nome)
                                    <option value="{{ $id }}">{{ $nome }}</option>
                                @endforeach
                            </select>
                            @error('stoneMaquininhaId')
                                <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Forma de pagamento (opcional)</label>
                            <select wire:model="stoneOpcaoPagamentoId" class="w-full text-sm border border-gray-300 dark:border-white/10 dark:bg-gray-900 dark:text-gray-100 rounded-lg px-3 py-2 mt-1 focus:outline-none focus:ring-2 focus:ring-primary-500">
                                <option value="">Escolher na própria maquininha</option>
                                @foreach ($this->opcoesPagamentoStone as $id => $nome)
                                    <option value="{{ $id }}">{{ $nome }}</option>
                                @endforeach
                            </select>
                            <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Com uma forma escolhida, a maquininha já abre direto na tela daquele tipo (crédito/débito/PIX).</p>
                        </div>

                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            Valor: <strong class="text-gray-700 dark:text-gray-200">R$ {{ number_format($this->totalPreview, 2, ',', '.') }}</strong>
                        </p>

                        <div class="flex gap-2">
                            <button wire:click="enviarCobrancaStone" wire:loading.attr="disabled" type="button" class="flex-1 py-2 rounded-lg text-sm font-semibold bg-primary-600 text-white hover:bg-primary-700 disabled:opacity-50">
                                Enviar cobrança
                            </button>
                            <button wire:click="fecharModalStone" type="button" class="px-4 py-2 rounded-lg text-sm font-semibold border border-gray-300 dark:border-white/10 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5">
                                Cancelar
                            </button>
                        </div>
                    @elseif ($stoneStatusModal === 'aguardando')
                        <div wire:poll.3s="verificarStatusStone" class="flex flex-col items-center gap-3 py-4 text-center">
                            <svg class="animate-spin h-6 w-6 text-primary-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            <p class="text-sm text-gray-600 dark:text-gray-300">Aguardando o pagamento na maquininha…</p>
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
</x-filament-panels::page>
