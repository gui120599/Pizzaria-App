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
                Editar Pedido <span class="text-teal-600">#{{ $pedido->id }}</span>
            </h1>
            <span class="px-2 py-0.5 rounded-full text-xs font-bold
                @if($pedido->pedido_status === 'CANCELADO') bg-red-100 text-red-700
                @elseif($pedido->pedido_status === 'FINALIZADO') bg-green-100 text-green-700
                @elseif(in_array($pedido->pedido_status, ['ABERTO','INICIADO'])) bg-yellow-100 text-yellow-700
                @else bg-blue-100 text-blue-700 @endif">
                {{ $pedido->pedido_status }}
            </span>
        </div>

        @if(session('success'))
            <div class="mb-4 px-4 py-2 bg-green-100 text-green-700 rounded-lg text-sm">
                {{ session('success') }}
            </div>
        @endif

        <div class="grid grid-cols-1 xl:grid-cols-5 gap-4 h-full">

            {{-- COLUNA ESQUERDA: Seletor de produtos --}}
            <div class="xl:col-span-3">
                @livewire('pedido-produto-selector', ['pedidoId' => $pedido->id, 'saveButtonLabel' => 'Salvar Alterações'], key('edit-pedido-'.$pedido->id))
            </div>

            {{-- COLUNA DIREITA: Dados do pedido --}}
            <div class="xl:col-span-2">
                <form id="pedido-form" action="{{ route('pedido.salvar_edicao', $pedido->id) }}" method="POST" class="space-y-4">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="pedido_usuario_garcom_id" value="{{ $pedido->pedido_usuario_garcom_id ?? auth()->id() }}">

                    {{-- Cliente --}}
                    <div class="bg-white shadow-sm rounded-xl p-4 space-y-3"
                         x-data="{
                             tel: '{{ addslashes($pedido->cliente?->cliente_celular ?? '') }}',
                             clienteId: {{ $pedido->pedido_cliente_id ?? 'null' }},
                             nome: '{{ addslashes($pedido->cliente?->cliente_nome ?? '') }}',
                             encontrado: {{ $pedido->pedido_cliente_id ? 'true' : 'false' }},
                             buscando: false,
                             async buscar() {
                                 if (this.tel.replace(/\D/g,'').length < 8) return;
                                 this.buscando = true;
                                 const r = await fetch('/cardapio/lookup-cliente?telefone=' + encodeURIComponent(this.tel));
                                 const d = await r.json();
                                 this.buscando = false;
                                 if (d.encontrado) { this.clienteId = d.cliente_id; this.nome = d.nome; this.encontrado = true; }
                                 else { this.clienteId = null; this.nome = ''; this.encontrado = false; }
                             },
                             limpar() { this.tel = ''; this.clienteId = null; this.nome = ''; this.encontrado = false; }
                         }">
                        <p class="flex items-center gap-2 text-sm font-bold text-teal-700">
                            <i class='bx bx-user'></i> Cliente
                        </p>
                        <input type="hidden" name="pedido_cliente_id" :value="clienteId">
                        <input type="hidden" name="cliente_celular_novo" :value="tel">
                        <input type="hidden" name="cliente_nome_novo" :value="nome">

                        <div>
                            <x-input-label value="Telefone" />
                            <div class="flex gap-2 mt-1">
                                <input type="tel" x-model="tel" @input.debounce.500ms="buscar()"
                                       placeholder="(00) 00000-0000"
                                       class="flex-1 border-gray-300 rounded-lg shadow-sm text-sm focus:ring-teal-500 focus:border-teal-500">
                                <button type="button" @click="limpar()" x-show="tel"
                                        class="px-3 py-2 bg-gray-100 hover:bg-red-50 text-gray-500 rounded-lg text-xs transition-colors">✕</button>
                            </div>
                        </div>

                        <div x-show="buscando" class="text-xs text-gray-400">Buscando...</div>

                        <div x-show="encontrado && !buscando" class="flex items-center gap-2 p-2 bg-green-50 border border-green-200 rounded-lg">
                            <i class='bx bx-user-check text-green-600'></i>
                            <span class="text-sm text-green-700 font-medium" x-text="nome"></span>
                        </div>

                        <div x-show="!encontrado && !buscando && tel.replace(/\D/g,'').length >= 8">
                            <x-input-label value="Nome do cliente (novo)" />
                            <input type="text" x-model="nome" placeholder="Nome completo"
                                   class="mt-1 w-full border-gray-300 rounded-lg shadow-sm text-sm focus:ring-teal-500 focus:border-teal-500">
                        </div>
                    </div>

                    {{-- Entrega --}}
                    @php
                        $opcaoEntregasJS = $opcoes_entregas->map(fn($o) => [
                            'id'          => $o->id,
                            'nome'        => $o->opcaoentrega_nome,
                            'valor_frete' => (float) $o->opcaoentrega_valor_frete,
                            'min_frete'   => (float) $o->opcaoentrega_min_valor_frete,
                        ])->values()->all();
                    @endphp
                    <div class="bg-white shadow-sm rounded-xl p-4 space-y-3"
                         x-data="{
                             opcaoId: {{ $pedido->pedido_opcaoentrega_id ?? 'null' }},
                             opcaoNome: '{{ addslashes(optional($pedido->opcaoEntrega)->opcaoentrega_nome ?? '') }}',
                             opcoes: @js($opcaoEntregasJS),
                             get requerEndereco() {
                                 return this.opcaoNome.toLowerCase().includes('entrega') || this.opcaoNome.toLowerCase().includes('deliver');
                             },
                             get opcaoAtual() { return this.opcoes.find(o => o.id == this.opcaoId) ?? null; },
                             get temFrete() { return this.opcaoAtual && this.opcaoAtual.valor_frete > 0; },
                             onOpcaoChange(e) {
                                 this.opcaoId = e.target.value ? parseInt(e.target.value) : null;
                                 this.opcaoNome = e.target.options[e.target.selectedIndex].text;
                             }
                         }">
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
                                    <option value="{{ $opcao->id }}"
                                        {{ $pedido->pedido_opcaoentrega_id == $opcao->id ? 'selected' : '' }}>
                                        {{ $opcao->opcaoentrega_nome }}
                                    </option>
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
                                      class="mt-1 w-full border-gray-300 rounded-lg shadow-sm text-sm focus:ring-teal-500 focus:border-teal-500"
                                      placeholder="Rua, número, bairro...">{{ $pedido->pedido_endereco_entrega }}</textarea>
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
                                    <option value="{{ $pag->opcaopag_nome }}"
                                        {{ $pedido->pedido_descricao_pagamento === $pag->opcaopag_nome ? 'selected' : '' }}>
                                        {{ $pag->opcaopag_nome }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <x-input-label for="pedido_observacao_pagamento" value="Observação do pagamento" />
                            <x-text-input id="pedido_observacao_pagamento" name="pedido_observacao_pagamento"
                                          type="text" class="mt-1 w-full text-sm"
                                          value="{{ $pedido->pedido_observacao_pagamento }}"
                                          placeholder="Ex: Troco para R$ 50,00" />
                        </div>
                    </div>

                    {{-- Totais atuais (somente leitura, referência) --}}
                    <div class="bg-gray-50 rounded-xl p-4 space-y-1.5 text-sm mb-24">
                        <div class="flex justify-between text-gray-500">
                            <span>Itens</span>
                            <span>R$ {{ number_format($pedido->pedido_valor_itens, 2, ',', '.') }}</span>
                        </div>
                        @if($pedido->pedido_valor_desconto > 0)
                            <div class="flex justify-between text-orange-500">
                                <span>Descontos</span>
                                <span>- R$ {{ number_format($pedido->pedido_valor_desconto, 2, ',', '.') }}</span>
                            </div>
                        @endif
                        @if(isset($pedido->pedido_valor_frete) && $pedido->pedido_valor_frete > 0)
                            <div class="flex justify-between text-yellow-600">
                                <span class="flex items-center gap-1"><i class='bx bx-cycling text-xs'></i> Taxa de entrega</span>
                                <span>+ R$ {{ number_format($pedido->pedido_valor_frete, 2, ',', '.') }}</span>
                            </div>
                        @endif
                        <div class="flex justify-between font-bold text-gray-800 text-base border-t border-gray-200 pt-1.5 mt-1.5">
                            <span>Total salvo</span>
                            <span>R$ {{ number_format($pedido->pedido_valor_total, 2, ',', '.') }}</span>
                        </div>
                        <p class="text-[10px] text-gray-400">Os totais são recalculados ao salvar</p>
                    </div>
                </form>
            </div>

        </div>
    </div>

    {{-- FAB: Cancelar Pedido (acima do FAB de salvar do PedidoProdutoSelector) --}}
    @if(!in_array($pedido->pedido_status, ['CANCELADO', 'FINALIZADO']))
        <div class="fixed bottom-24 inset-x-0 px-4 z-[39] flex justify-center pointer-events-none">
            <form action="{{ route('pedido.cancelar', $pedido->id) }}" method="POST"
                  x-data
                  @submit.prevent="if (confirm('Cancelar o pedido #{{ $pedido->id }}? Esta ação não pode ser desfeita.')) $el.submit()">
                @csrf
                <button type="submit"
                        class="pointer-events-auto py-2.5 px-5 bg-red-600 hover:bg-red-700 active:bg-red-800 rounded-2xl text-white font-bold flex items-center gap-2 shadow-xl transition-colors whitespace-nowrap">
                    <i class='bx bx-x-circle text-lg'></i>
                    <span class="text-sm uppercase tracking-widest">Cancelar Pedido</span>
                </button>
            </form>
        </div>
    @endif
</x-app-layout>
