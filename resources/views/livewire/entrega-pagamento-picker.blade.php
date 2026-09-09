<div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-white/10 p-4 space-y-3">
    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Entrega e pagamento</h3>

    <div>
        <label class="text-xs text-gray-500 dark:text-gray-400">Opção de entrega</label>
        <select wire:model.live="opcaoEntregaId"
            class="w-full mt-1 px-3 py-2 text-sm border border-gray-300 dark:border-white/10 rounded-lg bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-primary-500">
            <option value="">Selecione...</option>
            @foreach ($opcoesEntrega as $opcao)
                <option value="{{ $opcao->id }}" @selected($opcaoEntregaId == $opcao->id)>{{ $opcao->opcaoentrega_nome }}</option>
            @endforeach
        </select>
    </div>

    <div class="space-y-2">
        <div class="flex items-center justify-between">
            <label class="text-xs text-gray-500 dark:text-gray-400">Forma(s) de pagamento combinada(s)</label>
            <button type="button" wire:click="adicionarLinhaPagamento"
                class="text-xs font-medium text-primary-600 hover:text-primary-700 dark:text-primary-400">
                + Adicionar
            </button>
        </div>

        @foreach ($pagamentos as $indice => $linha)
            <div class="flex items-start gap-2">
                <select wire:change="$set('pagamentos.{{ $indice }}.opcaoPagamentoId', $event.target.value)"
                    class="flex-1 px-2 py-1.5 text-xs border border-gray-300 dark:border-white/10 rounded-lg bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-primary-500">
                    <option value="">Escolha...</option>
                    @foreach ($opcoesPagamento as $opcao)
                        <option value="{{ $opcao->id }}" @selected($linha['opcaoPagamentoId'] == $opcao->id)>{{ $opcao->opcaopag_nome }}</option>
                    @endforeach
                </select>

                <div class="w-24">
                    <input type="text" wire:model.live.debounce.500ms="pagamentos.{{ $indice }}.valor"
                        x-on:input="$el.value = window.maskMoney ? window.maskMoney($el.value) : $el.value"
                        class="w-full px-2 py-1.5 text-xs text-right border border-gray-300 dark:border-white/10 rounded-lg bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-primary-500">
                </div>

                @if ($this->linhaEhDinheiro($indice))
                    <div class="w-24">
                        <input type="text" wire:model.live.debounce.500ms="pagamentos.{{ $indice }}.trocoPara" placeholder="Troco p/"
                            x-on:input="$el.value = window.maskMoney ? window.maskMoney($el.value) : $el.value"
                            class="w-full px-2 py-1.5 text-xs text-right border border-gray-300 dark:border-white/10 rounded-lg bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-primary-500">
                    </div>
                @endif

                @if (count($pagamentos) > 1)
                    <button type="button" wire:click="removerLinhaPagamento({{ $indice }})"
                        class="text-gray-400 hover:text-red-500 mt-1.5">
                        <x-heroicon-o-x-mark class="h-4 w-4" />
                    </button>
                @endif
            </div>
        @endforeach
    </div>
</div>
