<x-app-layout>
    <div class="py-2 px-2 sm:px-4 h-full">

        {{-- Cabeçalho --}}
        <div class="flex items-center gap-3 mb-4">
            <a href="{{ route('pedidos') }}"
               class="flex items-center gap-1 text-sm text-gray-500 hover:text-teal-600 transition">
                <i class='bx bx-arrow-back'></i> Voltar
            </a>
            <span class="text-gray-300">|</span>
            <h1 class="text-lg font-bold text-gray-800">
                Novo Pedido
            </h1>
            <span class="px-2 py-0.5 rounded-full text-xs font-bold bg-yellow-100 text-yellow-700">
                RASCUNHO
            </span>
        </div>

        @if(session('success'))
            <div class="mb-4 px-4 py-2 bg-green-100 text-green-700 rounded-lg text-sm">
                {{ session('success') }}
            </div>
        @endif
        @if(session('error'))
            <div class="mb-4 px-4 py-2 bg-red-100 text-red-700 rounded-lg text-sm font-medium">
                <i class='bx bx-error-circle mr-1'></i> {{ session('error') }}
            </div>
        @endif

        <div class="grid grid-cols-1 xl:grid-cols-5 gap-4 h-full">

            {{-- COLUNA ESQUERDA: Seletor de produtos --}}
            <div class="xl:col-span-3">
                @livewire('pedido-produto-selector', ['pedidoId' => $pedido->id, 'saveButtonLabel' => 'Abrir Pedido'], key('create-pedido-'.$pedido->id))
            </div>

            {{-- COLUNA DIREITA: Dados do pedido --}}
            <div class="xl:col-span-2">
                <form id="pedido-form" action="{{ route('pedido.store') }}" method="POST" class="space-y-4">
                    @csrf
                    <input type="hidden" name="pedido_id" value="{{ $pedido->id }}">
                    <input type="hidden" name="pedido_usuario_garcom_id" value="{{ auth()->id() }}">

                    {{-- Cliente --}}
                    @include('app.pedido.partials.cliente-form', ['pedido' => $pedido])

                    {{-- Entrega (com frete reativo) --}}
                    @php
                        $opcaoEntregasCreateJS = $opcoes_entregas->map(fn($o) => [
                            'id'          => $o->id,
                            'nome'        => $o->opcaoentrega_nome,
                            'valor_frete' => (float) $o->opcaoentrega_valor_frete,
                            'min_frete'   => (float) $o->opcaoentrega_min_valor_frete,
                        ])->values()->all();
                    @endphp
                    <div class="bg-white shadow-sm rounded-xl p-4 space-y-3"
                         x-data="{
                             opcaoId: null,
                             opcaoNome: '',
                             opcoes: @js($opcaoEntregasCreateJS),
                             endereco: '',
                             totalItens: 0,
                             totalDesconto: 0,
                             get requerEndereco() {
                                 return this.opcaoNome.toLowerCase().includes('entrega') || this.opcaoNome.toLowerCase().includes('deliver');
                             },
                             get opcaoAtual() { return this.opcoes.find(o => o.id == this.opcaoId) ?? null; },
                             get temFrete()    { return this.opcaoAtual && this.opcaoAtual.valor_frete > 0; },
                             get valorFrete() {
                                 if (!this.opcaoAtual || !this.opcaoAtual.valor_frete) return 0;
                                 const liq = this.totalItens - this.totalDesconto;
                                 if (this.opcaoAtual.min_frete > 0 && liq >= this.opcaoAtual.min_frete) return 0;
                                 return this.opcaoAtual.valor_frete;
                             },
                             get totalFinal() { return Math.max(0, this.totalItens - this.totalDesconto + this.valorFrete); },
                             onOpcaoChange(e) {
                                 this.opcaoId   = e.target.value ? parseInt(e.target.value) : null;
                                 this.opcaoNome = e.target.options[e.target.selectedIndex].text;
                             },
                             onItensAtualizar(itens) {
                                 // item.valor é líquido (já abatido o desconto); totalItens exibe o bruto
                                 this.totalDesconto = itens.reduce((s, i) => s + (parseFloat(i.desconto) || 0), 0);
                                 this.totalItens    = itens.reduce((s, i) => s + (parseFloat(i.valor) || 0) + (parseFloat(i.desconto) || 0), 0);
                             }
                         }"
                         @itens-pedido-atualizados.window="onItensAtualizar($event.detail.itens)"
                         @endereco-cliente.window="endereco = $event.detail.endereco">

                        <p class="flex items-center gap-2 text-sm font-bold text-teal-700">
                            <i class='bx bx-map-pin'></i> Entrega
                        </p>
                        <div>
                            <x-input-label for="pedido_opcaoentrega_id" value="Opção de entrega" />
                            <select name="pedido_opcaoentrega_id" id="pedido_opcaoentrega_id" required
                                    @change="onOpcaoChange($event)"
                                    class="mt-1 w-full border-gray-300 rounded-lg shadow-sm text-sm focus:ring-teal-500 focus:border-teal-500">
                                <option value="">— Selecione —</option>
                                @foreach($opcoes_entregas as $opcao)
                                    <option value="{{ $opcao->id }}">{{ $opcao->opcaoentrega_nome }}</option>
                                @endforeach
                            </select>
                        </div>

                        {{-- Aviso de taxa de entrega --}}
                        <div x-show="temFrete" style="display:none"
                             class="flex items-start gap-2 text-xs text-yellow-700 bg-yellow-50 border border-yellow-200 rounded-lg px-3 py-2">
                            <i class='bx bx-info-circle text-sm mt-0.5 shrink-0'></i>
                            <span>Taxa de entrega de
                                <strong x-text="'R$ ' + opcaoAtual?.valor_frete.toFixed(2).replace('.',',')"></strong>
                                para pedidos abaixo de
                                <strong x-text="'R$ ' + opcaoAtual?.min_frete.toFixed(2).replace('.',',')"></strong>.
                                O valor será calculado ao salvar.
                            </span>
                        </div>

                        <div x-show="requerEndereco" x-cloak>
                            <x-input-label for="pedido_endereco_entrega" value="Endereço de entrega" />
                            <textarea name="pedido_endereco_entrega" id="pedido_endereco_entrega" rows="2"
                                      x-model="endereco"
                                      class="mt-1 w-full border-gray-300 rounded-lg shadow-sm text-sm focus:ring-teal-500 focus:border-teal-500"
                                      placeholder="Rua, número, bairro..."></textarea>
                        </div>

                        {{-- Totais reativos --}}
                        <div class="border-t border-gray-100 pt-3 space-y-1.5 text-sm">
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">Resumo do pedido</p>
                            <div class="flex justify-between text-gray-500">
                                <span>Itens</span>
                                <span x-text="'R$ ' + totalItens.toFixed(2).replace('.',',')">R$ 0,00</span>
                            </div>
                            <div x-show="totalDesconto > 0" class="flex justify-between text-orange-500 font-medium" style="display:none">
                                <span class="flex items-center gap-1"><i class='bx bxs-purchase-tag text-xs'></i> Descontos</span>
                                <span x-text="'- R$ ' + totalDesconto.toFixed(2).replace('.',',')"></span>
                            </div>
                            <div x-show="valorFrete > 0" class="flex justify-between text-yellow-600 font-medium" style="display:none">
                                <span class="flex items-center gap-1"><i class='bx bx-cycling text-xs'></i> Taxa de entrega</span>
                                <span x-text="'+ R$ ' + valorFrete.toFixed(2).replace('.',',')"></span>
                            </div>
                            <div class="flex justify-between font-bold text-gray-800 text-base border-t border-gray-100 pt-1.5 mt-0.5">
                                <span>Total</span>
                                <span class="text-teal-700" x-text="'R$ ' + totalFinal.toFixed(2).replace('.',',')">R$ 0,00</span>
                            </div>
                            <p class="text-[10px] text-gray-400">Os totais são recalculados ao salvar</p>
                        </div>

                    </div>

                    {{-- Pagamento --}}
                    <div class="bg-white shadow-sm rounded-xl p-4 space-y-3">
                        <p class="flex items-center gap-2 text-sm font-bold text-teal-700">
                            <i class='bx bx-credit-card'></i> Pagamento
                        </p>
                        <div>
                            <x-input-label for="pedido_descricao_pagamento" value="Forma de pagamento" />
                            <select name="pedido_descricao_pagamento" id="pedido_descricao_pagamento"
                                    class="mt-1 w-full border-gray-300 rounded-lg shadow-sm text-sm focus:ring-teal-500 focus:border-teal-500">
                                <option value="">— Selecione —</option>
                                @foreach($opcoes_pagamento as $pag)
                                    <option value="{{ $pag->opcaopag_nome }}">{{ $pag->opcaopag_nome }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <x-input-label for="pedido_observacao_pagamento" value="Observação do pagamento" />
                            <x-text-input id="pedido_observacao_pagamento" name="pedido_observacao_pagamento"
                                          type="text" class="mt-1 w-full text-sm"
                                          placeholder="Ex: Troco para R$ 50,00" />
                        </div>
                    </div>

                    <div class="pb-24"></div>
                </form>
            </div>

        </div>
    </div>
</x-app-layout>
