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
                    @include('app.pedido.partials.cliente-form', ['pedido' => $pedido])

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
            <button type="button" x-data
                    x-on:click="$dispatch('open-modal', 'cancelar-pedido-{{ $pedido->id }}')"
                    class="pointer-events-auto py-2.5 px-5 bg-red-600 hover:bg-red-700 active:bg-red-800 rounded-2xl text-white font-bold flex items-center gap-2 shadow-xl transition-colors whitespace-nowrap">
                <i class='bx bx-x-circle text-lg'></i>
                <span class="text-sm uppercase tracking-widest">Cancelar Pedido</span>
            </button>
        </div>

        {{-- z-[70] para ficar acima do selector (FAB z-40, painéis z-50, modais z-[60]). --}}
        <div x-data="{ open: false }"
            x-on:open-modal.window="$event.detail === 'cancelar-pedido-{{ $pedido->id }}' && (open = true)"
            x-on:close-modal.window="$event.detail === 'cancelar-pedido-{{ $pedido->id }}' && (open = false)"
            x-on:keydown.escape.window="open = false"
            x-show="open" style="display: none;"
            class="fixed inset-0 z-[70] flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-gray-500/75" x-on:click="open = false"></div>
            <div class="relative w-full max-w-md bg-white rounded-lg shadow-xl" x-show="open" x-transition>
                <form action="{{ route('pedido.cancelar', $pedido->id) }}" method="POST" class="p-6">
                    @csrf
                    <h2 class="text-lg font-medium text-gray-900">
                        {{ __('Cancelar pedido #' . $pedido->id) }}
                    </h2>
                    <p class="mt-1 text-sm text-gray-600">
                        {{ __('Esta ação não pode ser desfeita. Selecione o motivo do cancelamento.') }}
                    </p>

                    <div class="mt-4">
                        <x-input-label for="pedido_motivo_cancelamento_{{ $pedido->id }}" :value="__('Motivo')" />
                        <select id="pedido_motivo_cancelamento_{{ $pedido->id }}" name="pedido_motivo_cancelamento" required
                            class="mt-1 block w-full border-gray-300 focus:border-red-500 focus:ring-red-500 rounded-md shadow-sm">
                            <option value="" disabled selected>{{ __('Selecione...') }}</option>
                            @foreach (\App\Enums\MotivoCancelamentoEnum::paraSelect() as $valor => $rotulo)
                                <option value="{{ $valor }}">{{ $rotulo }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="mt-6 flex justify-end gap-x-3">
                        <x-secondary-button type="button" x-on:click="open = false">
                            {{ __('Voltar') }}
                        </x-secondary-button>
                        <x-danger-button>{{ __('Confirmar cancelamento') }}</x-danger-button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</x-app-layout>
