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
            </div>
        </div>
    </div>
</x-filament-panels::page>
