<x-app-layout>
    <style>[x-cloak] { display: none !important; }</style>
    <div id="toast-container" class="fixed top-4 right-4 z-[100] flex flex-col gap-2 pointer-events-none"></div>

    <div class="py-2 px-2 sm:px-4">

        {{-- Cabeçalho --}}
        <div class="flex items-center gap-3 mb-4">
            <a href="{{ route('sessao_caixa') }}"
               class="flex items-center gap-1 text-sm text-gray-500 hover:text-teal-600 transition">
                <i class='bx bx-arrow-back'></i> Caixa
            </a>
            <span class="text-gray-300">|</span>
            <h1 class="text-lg font-bold text-gray-800">
                Editar Venda <span class="text-teal-600">#{{ $venda->id }}</span>
            </h1>
            <span class="px-2 py-0.5 rounded-full text-xs font-bold
                @if($venda->venda_status === 'CANCELADA') bg-red-100 text-red-700
                @elseif($venda->venda_status === 'FINALIZADA') bg-green-100 text-green-700
                @elseif($venda->venda_status === 'INICIADA') bg-yellow-100 text-yellow-700
                @else bg-blue-100 text-blue-700 @endif">
                {{ $venda->venda_status }}
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

        <div class="grid grid-cols-1 xl:grid-cols-5 gap-4">

            {{-- COLUNA ESQUERDA: Seletor de produtos --}}
            <div class="xl:col-span-3"
                 x-data="{
                     catId: null,
                     busca: '',
                     mostra(nome, catId) {
                         const textoOk = this.busca === '' || nome.toLowerCase().includes(this.busca.toLowerCase());
                         const catOk   = this.catId === null || this.catId === catId;
                         return textoOk && catOk;
                     }
                 }">

                <div class="bg-white shadow-sm rounded-xl overflow-hidden">

                    {{-- Busca + Categorias --}}
                    <div class="px-4 pt-4 pb-3 border-b border-gray-100 space-y-3">

                        {{-- Busca --}}
                        <div class="relative">
                            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none"
                                 xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                            </svg>
                            <input x-model.debounce.200ms="busca" type="text" placeholder="Buscar produto..."
                                   class="w-full pl-9 pr-4 py-2 text-sm border border-gray-300 rounded-xl bg-white text-gray-700 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent">
                        </div>

                        {{-- Categorias --}}
                        <div class="flex gap-2 overflow-x-auto pb-1">
                            <button @click="catId = null" type="button"
                                    class="shrink-0 px-3 py-1.5 rounded-full text-xs font-semibold border-2 transition-colors"
                                    :class="catId === null
                                        ? 'border-teal-500 bg-teal-50 text-teal-700'
                                        : 'border-gray-200 bg-white text-gray-500 hover:border-gray-400'">
                                Todos
                            </button>
                            @foreach($categorias as $categoria)
                                <button @click="catId = {{ $categoria->id }}" type="button"
                                        class="shrink-0 px-3 py-1.5 rounded-full text-xs font-semibold border-2 transition-colors"
                                        :class="catId === {{ $categoria->id }}
                                            ? 'border-teal-500 bg-teal-50 text-teal-700'
                                            : 'border-gray-200 bg-white text-gray-500 hover:border-gray-400'">
                                    {{ $categoria->categoria_nome }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    {{-- Grid de produtos --}}
                    <div class="divide-y divide-gray-100 overflow-y-auto" style="max-height: clamp(18rem, 60vh, 50rem)">
                        @foreach($categorias as $categoria)
                            @foreach($categoria->produtos as $produto)
                                @php
                                    $precoVenda = (float) $produto->produto_preco_venda;
                                    $precoPromo = (float) $produto->produto_preco_promocional;
                                    $temPromo   = $precoPromo > 0 && $precoPromo < $precoVenda;
                                    $precoFinal = $temPromo ? $precoPromo : $precoVenda;
                                @endphp
                                <div x-show="mostra('{{ addslashes($produto->produto_descricao) }}', {{ $categoria->id }})"
                                     class="relative p-2 flex items-start gap-2 bg-white hover:bg-gray-50 transition-colors">

                                    {{-- Imagem --}}
                                    <div class="relative shrink-0">
                                        <img src="{{ $produto->getImagemUrl() }}"
                                             alt="{{ $produto->produto_descricao }}"
                                             class="w-20 h-16 object-cover rounded-lg bg-gray-100"
                                             onerror="this.src=''">
                                        @if($temPromo)
                                            <span class="absolute top-1 left-1 bg-orange-500 text-white text-[9px] font-bold px-1 py-0.5 rounded flex items-center gap-0.5">
                                                <i class='bx bxs-purchase-tag text-[9px]'></i> PROMO
                                            </span>
                                        @endif
                                    </div>

                                    {{-- Info --}}
                                    <div class="flex-1 min-w-0 flex flex-col justify-between pr-10 min-h-[4rem]">
                                        <div>
                                            <span class="inline-block text-[9px] font-semibold uppercase tracking-wide text-orange-600 bg-orange-50 rounded px-1 mb-0.5">
                                                {{ $categoria->categoria_nome }}
                                            </span>
                                            <p class="text-gray-800 text-sm font-semibold leading-snug line-clamp-2">{{ $produto->produto_descricao }}</p>
                                        </div>
                                        <div>
                                            @if($temPromo)
                                                <span class="text-gray-400 text-xs line-through block leading-tight">R$ {{ number_format($precoVenda, 2, ',', '.') }}</span>
                                                <span class="text-green-600 text-base font-bold">R$ {{ number_format($precoFinal, 2, ',', '.') }}</span>
                                            @else
                                                <span class="text-gray-800 text-base font-bold">R$ {{ number_format($precoFinal, 2, ',', '.') }}</span>
                                            @endif
                                        </div>
                                    </div>

                                    {{-- Botão + --}}
                                    <button type="button"
                                            class="add-produto absolute bottom-2 right-2 w-8 h-8 rounded-full bg-green-500 hover:bg-green-400 active:scale-90 text-white text-xl font-bold flex items-center justify-center shadow transition-all z-10"
                                            data-produto_id="{{ $produto->id }}"
                                            data-produto_valor="{{ $produto->produto_preco_venda }}">+</button>
                                </div>
                            @endforeach
                        @endforeach

                        <div class="flex flex-col items-center justify-center py-12 text-gray-400"
                             x-show="!document.querySelector('[x-show]:not([style*=\'none\'])') && busca !== ''"
                             style="display:none">
                            <svg class="w-8 h-8 mb-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                            </svg>
                            <p class="text-sm">Nenhum produto encontrado</p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- COLUNA DIREITA: Dados, itens, totais e pagamento --}}
            <div class="xl:col-span-2 flex flex-col gap-4">

                <form id="formVenda" action="{{ route('venda.salvar_venda', $venda->id) }}" method="POST">
                    @csrf
                    <input type="hidden" name="venda_id" value="{{ $venda->id }}">
                    <input type="hidden" name="venda_sessao_caixa_id" value="{{ $venda->venda_sessao_caixa_id }}">

                    <div class="flex flex-col gap-4">

                        {{-- Sessão --}}
                        <div class="bg-white shadow-sm rounded-xl p-4">
                            <p class="flex items-center gap-2 text-sm font-bold text-teal-700 mb-2">
                                <i class='bx bxs-receipt'></i> Sessão
                            </p>
                            <div class="grid grid-cols-3 gap-2 text-xs">
                                <div>
                                    <span class="block font-semibold text-gray-500 uppercase tracking-wide mb-0.5">Venda</span>
                                    <span class="text-gray-800 font-medium">#{{ $venda->id }}</span>
                                </div>
                                <div>
                                    <span class="block font-semibold text-gray-500 uppercase tracking-wide mb-0.5">Caixa</span>
                                    <span class="text-gray-800 font-medium">{{ $venda->sessaoCaixa?->caixa?->caixa_nome ?? '—' }}</span>
                                </div>
                                <div>
                                    <span class="block font-semibold text-gray-500 uppercase tracking-wide mb-0.5">Operador</span>
                                    <span class="text-gray-800 font-medium">{{ $venda->sessaoCaixa?->user?->name ?? '—' }}</span>
                                </div>
                            </div>
                        </div>

                        {{-- Cliente --}}
                        <div class="bg-white shadow-sm rounded-xl p-4 space-y-3"
                             x-data="{
                                 tel: '{{ addslashes($venda->cliente?->cliente_celular ?? '') }}',
                                 clienteId: {{ $venda->venda_cliente_id ?? 'null' }},
                                 nome: '{{ addslashes($venda->cliente?->cliente_nome ?? '') }}',
                                 encontrado: {{ $venda->venda_cliente_id ? 'true' : 'false' }},
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
                            <input type="hidden" name="venda_cliente_id" :value="clienteId">
                            <input type="hidden" name="venda_cliente_nome" :value="nome">

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

                            <div x-show="encontrado && !buscando"
                                 class="flex items-center gap-2 p-2 bg-green-50 border border-green-200 rounded-lg">
                                <i class='bx bx-user-check text-green-600'></i>
                                <span class="text-sm text-green-700 font-medium" x-text="nome"></span>
                            </div>

                            <div x-show="!encontrado && !buscando && tel.replace(/\D/g,'').length >= 8">
                                <x-input-label value="Nome do cliente (novo)" />
                                <input type="text" x-model="nome" placeholder="Nome completo"
                                       class="mt-1 w-full border-gray-300 rounded-lg shadow-sm text-sm focus:ring-teal-500 focus:border-teal-500">
                            </div>
                        </div>

                        {{-- Itens --}}
                        <div class="bg-white shadow-sm rounded-xl p-4">
                            <p class="flex items-center gap-2 text-sm font-bold text-teal-700 mb-3">
                                <i class='bx bx-cart'></i> Itens
                                <span id="badge-qtd-itens"
                                      class="ml-auto bg-teal-100 text-teal-700 rounded-full px-2 py-0.5 text-xs font-bold hidden"></span>
                            </p>
                            <div id="itens_venda" class="space-y-1 max-h-72 overflow-y-auto">
                                <p class="text-sm text-gray-400 text-center py-6">Nenhum item adicionado.</p>
                            </div>
                        </div>

                        {{-- Totais --}}
                        <div class="bg-white shadow-sm rounded-xl p-4 space-y-3">
                            <p class="flex items-center gap-2 text-sm font-bold text-teal-700">
                                <i class='bx bx-calculator'></i> Totais
                            </p>

                            <div>
                                <x-input-label for="venda_valor_frete" value="Frete" />
                                <x-money-input id="venda_valor_frete" name="venda_valor_frete"
                                    class="venda_valor_frete money mt-1 w-full" autocomplete="off" />
                            </div>

                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <x-input-label for="venda_valor_itens" value="Itens" />
                                    <x-money-input id="venda_valor_itens" name="venda_valor_itens" readonly
                                        class="money mt-1 w-full bg-gray-50" />
                                </div>
                                <div>
                                    <x-input-label for="venda_valor_acrescimo" value="Acréscimo" />
                                    <x-money-input id="venda_valor_acrescimo" name="venda_valor_acrescimo" readonly
                                        class="money mt-1 w-full bg-gray-50" />
                                </div>
                                <div>
                                    <x-input-label for="venda_valor_desconto" value="Desconto" />
                                    <x-money-input id="venda_valor_desconto" name="venda_valor_desconto" readonly
                                        class="money mt-1 w-full bg-gray-50" />
                                </div>
                                <div>
                                    <x-input-label for="venda_valor_pago" value="Pago" />
                                    <x-money-input id="venda_valor_pago" name="venda_valor_pago" readonly
                                        class="money mt-1 w-full bg-gray-50" />
                                </div>
                            </div>

                            <div class="border-t border-gray-100 pt-3">
                                <x-input-label for="venda_valor_total" value="Total" />
                                <x-money-input id="venda_valor_total" name="venda_valor_total" readonly
                                    class="money mt-1 w-full text-xl font-bold text-teal-700" />
                            </div>

                            <div>
                                <x-input-label for="venda_valor_troco" value="Troco" />
                                <x-money-input id="venda_valor_troco" name="venda_valor_troco" readonly
                                    class="money mt-1 w-full bg-gray-50" />
                            </div>
                        </div>

                    </div>
                </form>

                {{-- Pagamento --}}
                <div class="bg-white shadow-sm rounded-xl p-4 space-y-3"
                     x-data="{
                         opcoes: @js($opcoesPagamentos),
                         selectedId: '',
                         get opcaoSelecionada() { return this.opcoes.find(o => String(o.id) === String(this.selectedId)) ?? null; },
                         get showTaxa()   { const t = this.opcaoSelecionada?.opcaopag_tipo_taxa; return t === 'ACRESCENTAR' || t === 'DESCONTAR'; },
                         get showCartao() { const n = (this.opcaoSelecionada?.opcaopag_nome ?? '').toUpperCase(); return n.includes('CARTÃO') || n.includes('PIX'); },
                         get isAcrescimo(){ return this.opcaoSelecionada?.opcaopag_tipo_taxa === 'ACRESCENTAR'; },
                         get isDesconto() { return this.opcaoSelecionada?.opcaopag_tipo_taxa === 'DESCONTAR'; },
                         get taxa()       { return parseFloat(this.opcaoSelecionada?.opcaopag_valor_percentual_taxa ?? 0) || 0; }
                     }">

                    <p class="flex items-center gap-2 text-sm font-bold text-teal-700">
                        <i class='bx bx-credit-card'></i> Pagamento
                    </p>

                    <div>
                        <x-input-label for="pg_venda_opcaopagamento_id" value="Tipo de pagamento" />
                        <x-select-input :options="$opcoesPagamentos" value-field="id" display-field="opcaopag_nome"
                            id="pg_venda_opcaopagamento_id" name="pg_venda_opcaopagamento_id"
                            class="mt-1 w-full" x-model="selectedId" />
                    </div>

                    <div>
                        <x-input-label for="pg_venda_valor_pagamento" value="Valor" />
                        <x-money-input id="pg_venda_valor_pagamento" name="pg_venda_valor_pagamento"
                            type="text" class="money mt-1 w-full" autocomplete="off" />
                    </div>

                    <div class="grid grid-cols-2 gap-2" x-show="showTaxa" x-cloak>
                        <div>
                            <x-input-label for="opcao_pag_taxa" value="% Taxa" />
                            <x-text-input id="opcao_pag_taxa" name="opcao_pag_taxa" type="text"
                                class="mt-1 w-full" readonly x-bind:value="taxa" />
                        </div>
                        <div x-show="isAcrescimo" x-cloak>
                            <x-input-label for="pg_venda_valor_acrescimo" value="Acréscimo" />
                            <x-money-input id="pg_venda_valor_acrescimo" name="pg_venda_valor_acrescimo"
                                type="text" class="money mt-1 w-full" readonly />
                        </div>
                        <div x-show="isDesconto" x-cloak>
                            <x-input-label for="pg_venda_valor_desconto" value="Desconto" />
                            <x-money-input id="pg_venda_valor_desconto" name="pg_venda_valor_desconto"
                                type="text" class="money mt-1 w-full" readonly />
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-2" x-show="showCartao" x-cloak>
                        <div>
                            <x-input-label for="pg_venda_cartao_id" value="Bandeira" />
                            <x-select-input :options="$cartoes" value-field="id" display-field="cartao_bandeira"
                                id="pg_venda_cartao_id" name="pg_venda_cartao_id" class="mt-1 w-full" />
                        </div>
                        <div>
                            <x-input-label for="pg_venda_numero_autorizacao_cartao" value="Nº Autorização" />
                            <x-text-input id="pg_venda_numero_autorizacao_cartao"
                                name="pg_venda_numero_autorizacao_cartao" type="text" class="mt-1 w-full" />
                        </div>
                    </div>

                    <button type="button" id="registar_pagamento"
                            class="w-full flex items-center justify-center gap-2 py-2.5 bg-teal-600 hover:bg-teal-700 active:bg-teal-800 text-white rounded-lg text-sm font-bold transition-colors">
                        <i class='bx bx-check-square text-base'></i> Registrar Pagamento
                    </button>

                    {{-- Pagamentos registrados --}}
                    <div>
                        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">Pagamentos registrados</p>
                        <div class="overflow-x-auto">
                            <table class="w-full text-xs">
                                <thead>
                                    <tr class="border-b border-gray-100 text-gray-500">
                                        <th class="pb-1.5 text-left font-semibold">#</th>
                                        <th class="pb-1.5 text-left font-semibold">Tipo</th>
                                        <th class="pb-1.5 text-right font-semibold">Valor</th>
                                        <th class="pb-1.5"></th>
                                    </tr>
                                </thead>
                                <tbody id="body_tabela_pagamentos"></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="pb-28"></div>
            </div>
        </div>
    </div>

    {{-- Loading overlay --}}
    <div id="carregando"
         class="hidden fixed inset-0 z-[90] flex justify-center items-center bg-slate-700/40">
        <div class="bg-white rounded-2xl p-6 shadow-2xl text-center">
            <i class='bx bx-loader-circle bx-spin bx-rotate-90 text-4xl text-teal-600'></i>
            <p class="mt-2 text-sm font-medium text-gray-600">Carregando...</p>
        </div>
    </div>

    {{-- FAB: Finalizar Venda --}}
    <div class="fixed bottom-5 inset-x-0 px-4 z-40 flex justify-end pointer-events-none">
        <button type="button" onclick="handleFinalizarClick()"
                class="pointer-events-auto py-3 px-6 bg-teal-600 hover:bg-teal-700 active:bg-teal-800 rounded-2xl text-white font-bold flex items-center gap-2 shadow-xl transition-colors whitespace-nowrap">
            <i class='bx bx-check-double text-lg'></i>
            <span class="text-sm uppercase tracking-widest">Finalizar Venda</span>
        </button>
    </div>

    {{-- FAB: Cancelar Venda --}}
    @if(!in_array($venda->venda_status, ['CANCELADA', 'FINALIZADA']))
        <div class="fixed bottom-5 inset-x-0 px-4 z-[39] flex justify-start pointer-events-none">
            <form action="{{ route('venda.cancelar_web', $venda->id) }}" method="POST"
                  x-data
                  @submit.prevent="if (confirm('Cancelar a venda #{{ $venda->id }}? Esta ação não pode ser desfeita.')) $el.submit()">
                @csrf
                <button type="submit"
                        class="pointer-events-auto py-2.5 px-5 bg-red-600 hover:bg-red-700 active:bg-red-800 rounded-2xl text-white font-bold flex items-center gap-2 shadow-xl transition-colors whitespace-nowrap">
                    <i class='bx bx-x-circle text-lg'></i>
                    <span class="text-sm uppercase tracking-widest">Cancelar</span>
                </button>
            </form>
        </div>
    @endif

    <script>
        function handleFinalizarClick() {
            const rawPago  = document.getElementById('venda_valor_pago').value.replace(/\./g, '').replace(',', '.') || '0';
            const rawTotal = document.getElementById('venda_valor_total').value.replace(/\./g, '').replace(',', '.') || '0';
            const valorPago  = parseFloat(rawPago)  || 0;
            const valorTotal = parseFloat(rawTotal) || 0;
            if (valorTotal > 0 && valorPago >= valorTotal) {
                document.getElementById('formVenda').submit();
                return;
            }
            showToast('Valor pago insuficiente para finalizar a venda!', 'warning');
            document.getElementById('pg_venda_valor_pagamento')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            document.getElementById('pg_venda_valor_pagamento')?.focus();
        }

        function showToast(message, type = 'error') {
            const container = document.getElementById('toast-container');
            const colors = {
                error:   'bg-red-600 border-red-700',
                success: 'bg-teal-600 border-teal-700',
                warning: 'bg-yellow-500 border-yellow-600'
            };
            const icons = {
                error:   'bx-error-circle',
                success: 'bx-check-circle',
                warning: 'bx-info-circle'
            };
            const toast = document.createElement('div');
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
        $(document).ready(function () {

            const VENDA_ID = {{ $venda->id }};
            const opcao_pag = @json($opcoesPagamentos);

            // Carrega estado inicial
            ListaItensVenda(VENDA_ID);
            listarVenda(VENDA_ID);
            @if($venda->pagamentos->isNotEmpty())
                listarPagamentos(@json($venda->pagamentos));
            @endif

            // ── Adicionar produto ────────────────────────────────────────────
            $(document).on('click', '.add-produto', function (e) {
                e.stopPropagation();
                $("#carregando").removeClass('hidden');
                AdicionaProduto($(this).data('produto_id'), VENDA_ID);
            });

            // ── Frete ────────────────────────────────────────────────────────
            $(".venda_valor_frete").keyup(function () {
                $("#carregando").removeClass('hidden');
                AtualizaValorFrete($("#venda_valor_frete").val(), VENDA_ID);
            });
            $(".venda_valor_frete").focus(function () { $(this).val(""); });
            $(".venda_valor_frete").blur(function ()  { listarVenda(VENDA_ID); });

            // ── Cálculo taxa ao digitar valor do pagamento ───────────────────
            $("#pg_venda_valor_pagamento").keyup(function () {
                const valor_pag  = parseFloat($(this).val().replace(',', '.')) || 0;
                const valor_taxa = parseFloat($("#opcao_pag_taxa").val()) || 0;
                const opt = opcao_pag.find(op => String(op.id) === String($("#pg_venda_opcaopagamento_id").val()));
                if (!opt) return;
                if (opt.opcaopag_tipo_taxa === 'ACRESCENTAR') {
                    $("#pg_venda_valor_acrescimo").val((valor_pag * valor_taxa / 100).toFixed(2));
                } else if (opt.opcaopag_tipo_taxa === 'DESCONTAR') {
                    $("#pg_venda_valor_desconto").val((valor_pag * valor_taxa / 100).toFixed(2));
                }
            });

            // ── Registrar pagamento ──────────────────────────────────────────
            $("#registar_pagamento").click(function (e) {
                e.preventDefault();
                InserePagamento();
            });
            $('#pg_venda_valor_pagamento, #pg_venda_numero_autorizacao_cartao').on('keypress', function (e) {
                if (e.which === 13) { e.preventDefault(); InserePagamento(); }
            });

            // Evita backspace fora de inputs
            document.addEventListener('keydown', function (e) {
                const tag = (e.target || e.srcElement).tagName;
                if (e.key === 'Backspace' && tag !== 'INPUT' && tag !== 'TEXTAREA') e.preventDefault();
            });

            // ────────────────────────────────────────────────────────────────
            // FUNÇÕES
            // ────────────────────────────────────────────────────────────────

            function AdicionaProduto(produto_id, venda_id) {
                $.ajax({
                    type: "POST",
                    url: "{{ route('item_venda.add_produto') }}",
                    data: { '_token': '{{ csrf_token() }}', produto_id, venda_id },
                    dataType: "JSON",
                    success: function () { ListaItensVenda(venda_id); },
                    error: function () {
                        showToast('Erro ao adicionar produto!');
                        $("#carregando").addClass('hidden');
                    }
                });
            }

            function ListaItensVenda(venda_id) {
                $.ajax({
                    type: "GET",
                    url: "{{ route('item_venda.listar') }}",
                    data: { '_token': '{{ csrf_token() }}', venda_id },
                    dataType: "JSON",
                    success: function (response) {
                        const container = $('#itens_venda');
                        const badge     = $('#badge-qtd-itens');
                        container.empty();

                        if (response.length > 0) {
                            badge.text(response.length).removeClass('hidden');
                            $.each(response, function (index, item) {
                                container.append(`
                                    <div class="border border-gray-100 rounded-xl bg-white hover:bg-gray-50 transition-colors" data-item_produto_id="${item.produto.id}">
                                        <div class="flex items-center gap-2 px-3 py-2">
                                            <div class="flex-1 min-w-0">
                                                <p class="text-[10px] font-semibold uppercase tracking-wide text-orange-600 leading-tight">
                                                    ${item.produto.categoria.categoria_nome}
                                                </p>
                                                <p class="text-sm font-medium text-gray-800 truncate" id="produto_nome_${item.id}">
                                                    ${item.produto.produto_descricao}
                                                </p>
                                                <p class="text-xs text-gray-500">
                                                    R$ <span id="item_valor_view_${item.id}">${parseFloat(item.item_venda_valor).toFixed(2).replace('.', ',')}</span>
                                                    &nbsp;·&nbsp; Qtd. <span id="item_qtd_view_${item.id}">${item.item_venda_quantidade}</span>
                                                </p>
                                            </div>
                                            <button type="button" data-item_id="${item.id}"
                                                    class="toogle_item w-7 h-7 rounded-full bg-gray-100 hover:bg-teal-50 text-gray-500 flex items-center justify-center transition-colors rotate-180">
                                                <i class="bx bx-chevron-up text-base"></i>
                                            </button>
                                        </div>

                                        <div id="item_venda_${item.id}" class="hidden px-3 pb-3 space-y-2">
                                            <div class="flex items-center gap-2">
                                                <span class="text-xs text-gray-500 font-medium">Quantidade</span>
                                                <div class="flex items-center gap-1 ml-auto">
                                                    <button type="button" class="minus-btn w-7 h-7 rounded-full bg-gray-100 hover:bg-red-100 text-gray-600 flex items-center justify-center font-bold text-base transition-colors"
                                                            data-item_id="${item.id}" data-produto_preco_venda="${item.item_venda_valor_unitario}">−</button>
                                                    <span id="item_venda_quantidade_${item.id}"
                                                          class="w-8 text-center text-sm font-semibold tabular-nums">${item.item_venda_quantidade}</span>
                                                    <button type="button" class="plus-btn w-7 h-7 rounded-full bg-gray-100 hover:bg-green-100 text-gray-600 flex items-center justify-center font-bold text-base transition-colors"
                                                            data-item_id="${item.id}" data-produto_preco_venda="${item.item_venda_valor_unitario}">+</button>
                                                </div>
                                            </div>

                                            <div class="grid grid-cols-3 gap-2">
                                                <div>
                                                    <x-input-label :value="__('Adicionais')" />
                                                    <x-text-input id="item_venda_adicionais_${item.id}" type="text"
                                                        class="mt-0.5 w-full text-xs" value="${item.item_venda_valor_adicionais}" readonly />
                                                </div>
                                                <div>
                                                    <x-input-label :value="__('Desconto R$')" />
                                                    <x-text-input id="item_venda_desconto_${item.id}" type="text"
                                                        class="item_desconto money mt-0.5 w-full text-xs"
                                                        value="${item.item_venda_desconto}"
                                                        data-item_id="${item.id}"
                                                        data-produto_preco_venda="${item.produto.produto_preco_venda}" />
                                                </div>
                                                <div>
                                                    <x-input-label :value="__('Valor R$')" />
                                                    <x-text-input id="item_venda_valor_${item.id}" type="text"
                                                        class="mt-0.5 w-full text-xs" value="${item.item_venda_valor}" readonly />
                                                </div>
                                            </div>

                                            <button type="button" class="remove_item w-full py-1.5 bg-red-50 hover:bg-red-100 text-red-600 rounded-lg text-xs font-semibold transition-colors flex items-center justify-center gap-1"
                                                    data-item_id="${item.id}" data-venda_id="${item.item_venda_venda_id}">
                                                <i class='bx bx-trash text-sm'></i> Remover item
                                            </button>
                                        </div>
                                    </div>
                                `);
                                $("#carregando").addClass('hidden');
                            });
                        } else {
                            badge.addClass('hidden');
                            container.html('<p class="text-sm text-gray-400 text-center py-6">Nenhum item adicionado.</p>');
                            $("#carregando").addClass('hidden');
                        }

                        // Toggle detalhe do item
                        $(".toogle_item").click(function (e) {
                            e.preventDefault();
                            const item_id = $(this).data('item_id');
                            const panel   = $("#item_venda_" + item_id);
                            if (panel.is(":visible")) {
                                panel.slideUp(150);
                                $(this).addClass('rotate-180');
                            } else {
                                panel.slideDown(150);
                                $(this).removeClass('rotate-180');
                            }
                        });

                        // Atualizar quantidade
                        function atualizarQtdItemVenda(id, novaQtd) {
                            $("#item_venda_quantidade_" + id).text(novaQtd === 0.5 ? '½' : novaQtd);
                            $("#item_qtd_view_" + id).html(novaQtd === 0.5 ? 'Meia' : novaQtd);
                            $.ajax({
                                type: "POST",
                                url: "{{ route('item_venda.update_qtd_valor') }}",
                                data: { item_id: id, venda_id: VENDA_ID, item_venda_quantidade: novaQtd, '_token': '{{ csrf_token() }}' },
                                dataType: "json",
                                success: function (r) {
                                    if (r.item_venda_valor !== undefined) {
                                        const v = parseFloat(r.item_venda_valor).toFixed(2).replace('.', ',');
                                        $("#item_venda_valor_" + id).val(parseFloat(r.item_venda_valor).toFixed(2));
                                        $("#item_valor_view_" + id).html(v);
                                    }
                                    listarVenda(VENDA_ID);
                                },
                                error: function () { showToast('Erro ao atualizar quantidade.'); }
                            });
                        }

                        $(".minus-btn").click(function (e) {
                            e.preventDefault();
                            const id  = $(this).data('item_id');
                            const cur = parseFloat($("#item_venda_quantidade_" + id).text()) || 1;
                            atualizarQtdItemVenda(id, (cur === 1 || cur === 0.5) ? 0.5 : cur - 1);
                        });
                        $(".plus-btn").click(function (e) {
                            e.preventDefault();
                            const id  = $(this).data('item_id');
                            const cur = parseFloat($("#item_venda_quantidade_" + id).text()) || 0.5;
                            atualizarQtdItemVenda(id, cur === 0.5 ? 1 : cur + 1);
                        });

                        // Desconto por item
                        let item_desconto;
                        $(".item_desconto").keyup(function () {
                            const item_id   = $(this).data('item_id');
                            item_desconto   = parseFloat($(this).val().replace(',', '.'));
                            item_desconto   = isNaN(item_desconto) ? 0 : parseFloat(item_desconto.toFixed(2));
                            const valorUnit = parseFloat($("#item_venda_valor_" + item_id).val());
                            const qtd       = parseFloat($("#item_venda_quantidade_" + item_id).text());
                            const novo      = (parseFloat($(this).data('produto_preco_venda')) * qtd) - item_desconto;
                            $("#item_venda_valor_" + item_id).val(novo.toFixed(2));
                            $.ajax({
                                type: "POST",
                                url: "{{ route('item_venda.update_desconto') }}",
                                data: { item_id, venda_id: VENDA_ID, item_desconto, '_token': '{{ csrf_token() }}' },
                                dataType: "json",
                                success: function () {
                                    $("#item_valor_view_" + item_id).html(novo.toFixed(2).replace('.', ','));
                                    listarVenda(VENDA_ID);
                                },
                                error: function () { showToast('Erro ao atualizar desconto.'); }
                            });
                        });
                        $(".item_desconto").focus(function () { item_desconto = $(this).val(); $(this).val(""); });
                        $(".item_desconto").blur(function ()  { $(this).val(item_desconto); });

                        // Remover item
                        $(".remove_item").click(function (e) {
                            e.preventDefault();
                            if (!confirm('Remover este item da venda?')) return;
                            const item_id  = $(this).data('item_id');
                            const venda_id = $(this).data('venda_id');
                            $.ajax({
                                type: "POST",
                                url: "{{ route('item_venda.remove_produto') }}",
                                data: { item_id, venda_id, '_token': '{{ csrf_token() }}' },
                                dataType: "json",
                                success: function () { ListaItensVenda(VENDA_ID); },
                                error: function () { showToast('Erro ao remover item.'); }
                            });
                        });

                        $('.money').mask('#.##0,00', { reverse: true });
                    },
                    error: function () {
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
                    success: function (response) {
                        if (response.length > 0) {
                            const v = response[0];
                            $("#venda_valor_frete").val(v.venda_valor_frete);
                            $("#venda_valor_itens").val(v.venda_valor_itens);
                            $("#venda_valor_acrescimo").val(v.venda_valor_acrescimo);
                            $("#venda_valor_desconto").val(v.venda_valor_desconto);
                            $("#venda_valor_total").val(v.venda_valor_total);
                            $("#venda_valor_pago").val(v.venda_valor_pago);
                            $("#venda_valor_troco").val(v.venda_valor_troco);
                        }
                    },
                    error: function () { showToast('Erro ao carregar totais!'); }
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
                    success: function () { listarVenda(venda_id); },
                    error: function () { showToast('Erro ao atualizar frete!'); }
                });
            }

            function InserePagamento() {
                const selectedId = $("#pg_venda_opcaopagamento_id").val();
                const opt  = opcao_pag.find(op => String(op.id) === String(selectedId));
                const desc = (opt?.opcaopag_nome ?? '').toUpperCase();
                const cartao_id = (desc.includes('CARTÃO') || desc.includes('PIX'))
                    ? $("#pg_venda_cartao_id").val()
                    : null;

                $.ajax({
                    type: "POST",
                    url: "{{ route('pagamento_venda.store') }}",
                    data: {
                        venda_id:                          VENDA_ID,
                        pg_venda_opcaopagamento_id:        selectedId,
                        pg_venda_valor_pagamento:          $("#pg_venda_valor_pagamento").val(),
                        pg_venda_valor_acrescimo:          $("#pg_venda_valor_acrescimo").val(),
                        pg_venda_valor_desconto:           $("#pg_venda_valor_desconto").val(),
                        pg_venda_cartao_id:                cartao_id,
                        pg_venda_numero_autorizacao_cartao: $("#pg_venda_numero_autorizacao_cartao").val(),
                        '_token': '{{ csrf_token() }}'
                    },
                    dataType: "json",
                    success: function (response) {
                        listarPagamentos(response.pagamentosVenda);
                        listarVenda(VENDA_ID);
                        $("#pg_venda_valor_pagamento, #pg_venda_valor_acrescimo, #pg_venda_valor_desconto, #pg_venda_numero_autorizacao_cartao").val("");
                        showToast('Pagamento registrado!', 'success');
                    },
                    error: function () { showToast('Erro ao registrar pagamento!'); }
                });
            }

            function listarPagamentos(pagamentosVenda) {
                const tbody = $('#body_tabela_pagamentos');
                tbody.empty();
                $.each(pagamentosVenda, function (index, pagamento) {
                    tbody.append(`
                        <tr class="border-b border-gray-50">
                            <td class="py-1.5 text-gray-500">${index + 1}</td>
                            <td class="py-1.5 text-gray-700 font-medium">${pagamento.opcao_pagamento.opcaopag_nome}</td>
                            <td class="py-1.5 text-right text-gray-800 font-semibold">R$ ${pagamento.pg_venda_valor_pagamento}</td>
                            <td class="py-1.5 pl-2">
                                <button type="button" class="remover_pg_venda w-6 h-6 flex items-center justify-center bg-red-50 hover:bg-red-100 text-red-500 rounded-full transition-colors"
                                        data-pg_venda_id="${pagamento.id}" title="Remover">
                                    <i class="bx bx-x text-sm"></i>
                                </button>
                            </td>
                        </tr>
                    `);
                });

                $(".remover_pg_venda").click(function (e) {
                    e.preventDefault();
                    const pg_venda_id = $(this).data('pg_venda_id');
                    $('#carregando').removeClass('hidden');
                    $.ajax({
                        type: "POST",
                        url: "{{ route('pagamento_venda.destroy') }}",
                        data: { pg_venda_id, '_token': '{{ csrf_token() }}' },
                        dataType: "json",
                        success: function (response) {
                            listarPagamentos(response.pagamentosVenda);
                            listarVenda(response.venda.id);
                            $('#carregando').addClass('hidden');
                        },
                        error: function () {
                            showToast('Erro ao remover pagamento!');
                            $('#carregando').addClass('hidden');
                        }
                    });
                });
            }

        });
    </script>
</x-app-layout>
