<x-app-layout>
    <style>
        body { scrollbar-width: thin; scrollbar-color: #888 #f1f1f1; }
        ::-webkit-scrollbar { width: 12px; }
        ::-webkit-scrollbar-thumb { background-color: #888; border-radius: 10px; }
        ::-webkit-scrollbar-track { background-color: #f1f1f1; border-radius: 10px; }
        .nav-link.active { color: rgb(20 184 166); border-bottom-color: rgb(20 184 166); font-weight: bold; }
        [x-cloak] { display: none !important; }
    </style>
    {{-- Container de toasts --}}
    <div id="toast-container" class="fixed top-4 right-4 z-[100] flex flex-col gap-2 pointer-events-none"></div>

    <div class="mx-auto p-2" x-data>
        <form action="{{ route('venda.store') }}" method="post" id="formVenda" class="w-full">
            @csrf
            <div class="relative p-2 bg-white shadow-sm rounded-lg grid grid-cols-1 lg:grid-cols-8 gap-2 items-start">
                <!-- Overlay de carregamento -->
                <div id="carregando"
                    class="hidden absolute inset-0 z-10 flex justify-center items-center bg-slate-600/50 rounded-lg transition duration-150 ease-in-out">
                    <div class="text-center text-white">
                        <i class='bx bx-loader-circle bx-spin bx-rotate-90 text-5xl'></i>
                        <p class="mt-2 text-sm font-medium">Carregando...</p>
                    </div>
                </div>

                {{-- Coluna principal: abas + seções --}}
                <div class="lg:col-span-6 flex flex-col min-h-0" x-data>

                    <nav class="bg-transparent border-b border-gray-100">
                        <div class="w-full px-2">
                            <div class="overflow-x-auto">
                                <div class="flex space-x-3 h-8 min-w-max">

                                    @php $navBase = "nav-link cursor-pointer inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-light leading-5 text-gray-500 hover:text-teal-600 hover:border-teal-400 focus:outline-none transition duration-150 ease-in-out"; @endphp

                                    <div class="{{ $navBase }}"
                                        :class="{ 'active': $store.venda.secao === 'dados-section' }"
                                        @click="$store.venda.secao = 'dados-section'">
                                        <i class='bx bxs-receipt me-2'></i>
                                        {{ __('Dados') }}
                                    </div>

                                    <div class="{{ $navBase }}"
                                        :class="{ 'active': $store.venda.secao === 'mesas-section' }"
                                        @click="$store.venda.secao = 'mesas-section'">
                                        <i class='bx bx-chair me-2'></i>
                                        {{ __('Mesas') }}
                                    </div>

                                    <div class="{{ $navBase }}"
                                        :class="{ 'active': $store.venda.secao === 'pedidos-section' }"
                                        @click="$store.venda.secao = 'pedidos-section'">
                                        <i class="bx bx-basket me-2"></i>
                                        {{ __('Pedidos') }}
                                    </div>

                                    <div class="{{ $navBase }}"
                                        :class="{ 'active': $store.venda.secao === 'produtos-section' }"
                                        @click="$store.venda.secao = 'produtos-section'">
                                        <i class='bx bxs-pizza me-2'></i>
                                        {{ __('Produtos') }}
                                    </div>

                                    <div id="pagamentos" class="{{ $navBase }}"
                                        :class="{ 'active': $store.venda.secao === 'pagamentos-section' }"
                                        @click="$store.venda.secao = 'pagamentos-section'">
                                        <i class='bx bx-dollar me-2'></i>
                                        {{ __('Pagamentos') }}
                                    </div>

                                    <div id="finalizar" class="{{ $navBase }}"
                                        :class="{ 'active': $store.venda.secao === 'finalizar-section' }"
                                        @click="handleFinalizarClick()">
                                        <i class='bx bx-check-double me-2'></i>
                                        {{ __('Finalizar Venda') }}
                                    </div>

                                </div>
                            </div>
                        </div>
                    </nav>

                    <div class="w-full overflow-auto">

                        <div class="dados-section secao p-3" x-show="$store.venda.secao === 'dados-section'" x-cloak>
                            <h2 class="flex items-center gap-x-2 text-lg font-bold text-teal-700 mb-3">
                                <i class='bx bxs-receipt'></i>
                                <span>{{ __('Dados da Venda') }}</span>
                            </h2>
                            <div class="grid grid-cols-1 md:grid-cols-6 gap-x-2 gap-y-4">
                                {{-- Dados do Caixa --}}
                                <div class="md:col-span-1">
                                    <x-input-label for="venda_id" :value="__('Cód. Venda')" />
                                    <x-text-input id="venda_id" name="venda_id" type="text" class="mt-1 w-full"
                                        autocomplete="off" value="{{ old('id') }}" />
                                    <x-input-error :messages="$errors->updatePassword->get('venda_id')" class="mt-2" />
                                </div>

                                <div class="md:col-span-1">
                                    <x-input-label for="venda_sessao_caixa_id" :value="__('Cód. Sessão do Caixa')" />
                                    <x-text-input id="venda_sessao_caixa_id" name="venda_sessao_caixa_id" type="text"
                                        class="mt-1 w-full" autocomplete="off" value="{{ $sessaoCaixa->id }}"
                                        readonly />
                                    <x-input-error :messages="$errors->updatePassword->get('venda_sessao_caixa_id')" class="mt-2" />
                                </div>

                                <div class="md:col-span-1">
                                    <x-input-label for="caixa_nome" :value="__('Caixa')" />
                                    <x-text-input id="caixa_nome" name="caixa_nome" type="text" class="mt-1 w-full"
                                        autocomplete="off" value="{{ $sessaoCaixa->caixa->caixa_nome }}" readonly />
                                    <x-input-error :messages="$errors->updatePassword->get('caixa_nome')" class="mt-2" />
                                </div>

                                <div class="md:col-span-3">
                                    <x-input-label for="sessao_caixa_funcionario_id" :value="__('Funcionário')" />
                                    <x-text-input id="sessao_caixa_funcionario_id" name="sessao_caixa_funcionario_id"
                                        type="text" class="mt-1 w-full" autocomplete="off"
                                        value="{{ $sessaoCaixa->user->name }}" readonly />
                                    <x-input-error :messages="$errors->updatePassword->get('sessao_caixa_funcionario_id')" class="mt-2" />
                                </div>

                                {{-- Dados do Cliente --}}
                                <div class="col-span-full">
                                    <hr class="h-px my-1 border-0 bg-gray-200">
                                    <h2 class="flex items-center gap-x-2 text-lg font-bold text-teal-700">
                                        <i class='bx bx-user'></i>
                                        <span>{{ __('Dados Cliente') }}</span>
                                    </h2>
                                </div>

                                <div class="relative md:col-span-full">
                                    <x-text-input id="venda_cliente_id" name="venda_cliente_id" type="text"
                                        class="mt-1 w-full" hidden />
                                    <x-text-input id="venda_cliente_nome" name="venda_cliente_nome" class="mt-1 w-full"
                                        placeholder="Nome Cliente" autocomplete="new-password"></x-text-input>

                                    <div id="lista_clientes"
                                        class="absolute w-full bg-white rounded-lg px-2 py-3 shadow-lg shadow-green-400/10 hidden overflow-auto max-h-96 md:max-h-80 lg:max-h-72 border">
                                        @foreach ($clientes as $cliente)
                                            <div id="linha_cliente"
                                                class="border-b-2 hover:bg-teal-700 hover:text-white rounded-lg p-2 cursor-pointer transition duration-150 ease-in-out"
                                                onclick="selecionarCliente({{ $cliente }})">
                                                {{ $cliente->id }} - {{ $cliente->cliente_nome }}
                                            </div>
                                        @endforeach
                                    </div>
                                </div>

                                <div class="md:col-span-3">
                                    <x-input-label for="venda_cliente_cpf" :value="__('CPF Cliente')" />
                                    <x-text-input id="venda_cliente_cpf" name="venda_cliente_cpf" type="text"
                                        class="cpf mt-1 w-full" autocomplete="off"
                                        value="{{ old('venda_cliente_cpf') }}" placeholder="000.000.000-00" />
                                    <x-input-error :messages="$errors->updatePassword->get('venda_cliente_cpf')" class="mt-2" />
                                </div>

                                <div class="md:col-span-3">
                                    <x-input-label for="venda_cliente_cnpj" :value="__('CNPJ Cliente')" />
                                    <x-text-input id="venda_cliente_cnpj" name="venda_cliente_cnpj" type="text"
                                        class="cnpj mt-1 w-full" autocomplete="off"
                                        value="{{ old('venda_cliente_cnpj') }}" placeholder="000.000.000/0000-00" />
                                    <x-input-error :messages="$errors->updatePassword->get('venda_cliente_cnpj')" class="mt-2" />
                                </div>

                                <div class="md:col-span-2">
                                    <x-input-label for="venda_cliente_telefone" :value="__('Telefone Cliente')" />
                                    <x-text-input id="venda_cliente_telefone" name="venda_cliente_telefone"
                                        type="text" class="phone_ddd mt-1 w-full" autocomplete="off"
                                        value="{{ old('venda_cliente_telefone') }}" placeholder="(00)9 0000-0000" />
                                    <x-input-error :messages="$errors->updatePassword->get('venda_cliente_telefone')" class="mt-2" />
                                </div>

                                <div class="md:col-span-4">
                                    <x-input-label for="venda_cliente_email" :value="__('Email Cliente')" />
                                    <x-text-input id="venda_cliente_email" name="venda_cliente_email" type="Email"
                                        class="mt-1 w-full" autocomplete="off"
                                        value="{{ old('venda_cliente_email') }}" placeholder="exemplo@gmail.com" />
                                    <x-input-error :messages="$errors->updatePassword->get('venda_cliente_email')" class="mt-2" />
                                </div>

                            </div>
                        </div>

                        <div class="pagamentos-section secao p-3"
                             x-show="$store.venda.secao === 'pagamentos-section'"
                             x-cloak
                             x-data="{
                                 opcoes: @js($opcoesPagamentos),
                                 selectedId: '',
                                 get opcaoSelecionada() { return this.opcoes.find(o => String(o.id) === String(this.selectedId)) ?? null; },
                                 get showTaxa() { const t = this.opcaoSelecionada?.opcaopag_tipo_taxa; return t === 'ACRESCENTAR' || t === 'DESCONTAR'; },
                                 get showCartao() { const n = (this.opcaoSelecionada?.opcaopag_nome ?? '').toUpperCase(); return n.includes('CARTÃO') || n.includes('PIX'); },
                                 get isAcrescimo() { return this.opcaoSelecionada?.opcaopag_tipo_taxa === 'ACRESCENTAR'; },
                                 get isDesconto()  { return this.opcaoSelecionada?.opcaopag_tipo_taxa === 'DESCONTAR'; },
                                 get taxa() { return parseFloat(this.opcaoSelecionada?.opcaopag_valor_percentual_taxa ?? 0) || 0; }
                             }">
                            <div class="grid grid-cols-1 md:grid-cols-6 gap-x-2 gap-y-4">

                                {{-- Pagamento --}}
                                <div class="col-span-full">
                                    <h2 class="flex items-center gap-x-2 text-lg font-bold text-teal-700">
                                        <span>{{ __('Pagamento') }}</span>
                                    </h2>
                                </div>
                                <div class="md:col-span-4">
                                    <x-input-label for="pg_venda_opcaopagamento_id" :value="__('Tipo Pagamento')" />
                                    <x-select-input :options="$opcoesPagamentos" value-field="id" display-field="opcaopag_nome"
                                        id="pg_venda_opcaopagamento_id" name="pg_venda_opcaopagamento_id"
                                        class="mt-1 w-full" x-model="selectedId" />
                                </div>
                                <div class="md:col-span-2">
                                    <x-input-label for="pg_venda_valor_pagamento" :value="__('Valor Pagamento')" />
                                    <x-money-input id="pg_venda_valor_pagamento" name="pg_venda_valor_pagamento"
                                        type="text" class="money mt-1 w-full" autocomplete="off"
                                        value="{{ old('valor_pagamento') }}" />
                                </div>
                                <div id="dadosTaxa"
                                    class="md:col-span-6 grid grid-cols-1 md:grid-cols-6 gap-x-2 gap-y-4 p-1"
                                    x-show="showTaxa" x-cloak>

                                    <div class="md:col-span-3">
                                        <x-input-label for="opcao_pag_taxa" :value="__('% Taxa')" />
                                        <x-text-input id="opcao_pag_taxa" name="opcao_pag_taxa" type="text"
                                            class="mt-1 w-full" autocomplete="off" readonly
                                            x-bind:value="taxa" />
                                    </div>
                                    <div class="md:col-span-3" x-show="isAcrescimo" x-cloak>
                                        <x-input-label for="pg_venda_valor_acrescimo" :value="__('Valor Acrescimo')" />
                                        <x-money-input id="pg_venda_valor_acrescimo" name="pg_venda_valor_acrescimo"
                                            type="text" class="money mt-1 w-full" autocomplete="off"
                                            value="{{ old('valor_pagamento') }}" readonly />
                                    </div>
                                    <div class="md:col-span-3" x-show="isDesconto" x-cloak>
                                        <x-input-label for="pg_venda_valor_desconto" :value="__('Valor Desconto')" />
                                        <x-money-input id="pg_venda_valor_desconto" name="pg_venda_valor_desconto"
                                            type="text" class="money mt-1 w-full" autocomplete="off"
                                            value="{{ old('valor_pagamento') }}" readonly />
                                    </div>
                                </div>

                                <div id="dadosCartao"
                                    class="md:col-span-6 grid grid-cols-1 md:grid-cols-6 gap-x-2 gap-y-4 p-1"
                                    x-show="showCartao" x-cloak>

                                    <div class="md:col-span-3">
                                        <x-input-label for="pg_venda_cartao_id" :value="__('Bandeira do Cartão')" />
                                        <x-select-input :options="$cartoes" value-field="id"
                                            display-field="cartao_bandeira" id="pg_venda_cartao_id"
                                            name="pg_venda_cartao_id" class="mt-1 w-full" />
                                    </div>
                                    <div class="md:col-span-3">
                                        <x-input-label for="pg_venda_numero_autorizacao_cartao" :value="__('Nº Autorização Transação')" />
                                        <x-text-input id="pg_venda_numero_autorizacao_cartao"
                                            name="pg_venda_numero_autorizacao_cartao" type="text"
                                            class="mt-1 w-full" autocomplete="off"
                                            value="{{ old('pg_venda_numero_autorizacao_cartao') }}" />
                                    </div>
                                </div>

                                <div class="col-span-full">
                                    <x-input-label
                                        for="venda_valor_desconto">{{ __('Registrar Pagamento') }}</x-input-label>

                                    <x-primary-button class="mt-1 h-3/5" type="button" id="registar_pagamento">
                                        <i class='text-base bx bx-check-square'></i>
                                    </x-primary-button>


                                </div>

                                <div class="col-span-full">
                                    <table class="w-full text-center text-[7px] md:text-base">
                                        <thead>
                                            <tr class="border-b-4">
                                                <th class="px-1 md:px-4">#</th>
                                                <th class="px-1 md:px-4">Tipo Pag.</th>
                                                <th class="px-1 md:px-4">Taxa</th>
                                                <th class="px-1 md:px-4">Valor Desc.</th>
                                                <th class="px-1 md:px-4">Valor Acres.</th>
                                                <th class="px-1 md:px-4">Valor Pago</th>
                                                <th class="px-1 md:px-4">Opções</th>
                                            </tr>
                                        </thead>
                                        <tbody id="body_tabela_pagamentos">

                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <div class="mesas-section secao p-3" x-show="$store.venda.secao === 'mesas-section'" x-cloak>
                            <h2 class="flex items-center gap-x-2 text-lg font-bold text-teal-700 mb-3">
                                <i class='bx bx-chair'></i>
                                <span>{{ __('Mesas com Pedidos') }}</span>
                            </h2>
                            @foreach ($sessaoMesas as $sessaoMesa)
                                @php
                                    $clientesNaMesa  = [];
                                    $totalNaoCobrado = 0;
                                    $totalCobrado    = 0;
                                    foreach ($sessaoMesa->pedidos as $pedido) {
                                        foreach ($pedido->item_pedido_pedido_id as $item) {
                                            $cobrado = $item->item_pedido_venda_id !== null
                                                        && optional($item->venda)->venda_status === 'FINALIZADA';
                                            if ($cobrado) {
                                                $totalCobrado += $item->item_pedido_valor;
                                            } else {
                                                $totalNaoCobrado += $item->item_pedido_valor;
                                                $cid = $item->item_pedido_cliente_id !== null
                                                    ? (string) $item->item_pedido_cliente_id
                                                    : 'sem_cliente';
                                                if (!isset($clientesNaMesa[$cid])) {
                                                    $clientesNaMesa[$cid] = [
                                                        'id'       => $cid,
                                                        'nome'     => $item->item_pedido_cliente_id
                                                            ? ($item->cliente?->cliente_nome ?? 'Cliente #'.$item->item_pedido_cliente_id)
                                                            : 'Sem identificação',
                                                        'item_ids' => [],
                                                    ];
                                                }
                                                $clientesNaMesa[$cid]['item_ids'][] = $item->id;
                                            }
                                        }
                                    }
                                @endphp
                                <div class="w-full border border-gray-200 p-3 my-2 rounded-xl shadow-sm"
                                    x-data="{
                                        aberto: true,
                                        selecionados: [],
                                        selecionarCliente(ids) {
                                            ids.forEach(id => {
                                                if (!this.selecionados.includes(String(id))) this.selecionados.push(String(id));
                                            });
                                        },
                                        limparSelecao() { this.selecionados = []; },
                                        get temSelecionados() { return this.selecionados.length > 0; }
                                    }">
                                    <div class="flex justify-between">
                                        <div class="cursor-pointer flex space-x-2 justify-between items-center">
                                            <input type="checkbox" class="sessaoMesa" name="id_sessao_mesa[]"
                                                id="mesa_id_{{ $sessaoMesa->mesa->id }}"
                                                value="{{ $sessaoMesa->id }}"
                                                data-sessaomesa_id="{{ $sessaoMesa->id }}" />
                                            <x-input-label class="text-sm font-bold"
                                                for="mesa_id_{{ $sessaoMesa->mesa->id }}">{{ $sessaoMesa->mesa->mesa_nome }}</x-input-label>
                                            <span class="text-[10px] text-gray-400 ml-1">{{ $sessaoMesa->sessao_mesa_status }}</span>
                                            <x-secondary-button
                                                onclick="window.open('{{ route('sessaoMesa.imprimir', ['id' => $sessaoMesa->id]) }}', 'Itens Sessão Mesa','width=600,height=400');"
                                                title="IMPRIMIR">
                                                <i class='bx bx-printer'></i>
                                            </x-secondary-button>
                                        </div>
                                        <span @click.prevent="aberto = !aberto"
                                            :class="{ 'rotate-180': aberto }"
                                            class="bx bx-chevron-up p-1 hover:bg-slate-400 cursor-pointer rounded-full transition duration-300 ease-in-out">
                                        </span>
                                    </div>

                                    {{-- Pedidos IDs --}}
                                    <div class="flex justify-between mt-1">
                                        <span class="text-[10px] text-gray-400">
                                            Pedidos:
                                            @foreach ($sessaoMesa->pedidos as $pedido)
                                                {{ $pedido->id }}{{ !$loop->last ? ' · ' : '' }}
                                            @endforeach
                                        </span>
                                    </div>

                                    <div x-show="aberto" class="mt-2">
                                        {{-- Filtros por cliente --}}
                                        @if(!empty($clientesNaMesa))
                                            <div class="flex flex-wrap gap-1 mb-2">
                                                @foreach($clientesNaMesa as $cliente)
                                                    <button type="button"
                                                        @click="selecionarCliente({{ json_encode(array_values($cliente['item_ids'])) }})"
                                                        class="inline-flex items-center gap-1 px-2 py-1 text-[11px] font-medium bg-indigo-50 hover:bg-indigo-100 border border-indigo-200 text-indigo-700 rounded-lg transition">
                                                        <i class='bx bx-user text-[10px]'></i>
                                                        {{ $cliente['nome'] }}
                                                        <span class="font-bold">({{ count($cliente['item_ids']) }})</span>
                                                    </button>
                                                @endforeach
                                            </div>
                                        @endif

                                        {{-- Lista de itens com checkbox --}}
                                        <div class="divide-y divide-gray-100 border border-gray-100 rounded-xl overflow-hidden">
                                            @foreach($sessaoMesa->pedidos as $pedido)
                                                @foreach($pedido->item_pedido_pedido_id as $item)
                                                    @php $cobrado = $item->item_pedido_venda_id !== null
                                                        && optional($item->venda)->venda_status === 'FINALIZADA'; @endphp
                                                    <label class="flex items-center gap-2 px-3 py-2 select-none
                                                        {{ $cobrado ? 'bg-gray-50 opacity-60 cursor-not-allowed' : 'cursor-pointer hover:bg-teal-50' }}">
                                                        <input type="checkbox"
                                                            value="{{ $item->id }}"
                                                            x-model="selecionados"
                                                            {{ $cobrado ? 'disabled' : '' }}
                                                            class="w-4 h-4 rounded border-gray-300 text-teal-600 focus:ring-teal-500 shrink-0">
                                                        @if($item->item_pedido_cliente_id)
                                                            <span class="shrink-0 text-[9px] font-semibold text-indigo-700 bg-indigo-50 border border-indigo-200 rounded px-1 py-0.5">
                                                                {{ $item->cliente?->cliente_nome ?? '#'.$item->item_pedido_cliente_id }}
                                                            </span>
                                                        @endif
                                                        <span class="flex-1 text-xs text-gray-800">
                                                            {{ $item->produto?->produto_descricao ?? 'Produto #'.$item->item_pedido_produto_id }}
                                                            <span class="text-gray-400">
                                                                × {{ $item->item_pedido_quantidade == floor($item->item_pedido_quantidade) ? (int)$item->item_pedido_quantidade : $item->item_pedido_quantidade }}
                                                            </span>
                                                        </span>
                                                        <span class="shrink-0 text-xs font-semibold {{ $cobrado ? 'text-gray-400 line-through' : 'text-gray-700' }}">
                                                            R$ {{ number_format($item->item_pedido_valor, 2, ',', '.') }}
                                                        </span>
                                                        @if($cobrado)
                                                            <span class="cobrado-badge shrink-0 text-[9px] font-bold text-green-600 bg-green-50 border border-green-200 rounded px-1 py-0.5 flex items-center gap-0.5">
                                                                <i class='bx bx-check'></i> cobrado
                                                            </span>
                                                        @endif
                                                    </label>
                                                @endforeach
                                            @endforeach
                                        </div>

                                        {{-- Total + botão Lançar --}}
                                        <div class="mt-2 flex items-center justify-between gap-2">
                                            <div class="text-xs text-gray-500">
                                                <span class="font-bold text-gray-700">R$ {{ number_format($totalNaoCobrado, 2, ',', '.') }}</span> a cobrar
                                                @if($totalCobrado > 0)
                                                    · <span class="text-green-600">R$ {{ number_format($totalCobrado, 2, ',', '.') }} cobrado</span>
                                                @endif
                                            </div>
                                            <div class="flex items-center gap-2">
                                                <button type="button"
                                                    @click="limparSelecao()"
                                                    x-show="temSelecionados" x-cloak
                                                    class="text-xs text-gray-400 hover:text-gray-600 transition px-2 py-1 hover:bg-gray-100 rounded-lg">
                                                    Limpar
                                                </button>
                                                <button type="button"
                                                    @click="if(temSelecionados) window.lancarItensVenda([...selecionados], $el.closest('[x-data]'))"
                                                    :disabled="!temSelecionados"
                                                    :class="temSelecionados
                                                        ? 'bg-teal-600 hover:bg-teal-700 text-white shadow'
                                                        : 'bg-gray-100 text-gray-400 cursor-not-allowed'"
                                                    class="inline-flex items-center gap-1.5 px-4 py-2 text-xs font-semibold rounded-lg transition">
                                                    <i class='bx bx-send text-sm'></i>
                                                    <span x-text="temSelecionados ? 'Lançar ' + selecionados.length + ' item(s)' : 'Selecione itens'">Selecione itens</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="produtos-section secao p-3" x-show="$store.venda.secao === 'produtos-section'" x-cloak>
                            <h2 class="flex items-center gap-x-2 text-lg font-bold text-teal-700 mb-3">
                                <i class='bx bxs-pizza'></i>
                                <span>{{ __('Produtos') }}</span>
                            </h2>
                            @foreach ($categorias as $categoria)
                                <div class="mb-4" id="categoria_{{ $categoria->id }}">
                                    <h3 class="text-sm font-semibold text-teal-700 uppercase tracking-wide mb-2">{{ $categoria->categoria_nome }}</h3>
                                    @if ($categoria->produtos->isEmpty())
                                        <p class="text-gray-400">Não há produtos disponíveis nesta categoria.</p>
                                    @else
                                        <div
                                            class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 2xl:grid-cols-5 gap-4 ">
                                            @foreach ($categoria->produtos as $produto)
                                                <div class="relative snap-end ">
                                                    <div class="produto cursor-pointer hover:shadow-lg"
                                                        data-produto_id="{{ $produto->id }}"
                                                        data-produto_valor="{{ $produto->produto_preco_venda }}">
                                                        <div
                                                            class="w-full bg-gray-50 border border-gray-200 p-2 rounded-xl flex items-start justify-between hover:border-teal-300 hover:bg-teal-50 transition duration-150 gap-1">
                                                            <div class="flex flex-col justify-center w-full">
                                                                <h2 class="text-gray-900 text-[8px] uppercase">
                                                                    @if (isset($produto->produto_referencia) && $produto->produto_referencia !== null)
                                                                        {{ $produto->produto_descricao }} - <span>Ref.
                                                                            {{ $produto->produto_referencia }}</span>
                                                                    @else
                                                                        {{ $produto->categoria->categoria_nome }}
                                                                        {{ $produto->produto_descricao }}
                                                                    @endif
                                                                </h2>
                                                                <div class="flex items-center">
                                                                    <span
                                                                        class="text-gray-900 text-sm font-bold">R${{ str_replace('.', ',', $produto->produto_preco_venda) }}</span>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>

                        <div class="pedidos-section secao p-3" x-show="$store.venda.secao === 'pedidos-section'">
                            <h2 class="flex items-center gap-x-2 text-lg font-bold text-teal-700 mb-3">
                                <i class='bx bx-basket'></i>
                                <span>{{ __('Pedidos Avulsos') }}</span>
                            </h2>
                            @foreach ($pedidos as $pedido)
                                @if ($pedido->pedido_sessao_mesa_id == null || $pedido->pedido_sessao_mesa_id == '')
                                    <div class="w-full border border-gray-200 p-3 my-2 rounded-xl shadow-sm" x-data="{ aberto: true }">
                                        <div class="flex justify-between">
                                            <div>
                                                <input type="checkbox" class="pedido" name="id_pedido[]"
                                                    id="pedido_{{ $pedido->id }}" value="{{ $pedido->id }}"
                                                    data-pedido_id="{{ $pedido->id }}" />
                                                <label for="pedido_{{ $pedido->id }}">Pedido:
                                                    {{ $pedido->id }} -
                                                    {{ \Carbon\Carbon::parse($pedido->updated_at)->format('d/m/Y H:i:s') }} -
                                                    {{ $pedido->opcaoEntrega->opcaoentrega_nome }}
                                                </label>
                                                <x-secondary-button
                                                    onclick="window.open('{{ route('pedido.imprimir', ['id' => $pedido->id]) }}', 'Teste', 'width=600,height=400' );"
                                                    title="IMPRIMIR">
                                                    <i class='bx bx-printer'></i>
                                                </x-secondary-button>
                                            </div>
                                            <span @click.prevent="aberto = !aberto"
                                                :class="{ 'rotate-180': aberto }"
                                                class="bx bx-chevron-up p-1 hover:bg-slate-400 cursor-pointer rounded-full transition duration-300 ease-in-out">
                                            </span>
                                        </div>
                                        @php
                                            $itensAgrupados = [];

                                            // Agrupa itens de todos os pedidos por mesa
                                            foreach ($pedido->item_pedido_pedido_id as $item) {
                                                $produtoId = $item->item_pedido_produto_id;

                                                if (!isset($itensAgrupados[$produtoId])) {
                                                    $itensAgrupados[$produtoId] = [
                                                        'produto' => $item->produto,
                                                        'categoria' => $item->produto->categoria,
                                                        'item' => $item,
                                                        'total_quantidade' => 0,
                                                        'total_desconto' => 0,
                                                        'total_valor' => 0,
                                                    ];
                                                }

                                                $itensAgrupados[$produtoId]['total_quantidade'] +=
                                                    $item->item_pedido_quantidade;
                                                $itensAgrupados[$produtoId]['total_desconto'] +=
                                                    $item->item_pedido_desconto;
                                                $itensAgrupados[$produtoId]['total_valor'] += $item->item_pedido_valor;
                                            }
                                            $valorTotal = array_sum(array_column($itensAgrupados, 'total_valor'));
                                        @endphp

                                        <div id="table_pedido_{{ $pedido->id }}" x-show="aberto">
                                            <table class="w-full text-center text-[7px] md:text-base">
                                                <thead>
                                                    <tr class="border-b-4">
                                                        <th class="px-1 md:px-4">#</th>
                                                        <th class="px-1 md:px-4">Produto</th>
                                                        <th class="px-1 md:px-4">Qtd</th>
                                                        <th class="px-1 md:px-4">Valor Unt.</th>
                                                        <th class="px-1 md:px-4">Valor Desc.</th>
                                                        <th class="px-1 md:px-4">Valor Total</th>
                                                        <th class="px-1 md:px-4">Opções</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @foreach ($itensAgrupados as $produtoId => $item)
                                                        <tr>
                                                            <td>{{ $item['produto']->id }}</td>
                                                            <td>
                                                                {{ $item['categoria']->categoria_nome ?? 'Sem categoria' }}
                                                                {{ $item['produto']->produto_descricao }}
                                                            </td>
                                                            <td>{{ $item['total_quantidade'] }}</td>
                                                            <td>{{ number_format($item['item']->item_pedido_valor_unitario, 2, ',', '.') }}
                                                            </td>
                                                            <td>{{ number_format($item['total_desconto'], 2, ',', '.') }}
                                                            </td>
                                                            <td>{{ number_format($item['total_valor'], 2, ',', '.') }}
                                                            </td>
                                                            <td>{{ $item['item']->item_pedido_status }}</td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                        <span class="text-end">
                                            <p class="font-bold">Valor Total R$
                                                {{ number_format($valorTotal, 2, ',', '.') }}</p>
                                        </span>
                                        <span class="text-end">
                                            <p class="font-bold">{{ $pedido->pedido_status }}</p>
                                        </span>
                                    </div>
                                @endif
                            @endforeach
                        </div>

                    </div>
                </div>

                {{-- Coluna de totais --}}
                <div class="lg:col-span-2 border border-gray-200 rounded-xl shadow-sm flex flex-col lg:sticky lg:top-2">
                    <div class="px-3 py-2 border-b border-gray-100 bg-gray-50 rounded-t-xl">
                        <h3 class="text-sm font-bold text-teal-700 flex items-center gap-1">
                            <i class='bx bx-calculator'></i> Totais
                        </h3>
                    </div>
                    <div class="p-2 flex flex-col gap-2">
                        <div>
                            <x-input-label for="venda_valor_frete">{{ __('Frete') }}</x-input-label>
                            <x-money-input id="venda_valor_frete" name="venda_valor_frete"
                                class="venda_valor_frete money w-full text-lg font-bold"
                                autocomplete="off"></x-input-text>
                        </div>
                        <div>
                            <x-input-label for="venda_valor_itens">{{ __('Itens') }}</x-input-label>
                            <x-money-input id="venda_valor_itens" name="venda_valor_itens" readonly
                                class="money w-full text-lg font-bold"></x-input-text>
                        </div>
                        <div>
                            <x-input-label for="venda_valor_acrescimo">{{ __('Acréscimo') }}</x-input-label>
                            <x-money-input id="venda_valor_acrescimo" name="venda_valor_acrescimo" readonly
                                class="money w-full text-lg font-bold"></x-input-text>
                        </div>
                        <div>
                            <x-input-label for="venda_valor_desconto">{{ __('Desconto') }}</x-input-label>
                            <x-money-input id="venda_valor_desconto" name="venda_valor_desconto" readonly
                                class="money w-full text-lg font-bold"></x-input-text>
                        </div>
                        <div class="border-t border-gray-200 pt-2">
                            <x-input-label for="venda_valor_total" class="font-semibold text-teal-700">{{ __('Total') }}</x-input-label>
                            <x-money-input id="venda_valor_total" name="venda_valor_total" readonly
                                class="money w-full text-2xl font-bold text-teal-700"></x-input-text>
                        </div>
                        <div>
                            <x-input-label for="venda_valor_pago">{{ __('Pago') }}</x-input-label>
                            <x-money-input id="venda_valor_pago" name="venda_valor_pago" readonly
                                class="money w-full text-lg font-bold"></x-input-text>
                        </div>
                        <div>
                            <x-input-label for="venda_valor_troco">{{ __('Troco') }}</x-input-label>
                            <x-money-input id="venda_valor_troco" name="venda_valor_troco" readonly
                                class="money w-full text-lg font-bold"></x-input-text>
                        </div>
                    </div>
                </div>

            </div>
        </form>

        {{-- FAB: abre o drawer de itens da venda --}}
        <div class="fixed bottom-5 inset-x-0 px-4 z-40 flex justify-center pointer-events-none"
             x-show="$store.venda.qtdItens > 0" x-cloak>
            <button @click="$store.venda.drawerOpen = true" type="button"
                class="pointer-events-auto flex items-center gap-3 bg-teal-600 hover:bg-teal-500 text-white font-semibold px-6 py-3 rounded-2xl shadow-2xl transition">
                <span class="flex items-center justify-center w-6 h-6 bg-white text-teal-700 rounded-full text-xs font-bold"
                      x-text="$store.venda.qtdItens">0</span>
                <span class="text-sm">Itens na venda</span>
                <span class="font-bold text-sm">R$ <span x-text="$store.venda.valorTotal">0,00</span></span>
                <i class='bx bx-list-ul text-lg'></i>
            </button>
        </div>

        {{-- Drawer de itens da venda --}}
        <div x-show="$store.venda.drawerOpen"
             class="fixed inset-0 z-50 flex flex-col justify-end"
             x-cloak>
            {{-- Backdrop --}}
            <div @click="$store.venda.drawerOpen = false"
                 class="absolute inset-0 bg-black/50 backdrop-blur-sm"
                 x-transition:enter="transition ease-out duration-300"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 x-transition:leave="transition ease-in duration-200"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0">
            </div>
            {{-- Sheet --}}
            <div class="relative bg-white rounded-t-3xl max-h-[85vh] flex flex-col z-10 w-full md:max-w-2xl md:mx-auto shadow-2xl"
                 x-transition:enter="transition ease-out duration-300"
                 x-transition:enter-start="translate-y-full"
                 x-transition:enter-end="translate-y-0"
                 x-transition:leave="transition ease-in duration-200"
                 x-transition:leave-start="translate-y-0"
                 x-transition:leave-end="translate-y-full">
                {{-- Handle --}}
                <div class="flex justify-center pt-3 pb-2 shrink-0">
                    <div class="w-10 h-1 bg-gray-300 rounded-full"></div>
                </div>
                {{-- Header --}}
                <div class="flex items-center justify-between px-4 pb-3 shrink-0 border-b border-gray-100">
                    <h3 class="text-base font-bold text-teal-700 flex items-center gap-2">
                        <i class='bx bx-list-ul'></i> Itens da Venda
                    </h3>
                    <button @click="$store.venda.drawerOpen = false" type="button"
                        class="p-1 hover:bg-gray-100 rounded-full transition">
                        <i class='bx bx-x text-xl'></i>
                    </button>
                </div>
                {{-- Body --}}
                <div id="itens_venda" class="overflow-y-auto flex-grow px-2 py-1"></div>
                {{-- Footer --}}
                <div class="px-4 py-4 border-t border-gray-100 bg-gray-50 shrink-0 rounded-b-3xl">
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-500">Total da venda</span>
                        <span class="text-xl font-bold text-teal-700">R$ <span x-text="$store.venda.valorTotal">0,00</span></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script>
        function selecionarCliente(cliente) {
            document.getElementById("venda_cliente_id").value = cliente.id;
            document.getElementById("venda_cliente_nome").value = cliente.cliente_nome;
            document.getElementById("venda_cliente_telefone").value = cliente.cliente_celular;
            document.getElementById("venda_cliente_email").value = cliente.cliente_email;
            document.getElementById("venda_cliente_cpf").value = cliente.cliente_cpf;
            document.getElementById("venda_cliente_cnpj").value = cliente.cliente_cnpj;
        }

        document.addEventListener('DOMContentLoaded', function() {


            const inputCliente = document.getElementById('venda_cliente_nome');
            const listaClientes = document.getElementById('lista_clientes');

            // Mostrar a lista de clientes quando o campo de texto estiver focado
            inputCliente.addEventListener('focus', function() {
                listaClientes.classList.remove('hidden');
            });

            // Ocultar a lista de clientes quando o campo de texto perder o foco
            inputCliente.addEventListener('blur', function() {
                setTimeout(() => {
                    listaClientes.classList.add('hidden');
                }, 200);

            });

            // Filtrar a lista de clientes conforme o usuário digita
            inputCliente.addEventListener('input', function() {
                const textoDigitado = inputCliente.value.toLowerCase();
                const itemsClientes = listaClientes.querySelectorAll('div');

                itemsClientes.forEach(function(itemCliente) {
                    const nomeCliente = itemCliente.textContent.toLowerCase();
                    if (nomeCliente.includes(textoDigitado)) {
                        itemCliente.style.display = 'block';
                    } else {
                        itemCliente.style.display = 'none';
                    }
                });
            });
        });
    </script>
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.store('venda', {
                secao: 'pedidos-section',
                drawerOpen: false,
                qtdItens: 0,
                valorTotal: '0,00',
            });
        });

        function handleFinalizarClick() {
            const valorPago  = parseFloat(document.getElementById('venda_valor_pago').value)  || 0;
            const valorTotal = parseFloat(document.getElementById('venda_valor_total').value) || 0;
            if (valorTotal > 0 && valorPago >= valorTotal) {
                document.getElementById('formVenda').submit();
                return;
            }
            document.querySelector('.abrir-modal')?.click();
            const titulo = document.getElementById('modal-title');
            const corpo  = document.getElementById('modal-body');
            if (titulo) titulo.innerHTML = `<h2>OLÁ {{ Auth::user()->name }}</h2>`;
            if (corpo)  corpo.innerHTML  = `
                <div class="p-2 flex items-center">
                    <i class="bx bx-info-circle text-4xl text-yellow-500"></i>
                    <div class="ml-4">
                        <h4 class="text-xl font-bold">Atenção</h4>
                        <p>Valor Pago é insuficiente para finalizar a venda!</p>
                    </div>
                </div>`;
            Alpine.store('venda').secao = 'pagamentos-section';
        }

        function showToast(message, type = 'error') {
            const container = document.getElementById('toast-container');
            const colors = {
                error:   'bg-red-600 border-red-700',
                success: 'bg-teal-600 border-teal-700',
                warning: 'bg-yellow-500 border-yellow-600',
            };
            const icons = {
                error:   'bx-error-circle',
                success: 'bx-check-circle',
                warning: 'bx-info-circle',
            };
            const toast = document.createElement('div');
            toast.className = `pointer-events-auto flex items-center gap-3 px-4 py-3 rounded-xl border shadow-xl text-white text-sm font-medium max-w-xs transition-all duration-300 opacity-0 translate-x-4 ${colors[type] ?? colors.error}`;
            toast.innerHTML = `<i class="bx ${icons[type] ?? icons.error} text-lg shrink-0"></i><span>${message}</span>`;
            container.appendChild(toast);
            requestAnimationFrame(() => {
                toast.classList.remove('opacity-0', 'translate-x-4');
            });
            setTimeout(() => {
                toast.classList.add('opacity-0', 'translate-x-4');
                toast.addEventListener('transitionend', () => toast.remove());
            }, 4000);
        }
    </script>
    <script type="module">
        $(document).ready(function() {

            // Converte o array PHP para JSON e o passa para o JavaScript
            let opcao_pag = @json($opcoesPagamentos);

            if ($(".toggleSideBar").length) $(".toggleSideBar").trigger("click");

            //Adiciona/Remove Itens dos pedidos da Sessão de Mesa Selecionada pelo usuário
            $('.sessaoMesa').click(function() {
                $("#carregando").removeClass('hidden');

                if ($(this).is(':checked')) {
                    //Verifica se o venda está aberto
                    const venda_id = $("#venda_id").val();
                    if (venda_id === "") {
                        //Se não estiver ele abre um novo e já adiciona os itens da sessao mesa selecionada na venda aberta

                        // Uso da função com a promessa
                        IniciarVenda().then(vendaId => {
                            AdicionaItensSessaoMesa($(this).data('sessaomesa_id'), vendaId);
                        }).catch(error => {
                        });
                    } else {
                        AdicionaItensSessaoMesa($(this).data('sessaomesa_id'), venda_id);
                    }
                } else {
                    RemoveItensSessaoMesa($(this).data('sessaomesa_id'), $("#venda_id").val());
                }
            });

            //Adiciona/Remove Itens dos pedidos selecionados pelo o usuário
            $('.pedido').click(function() {
                $("#carregando").removeClass('hidden');

                if ($(this).is(':checked')) {
                    //Verifica se o venda está aberto
                    const venda_id = $("#venda_id").val();
                    if (venda_id === "") {
                        //Se não estiver ele abre um novo e já adiciona os itens do pedido selecionado na venda aberta

                        // Uso da função com a promessa
                        IniciarVenda().then(vendaId => {
                            AdicionaItensPedido($(this).data('pedido_id'), vendaId);
                        }).catch(error => {
                        });
                    } else {
                        AdicionaItensPedido($(this).data('pedido_id'), venda_id);
                    }
                } else {
                    RemoveItensPedido($(this).data('pedido_id'), $("#venda_id").val());
                }

            });

            //Adiciona o produto no venda
            $(".produto").click(function(e) {
                e.preventDefault();
                const item_venda_produto_id = $(this).data('produto_id');
                const venda_id = $("#venda_id").val();
                $("#carregando").removeClass('hidden');
                if (venda_id === "") {
                    //Se não estiver ele abre um novo e já adiciona os itens do pedido selecionado na venda aberta

                    // Uso da função com a promessa
                    IniciarVenda().then(vendaId => {
                        AdicionaProduto($(this).data('produto_id'), vendaId);
                    }).catch(error => {
                    });
                } else {
                    AdicionaProduto($(this).data('produto_id'), venda_id);
                }

            });

            // Lança itens selecionados por checkbox na venda (acessível pelo Alpine via window.lancarItensVenda)
            window.lancarItensVenda = function(item_ids, componentEl) {
                if (!item_ids || item_ids.length === 0) return;
                const venda_id = $("#venda_id").val();
                $("#carregando").removeClass('hidden');
                if (venda_id === "") {
                    IniciarVenda().then(vendaId => {
                        LancarItensPorSelecao(item_ids, vendaId, componentEl);
                    }).catch(() => { $("#carregando").addClass('hidden'); });
                } else {
                    LancarItensPorSelecao(item_ids, venda_id, componentEl);
                }
            };

            let venda_valor_frete;

            // Função que atualiza o valor total quando insere qualquer valor no campo de desconto
            $(".venda_valor_frete").keyup(function(e) {
                $("#carregando").removeClass('hidden');
                venda_valor_frete = $("#venda_valor_frete").val();
                const venda_id = $("#venda_id").val();
                if (venda_id === "") {
                    $("#venda_valor_frete").attr('readonly', true);
                    //Se não estiver ele abre um novo e já adiciona os itens do pedido selecionado na venda aberta

                    // Uso da função com a promessa
                    IniciarVenda().then(vendaId => {
                        AtualizaValorFrete(venda_valor_frete, vendaId);
                        $("#venda_valor_frete").attr('readonly', false);
                    }).catch(error => {
                    });
                } else {
                    AtualizaValorFrete(venda_valor_frete, venda_id);
                    $("#carregando").addClass('hidden');
                }

            });

            // Função que executa quando o campo de desconto recebe foco
            $(".venda_valor_frete").focus(function(e) {
                e.preventDefault();
                // Armazena o valor atual do campo de desconto e limpa o campo
                venda_valor_frete = $(this).val();
                $(this).val("");
            });

            // Função que executa quando o campo de desconto perde o foco
            $(".venda_valor_frete").blur(function(e) {
                e.preventDefault();
                // Verifica se o valor do desconto é diferente de vazio ou "0.00" ou "0,00"
                if (venda_valor_frete !== "" || venda_valor_frete !== "0.00" ||
                    venda_valor_frete !== "0,00") {
                    // Se for diferente, restaura o valor anterior do campo de desconto
                    if ($("#venda_id").val()) {
                        listarVenda($("#venda_id").val());
                    }

                } else {
                    // Se for vazio ou "0.00" ou "0,00", define o valor como "0.00"
                    $(this).val("0.00");
                }
            });

            // Cálculo de acréscimo/desconto ao digitar o valor do pagamento
            $("#pg_venda_valor_pagamento").keyup(function(e) {
                const valor_pag = parseFloat($(this).val()) || 0;
                const valor_taxa = parseFloat($("#opcao_pag_taxa").val()) || 0;
                const selectedId = $("#pg_venda_opcaopagamento_id").val();
                const selectedOption = opcao_pag.find(op => String(op.id) === String(selectedId));
                if (!selectedOption) return;
                if (selectedOption.opcaopag_tipo_taxa === 'ACRESCENTAR') {
                    $("#pg_venda_valor_acrescimo").val((valor_pag * valor_taxa / 100).toFixed(2));
                } else if (selectedOption.opcaopag_tipo_taxa === 'DESCONTAR') {
                    $("#pg_venda_valor_desconto").val((valor_pag * valor_taxa / 100).toFixed(2));
                }
            });

            $("#registar_pagamento").click(function(e) {
                e.preventDefault();
                $(".carregando").removeClass("hidden");
                InserePagamento();
            });

            $('#pg_venda_valor_pagamento').on('keypress', function(e) {
                if (e.which === 13) { // 13 é o código da tecla Enter
                    e.preventDefault(); // evita que o form seja enviado (opcional)
                    $(".carregando").removeClass("hidden");
                    InserePagamento();
                }
            });
            
            $('#pg_venda_numero_autorizacao_cartao').on('keypress', function(e) {
                if (e.which === 13) { // 13 é o código da tecla Enter
                    e.preventDefault(); // evita que o form seja enviado (opcional)
                    $(".carregando").removeClass("hidden");
                    InserePagamento();
                }
            });

            $("#venda_cliente_nome").keyup(function() {
                if (!$(this).val()) {
                    $("#venda_cliente_id").val("");
                    $("#venda_cliente_cpf").val("");
                    $("#venda_cliente_cnpj").val("");
                    $("#venda_cliente_telefone").val("");
                    $("#venda_cliente_email").val("");
                }
            });





            function IniciarVenda() {
                const form = document.getElementById('formVenda');
                var route = '{{ route('venda.salvar_venda', 14) }}';
                const venda_sessao_caixa_id = $("#venda_sessao_caixa_id").val();
                const venda_cliente_id = $("#venda_cliente_id").val();

                return new Promise((resolve, reject) => {
                    // Fazer uma requisição AJAX para iniciar a venda
                    $.ajax({
                        url: "{{ route('venda.iniciar') }}",
                        type: 'POST',
                        dataType: 'json',
                        data: {
                            '_token': '{{ csrf_token() }}',
                            venda_sessao_caixa_id,
                            venda_cliente_id
                        },
                        success: function(response) {
                            if (response && response.venda_id) {
                                $("#venda_id").val(response.venda_id);
                                $("#venda_id_titulo").text("Nº: " + response.venda_id);
                                form.action = route.replace('14', response.venda_id);
                                resolve($("#venda_id").val());
                                $("#carregando").addClass('hidden');
                            } else {
                                showToast('Erro ao iniciar a venda. Por favor, tente novamente.');
                                reject('Erro ao iniciar a venda.');
                                $("#carregando").addClass('hidden');
                            }
                        },
                        error: function() {
                            showToast('Erro ao iniciar a venda. Por favor, tente novamente.');
                            reject('Erro ao iniciar a venda.');
                            $("#carregando").addClass('hidden');
                        }
                    });
                });
            }

            function AdicionaItensSessaoMesa(sessaoMesa_id, venda_id) {
                $.ajax({
                    type: "POST",
                    url: "{{ route('item_venda.add_item_sessaoMesa') }}",
                    data: {
                        '_token': '{{ csrf_token() }}',
                        sessaoMesa_id,
                        venda_id
                    },
                    dataType: "JSON",
                    success: function(response) {
                        ListaItensVenda(venda_id);
                    },
                    error: function() {
                        showToast('Erro ao adicionar itens da sessão de mesa!');
                    }
                });
            }

            function RemoveItensSessaoMesa(sessaoMesa_id, venda_id) {
                $.ajax({
                    type: "POST",
                    url: "{{ route('item_venda.remove_item_sessaoMesa') }}",
                    data: {
                        '_token': '{{ csrf_token() }}',
                        sessaoMesa_id,
                        venda_id
                    },
                    dataType: "JSON",
                    success: function(response) {
                        ListaItensVenda(venda_id);

                    },
                    error: function() {
                        showToast('Erro ao remover itens da sessão de mesa!');
                    }
                });
            }

            function LancarItensPorSelecao(item_ids, venda_id, componentEl) {
                $.ajax({
                    type: "POST",
                    url: "{{ route('item_venda.add_por_selecao') }}",
                    data: {
                        '_token': '{{ csrf_token() }}',
                        item_ids,
                        venda_id
                    },
                    dataType: "JSON",
                    success: function(response) {
                        // Limpa Alpine selecionados do card
                        if (componentEl && typeof Alpine !== 'undefined') {
                            try { Alpine.$data(componentEl).selecionados = []; } catch(e) {}
                        }
                        // Marca visualmente os itens como cobrados
                        (response.cobrados || []).forEach(function(id) {
                            const cb = $('input[type=checkbox][value="' + id + '"]');
                            cb.prop('disabled', true).prop('checked', false);
                            cb.closest('label')
                                .addClass('opacity-60 bg-gray-50 cursor-not-allowed')
                                .removeClass('hover:bg-teal-50 cursor-pointer');
                            if (!cb.siblings('.cobrado-badge').length) {
                                cb.closest('label').append(
                                    `<span class="cobrado-badge shrink-0 text-[9px] font-bold text-green-600 bg-green-50 border border-green-200 rounded px-1 py-0.5 flex items-center gap-0.5"><i class='bx bx-check'></i> cobrado</span>`
                                );
                            }
                        });
                        ListaItensVenda(venda_id);
                        showToast('Itens lançados na venda!', 'success');
                    },
                    error: function() {
                        showToast('Erro ao lançar itens!');
                        $("#carregando").addClass('hidden');
                    }
                });
            }

            function AdicionaItensPedido(pedido_id, venda_id) {
                $.ajax({
                    type: "POST",
                    url: "{{ route('item_venda.add_item_pedido') }}",
                    data: {
                        '_token': '{{ csrf_token() }}',
                        pedido_id,
                        venda_id
                    },
                    dataType: "JSON",
                    success: function(response) {
                        ListaItensVenda(venda_id);
                    },
                    error: function() {
                        showToast('Erro ao adicionar itens do pedido!');
                    }
                });

            }

            function RemoveItensPedido(pedido_id, venda_id) {
                $.ajax({
                    type: "POST",
                    url: "{{ route('item_venda.remove_item_pedido') }}",
                    data: {
                        '_token': '{{ csrf_token() }}',
                        pedido_id,
                        venda_id
                    },
                    dataType: "JSON",
                    success: function(response) {
                        ListaItensVenda(venda_id);
                    },
                    error: function() {
                        showToast('Erro ao remover itens do pedido #' + pedido_id + '. Contate o administrador.');
                    }
                });
            }

            function AdicionaProduto(produto_id, venda_id) {
                $.ajax({
                    type: "POST",
                    url: "{{ route('item_venda.add_produto') }}",
                    data: {
                        '_token': '{{ csrf_token() }}',
                        produto_id,
                        venda_id
                    },
                    dataType: "JSON",
                    success: function(response) {
                        ListaItensVenda(venda_id);
                    },
                    error: function() {
                        showToast('Erro ao adicionar produto!');
                    }
                });

            }

            function RemoveProduto(produto_id, venda_id) {
                $.ajax({
                    type: "POST",
                    url: "{{ route('item_venda.remove_produto') }}",
                    data: {
                        '_token': '{{ csrf_token() }}',
                        produto_id,
                        venda_id
                    },
                    dataType: "JSON",
                    success: function(response) {
                        ListaItensVenda(venda_id);
                    },
                    error: function() {
                        showToast('Erro ao remover produto #' + produto_id + '. Contate o administrador.');
                    }
                });
            }

            function ListaItensVenda(venda_id) {
                $.ajax({
                    type: "GET",
                    url: "{{ route('item_venda.listar') }}",
                    data: {
                        '_token': '{{ csrf_token() }}',
                        venda_id
                    },
                    dataType: "JSON",
                    success: function(response) {
                        Alpine.store('venda').qtdItens = response.length;

                        // Limpe o conteúdo atual antes de adicionar os novos itens
                        $('#itens_venda').empty();

                        // Verifique se há itens de venda encontrados na resposta
                        if (response.length > 0) {
                            // Itere sobre cada item retornado na resposta
                            $.each(response, function(index, item) {
                                // Crie o HTML para o item de venda e o produto associado
                                var itemHtml = `
                                    <div class="border-y px-2 py-1 cursor-pointer hover:bg-gray-200" data-item_produto_id="${item.produto.id}">
                                        <div class="grid grid-cols-6 items-center">
                                            <div class="col-span-5 flex flex-row items-start space-x-2">
                                                <span>${item.item_numero}</span><span id="produto_nome_${item.id}" class="truncate overflow-ellipsis text-sm">${item.produto.categoria.categoria_nome} ${item.produto.produto_descricao}<p>R$ <span id="item_valor_view_${item.id}">${item.item_venda_valor}</span> Qtd. <span id="item_qtd_view_${item.id}">${item.item_venda_quantidade}</span></p></span>
                                            </div>
                                            <span data-item_id="${item.id}" class="col-span-1 mx-auto toogle_item p-1 hover:bg-slate-400 cursor-pointer rotate-180 rounded-full transition duration-300 ease-in-out ">
                                                <i class="bx bx-chevron-up "></i>
                                            </span>
                                        </div>
                                        <div id="item_venda_${item.id}" class="px-5 pb-2 hidden bg-white">
                                            <x-input-label for="item_venda_quantidade" :value="__('Quantidade')" />
                                            <div class="flex items-stretch justify-evenly">
                                                <button type="button" id="minus-btn"
                                                    class="minus-btn w-full px-3 py-1 bg-gray-200 border border-gray-300 rounded-l-md hover:text-xl hover:font-semibold hover:bg-gray-300 focus:outline-none"
                                                    data-item_id="${item.id}" data-produto_preco_venda="${item.item_venda_valor_unitario}">-</button>
                                                <input type="text" id="item_venda_quantidade_${item.id}" name="item_venda_quantidade"
                                                    value="${item.item_venda_quantidade}"
                                                    class="w-20 text-center border border-gray-300 rounded-none focus:outline-none focus:ring-1 focus:ring-gray-400"
                                                    readonly>
                                                <button type="button" id="plus-btn"
                                                    class="plus-btn w-full px-3 py-1 bg-gray-200 border border-gray-300 rounded-r-md hover:text-xl hover:font-semibold hover:bg-gray-300 focus:outline-none"
                                                    data-item_id="${item.id}" data-produto_preco_venda="${item.item_venda_valor_unitario}">+</button>
                                            </div>
                                            `;
                                if (item.produto.ap_produto_id && Array.isArray(item.produto
                                        .ap_produto_id) && item.produto.ap_produto_id.length >
                                    0) {
                                    itemHtml += `
                                    <x-input-label :value="__('Adicionais')" />
                                    <div class="max-h-40 overflow-auto overflow-x-hidden">
                                `;

                                    item.produto.ap_produto_id.forEach(add => {
                                        // Busque o adicional relacionado no `adicionais_item_pedido` para pegar a quantidade
                                        const adicionalItemVenda = item
                                            .adicionais_item_venda ?
                                            item.adicionais_item_venda.find(aiv => aiv
                                                .aiv_adicional_id === add.adicional.id
                                            ) : null;

                                        // Quantidade padrão é 0, caso não haja relação em adicionaisItemPedido
                                        const quantidade = adicionalItemVenda ?
                                            adicionalItemVenda
                                            .aiv_quantidade : 0;

                                        itemHtml += `
                                        <div class="grid grid-cols-8 mt-2 items-center">
                                            <div class="col-span-1">
                                                <img id="imagem-preview" class="w-10 h-10 object-cover rounded-lg" src="/img/fotos_adicionais/${add.adicional.adicional_foto}" alt="Imagem do Adicional">
                                            </div>
                                            <div class="col-span-2">
                                                <span class="text-start">${add.adicional.adicional_nome}</span>
                                            </div>
                                            <div class="col-span-2">
                                                <span class="text-end">R$ ${add.adicional.adicional_valor}</span>
                                            </div>
                                            <div class="col-span-3">
                                                <input type="text" id="item_pedido_adicional_quantidade_${add.adicional.id}" name="item_pedido_adicional_quantidade"
                                                    value="${quantidade}"
                                                    class="max-w-14 text-center border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-gray-400"
                                                    readonly />
                                            </div>
                                        </div>
                                    `;
                                    });

                                    itemHtml += `</div>`;
                                }
                                itemHtml += `
                                        <div class="flex gap-x-1">
                                            <div class="flex flex-col">
                                                <x-input-label for="item_venda_adicionais" :value="__('Adicionais R$')" />
                                                <x-text-input id="item_venda_adicionais_${item.id}" name="item_venda_adicionais" type="text"
                                                class="item_desconto mt-1 w-full" value="${item.item_venda_valor_adicionais}" data-item_id="${item.id}" autocomplete="off" readonly />
                                            </div>
                                            <div class="flex flex-col">
                                                <x-input-label for="item_venda_desconto" :value="__('Desconto R$')" />
                                                <x-text-input id="item_venda_desconto_${item.id}" name="item_venda_desconto" type="text"
                                                class="item_desconto money mt-1 w-full" value="${item.item_venda_desconto}" data-item_id="${item.id}" data-produto_preco_venda="${item.produto.produto_preco_venda}" autocomplete="off" />
                                            </div>
                                            <div class="flex flex-col">
                                                <x-input-label for="item_venda_valor_unitario" :value="__('Valor Unit. R$')" />
                                                <x-text-input id="item_venda_valor_unitario_${item.id}" name="item_venda_valor_unitario" type="text"
                                                class="mt-1 w-full" value="${item.item_venda_valor_unitario}" autocomplete="off" readonly />
                                            </div>
                                            <div class="flex flex-col">
                                                <x-input-label for="item_venda_valor" :value="__('Valor R$')" />
                                                <x-text-input id="item_venda_valor_${item.id}" name="item_venda_valor" type="text"
                                                class="mt-1 w-full" value="${item.item_venda_valor}" autocomplete="off" readonly />
                                            </div>
                                        </div>
                                        <x-danger-button type="button" class="remove_item mt-1 w-full" id="remove_items" data-item_id="${item.id}" data-venda_id="${item.item_venda_venda_id}">Remover</x-danger-button>
                                    </div> `;

                                // Adicione o HTML do item de venda ao container
                                $('#itens_venda').append(itemHtml);
                                $("#carregando").addClass('hidden');
                            });

                        } else {
                            // Se não houver itens de venda inseridos, exiba uma mensagem indicando isso
                            $('#itens_venda').html(
                                '<p class="p-2">Nenhum produto encontrado para este venda!</p>');
                            $("#carregando").addClass('hidden');
                        }

                        //Abre o form do item do pedido
                        $(".toogle_item").click(function(e) {
                            e.preventDefault();
                            const item_id = $(this).data('item_id');
                            const itemPedido = $("#item_venda_" + item_id);
                            const produtoNome = $("#produto_nome_" + item_id);

                            // Verifica se o item já está visível
                            if (itemPedido.is(":visible")) {
                                // Se estiver visível, contrai o elemento com slideup
                                itemPedido.slideUp();
                                $(this).addClass('rotate-180');
                                produtoNome.removeClass("overflow-ellipsis");
                            } else {
                                // Se não estiver visível, expande o elemento com slidedown
                                itemPedido.slideDown();
                                $(this).removeClass('rotate-180');
                                produtoNome.addClass("overflow-ellipsis");
                            }
                        });

                        //Altera a quantidade e valor do item de venda
                        function atualizarQtdItemVenda(id, novaQtd) {
                            const venda_id = $("#venda_id").val();
                            $("#item_venda_quantidade_" + id).val(novaQtd.toString());
                            $("#item_qtd_view_" + id).html(novaQtd === 0.5 ? 'Meia' : novaQtd);

                            $.ajax({
                                type: "POST",
                                url: "{{ route('item_venda.update_qtd_valor') }}",
                                data: {
                                    item_id: id,
                                    venda_id,
                                    item_venda_quantidade: novaQtd,
                                    '_token': '{{ csrf_token() }}'
                                },
                                dataType: "json",
                                success: function(response) {
                                    if (response.item_venda_valor !== undefined) {
                                        $("#item_venda_valor_" + id).val(parseFloat(response.item_venda_valor).toFixed(2));
                                        $("#item_valor_view_" + id).html(parseFloat(response.item_venda_valor).toFixed(2));
                                    }
                                    listarVenda(venda_id);
                                },
                                error: function() {
                                    showToast('Erro ao atualizar a quantidade do item.');
                                }
                            });
                        }

                        $(".minus-btn").click(function(e) {
                            e.preventDefault();
                            const id  = $(this).data('item_id');
                            const cur = parseFloat($("#item_venda_quantidade_" + id).val()) || 1;
                            const nova = (cur === 1 || cur === 0.5) ? 0.5 : cur - 1;
                            atualizarQtdItemVenda(id, nova);
                        });

                        $(".plus-btn").click(function(e) {
                            e.preventDefault();
                            const id  = $(this).data('item_id');
                            const cur = parseFloat($("#item_venda_quantidade_" + id).val()) || 0.5;
                            const nova = cur === 0.5 ? 1 : cur + 1;
                            atualizarQtdItemVenda(id, nova);
                        });

                        let item_desconto;

                        // Função que atualiza o valor total quando insere qualquer valor no campo de desconto
                        $(".item_desconto").keyup(function(e) {
                            const item_id  = $(this).data('item_id');
                            const venda_id_desconto = $("#venda_id").val();
                            item_desconto  = parseFloat($(this).val().replace(',', '.'));
                            item_desconto  = item_desconto.toFixed(2);

                            if (isNaN(item_desconto)) {
                                item_desconto = 0.00;
                            }

                            const valorTotalItem = parseFloat($("#item_venda_valor_unitario_" + item_id).val())
                                * parseFloat($("#item_venda_quantidade_" + item_id).val());
                            const novoValorTotal = valorTotalItem - item_desconto;

                            $("#item_venda_valor_" + item_id).val(novoValorTotal.toFixed(2));

                            $.ajax({
                                type: "POST",
                                url: "{{ route('item_venda.update_desconto') }}",
                                data: {
                                    item_id,
                                    venda_id: venda_id_desconto,
                                    item_desconto,
                                    '_token': '{{ csrf_token() }}'
                                },
                                dataType: "json",
                                success: function(response) {
                                    $("#item_valor_view_" + item_id).html(novoValorTotal.toFixed(2));
                                    listarVenda(venda_id_desconto);
                                },
                                error: function() {
                                    showToast('Erro ao atualizar o desconto do item.');
                                }

                            });





                        });

                        // Função que executa quando o campo de desconto recebe foco
                        $(".item_desconto").focus(function(e) {
                            e.preventDefault();
                            // Armazena o valor atual do campo de desconto e limpa o campo
                            item_desconto = $(this).val();
                            $(this).val("");
                        });

                        // Função que executa quando o campo de desconto perde o foco
                        $(".item_desconto").blur(function(e) {
                            e.preventDefault();
                            // Verifica se o valor do desconto é diferente de vazio ou "0.00" ou "0,00"
                            if (item_desconto !== "" || item_desconto !== "0.00" ||
                                item_desconto !== "0,00") {
                                // Se for diferente, restaura o valor anterior do campo de desconto
                                $(this).val(item_desconto);
                            } else {
                                // Se for vazio ou "0.00" ou "0,00", define o valor como "0.00"
                                $(this).val("0.00");
                            }
                        });

                        //Remove item do pedido
                        $(".remove_item").click(function(e) {
                            e.preventDefault();
                            const item_id = $(this).data('item_id');
                            const venda_id = $(this).data('venda_id');
                            $.ajax({
                                type: "POST",
                                url: "{{ route('item_venda.remove_produto') }}",
                                data: {
                                    item_id,
                                    venda_id,
                                    '_token': '{{ csrf_token() }}'
                                },
                                dataType: "json",
                                success: function(response) {
                                    ListaItensVenda(venda_id);
                                },
                                error: function() {
                                    showToast('Erro ao remover o item da venda.');
                                }
                            });

                        });

                        $('.money').mask('#.##0,00', {
                            reverse: true
                        });
                    },
                    error: function() {
                        showToast('Erro ao listar itens da venda!');
                        $("#carregando").addClass('hidden');
                    }
                });
                listarVenda(venda_id);

            }

            function listarVenda(venda_id) {
                $.ajax({
                    type: "GET",
                    url: "{{ route('venda.listar') }}",
                    data: {
                        '_token': '{{ csrf_token() }}',
                        venda_id
                    },
                    dataType: "JSON",
                    success: function(response) {
                        if (response.length > 0) {
                            $.each(response, function(indexInArray, venda) {
                                $("#venda_valor_frete").val(venda.venda_valor_frete);
                                $("#venda_valor_itens").val(venda.venda_valor_itens);
                                $("#venda_valor_acrescimo").val(venda.venda_valor_acrescimo);
                                $("#venda_valor_desconto").val(venda.venda_valor_desconto);
                                $("#venda_valor_total").val(venda.venda_valor_total);
                                $("#venda_valor_pago").val(venda.venda_valor_pago);
                                $("#venda_valor_troco").val(venda.venda_valor_troco);
                                Alpine.store('venda').valorTotal = parseFloat(venda.venda_valor_total || 0).toFixed(2).replace('.', ',');
                            });
                        }

                    },
                    error: function() {
                        showToast('Erro ao listar itens da venda!');
                        $("#carregando").addClass('hidden');
                    }
                });
            }

            function CancelarVenda(venda_id) {
                if (venda_id) {
                    $.ajax({
                        type: 'POST',
                        url: "{{ route('venda.cancelar') }}",
                        data: {
                            '_token': '{{ csrf_token() }}',
                            venda_id
                        },
                        success: function(response) {
                        },
                        error: function() {
                        }
                    });
                }
            }

            function AtualizaValorFrete(venda_valor_frete, venda_id) {
                // Verifica se o valor é uma string vazia
                if (!venda_valor_frete.trim()) {
                    venda_valor_frete = "0.00";
                }

                // Converte o valor para float
                venda_valor_frete = parseFloat(venda_valor_frete.replace(',', '.'));

                // Verifica se a conversão resultou em NaN e ajusta o valor para 0.00
                if (isNaN(venda_valor_frete)) {
                    venda_valor_frete = 0.00;
                }

                // Envia a requisição AJAX com o valor do frete atualizado
                $.ajax({
                    type: "POST",
                    url: "{{ route('venda.update_valor_frete') }}",
                    data: {
                        venda_id,
                        venda_valor_frete,
                        '_token': '{{ csrf_token() }}'
                    },
                    dataType: "json",
                    success: function(response) {
                        listarVenda(venda_id);
                    },
                    error: function() {
                        showToast('Erro ao atualizar o valor do frete!');
                    }
                });
            }

            function InserePagamento() {


                const venda_id = $("#venda_id").val();

                if (venda_id === null || venda_id === "" || venda_id === undefined) {
                    // Uso da função com a promessa
                    IniciarVenda().then(vendaId => {
                        venda_id = vendaId;
                    }).catch(error => {
                    });
                }

                let pg_venda_valor_pagamento = $("#pg_venda_valor_pagamento").val();

                let pg_venda_valor_acrescimo = $("#pg_venda_valor_acrescimo").val();

                let pg_venda_valor_desconto = $("#pg_venda_valor_desconto").val();

                const pg_venda_opcaopagamento_id = $("#pg_venda_opcaopagamento_id").val();

                let pg_venda_cartao_id = null;

                const pg_venda_numero_autorizacao_cartao = $("#pg_venda_numero_autorizacao_cartao").val();

                // Encontra a opção de pagamento correspondente
                const selectedOption = opcao_pag.find(op => op.id == pg_venda_opcaopagamento_id);

                const desc = selectedOption.opcaopag_nome.toUpperCase();
                if (desc.includes('CARTÃO') || desc.includes('PIX')) {
                    pg_venda_cartao_id = $("#pg_venda_cartao_id").val();
                }
                $.ajax({
                    type: "POST",
                    url: "{{ route('pagamento_venda.store') }}",
                    data: {
                        venda_id,
                        pg_venda_opcaopagamento_id,
                        pg_venda_valor_pagamento,
                        pg_venda_valor_acrescimo,
                        pg_venda_valor_desconto,
                        pg_venda_cartao_id,
                        pg_venda_numero_autorizacao_cartao,
                        '_token': '{{ csrf_token() }}'
                    },
                    dataType: "json",
                    success: function(response) {

                        listarPagamentos(response.pagamentosVenda);
                        listarVenda(venda_id);
                        $("#pg_venda_valor_pagamento").val("");
                        $("#pg_venda_valor_acrescimo").val("");
                        $("#pg_venda_valor_desconto").val("");
                        $("#pg_venda_numero_autorizacao_cartao").val("");



                    },
                    error: function(error) {

                    }
                });

            }

            function listarPagamentos(pagamentosVenda) {
                // Limpa a tabela de pagamentos
                $('#body_tabela_pagamentos').empty();

                // Itera sobre os pagamentos e adiciona-os na tabela
                $.each(pagamentosVenda, function(index, pagamento) {
                    $('#body_tabela_pagamentos').append(`
                        <tr>
                            <td class="px-1 md:px-4">${index + 1}</td>
                            <td class="px-1 md:px-4">${pagamento.opcao_pagamento.opcaopag_nome}</td>
                            <td class="px-1 md:px-4 text-sm">${pagamento.opcao_pagamento.opcaopag_tipo_taxa}</td>
                            <td class="px-1 md:px-4">R$ ${pagamento.pg_venda_valor_desconto}</td>
                            <td class="px-1 md:px-4">R$ ${pagamento.pg_venda_valor_acrescimo}</td>
                            <td class="px-1 md:px-4">R$ ${pagamento.pg_venda_valor_pagamento}</td>
                            <td class="px-1 md:px-4">
                                <x-danger-button type="button" class="remover_pg_venda mt-1" data-pg_venda_id="${pagamento.id}">Remover</x-danger-button>
                            </td>
                        </tr>
                    `);
                });

                $(".remover_pg_venda").click(function(e) {
                    e.preventDefault();
                    $('#carregando').removeClass('hidden');

                    let pg_venda_id = $(this).data('pg_venda_id');

                    $.ajax({
                        type: "POST",
                        url: "{{ route('pagamento_venda.destroy') }}",
                        data: {
                            pg_venda_id,
                            '_token': '{{ csrf_token() }}'
                        },
                        dataType: "json",
                        success: function(response) {


                            listarPagamentos(response.pagamentosVenda);
                            listarVenda(response.venda.id);

                            $('#carregando').addClass('hidden');
                        },
                        error: function(error) {
                            $('#carregando').removeClass('hidden');
                        }
                    });
                });

            }

            window.addEventListener('load', function() {
                // Evita o botão "voltar"
                history.pushState(null, null, location.href);
                window.addEventListener('popstate', function() {
                    history.pushState(null, null, location.href);
                });
            });

            // Evita Backspace fora de inputs
            document.addEventListener('keydown', function(e) {
                const target = e.target || e.srcElement;
                const isInput = target.tagName === 'INPUT' || target.tagName === 'TEXTAREA';

                if (e.key === 'Backspace' && !isInput) {
                    e.preventDefault();
                }
            });

            // Cancela venda se recarregar ou fechar com valor 0
            window.addEventListener('beforeunload', function(e) {
                const totalVenda = parseFloat(document.getElementById('venda_valor_total').innerText);

                if (totalVenda === 0) {
                    const venda_id = $("#venda_id").val();
                    CancelarVenda(venda_id);
                }
            });

        });
    </script>
</x-app-layout>
