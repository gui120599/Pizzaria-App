<x-app-layout>
    <style>
        body { scrollbar-width: thin; scrollbar-color: #888 #f1f1f1; }
        ::-webkit-scrollbar { width: 12px; }
        ::-webkit-scrollbar-thumb { background-color: #888; border-radius: 10px; }
        ::-webkit-scrollbar-track { background-color: #f1f1f1; border-radius: 10px; }
        .nav-link.active { color: rgb(20 184 166); border-bottom-color: rgb(20 184 166); font-weight: bold; }
        [x-cloak] { display: none !important; }
    </style>
    <div id="toast-container" class="fixed top-4 right-4 z-[100] flex flex-col gap-2 pointer-events-none"></div>

    <div class="mx-auto p-2" x-data>

        {{-- Cabeçalho --}}
        <div class="flex items-center gap-3 mb-2 px-1">
            <a href="{{ route('sessao_caixa') }}"
               class="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-teal-600 transition">
                <i class='bx bx-chevron-left text-lg'></i> Caixa
            </a>
            <span class="text-gray-300">|</span>
            <h1 class="text-base font-bold text-teal-700 flex items-center gap-2">
                <i class='bx bx-edit'></i>
                Editar Venda <span class="text-gray-400 font-normal">Nº {{ $venda->id }}</span>
            </h1>
            <span class="ml-auto inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold
                {{ $venda->venda_status === 'INICIADA' ? 'bg-yellow-100 text-yellow-700 border border-yellow-200' : 'bg-gray-100 text-gray-600' }}">
                {{ $venda->venda_status }}
            </span>
        </div>

        <form action="{{ route('venda.salvar_venda', $venda->id) }}" method="post" id="formVenda" class="w-full">
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

                {{-- Coluna principal --}}
                <div class="lg:col-span-6 flex flex-col min-h-0" x-data>
                    <nav class="bg-transparent border-b border-gray-100">
                        <div class="w-full px-2">
                            <div class="overflow-x-auto">
                                <div class="flex space-x-3 h-8 min-w-max">
                                    @php $navBase = "nav-link cursor-pointer inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-light leading-5 text-gray-500 hover:text-teal-600 hover:border-teal-400 focus:outline-none transition duration-150 ease-in-out"; @endphp

                                    <div class="{{ $navBase }}"
                                        :class="{ 'active': $store.venda.secao === 'dados-section' }"
                                        @click="$store.venda.secao = 'dados-section'">
                                        <i class='bx bxs-receipt me-2'></i>{{ __('Dados') }}
                                    </div>

                                    <div class="{{ $navBase }}"
                                        :class="{ 'active': $store.venda.secao === 'produtos-section' }"
                                        @click="$store.venda.secao = 'produtos-section'">
                                        <i class='bx bxs-pizza me-2'></i>{{ __('Produtos') }}
                                    </div>

                                    <div id="pagamentos" class="{{ $navBase }}"
                                        :class="{ 'active': $store.venda.secao === 'pagamentos-section' }"
                                        @click="$store.venda.secao = 'pagamentos-section'">
                                        <i class='bx bx-dollar me-2'></i>{{ __('Pagamentos') }}
                                    </div>

                                    <div id="finalizar" class="{{ $navBase }}"
                                        :class="{ 'active': $store.venda.secao === 'finalizar-section' }"
                                        @click="handleFinalizarClick()">
                                        <i class='bx bx-check-double me-2'></i>{{ __('Finalizar Venda') }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </nav>

                    <div class="w-full overflow-auto">

                        {{-- SEÇÃO: DADOS --}}
                        <div class="dados-section secao p-3" x-show="$store.venda.secao === 'dados-section'" x-cloak>
                            <h2 class="flex items-center gap-x-2 text-lg font-bold text-teal-700 mb-3">
                                <i class='bx bxs-receipt'></i>
                                <span>{{ __('Dados da Venda') }}</span>
                            </h2>
                            <div class="grid grid-cols-1 md:grid-cols-6 gap-x-2 gap-y-4">
                                <div class="md:col-span-1">
                                    <x-input-label for="venda_id" :value="__('Cód. Venda')" />
                                    <x-text-input id="venda_id" name="venda_id" type="text" class="mt-1 w-full"
                                        autocomplete="off" value="{{ $venda->id }}" readonly />
                                </div>
                                <div class="md:col-span-1">
                                    <x-input-label for="venda_sessao_caixa_id" :value="__('Cód. Sessão Caixa')" />
                                    <x-text-input id="venda_sessao_caixa_id" name="venda_sessao_caixa_id" type="text"
                                        class="mt-1 w-full" value="{{ $venda->venda_sessao_caixa_id }}" readonly />
                                </div>
                                <div class="md:col-span-1">
                                    <x-input-label for="caixa_nome" :value="__('Caixa')" />
                                    <x-text-input id="caixa_nome" type="text" class="mt-1 w-full"
                                        value="{{ $venda->sessaoCaixa?->caixa?->caixa_nome ?? '—' }}" readonly />
                                </div>
                                <div class="md:col-span-3">
                                    <x-input-label for="sessao_caixa_funcionario" :value="__('Funcionário')" />
                                    <x-text-input id="sessao_caixa_funcionario" type="text" class="mt-1 w-full"
                                        value="{{ $venda->sessaoCaixa?->user?->name ?? '—' }}" readonly />
                                </div>

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
                                        class="absolute w-full bg-white rounded-lg px-2 py-3 shadow-lg shadow-green-400/10 hidden overflow-auto max-h-96 md:max-h-80 lg:max-h-72 border z-20">
                                        @foreach ($clientes as $cliente)
                                            <div class="border-b-2 hover:bg-teal-700 hover:text-white rounded-lg p-2 cursor-pointer transition duration-150 ease-in-out"
                                                onclick="selecionarCliente({{ $cliente }})">
                                                {{ $cliente->id }} - {{ $cliente->cliente_nome }}
                                            </div>
                                        @endforeach
                                    </div>
                                </div>

                                <div class="md:col-span-3">
                                    <x-input-label for="venda_cliente_cpf" :value="__('CPF Cliente')" />
                                    <x-text-input id="venda_cliente_cpf" name="venda_cliente_cpf" type="text"
                                        class="cpf mt-1 w-full" placeholder="000.000.000-00" />
                                </div>
                                <div class="md:col-span-3">
                                    <x-input-label for="venda_cliente_cnpj" :value="__('CNPJ Cliente')" />
                                    <x-text-input id="venda_cliente_cnpj" name="venda_cliente_cnpj" type="text"
                                        class="cnpj mt-1 w-full" placeholder="000.000.000/0000-00" />
                                </div>
                                <div class="md:col-span-2">
                                    <x-input-label for="venda_cliente_telefone" :value="__('Telefone Cliente')" />
                                    <x-text-input id="venda_cliente_telefone" name="venda_cliente_telefone"
                                        type="text" class="phone_ddd mt-1 w-full" placeholder="(00)9 0000-0000" />
                                </div>
                                <div class="md:col-span-4">
                                    <x-input-label for="venda_cliente_email" :value="__('Email Cliente')" />
                                    <x-text-input id="venda_cliente_email" name="venda_cliente_email" type="Email"
                                        class="mt-1 w-full" placeholder="exemplo@gmail.com" />
                                </div>
                            </div>
                        </div>

                        {{-- SEÇÃO: PRODUTOS --}}
                        <div class="produtos-section secao p-3" x-show="$store.venda.secao === 'produtos-section'" x-cloak>
                            <h2 class="flex items-center gap-x-2 text-lg font-bold text-teal-700 mb-3">
                                <i class='bx bxs-pizza'></i>
                                <span>{{ __('Produtos') }}</span>
                            </h2>
                            @foreach ($categorias as $categoria)
                                <div class="mb-4">
                                    <h3 class="text-sm font-semibold text-teal-700 uppercase tracking-wide mb-2">{{ $categoria->categoria_nome }}</h3>
                                    @if ($categoria->produtos->isEmpty())
                                        <p class="text-gray-400 text-sm">Nenhum produto nesta categoria.</p>
                                    @else
                                        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 2xl:grid-cols-5 gap-4">
                                            @foreach ($categoria->produtos as $produto)
                                                <div class="produto cursor-pointer hover:shadow-lg"
                                                    data-produto_id="{{ $produto->id }}"
                                                    data-produto_valor="{{ $produto->produto_preco_venda }}">
                                                    <div class="w-full bg-gray-50 border border-gray-200 p-2 rounded-xl flex items-start justify-between hover:border-teal-300 hover:bg-teal-50 transition duration-150 gap-1">
                                                        <div class="flex flex-col justify-center w-full">
                                                            <h2 class="text-gray-900 text-[8px] uppercase">
                                                                {{ $produto->categoria->categoria_nome }} {{ $produto->produto_descricao }}
                                                            </h2>
                                                            <div class="flex items-center">
                                                                <span class="text-gray-900 text-sm font-bold">R${{ str_replace('.', ',', $produto->produto_preco_venda) }}</span>
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

                        {{-- SEÇÃO: PAGAMENTOS --}}
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
                                        type="text" class="money mt-1 w-full" autocomplete="off" />
                                </div>
                                <div id="dadosTaxa"
                                    class="md:col-span-6 grid grid-cols-1 md:grid-cols-6 gap-x-2 gap-y-4 p-1"
                                    x-show="showTaxa" x-cloak>
                                    <div class="md:col-span-3">
                                        <x-input-label for="opcao_pag_taxa" :value="__('% Taxa')" />
                                        <x-text-input id="opcao_pag_taxa" name="opcao_pag_taxa" type="text"
                                            class="mt-1 w-full" readonly x-bind:value="taxa" />
                                    </div>
                                    <div class="md:col-span-3" x-show="isAcrescimo" x-cloak>
                                        <x-input-label for="pg_venda_valor_acrescimo" :value="__('Valor Acrescimo')" />
                                        <x-money-input id="pg_venda_valor_acrescimo" name="pg_venda_valor_acrescimo"
                                            type="text" class="money mt-1 w-full" readonly />
                                    </div>
                                    <div class="md:col-span-3" x-show="isDesconto" x-cloak>
                                        <x-input-label for="pg_venda_valor_desconto" :value="__('Valor Desconto')" />
                                        <x-money-input id="pg_venda_valor_desconto" name="pg_venda_valor_desconto"
                                            type="text" class="money mt-1 w-full" readonly />
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
                                        <x-input-label for="pg_venda_numero_autorizacao_cartao" :value="__('Nº Autorização')" />
                                        <x-text-input id="pg_venda_numero_autorizacao_cartao"
                                            name="pg_venda_numero_autorizacao_cartao" type="text"
                                            class="mt-1 w-full" />
                                    </div>
                                </div>
                                <div class="col-span-full">
                                    <x-input-label>{{ __('Registrar Pagamento') }}</x-input-label>
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
                                        <tbody id="body_tabela_pagamentos"></tbody>
                                    </table>
                                </div>
                            </div>
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
                                class="venda_valor_frete money w-full text-lg font-bold" autocomplete="off" />
                        </div>
                        <div>
                            <x-input-label for="venda_valor_itens">{{ __('Itens') }}</x-input-label>
                            <x-money-input id="venda_valor_itens" name="venda_valor_itens" readonly
                                class="money w-full text-lg font-bold" />
                        </div>
                        <div>
                            <x-input-label for="venda_valor_acrescimo">{{ __('Acréscimo') }}</x-input-label>
                            <x-money-input id="venda_valor_acrescimo" name="venda_valor_acrescimo" readonly
                                class="money w-full text-lg font-bold" />
                        </div>
                        <div>
                            <x-input-label for="venda_valor_desconto">{{ __('Desconto') }}</x-input-label>
                            <x-money-input id="venda_valor_desconto" name="venda_valor_desconto" readonly
                                class="money w-full text-lg font-bold" />
                        </div>
                        <div class="border-t border-gray-200 pt-2">
                            <x-input-label for="venda_valor_total" class="font-semibold text-teal-700">{{ __('Total') }}</x-input-label>
                            <x-money-input id="venda_valor_total" name="venda_valor_total" readonly
                                class="money w-full text-2xl font-bold text-teal-700" />
                        </div>
                        <div>
                            <x-input-label for="venda_valor_pago">{{ __('Pago') }}</x-input-label>
                            <x-money-input id="venda_valor_pago" name="venda_valor_pago" readonly
                                class="money w-full text-lg font-bold" />
                        </div>
                        <div>
                            <x-input-label for="venda_valor_troco">{{ __('Troco') }}</x-input-label>
                            <x-money-input id="venda_valor_troco" name="venda_valor_troco" readonly
                                class="money w-full text-lg font-bold" />
                        </div>
                    </div>
                </div>

            </div>
        </form>

        {{-- FAB: abre o drawer de itens --}}
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

        {{-- Drawer de itens --}}
        <div x-show="$store.venda.drawerOpen" class="fixed inset-0 z-50 flex flex-col justify-end" x-cloak>
            <div @click="$store.venda.drawerOpen = false"
                 class="absolute inset-0 bg-black/50 backdrop-blur-sm"
                 x-transition:enter="transition ease-out duration-300"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 x-transition:leave="transition ease-in duration-200"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0">
            </div>
            <div class="relative bg-white rounded-t-3xl max-h-[85vh] flex flex-col z-10 w-full md:max-w-2xl md:mx-auto shadow-2xl"
                 x-transition:enter="transition ease-out duration-300"
                 x-transition:enter-start="translate-y-full"
                 x-transition:enter-end="translate-y-0"
                 x-transition:leave="transition ease-in duration-200"
                 x-transition:leave-start="translate-y-0"
                 x-transition:leave-end="translate-y-full">
                <div class="flex justify-center pt-3 pb-2 shrink-0">
                    <div class="w-10 h-1 bg-gray-300 rounded-full"></div>
                </div>
                <div class="flex items-center justify-between px-4 pb-3 shrink-0 border-b border-gray-100">
                    <h3 class="text-base font-bold text-teal-700 flex items-center gap-2">
                        <i class='bx bx-list-ul'></i> Itens da Venda
                    </h3>
                    <button @click="$store.venda.drawerOpen = false" type="button"
                        class="p-1 hover:bg-gray-100 rounded-full transition">
                        <i class='bx bx-x text-xl'></i>
                    </button>
                </div>
                <div id="itens_venda" class="overflow-y-auto flex-grow px-2 py-1"></div>
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
            document.getElementById("venda_cliente_telefone").value = cliente.cliente_celular ?? '';
            document.getElementById("venda_cliente_email").value = cliente.cliente_email ?? '';
            document.getElementById("venda_cliente_cpf").value = cliente.cliente_cpf ?? '';
            document.getElementById("venda_cliente_cnpj").value = cliente.cliente_cnpj ?? '';
        }

        document.addEventListener('DOMContentLoaded', function() {
            const inputCliente  = document.getElementById('venda_cliente_nome');
            const listaClientes = document.getElementById('lista_clientes');

            inputCliente.addEventListener('focus', function() { listaClientes.classList.remove('hidden'); });
            inputCliente.addEventListener('blur',  function() { setTimeout(() => listaClientes.classList.add('hidden'), 200); });
            inputCliente.addEventListener('input', function() {
                const texto = inputCliente.value.toLowerCase();
                listaClientes.querySelectorAll('div').forEach(el => {
                    el.style.display = el.textContent.toLowerCase().includes(texto) ? 'block' : 'none';
                });
            });
        });
    </script>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.store('venda', {
                secao: 'dados-section',
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
            const colors = { error: 'bg-red-600 border-red-700', success: 'bg-teal-600 border-teal-700', warning: 'bg-yellow-500 border-yellow-600' };
            const icons  = { error: 'bx-error-circle', success: 'bx-check-circle', warning: 'bx-info-circle' };
            const toast  = document.createElement('div');
            toast.className = `pointer-events-auto flex items-center gap-3 px-4 py-3 rounded-xl border shadow-xl text-white text-sm font-medium max-w-xs transition-all duration-300 opacity-0 translate-x-4 ${colors[type] ?? colors.error}`;
            toast.innerHTML = `<i class="bx ${icons[type] ?? icons.error} text-lg shrink-0"></i><span>${message}</span>`;
            container.appendChild(toast);
            requestAnimationFrame(() => toast.classList.remove('opacity-0', 'translate-x-4'));
            setTimeout(() => {
                toast.classList.add('opacity-0', 'translate-x-4');
                toast.addEventListener('transitionend', () => toast.remove());
            }, 4000);
        }
    </script>

    <script type="module">
        $(document).ready(function() {

            const VENDA_ID = {{ $venda->id }};
            const opcao_pag = @json($opcoesPagamentos);

            // Pré-popula cliente se a venda já tiver um
            @if($venda->cliente)
                selecionarCliente(@json($venda->cliente));
            @endif

            // Carrega itens, totais e pagamentos existentes
            ListaItensVenda(VENDA_ID);
            listarVenda(VENDA_ID);
            @if($venda->pagamentos->isNotEmpty())
                listarPagamentos(@json($venda->pagamentos));
            @endif

            // Adicionar produto (venda já existe — sem IniciarVenda)
            $(".produto").click(function(e) {
                e.preventDefault();
                $("#carregando").removeClass('hidden');
                AdicionaProduto($(this).data('produto_id'), VENDA_ID);
            });

            // Frete
            $(".venda_valor_frete").keyup(function() {
                $("#carregando").removeClass('hidden');
                AtualizaValorFrete($("#venda_valor_frete").val(), VENDA_ID);
            });
            $(".venda_valor_frete").focus(function() { $(this).val(""); });
            $(".venda_valor_frete").blur(function()  { listarVenda(VENDA_ID); });

            // Cálculo acréscimo/desconto ao digitar valor do pagamento
            $("#pg_venda_valor_pagamento").keyup(function() {
                const valor_pag  = parseFloat($(this).val()) || 0;
                const valor_taxa = parseFloat($("#opcao_pag_taxa").val()) || 0;
                const opt = opcao_pag.find(op => String(op.id) === String($("#pg_venda_opcaopagamento_id").val()));
                if (!opt) return;
                if (opt.opcaopag_tipo_taxa === 'ACRESCENTAR') {
                    $("#pg_venda_valor_acrescimo").val((valor_pag * valor_taxa / 100).toFixed(2));
                } else if (opt.opcaopag_tipo_taxa === 'DESCONTAR') {
                    $("#pg_venda_valor_desconto").val((valor_pag * valor_taxa / 100).toFixed(2));
                }
            });

            $("#registar_pagamento").click(function(e) {
                e.preventDefault();
                InserePagamento();
            });
            $('#pg_venda_valor_pagamento, #pg_venda_numero_autorizacao_cartao').on('keypress', function(e) {
                if (e.which === 13) { e.preventDefault(); InserePagamento(); }
            });

            $("#venda_cliente_nome").keyup(function() {
                if (!$(this).val()) {
                    $("#venda_cliente_id, #venda_cliente_cpf, #venda_cliente_cnpj, #venda_cliente_telefone, #venda_cliente_email").val("");
                }
            });

            // Evita backspace fora de inputs
            document.addEventListener('keydown', function(e) {
                const tag = (e.target || e.srcElement).tagName;
                if (e.key === 'Backspace' && tag !== 'INPUT' && tag !== 'TEXTAREA') e.preventDefault();
            });

            // ------- FUNÇÕES -------

            function AdicionaProduto(produto_id, venda_id) {
                $.ajax({
                    type: "POST",
                    url: "{{ route('item_venda.add_produto') }}",
                    data: { '_token': '{{ csrf_token() }}', produto_id, venda_id },
                    dataType: "JSON",
                    success: function() { ListaItensVenda(venda_id); },
                    error: function() { showToast('Erro ao adicionar produto!'); $("#carregando").addClass('hidden'); }
                });
            }

            function ListaItensVenda(venda_id) {
                $.ajax({
                    type: "GET",
                    url: "{{ route('item_venda.listar') }}",
                    data: { '_token': '{{ csrf_token() }}', venda_id },
                    dataType: "JSON",
                    success: function(response) {
                        Alpine.store('venda').qtdItens = response.length;
                        $('#itens_venda').empty();

                        if (response.length > 0) {
                            $.each(response, function(index, item) {
                                var itemHtml = `
                                    <div class="border-y px-2 py-1 hover:bg-gray-200" data-item_produto_id="${item.produto.id}">
                                        <div class="grid grid-cols-6 items-center">
                                            <div class="col-span-5 flex flex-row items-start space-x-2">
                                                <span>${item.item_numero}</span>
                                                <span id="produto_nome_${item.id}" class="truncate overflow-ellipsis text-sm">
                                                    ${item.produto.categoria.categoria_nome} ${item.produto.produto_descricao}
                                                    <p>R$ <span id="item_valor_view_${item.id}">${item.item_venda_valor}</span>
                                                    Qtd. <span id="item_qtd_view_${item.id}">${item.item_venda_quantidade}</span></p>
                                                </span>
                                            </div>
                                            <span data-item_id="${item.id}" class="col-span-1 mx-auto toogle_item p-1 hover:bg-slate-400 cursor-pointer rotate-180 rounded-full transition">
                                                <i class="bx bx-chevron-up"></i>
                                            </span>
                                        </div>
                                        <div id="item_venda_${item.id}" class="px-5 pb-2 hidden bg-white">
                                            <x-input-label :value="__('Quantidade')" />
                                            <div class="flex items-stretch justify-evenly">
                                                <button type="button" class="minus-btn w-full px-3 py-1 bg-gray-200 border border-gray-300 rounded-l-md hover:font-semibold hover:bg-gray-300 focus:outline-none"
                                                    data-item_id="${item.id}" data-produto_preco_venda="${item.item_venda_valor_unitario}">-</button>
                                                <input type="text" id="item_venda_quantidade_${item.id}" value="${item.item_venda_quantidade}"
                                                    class="w-20 text-center border border-gray-300 rounded-none focus:outline-none" readonly>
                                                <button type="button" class="plus-btn w-full px-3 py-1 bg-gray-200 border border-gray-300 rounded-r-md hover:font-semibold hover:bg-gray-300 focus:outline-none"
                                                    data-item_id="${item.id}" data-produto_preco_venda="${item.item_venda_valor_unitario}">+</button>
                                            </div>
                                            <div class="flex gap-x-1 mt-2">
                                                <div class="flex flex-col">
                                                    <x-input-label :value="__('Adicionais R$')" />
                                                    <x-text-input id="item_venda_adicionais_${item.id}" type="text"
                                                        class="item_desconto mt-1 w-full" value="${item.item_venda_valor_adicionais}"
                                                        data-item_id="${item.id}" readonly />
                                                </div>
                                                <div class="flex flex-col">
                                                    <x-input-label :value="__('Desconto R$')" />
                                                    <x-text-input id="item_venda_desconto_${item.id}" type="text"
                                                        class="item_desconto money mt-1 w-full" value="${item.item_venda_desconto}"
                                                        data-item_id="${item.id}" data-produto_preco_venda="${item.produto.produto_preco_venda}" />
                                                </div>
                                                <div class="flex flex-col">
                                                    <x-input-label :value="__('Valor Unit. R$')" />
                                                    <x-text-input id="item_venda_valor_unitario_${item.id}" type="text"
                                                        class="mt-1 w-full" value="${item.item_venda_valor_unitario}" readonly />
                                                </div>
                                                <div class="flex flex-col">
                                                    <x-input-label :value="__('Valor R$')" />
                                                    <x-text-input id="item_venda_valor_${item.id}" type="text"
                                                        class="mt-1 w-full" value="${item.item_venda_valor}" readonly />
                                                </div>
                                            </div>
                                            <x-danger-button type="button" class="remove_item mt-1 w-full"
                                                data-item_id="${item.id}" data-venda_id="${item.item_venda_venda_id}">Remover</x-danger-button>
                                        </div>
                                    </div>`;
                                $('#itens_venda').append(itemHtml);
                                $("#carregando").addClass('hidden');
                            });
                        } else {
                            $('#itens_venda').html('<p class="p-2 text-gray-500">Nenhum item nesta venda.</p>');
                            $("#carregando").addClass('hidden');
                        }

                        // Toggle detalhe item
                        $(".toogle_item").click(function(e) {
                            e.preventDefault();
                            const item_id = $(this).data('item_id');
                            const panel   = $("#item_venda_" + item_id);
                            if (panel.is(":visible")) { panel.slideUp(); $(this).addClass('rotate-180'); }
                            else { panel.slideDown(); $(this).removeClass('rotate-180'); }
                        });

                        // Atualizar quantidade
                        function atualizarQtdItemVenda(id, novaQtd) {
                            $("#item_venda_quantidade_" + id).val(novaQtd.toString());
                            $("#item_qtd_view_" + id).html(novaQtd === 0.5 ? 'Meia' : novaQtd);
                            $.ajax({
                                type: "POST",
                                url: "{{ route('item_venda.update_qtd_valor') }}",
                                data: { item_id: id, venda_id: VENDA_ID, item_venda_quantidade: novaQtd, '_token': '{{ csrf_token() }}' },
                                dataType: "json",
                                success: function(r) {
                                    if (r.item_venda_valor !== undefined) {
                                        $("#item_venda_valor_" + id).val(parseFloat(r.item_venda_valor).toFixed(2));
                                        $("#item_valor_view_" + id).html(parseFloat(r.item_venda_valor).toFixed(2));
                                    }
                                    listarVenda(VENDA_ID);
                                },
                                error: function() { showToast('Erro ao atualizar quantidade.'); }
                            });
                        }

                        $(".minus-btn").click(function(e) {
                            e.preventDefault();
                            const id  = $(this).data('item_id');
                            const cur = parseFloat($("#item_venda_quantidade_" + id).val()) || 1;
                            atualizarQtdItemVenda(id, (cur === 1 || cur === 0.5) ? 0.5 : cur - 1);
                        });
                        $(".plus-btn").click(function(e) {
                            e.preventDefault();
                            const id  = $(this).data('item_id');
                            const cur = parseFloat($("#item_venda_quantidade_" + id).val()) || 0.5;
                            atualizarQtdItemVenda(id, cur === 0.5 ? 1 : cur + 1);
                        });

                        // Desconto por item
                        let item_desconto;
                        $(".item_desconto").keyup(function() {
                            const item_id  = $(this).data('item_id');
                            item_desconto  = parseFloat($(this).val().replace(',', '.'));
                            item_desconto  = isNaN(item_desconto) ? 0 : parseFloat(item_desconto.toFixed(2));
                            const valorUnit = parseFloat($("#item_venda_valor_unitario_" + item_id).val());
                            const qtd       = parseFloat($("#item_venda_quantidade_" + item_id).val());
                            const novo      = valorUnit * qtd - item_desconto;
                            $("#item_venda_valor_" + item_id).val(novo.toFixed(2));
                            $.ajax({
                                type: "POST",
                                url: "{{ route('item_venda.update_desconto') }}",
                                data: { item_id, venda_id: VENDA_ID, item_desconto, '_token': '{{ csrf_token() }}' },
                                dataType: "json",
                                success: function() {
                                    $("#item_valor_view_" + item_id).html(novo.toFixed(2));
                                    listarVenda(VENDA_ID);
                                },
                                error: function() { showToast('Erro ao atualizar desconto.'); }
                            });
                        });
                        $(".item_desconto").focus(function() { item_desconto = $(this).val(); $(this).val(""); });
                        $(".item_desconto").blur(function()  { $(this).val(item_desconto); });

                        // Remover item
                        $(".remove_item").click(function(e) {
                            e.preventDefault();
                            const item_id  = $(this).data('item_id');
                            const venda_id = $(this).data('venda_id');
                            $.ajax({
                                type: "POST",
                                url: "{{ route('item_venda.remove_produto') }}",
                                data: { item_id, venda_id, '_token': '{{ csrf_token() }}' },
                                dataType: "json",
                                success: function() { ListaItensVenda(VENDA_ID); },
                                error: function() { showToast('Erro ao remover item.'); }
                            });
                        });

                        $('.money').mask('#.##0,00', { reverse: true });
                    },
                    error: function() {
                        showToast('Erro ao listar itens!');
                        $("#carregando").addClass('hidden');
                    }
                });
                listarVenda(venda_id);
            }

            function listarVenda(venda_id) {
                $.ajax({
                    type: "GET",
                    url: "{{ route('venda.listar') }}",
                    data: { '_token': '{{ csrf_token() }}', venda_id },
                    dataType: "JSON",
                    success: function(response) {
                        if (response.length > 0) {
                            const v = response[0];
                            $("#venda_valor_frete").val(v.venda_valor_frete);
                            $("#venda_valor_itens").val(v.venda_valor_itens);
                            $("#venda_valor_acrescimo").val(v.venda_valor_acrescimo);
                            $("#venda_valor_desconto").val(v.venda_valor_desconto);
                            $("#venda_valor_total").val(v.venda_valor_total);
                            $("#venda_valor_pago").val(v.venda_valor_pago);
                            $("#venda_valor_troco").val(v.venda_valor_troco);
                            Alpine.store('venda').valorTotal = parseFloat(v.venda_valor_total || 0).toFixed(2).replace('.', ',');
                        }
                    },
                    error: function() { showToast('Erro ao carregar totais!'); }
                });
            }

            function AtualizaValorFrete(valor, venda_id) {
                if (!valor || !valor.trim()) valor = "0.00";
                const frete = parseFloat(valor.replace(',', '.'));
                $.ajax({
                    type: "POST",
                    url: "{{ route('venda.update_valor_frete') }}",
                    data: { venda_id, venda_valor_frete: isNaN(frete) ? 0 : frete, '_token': '{{ csrf_token() }}' },
                    dataType: "json",
                    success: function() { listarVenda(venda_id); },
                    error: function() { showToast('Erro ao atualizar frete!'); }
                });
            }

            function InserePagamento() {
                const selectedId = $("#pg_venda_opcaopagamento_id").val();
                const opt = opcao_pag.find(op => String(op.id) === String(selectedId));
                const desc = (opt?.opcaopag_nome ?? '').toUpperCase();
                const cartao_id = (desc.includes('CARTÃO') || desc.includes('PIX')) ? $("#pg_venda_cartao_id").val() : null;

                $.ajax({
                    type: "POST",
                    url: "{{ route('pagamento_venda.store') }}",
                    data: {
                        venda_id: VENDA_ID,
                        pg_venda_opcaopagamento_id:        selectedId,
                        pg_venda_valor_pagamento:          $("#pg_venda_valor_pagamento").val(),
                        pg_venda_valor_acrescimo:          $("#pg_venda_valor_acrescimo").val(),
                        pg_venda_valor_desconto:           $("#pg_venda_valor_desconto").val(),
                        pg_venda_cartao_id:                cartao_id,
                        pg_venda_numero_autorizacao_cartao: $("#pg_venda_numero_autorizacao_cartao").val(),
                        '_token': '{{ csrf_token() }}'
                    },
                    dataType: "json",
                    success: function(response) {
                        listarPagamentos(response.pagamentosVenda);
                        listarVenda(VENDA_ID);
                        $("#pg_venda_valor_pagamento, #pg_venda_valor_acrescimo, #pg_venda_valor_desconto, #pg_venda_numero_autorizacao_cartao").val("");
                    },
                    error: function() { showToast('Erro ao registrar pagamento!'); }
                });
            }

            function listarPagamentos(pagamentosVenda) {
                $('#body_tabela_pagamentos').empty();
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
                    const pg_venda_id = $(this).data('pg_venda_id');
                    $('#carregando').removeClass('hidden');
                    $.ajax({
                        type: "POST",
                        url: "{{ route('pagamento_venda.destroy') }}",
                        data: { pg_venda_id, '_token': '{{ csrf_token() }}' },
                        dataType: "json",
                        success: function(response) {
                            listarPagamentos(response.pagamentosVenda);
                            listarVenda(response.venda.id);
                            $('#carregando').addClass('hidden');
                        },
                        error: function() { showToast('Erro ao remover pagamento!'); $('#carregando').addClass('hidden'); }
                    });
                });
            }

        });
    </script>
</x-app-layout>
