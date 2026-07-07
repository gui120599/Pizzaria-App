<x-app-layout>
    <style>[x-cloak] { display: none !important; }</style>
    <div id="toast-container" class="fixed top-4 right-4 z-[100] flex flex-col gap-2 pointer-events-none"></div>

  <div x-data="{ showCliente: false, showPagamento: false }"
       x-init="$watch('showPagamento', v => { if (v) window.prepararModalPagamento && window.prepararModalPagamento(); })"
       @keydown.escape.window="showCliente = false; showPagamento = false"
       @keydown.window="
           if ($event.altKey && !$event.ctrlKey && !$event.metaKey && !$event.repeat) {
               if ($event.code === 'KeyC') { $event.preventDefault(); showPagamento = false; showCliente = !showCliente; }
               else if ($event.code === 'KeyP') { $event.preventDefault(); showCliente = false; showPagamento = !showPagamento; }
           }
       ">

    <div class="py-2 px-2 sm:px-4 pb-28">

        {{-- Cabeçalho --}}
        <div class="flex items-center gap-3 mb-4">
            <a href="{{ route('sessao_caixa') }}"
               class="flex items-center gap-1 text-sm text-gray-500 hover:text-teal-600 transition">
                <i class='bx bx-arrow-back'></i> Caixa
            </a>
            <span class="text-gray-300">|</span>
            <h1 class="text-lg font-bold text-gray-800">
                Editar Venda <span id="venda_id_titulo" class="text-teal-600">#{{ $venda->id }}</span>
            </h1>
            <span id="venda_status_badge"
                  class="px-2 py-0.5 rounded-full text-xs font-bold
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

            {{-- ════════════ COLUNA ESQUERDA: fontes de itens ════════════ --}}
            <div class="xl:col-span-3"
                 x-data="{
                     aba: 'pedidos',
                     catId: null,
                     busca: '',
                     buscaPedido: '',
                     buscaMesa: '',
                     mostra(nome, catId) {
                         const textoOk = this.busca === '' || nome.toLowerCase().includes(this.busca.toLowerCase());
                         const catOk   = this.catId === null || this.catId === catId;
                         return textoOk && catOk;
                     },
                     mostraPedido(hay) {
                         return this.buscaPedido === '' || hay.includes(this.buscaPedido.toLowerCase());
                     },
                     mostraMesa(hay) {
                         return this.buscaMesa === '' || hay.includes(this.buscaMesa.toLowerCase());
                     },
                     focarBusca() {
                         this.$nextTick(() => {
                             const ref = { pedidos: 'buscaPedidoInput', mesas: 'buscaMesaInput', produtos: 'buscaInput' }[this.aba];
                             this.$refs[ref]?.focus();
                         });
                     }
                 }"
                 x-init="focarBusca(); $watch('aba', () => focarBusca())"
                 @keydown.window="
                     if ($event.altKey && !$event.ctrlKey && !$event.metaKey && !$event.repeat) {
                         if ($event.code === 'KeyM')      { $event.preventDefault(); aba = 'mesas'; }
                         else if ($event.code === 'KeyE') { $event.preventDefault(); aba = 'pedidos'; }
                         else if ($event.code === 'KeyO') { $event.preventDefault(); aba = 'produtos'; }
                     }
                 ">

                <div class="bg-white shadow-sm rounded-xl overflow-hidden">

                    {{-- Sub-navegação de fontes --}}
                    <div class="flex border-b border-gray-100">
                        <button @click="aba = 'pedidos'" type="button"
                                class="flex-1 flex items-center justify-center gap-1.5 py-3 text-sm font-semibold border-b-2 transition-colors"
                                :class="aba === 'pedidos' ? 'border-teal-500 text-teal-700' : 'border-transparent text-gray-400 hover:text-gray-600'">
                            <i class='bx bx-basket'></i> Pedidos
                            <span class="ml-0.5 text-[9px] font-bold text-gray-400 border border-gray-300 rounded px-1 py-px hidden md:inline">Alt+E</span>
                        </button>
                        <button @click="aba = 'mesas'" type="button"
                                class="flex-1 flex items-center justify-center gap-1.5 py-3 text-sm font-semibold border-b-2 transition-colors"
                                :class="aba === 'mesas' ? 'border-teal-500 text-teal-700' : 'border-transparent text-gray-400 hover:text-gray-600'">
                            <i class='bx bx-chair'></i> Mesas
                            @if($sessaoMesas->isNotEmpty())
                                <span class="bg-teal-100 text-teal-700 rounded-full px-1.5 text-[10px] font-bold">{{ $sessaoMesas->count() }}</span>
                            @endif
                            <span class="ml-0.5 text-[9px] font-bold text-gray-400 border border-gray-300 rounded px-1 py-px hidden md:inline">Alt+M</span>
                        </button>
                        <button @click="aba = 'produtos'" type="button"
                                class="flex-1 flex items-center justify-center gap-1.5 py-3 text-sm font-semibold border-b-2 transition-colors"
                                :class="aba === 'produtos' ? 'border-teal-500 text-teal-700' : 'border-transparent text-gray-400 hover:text-gray-600'">
                            <i class='bx bxs-pizza'></i> Produtos
                            <span class="ml-0.5 text-[9px] font-bold text-gray-400 border border-gray-300 rounded px-1 py-px hidden md:inline">Alt+O</span>
                        </button>
                    </div>

                    {{-- ─── ABA: PRODUTOS ─────────────────────────────────── --}}
                    <div x-show="aba === 'produtos'">
                        {{-- Busca + categorias --}}
                        <div class="px-4 pt-4 pb-3 border-b border-gray-100 space-y-3">
                            <div class="relative">
                                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none"
                                     xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                                </svg>
                                <input x-ref="buscaInput" x-model.debounce.200ms="busca" type="text" placeholder="Buscar produto..."
                                       class="w-full pl-9 pr-4 py-2 text-sm border border-gray-300 rounded-xl bg-white text-gray-700 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent">
                            </div>
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
                                        <button type="button"
                                                class="add-produto absolute bottom-2 right-2 w-8 h-8 rounded-full bg-green-500 hover:bg-green-400 active:scale-90 text-white text-xl font-bold flex items-center justify-center shadow transition-all z-10"
                                                data-produto_id="{{ $produto->id }}"
                                                data-produto_valor="{{ $produto->produto_preco_venda }}">+</button>
                                    </div>
                                @endforeach
                            @endforeach
                        </div>
                    </div>

                    {{-- ─── ABA: MESAS ────────────────────────────────────── --}}
                    <div x-show="aba === 'mesas'" x-cloak>
                        {{-- Busca --}}
                        <div class="px-4 pt-4 pb-3 border-b border-gray-100">
                            <div class="relative">
                                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none"
                                     xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                                </svg>
                                <input x-ref="buscaMesaInput" x-model.debounce.200ms="buscaMesa" type="text" placeholder="Buscar mesa..."
                                       class="w-full pl-9 pr-4 py-2 text-sm border border-gray-300 rounded-xl bg-white text-gray-700 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent">
                            </div>
                        </div>

                        <div class="p-3 space-y-3 overflow-y-auto" style="max-height: clamp(18rem, 60vh, 50rem)">
                        @forelse($sessaoMesas as $sessaoMesa)
                            @php
                                $clientesNaMesa  = [];
                                $totalNaoCobrado = 0;
                                $totalLancado    = 0;
                                $totalCobrado    = 0;
                                $produtosNaMesa  = [];
                                foreach ($sessaoMesa->pedidos as $pedido) {
                                    foreach ($pedido->item_pedido_pedido_id as $item) {
                                        $produtosNaMesa[] = $item->produto?->produto_descricao ?? '';
                                        $vendaDoItem = $item->item_pedido_venda_id !== null ? optional($item->venda) : null;
                                        $cobrado     = $vendaDoItem && $vendaDoItem->venda_status === 'FINALIZADA';
                                        $lancado     = $item->item_pedido_venda_id !== null && !$cobrado;
                                        if ($cobrado) {
                                            $totalCobrado += $item->item_pedido_valor;
                                        } elseif ($lancado) {
                                            $totalLancado += $item->item_pedido_valor;
                                        } else {
                                            $totalNaoCobrado += $item->item_pedido_valor;
                                            $cid = $item->item_pedido_cliente_id !== null ? (string) $item->item_pedido_cliente_id : 'sem_cliente';
                                            if (!isset($clientesNaMesa[$cid])) {
                                                $clientesNaMesa[$cid] = [
                                                    'nome' => $item->item_pedido_cliente_id
                                                        ? ($item->cliente?->cliente_nome ?? 'Cliente #'.$item->item_pedido_cliente_id)
                                                        : 'Sem identificação',
                                                    'item_ids' => [],
                                                ];
                                            }
                                            $clientesNaMesa[$cid]['item_ids'][] = $item->id;
                                        }
                                    }
                                }
                                $buscaMesaTxt = strtolower(
                                    ($sessaoMesa->mesa->mesa_nome ?? '') . ' '
                                    . collect($clientesNaMesa)->pluck('nome')->join(' ') . ' '
                                    . implode(' ', $produtosNaMesa)
                                );
                            @endphp
                            <div class="border border-gray-200 rounded-xl shadow-sm overflow-hidden"
                                 x-show="mostraMesa(@js($buscaMesaTxt))"
                                 data-sessao-id="{{ $sessaoMesa->id }}"
                                 x-data="{
                                     aberto: true,
                                     selecionados: [],
                                     selecionarCliente(ids) {
                                         ids.forEach(id => { if (!this.selecionados.includes(String(id))) this.selecionados.push(String(id)); });
                                     },
                                     limparSelecao() { this.selecionados = []; },
                                     get temSelecionados() { return this.selecionados.length > 0; }
                                 }">
                                {{-- Cabeçalho da mesa --}}
                                <div class="flex items-center justify-between px-3 py-2 bg-gray-50 border-b border-gray-100">
                                    <div class="flex items-center gap-2 min-w-0">
                                        <i class='bx bx-chair text-teal-600'></i>
                                        <span class="text-sm font-bold text-gray-800 truncate">{{ $sessaoMesa->mesa->mesa_nome }}</span>
                                        <span class="sessao-status-badge text-[10px] text-gray-400 uppercase">{{ $sessaoMesa->sessao_mesa_status }}</span>
                                    </div>
                                    <div class="flex items-center gap-1 shrink-0">
                                        <button type="button"
                                                onclick="window.open('{{ route('sessaoMesa.imprimir', ['id' => $sessaoMesa->id]) }}', 'Itens Sessão Mesa','width=600,height=400');"
                                                class="w-7 h-7 rounded-full hover:bg-gray-200 text-gray-500 flex items-center justify-center transition-colors" title="Imprimir">
                                            <i class='bx bx-printer'></i>
                                        </button>
                                        <button @click="aberto = !aberto" type="button"
                                                :class="{ 'rotate-180': aberto }"
                                                class="w-7 h-7 rounded-full hover:bg-gray-200 text-gray-500 flex items-center justify-center transition-transform">
                                            <i class='bx bx-chevron-up'></i>
                                        </button>
                                    </div>
                                </div>

                                <div x-show="aberto" class="p-3">
                                    <p class="text-[10px] text-gray-400 mb-2">
                                        Pedidos:
                                        @foreach($sessaoMesa->pedidos as $pedido){{ $pedido->id }}{{ !$loop->last ? ' · ' : '' }}@endforeach
                                    </p>

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

                                    {{-- Lista de itens --}}
                                    <div class="divide-y divide-gray-100 border border-gray-100 rounded-xl overflow-hidden">
                                        @foreach($sessaoMesa->pedidos as $pedido)
                                            @foreach($pedido->item_pedido_pedido_id as $item)
                                                @php
                                                    $vendaDoItem = $item->item_pedido_venda_id !== null ? optional($item->venda) : null;
                                                    $cobrado     = $vendaDoItem && $vendaDoItem->venda_status === 'FINALIZADA';
                                                    $lancado     = $item->item_pedido_venda_id !== null && !$cobrado;
                                                @endphp
                                                <label class="flex items-start gap-2 px-3 py-2 select-none
                                                    {{ $cobrado || $lancado ? 'bg-gray-50 opacity-60 cursor-not-allowed' : 'cursor-pointer hover:bg-teal-50' }}">
                                                    <input type="checkbox" value="{{ $item->id }}" x-model="selecionados"
                                                        {{ $cobrado || $lancado ? 'disabled' : '' }}
                                                        class="mt-0.5 w-4 h-4 rounded border-gray-300 text-teal-600 focus:ring-teal-500 shrink-0">
                                                    @if($item->item_pedido_cliente_id)
                                                        <span class="mt-0.5 shrink-0 text-[9px] font-semibold text-indigo-700 bg-indigo-50 border border-indigo-200 rounded px-1 py-0.5">
                                                            {{ $item->cliente?->cliente_nome ?? '#'.$item->item_pedido_cliente_id }}
                                                        </span>
                                                    @endif
                                                    <span class="flex-1 min-w-0 text-xs text-gray-800">
                                                        {{ $item->produto?->produto_descricao ?? 'Produto #'.$item->item_pedido_produto_id }}
                                                        <span class="text-gray-400">× {{ $item->item_pedido_quantidade == floor($item->item_pedido_quantidade) ? (int)$item->item_pedido_quantidade : $item->item_pedido_quantidade }}</span>
                                                        @foreach($item->adicionaisItemPedido as $adic)
                                                            <span class="block text-[10px] text-gray-500 pl-2">
                                                                + {{ $adic->adicional->adicional_nome ?? 'Adicional' }}
                                                                @if($adic->aip_quantidade > 1)<span class="text-gray-400">× {{ (int) $adic->aip_quantidade }}</span>@endif
                                                                <span class="text-gray-400">(R$ {{ number_format($adic->aip_valor_total, 2, ',', '.') }})</span>
                                                            </span>
                                                        @endforeach
                                                        @if($item->item_pedido_desconto > 0)
                                                            <span class="block text-[10px] font-medium text-orange-500 pl-2">
                                                                Desc. − R$ {{ number_format($item->item_pedido_desconto, 2, ',', '.') }}
                                                            </span>
                                                        @endif
                                                    </span>
                                                    <span class="mt-0.5 shrink-0 text-xs font-semibold {{ $cobrado ? 'text-gray-400 line-through' : 'text-gray-700' }}">
                                                        R$ {{ number_format($item->item_pedido_valor, 2, ',', '.') }}
                                                    </span>
                                                    @if($cobrado)
                                                        <span class="cobrado-badge shrink-0 text-[9px] font-bold text-green-600 bg-green-50 border border-green-200 rounded px-1 py-0.5 flex items-center gap-0.5">
                                                            <i class='bx bx-check'></i> cobrado
                                                        </span>
                                                    @elseif($lancado)
                                                        <span class="lancado-badge shrink-0 text-[9px] font-bold text-blue-600 bg-blue-50 border border-blue-200 rounded px-1 py-0.5 flex items-center gap-0.5">
                                                            <i class='bx bx-time'></i> venda #{{ $item->item_pedido_venda_id }}
                                                        </span>
                                                    @endif
                                                </label>
                                            @endforeach
                                        @endforeach
                                    </div>

                                    {{-- Total + Lançar --}}
                                    <div class="mt-2 flex items-center justify-between gap-2">
                                        <div class="text-xs text-gray-500">
                                            <span class="font-bold text-gray-700">R$ {{ number_format($totalNaoCobrado, 2, ',', '.') }}</span> a cobrar
                                            @if($totalLancado > 0)
                                                · <span class="text-blue-600">R$ {{ number_format($totalLancado, 2, ',', '.') }} em venda</span>
                                            @endif
                                            @if($totalCobrado > 0)
                                                · <span class="text-green-600">R$ {{ number_format($totalCobrado, 2, ',', '.') }} cobrado</span>
                                            @endif
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <button type="button" @click="limparSelecao()" x-show="temSelecionados" x-cloak
                                                    class="text-xs text-gray-400 hover:text-gray-600 transition px-2 py-1 hover:bg-gray-100 rounded-lg">
                                                Limpar
                                            </button>
                                            <button type="button"
                                                    @click="if(temSelecionados) window.lancarItensVenda([...selecionados], $el.closest('[x-data]'))"
                                                    :disabled="!temSelecionados"
                                                    :class="temSelecionados ? 'bg-teal-600 hover:bg-teal-700 text-white shadow' : 'bg-gray-100 text-gray-400 cursor-not-allowed'"
                                                    class="inline-flex items-center gap-1.5 px-4 py-2 text-xs font-semibold rounded-lg transition">
                                                <i class='bx bx-send text-sm'></i>
                                                <span x-text="temSelecionados ? 'Lançar ' + selecionados.length + ' item(s)' : 'Selecione itens'">Selecione itens</span>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @empty
                            <div class="flex flex-col items-center justify-center py-12 text-gray-400">
                                <i class='bx bx-chair text-4xl mb-2'></i>
                                <p class="text-sm">Nenhuma mesa com pedidos em aberto.</p>
                            </div>
                        @endforelse
                        </div>
                    </div>

                    {{-- ─── ABA: PEDIDOS AVULSOS ──────────────────────────── --}}
                    <div x-show="aba === 'pedidos'" x-cloak>
                        {{-- Busca --}}
                        <div class="px-4 pt-4 pb-3 border-b border-gray-100">
                            <div class="relative">
                                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none"
                                     xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                                </svg>
                                <input x-ref="buscaPedidoInput" x-model.debounce.200ms="buscaPedido" type="text" placeholder="Buscar pedido..."
                                       class="w-full pl-9 pr-4 py-2 text-sm border border-gray-300 rounded-xl bg-white text-gray-700 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent">
                            </div>
                        </div>

                        <div class="p-3 space-y-3 overflow-y-auto" style="max-height: clamp(18rem, 60vh, 50rem)">
                        @php $temAvulso = false; @endphp
                        @foreach($pedidos as $pedido)
                            @if(empty($pedido->pedido_sessao_mesa_id))
                                @php
                                    $temAvulso = true;
                                    $valorPedido = $pedido->item_pedido_pedido_id->sum('item_pedido_valor');
                                    $jaEmVenda   = $pedido->pedido_venda_id !== null;
                                    $buscaPedidoTxt = strtolower(
                                        'pedido ' . $pedido->id . ' '
                                        . ($pedido->opcaoEntrega->opcaoentrega_nome ?? '') . ' '
                                        . $pedido->item_pedido_pedido_id
                                            ->map(fn($i) => $i->produto->produto_descricao ?? '')->join(' ')
                                    );
                                @endphp
                                <div class="border border-gray-200 rounded-xl shadow-sm overflow-hidden" x-data="{ aberto: false }"
                                     x-show="mostraPedido(@js($buscaPedidoTxt))">
                                    <div class="flex items-center justify-between px-3 py-2 bg-gray-50 border-b border-gray-100">
                                        <label class="flex items-center gap-2 min-w-0 {{ $jaEmVenda ? 'opacity-60 cursor-not-allowed' : 'cursor-pointer' }}">
                                            <input type="checkbox" class="pedido w-4 h-4 rounded border-gray-300 text-teal-600 focus:ring-teal-500 shrink-0"
                                                   id="pedido_{{ $pedido->id }}" value="{{ $pedido->id }}"
                                                   data-pedido_id="{{ $pedido->id }}" {{ $jaEmVenda ? 'disabled' : '' }}>
                                            <span class="text-sm font-bold text-gray-800">Pedido #{{ $pedido->id }}</span>
                                            <span class="text-[10px] text-gray-400 truncate">
                                                {{ \Carbon\Carbon::parse($pedido->updated_at)->format('d/m H:i') }}
                                                · {{ $pedido->opcaoEntrega->opcaoentrega_nome ?? '—' }}
                                            </span>
                                        </label>
                                        <div class="flex items-center gap-1 shrink-0">
                                            <button type="button"
                                                    onclick="window.open('{{ route('pedido.imprimir', ['id' => $pedido->id]) }}', 'Pedido', 'width=600,height=400');"
                                                    class="w-7 h-7 rounded-full hover:bg-gray-200 text-gray-500 flex items-center justify-center transition-colors" title="Imprimir">
                                                <i class='bx bx-printer'></i>
                                            </button>
                                            <button @click="aberto = !aberto" type="button"
                                                    :class="{ 'rotate-180': aberto }"
                                                    class="w-7 h-7 rounded-full hover:bg-gray-200 text-gray-500 flex items-center justify-center transition-transform">
                                                <i class='bx bx-chevron-up'></i>
                                            </button>
                                        </div>
                                    </div>

                                    <div x-show="aberto" x-cloak class="p-3">
                                        <div class="divide-y divide-gray-100 border border-gray-100 rounded-xl overflow-hidden">
                                            @foreach($pedido->item_pedido_pedido_id as $item)
                                                <div class="flex items-start gap-2 px-3 py-2">
                                                    <div class="flex-1 min-w-0 text-xs text-gray-800">
                                                        <span>
                                                            {{ $item->produto->categoria->categoria_nome ?? '' }} {{ $item->produto->produto_descricao ?? '' }}
                                                            <span class="text-gray-400">× {{ $item->item_pedido_quantidade == floor($item->item_pedido_quantidade) ? (int)$item->item_pedido_quantidade : $item->item_pedido_quantidade }}</span>
                                                        </span>
                                                        @foreach($item->adicionaisItemPedido as $adic)
                                                            <span class="block text-[10px] text-gray-500 pl-2">
                                                                + {{ $adic->adicional->adicional_nome ?? 'Adicional' }}
                                                                @if($adic->aip_quantidade > 1)<span class="text-gray-400">× {{ (int) $adic->aip_quantidade }}</span>@endif
                                                                <span class="text-gray-400">(R$ {{ number_format($adic->aip_valor_total, 2, ',', '.') }})</span>
                                                            </span>
                                                        @endforeach
                                                        @if($item->item_pedido_desconto > 0)
                                                            <span class="block text-[10px] font-medium text-orange-500 pl-2">
                                                                Desc. − R$ {{ number_format($item->item_pedido_desconto, 2, ',', '.') }}
                                                            </span>
                                                        @endif
                                                    </div>
                                                    <span class="shrink-0 text-xs font-semibold text-gray-700">R$ {{ number_format($item->item_pedido_valor, 2, ',', '.') }}</span>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>

                                    <div class="flex items-center justify-between px-3 py-2 border-t border-gray-100">
                                        <span class="text-[10px] uppercase font-semibold text-gray-400">{{ $pedido->pedido_status }}</span>
                                        <span class="text-sm font-bold text-gray-800">R$ {{ number_format($valorPedido, 2, ',', '.') }}</span>
                                    </div>
                                </div>
                            @endif
                        @endforeach

                        @if(!$temAvulso)
                            <div class="flex flex-col items-center justify-center py-12 text-gray-400">
                                <i class='bx bx-basket text-4xl mb-2'></i>
                                <p class="text-sm">Nenhum pedido avulso disponível.</p>
                            </div>
                        @endif
                        </div>
                    </div>

                </div>
            </div>

            {{-- ════════════ COLUNA DIREITA: dados / itens / totais / pagamento ════════════ --}}
            <div class="xl:col-span-2 flex flex-col gap-4">

                <form id="formVenda" action="{{ route('venda.salvar_venda', $venda->id) }}" method="POST">
                    @csrf
                    <input type="hidden" name="venda_id" id="venda_id" value="{{ $venda->id }}">
                    <input type="hidden" name="venda_sessao_caixa_id" id="venda_sessao_caixa_id" value="{{ $venda->venda_sessao_caixa_id }}">

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
                                    <span class="text-gray-800 font-medium">{{ $sessaoCaixa?->caixa?->caixa_nome ?? '—' }}</span>
                                </div>
                                <div>
                                    <span class="block font-semibold text-gray-500 uppercase tracking-wide mb-0.5">Operador</span>
                                    <span class="text-gray-800 font-medium">{{ $sessaoCaixa?->user?->name ?? '—' }}</span>
                                </div>
                            </div>
                        </div>

                        {{-- Modal: Cliente (campos permanecem dentro do #formVenda para o submit) --}}
                        <div x-show="showCliente" x-cloak class="fixed inset-0 z-[80] flex items-center justify-center p-4">
                            <div x-show="showCliente" x-transition.opacity
                                 class="absolute inset-0 bg-black/40" @click="showCliente = false"></div>
                            <div x-show="showCliente" x-transition
                                 class="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
                                <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100 sticky top-0 bg-white z-10">
                                    <p class="flex items-center gap-2 text-base font-bold text-teal-700">
                                        <i class='bx bx-user'></i> Cliente
                                    </p>
                                    <button type="button" @click="showCliente = false"
                                            class="w-8 h-8 rounded-full hover:bg-gray-100 text-gray-400 flex items-center justify-center transition-colors">
                                        <i class='bx bx-x text-2xl'></i>
                                    </button>
                                </div>
                                <div class="p-5 space-y-3">
                                    <input type="hidden" id="venda_cliente_id" name="venda_cliente_id" value="{{ $venda->venda_cliente_id }}">

                                    {{-- Busca por nome --}}
                                    <div class="relative">
                                        <x-input-label for="venda_cliente_nome" value="Nome do cliente" />
                                        <input id="venda_cliente_nome" name="venda_cliente_nome" type="text" autocomplete="off"
                                               value="{{ $venda->cliente?->cliente_nome }}"
                                               placeholder="Buscar ou digitar nome..."
                                               class="mt-1 w-full border-gray-300 rounded-lg shadow-sm text-sm focus:ring-teal-500 focus:border-teal-500">
                                        <div id="lista_clientes"
                                             class="absolute z-30 w-full bg-white rounded-lg mt-1 shadow-lg hidden overflow-auto max-h-72 border border-gray-100">
                                            @foreach($clientes as $cliente)
                                                <div class="border-b border-gray-50 hover:bg-teal-600 hover:text-white rounded-md px-3 py-2 text-sm cursor-pointer transition-colors"
                                                     onclick="selecionarCliente({{ $cliente }})">
                                                    {{ $cliente->id }} - {{ $cliente->cliente_nome }}
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-2 gap-2">
                                        <div>
                                            <x-input-label for="venda_cliente_cpf" value="CPF" />
                                            <x-text-input id="venda_cliente_cpf" name="venda_cliente_cpf" type="text"
                                                value="{{ $venda->cliente?->cliente_cpf }}"
                                                class="cpf mt-1 w-full text-sm" placeholder="000.000.000-00" />
                                        </div>
                                        <div>
                                            <x-input-label for="venda_cliente_cnpj" value="CNPJ" />
                                            <x-text-input id="venda_cliente_cnpj" name="venda_cliente_cnpj" type="text"
                                                value="{{ $venda->cliente?->cliente_cnpj }}"
                                                class="cnpj mt-1 w-full text-sm" placeholder="00.000.000/0000-00" />
                                        </div>
                                        <div>
                                            <x-input-label for="venda_cliente_telefone" value="Telefone" />
                                            <x-text-input id="venda_cliente_telefone" name="venda_cliente_telefone" type="text"
                                                value="{{ $venda->cliente?->cliente_celular }}"
                                                class="phone_ddd mt-1 w-full text-sm" placeholder="(00) 0 0000-0000" />
                                        </div>
                                        <div>
                                            <x-input-label for="venda_cliente_email" value="Email" />
                                            <x-text-input id="venda_cliente_email" name="venda_cliente_email" type="email"
                                                value="{{ $venda->cliente?->cliente_email }}"
                                                class="mt-1 w-full text-sm" placeholder="exemplo@email.com" />
                                        </div>
                                    </div>

                                    <button type="button" @click="showCliente = false"
                                            class="w-full mt-2 py-2.5 bg-teal-600 hover:bg-teal-700 active:bg-teal-800 text-white rounded-lg text-sm font-bold transition-colors">
                                        Confirmar
                                    </button>
                                </div>
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

                {{-- Modal: Pagamento --}}
                <div x-show="showPagamento" x-cloak class="fixed inset-0 z-[80] flex items-center justify-center p-4">
                    <div x-show="showPagamento" x-transition.opacity
                         class="absolute inset-0 bg-black/40" @click="showPagamento = false"></div>
                    <div x-show="showPagamento" x-transition
                         class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md max-h-[90vh] overflow-y-auto p-5 space-y-3"
                         x-data="{
                         opcoes: @js($opcoesPagamentos),
                         selectedId: '',
                         get opcaoSelecionada() { return this.opcoes.find(o => String(o.id) === String(this.selectedId)) ?? null; },
                         get showTaxa()   { const t = this.opcaoSelecionada?.opcaopag_tipo_taxa; return t === 'ACRESCENTAR' || t === 'DESCONTAR'; },
                         get requerBandeira()    { return !!this.opcaoSelecionada?.opcaopag_requer_bandeira; },
                         get requerAutorizacao() { return !!this.opcaoSelecionada?.opcaopag_requer_autorizacao; },
                         get showCartao() { return this.requerBandeira || this.requerAutorizacao; },
                         get isAcrescimo(){ return this.opcaoSelecionada?.opcaopag_tipo_taxa === 'ACRESCENTAR'; },
                         get isDesconto() { return this.opcaoSelecionada?.opcaopag_tipo_taxa === 'DESCONTAR'; },
                         get taxa()       { return parseFloat(this.opcaoSelecionada?.opcaopag_valor_percentual_taxa ?? 0) || 0; }
                     }">
                    <div class="flex items-center justify-between -mt-1">
                        <p class="flex items-center gap-2 text-base font-bold text-teal-700">
                            <i class='bx bx-credit-card'></i> Pagamento
                        </p>
                        <button type="button" @click="showPagamento = false"
                                class="w-8 h-8 rounded-full hover:bg-gray-100 text-gray-400 flex items-center justify-center transition-colors">
                            <i class='bx bx-x text-2xl'></i>
                        </button>
                    </div>
                    <div>
                        <x-input-label value="Tipo de pagamento" />
                        <x-radio-group :options="$opcoesPagamentos" value-field="id" display-field="opcaopag_nome"
                            name="pg_venda_opcaopagamento_id" x-model="selectedId" />
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <x-input-label for="pg_venda_valor_pagamento" value="Valor recebido" />
                            <x-money-input id="pg_venda_valor_pagamento" name="pg_venda_valor_pagamento"
                                type="text" class="money mt-1 w-full" autocomplete="off" />
                        </div>
                        <div>
                            <x-input-label for="pg_venda_valor_pago_pelo_cliente" value="Pago pelo cliente *" />
                            <x-money-input id="pg_venda_valor_pago_pelo_cliente" name="pg_venda_valor_pago_pelo_cliente"
                                type="text" class="money mt-1 w-full" autocomplete="off" required />
                        </div>
                    </div>
                    <div class="flex items-center justify-between rounded-lg bg-amber-50 border border-amber-200 px-3 py-2">
                        <span class="text-xs font-semibold text-amber-700 uppercase tracking-wide">Troco a devolver</span>
                        <span class="text-base font-bold text-amber-700">R$ <span id="pg_venda_troco_view">0,00</span></span>
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
                        <div x-show="requerBandeira" x-cloak>
                            <x-input-label for="pg_venda_cartao_id" value="Bandeira" />
                            <x-select-input :options="$cartoes" value-field="id" display-field="cartao_bandeira"
                                id="pg_venda_cartao_id" name="pg_venda_cartao_id" class="mt-1 w-full" />
                        </div>
                        <div x-show="requerAutorizacao" x-cloak>
                            <x-input-label for="pg_venda_numero_autorizacao_cartao" value="Nº Autorização" />
                            <x-text-input id="pg_venda_numero_autorizacao_cartao"
                                name="pg_venda_numero_autorizacao_cartao" type="text" class="mt-1 w-full" />
                        </div>
                    </div>
                    <button type="button" id="registar_pagamento"
                            class="w-full flex items-center justify-center gap-2 py-2.5 bg-teal-600 hover:bg-teal-700 active:bg-teal-800 text-white rounded-lg text-sm font-bold transition-colors">
                        <i class='bx bx-check-square text-base'></i> Registrar Pagamento
                    </button>
                    <div>
                        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">Pagamentos registrados</p>
                        <div class="overflow-x-auto">
                            <table class="w-full text-xs">
                                <thead>
                                    <tr class="border-b border-gray-100 text-gray-500">
                                        <th class="pb-1.5 text-left font-semibold">#</th>
                                        <th class="pb-1.5 text-left font-semibold">Tipo</th>
                                        <th class="pb-1.5 text-right font-semibold">Recebido</th>
                                        <th class="pb-1.5 text-right font-semibold">Troco</th>
                                        <th class="pb-1.5"></th>
                                    </tr>
                                </thead>
                                <tbody id="body_tabela_pagamentos"></tbody>
                            </table>
                        </div>
                    </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Loading overlay --}}
    <div id="carregando" class="hidden fixed inset-0 z-[90] flex justify-center items-center bg-slate-700/40">
        <div class="bg-white rounded-2xl p-6 shadow-2xl text-center">
            <i class='bx bx-loader-circle bx-spin bx-rotate-90 text-4xl text-teal-600'></i>
            <p class="mt-2 text-sm font-medium text-gray-600">Carregando...</p>
        </div>
    </div>

    {{-- ════════════ BOTÕES FLUTUANTES (centralizados, sem barra) ════════════ --}}
    <div class="fixed bottom-5 inset-x-0 z-40 px-4 flex justify-center pointer-events-none">
        <div class="pointer-events-auto flex items-center gap-2">

            {{-- Cancelar (somente após iniciar a venda) --}}
            <div id="fab-cancelar" class="hidden">
                <button type="button" onclick="cancelarVendaAtual()"
                        class="py-2.5 px-4 bg-red-600 hover:bg-red-700 active:bg-red-800 text-white rounded-xl font-semibold flex items-center gap-1.5 shadow-xl transition-colors">
                    <i class='bx bx-x-circle text-lg'></i>
                    <span class="text-xs uppercase tracking-wide hidden sm:inline">Cancelar</span>
                </button>
            </div>

            {{-- Cliente · Pagamento · Finalizar --}}
            <button type="button" @click="showPagamento = false; showCliente = !showCliente"
                    class="py-2.5 px-4 bg-white hover:bg-gray-50 active:bg-gray-100 text-gray-700 border border-gray-200 rounded-xl font-semibold flex items-center gap-1.5 shadow-xl transition-colors">
                <i class='bx bx-user text-lg'></i>
                <span class="text-xs uppercase tracking-wide hidden sm:inline">Cliente</span>
                <span class="text-[9px] font-bold text-gray-400 border border-gray-300 rounded px-1 py-px hidden md:inline">Alt+C</span>
            </button>
            <button type="button" @click="showCliente = false; showPagamento = !showPagamento"
                    class="py-2.5 px-4 bg-white hover:bg-gray-50 active:bg-gray-100 text-gray-700 border border-gray-200 rounded-xl font-semibold flex items-center gap-1.5 shadow-xl transition-colors">
                <i class='bx bx-credit-card text-lg'></i>
                <span class="text-xs uppercase tracking-wide hidden sm:inline">Pagamento</span>
                <span class="text-[9px] font-bold text-gray-400 border border-gray-300 rounded px-1 py-px hidden md:inline">Alt+P</span>
            </button>
            <button type="button" onclick="handleFinalizarClick()"
                    class="py-2.5 px-5 bg-teal-600 hover:bg-teal-700 active:bg-teal-800 rounded-xl text-white font-bold flex items-center gap-2 shadow-xl transition-colors whitespace-nowrap">
                <i class='bx bx-check-double text-lg'></i>
                <span class="text-xs uppercase tracking-widest">Finalizar</span>
            </button>
        </div>
    </div>

  </div>{{-- /root x-data (showCliente / showPagamento) --}}

    <script>
        function selecionarCliente(cliente) {
            document.getElementById("venda_cliente_id").value       = cliente.id;
            document.getElementById("venda_cliente_nome").value     = cliente.cliente_nome ?? '';
            document.getElementById("venda_cliente_telefone").value = cliente.cliente_celular ?? '';
            document.getElementById("venda_cliente_email").value    = cliente.cliente_email ?? '';
            document.getElementById("venda_cliente_cpf").value      = cliente.cliente_cpf ?? '';
            document.getElementById("venda_cliente_cnpj").value     = cliente.cliente_cnpj ?? '';
        }

        function handleFinalizarClick() {
            const toNum = v => parseFloat((v || '0').replace(/\./g, '').replace(',', '.')) || 0;
            const valorPago  = toNum(document.getElementById('venda_valor_pago').value);
            const valorTotal = toNum(document.getElementById('venda_valor_total').value);
            const valorTroco = toNum(document.getElementById('venda_valor_troco').value);
            const tol = 0.005;

            if (valorTotal <= 0) {
                showAvisoVenda('Adicione itens à venda antes de finalizar.', 'warning');
                return;
            }
            if (valorPago + tol < valorTotal) {
                showAvisoVenda('Valor pago insuficiente para finalizar a venda!', 'warning');
                document.getElementById('pg_venda_valor_pagamento')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                document.getElementById('pg_venda_valor_pagamento')?.focus();
                return;
            }
            // Recebido maior que o total mas sem troco informado: bloqueia (evita finalizar
            // com troco zerado quando o cliente, na verdade, tem troco a receber).
            if ((valorPago - valorTotal) > tol && valorTroco <= tol) {
                showAvisoVenda('O valor recebido excede o total da venda, mas nenhum troco foi informado. Ajuste o "Valor recebido" para o total da venda ou informe o "Pago pelo cliente" para gerar o troco.', 'warning');
                document.getElementById('pg_venda_valor_pago_pelo_cliente')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                document.getElementById('pg_venda_valor_pago_pelo_cliente')?.focus();
                return;
            }
            document.getElementById('formVenda').submit();
        }

        function showAvisoVenda(message, type = 'error') {
            const container = document.getElementById('toast-container');
            const colors = {
                error:   'bg-red-600 border-red-700',
                success: 'bg-teal-600 border-teal-700',
                warning: 'bg-yellow-500 border-yellow-600'
            };
            const icons = { error: 'bx-error-circle', success: 'bx-check-circle', warning: 'bx-info-circle' };
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

        function cancelarVendaAtual() {
            const venda_id = document.getElementById('venda_id').value;
            if (!venda_id) { window.location.href = "{{ route('sessao_caixa') }}"; return; }
            if (!confirm('Cancelar esta venda? Os itens serão liberados.')) return;
            window.__cancelarVenda(venda_id);
        }
    </script>

    <script type="module">
        $(document).ready(function () {

            const opcao_pag = @json($opcoesPagamentos);

            // ── Edição: a venda já existe; carrega o estado atual ─────────────
            ListaItensVenda(vendaId());
            listarVenda(vendaId());
            @if($venda->pagamentos->isNotEmpty())
                listarPagamentos(@json($venda->pagamentos));
            @endif
            @if(!in_array($venda->venda_status, ['CANCELADA', 'FINALIZADA']))
                $("#fab-cancelar").removeClass("hidden");
            @endif

            // ── Busca de clientes por nome ───────────────────────────────────
            const inputCliente  = document.getElementById('venda_cliente_nome');
            const listaClientes = document.getElementById('lista_clientes');
            inputCliente.addEventListener('focus', () => listaClientes.classList.remove('hidden'));
            inputCliente.addEventListener('blur',  () => setTimeout(() => listaClientes.classList.add('hidden'), 200));
            inputCliente.addEventListener('input', function () {
                const texto = inputCliente.value.toLowerCase();
                listaClientes.querySelectorAll('div').forEach(el => {
                    el.style.display = el.textContent.toLowerCase().includes(texto) ? 'block' : 'none';
                });
            });
            $("#venda_cliente_nome").keyup(function () {
                if (!$(this).val()) {
                    $("#venda_cliente_id, #venda_cliente_cpf, #venda_cliente_cnpj, #venda_cliente_telefone, #venda_cliente_email").val("");
                }
            });

            // ── Helpers de estado da venda ───────────────────────────────────
            function vendaId() { return $("#venda_id").val(); }

            function marcarVendaIniciada(id) {
                $("#venda_id").val(id);
                $("#venda_id_titulo").text("Nº " + id);
                $("#venda_status_badge").text("INICIADA")
                    .removeClass("bg-yellow-100 text-yellow-700")
                    .addClass("bg-blue-100 text-blue-700");
                $("#fab-cancelar").removeClass("hidden");
                document.getElementById('formVenda').action =
                    "{{ route('venda.salvar_venda', '__ID__') }}".replace('__ID__', id);
            }

            // Cria a venda no primeiro uso e devolve o id via Promise
            function IniciarVenda() {
                return new Promise((resolve, reject) => {
                    $.ajax({
                        url: "{{ route('venda.iniciar') }}",
                        type: 'POST',
                        dataType: 'json',
                        data: {
                            '_token': '{{ csrf_token() }}',
                            venda_sessao_caixa_id: $("#venda_sessao_caixa_id").val(),
                            venda_cliente_id:      $("#venda_cliente_id").val()
                        },
                        success: function (response) {
                            if (response && response.venda_id) {
                                marcarVendaIniciada(response.venda_id);
                                resolve(response.venda_id);
                            } else {
                                showAvisoVenda('Erro ao iniciar a venda.');
                                $("#carregando").addClass('hidden');
                                reject();
                            }
                        },
                        error: function () {
                            showAvisoVenda('Erro ao iniciar a venda.');
                            $("#carregando").addClass('hidden');
                            reject();
                        }
                    });
                });
            }

            // Garante uma venda existente antes de executar a ação
            function comVenda(callback) {
                const id = vendaId();
                if (id === "" || id === undefined || id === null) {
                    IniciarVenda().then(callback).catch(() => {});
                } else {
                    callback(id);
                }
            }

            // ── Fontes de itens ──────────────────────────────────────────────

            // Produto avulso
            $(document).on('click', '.add-produto', function (e) {
                e.stopPropagation();
                $("#carregando").removeClass('hidden');
                comVenda(id => AdicionaProduto($(this).data('produto_id'), id));
            });

            // Pedido avulso (adiciona/remove todos os itens)
            $('.pedido').click(function () {
                $("#carregando").removeClass('hidden');
                const pedido_id = $(this).data('pedido_id');
                if ($(this).is(':checked')) {
                    comVenda(id => AdicionaItensPedido(pedido_id, id));
                } else {
                    RemoveItensPedido(pedido_id, vendaId());
                }
            });

            // Itens de mesa por seleção (chamado pelo Alpine)
            window.lancarItensVenda = function (item_ids, componentEl) {
                if (!item_ids || item_ids.length === 0) return;
                $("#carregando").removeClass('hidden');
                comVenda(id => LancarItensPorSelecao(item_ids, id, componentEl));
            };

            // ── Frete ────────────────────────────────────────────────────────
            let freteAnterior;
            $(".venda_valor_frete").keyup(function () {
                $("#carregando").removeClass('hidden');
                const valor = $(this).val();
                comVenda(id => AtualizaValorFrete(valor, id));
            });
            $(".venda_valor_frete").focus(function () { freteAnterior = $(this).val(); $(this).val(""); });
            $(".venda_valor_frete").blur(function ()  { if (vendaId()) listarVenda(vendaId()); });

            // ── Pagamento ────────────────────────────────────────────────────
            // Converte valor mascarado (1.234,56) para número
            function parseMoeda(v) {
                return parseFloat((v || '0').replace(/\./g, '').replace(',', '.')) || 0;
            }
            // Troco = pago pelo cliente − recebido (nunca negativo)
            function atualizarTrocoPagamento() {
                const recebido = parseMoeda($("#pg_venda_valor_pagamento").val());
                const pagoCli  = parseMoeda($("#pg_venda_valor_pago_pelo_cliente").val());
                const troco    = pagoCli > recebido ? (pagoCli - recebido) : 0;
                $("#pg_venda_troco_view").text(troco.toFixed(2).replace('.', ','));
            }
            $("#pg_venda_valor_pagamento").keyup(function () {
                const valor_pag  = parseMoeda($(this).val());
                const valor_taxa = parseFloat($("#opcao_pag_taxa").val()) || 0;
                const opt = opcao_pag.find(op => String(op.id) === String($("input[name='pg_venda_opcaopagamento_id']:checked").val()));
                if (opt) {
                    if (opt.opcaopag_tipo_taxa === 'ACRESCENTAR') {
                        $("#pg_venda_valor_acrescimo").val((valor_pag * valor_taxa / 100).toFixed(2));
                    } else if (opt.opcaopag_tipo_taxa === 'DESCONTAR') {
                        $("#pg_venda_valor_desconto").val((valor_pag * valor_taxa / 100).toFixed(2));
                    }
                }
                atualizarTrocoPagamento();
            });
            $("#pg_venda_valor_pago_pelo_cliente").keyup(atualizarTrocoPagamento);
            $("#registar_pagamento").click(function (e) { e.preventDefault(); InserePagamento(); });
            $('#pg_venda_valor_pagamento, #pg_venda_valor_pago_pelo_cliente, #pg_venda_numero_autorizacao_cartao').on('keypress', function (e) {
                if (e.which === 13) { e.preventDefault(); InserePagamento(); }
            });

            // Ao abrir o modal de pagamento: preenche "Valor recebido" com o que falta
            // pagar (= total na primeira vez, editável) e foca em "Pago pelo cliente".
            window.prepararModalPagamento = function () {
                const total = parseMoeda($("#venda_valor_total").val());
                const pago  = parseMoeda($("#venda_valor_pago").val());
                const falta = Math.max(0, Math.round((total - pago) * 100) / 100);
                const $recebido = $("#pg_venda_valor_pagamento");
                if (parseMoeda($recebido.val()) === 0) {
                    $recebido.val(falta.toFixed(2).replace('.', ',')).trigger('input');
                }
                atualizarTrocoPagamento();
                setTimeout(function () {
                    const f = document.getElementById('pg_venda_valor_pago_pelo_cliente');
                    if (f) { f.focus(); if (f.select) f.select(); }
                }, 120);
            };

            // Evita backspace fora de inputs
            document.addEventListener('keydown', function (e) {
                const tag = (e.target || e.srcElement).tagName;
                if (e.key === 'Backspace' && tag !== 'INPUT' && tag !== 'TEXTAREA') e.preventDefault();
            });

            // ════════════ FUNÇÕES AJAX ════════════

            function AdicionaProduto(produto_id, venda_id) {
                $.ajax({
                    type: "POST", url: "{{ route('item_venda.add_produto') }}",
                    data: { '_token': '{{ csrf_token() }}', produto_id, venda_id }, dataType: "JSON",
                    success: function () { ListaItensVenda(venda_id); },
                    error: function () { showAvisoVenda('Erro ao adicionar produto!'); $("#carregando").addClass('hidden'); }
                });
            }

            function AdicionaItensPedido(pedido_id, venda_id) {
                $.ajax({
                    type: "POST", url: "{{ route('item_venda.add_item_pedido') }}",
                    data: { '_token': '{{ csrf_token() }}', pedido_id, venda_id }, dataType: "JSON",
                    success: function () { ListaItensVenda(venda_id); },
                    error: function () { showAvisoVenda('Erro ao adicionar itens do pedido!'); $("#carregando").addClass('hidden'); }
                });
            }

            function RemoveItensPedido(pedido_id, venda_id) {
                $.ajax({
                    type: "POST", url: "{{ route('item_venda.remove_item_pedido') }}",
                    data: { '_token': '{{ csrf_token() }}', pedido_id, venda_id }, dataType: "JSON",
                    success: function () { ListaItensVenda(venda_id); },
                    error: function () { showAvisoVenda('Erro ao remover itens do pedido #' + pedido_id + '.'); $("#carregando").addClass('hidden'); }
                });
            }

            function LancarItensPorSelecao(item_ids, venda_id, componentEl) {
                $.ajax({
                    type: "POST", url: "{{ route('item_venda.add_por_selecao') }}",
                    data: { '_token': '{{ csrf_token() }}', item_ids, venda_id }, dataType: "JSON",
                    success: function (response) {
                        if (componentEl && typeof Alpine !== 'undefined') {
                            try { Alpine.$data(componentEl).selecionados = []; } catch (e) {}
                        }
                        (response.cobrados || []).forEach(function (id) {
                            const cb = $('input[type=checkbox][value="' + id + '"]');
                            cb.prop('disabled', true).prop('checked', false);
                            cb.closest('label').addClass('opacity-60 bg-gray-50 cursor-not-allowed').removeClass('hover:bg-teal-50 cursor-pointer');
                            cb.siblings('.cobrado-badge, .lancado-badge').remove();
                            cb.closest('label').append(
                                `<span class="lancado-badge shrink-0 text-[9px] font-bold text-blue-600 bg-blue-50 border border-blue-200 rounded px-1 py-0.5 flex items-center gap-0.5"><i class='bx bx-time'></i> em venda</span>`
                            );
                        });
                        ListaItensVenda(venda_id);
                        if (response.sessoes_finalizadas && response.sessoes_finalizadas.length > 0) {
                            response.sessoes_finalizadas.forEach(function (sessaoId) {
                                const card = $('[data-sessao-id="' + sessaoId + '"]');
                                card.find('.sessao-status-badge').text('FINALIZADA').addClass('text-green-600').removeClass('text-gray-400');
                                card.find('input[type=checkbox]').prop('disabled', true);
                                card.find('button[\\@click*="lancarItensVenda"]').prop('disabled', true)
                                    .removeClass('bg-teal-600 hover:bg-teal-700 text-white shadow')
                                    .addClass('bg-gray-100 text-gray-400 cursor-not-allowed');
                            });
                            showAvisoVenda('Todos os itens recebidos! Sessão da mesa finalizada automaticamente.', 'success');
                        } else {
                            showAvisoVenda('Itens lançados na venda!', 'success');
                        }
                    },
                    error: function () { showAvisoVenda('Erro ao lançar itens!'); $("#carregando").addClass('hidden'); }
                });
            }

            function ListaItensVenda(venda_id) {
                $.ajax({
                    type: "GET", url: "{{ route('item_venda.listar') }}",
                    data: { '_token': '{{ csrf_token() }}', venda_id }, dataType: "JSON",
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
                                                <p class="text-[10px] font-semibold uppercase tracking-wide text-orange-600 leading-tight">${item.produto.categoria.categoria_nome}</p>
                                                <p class="text-sm font-medium text-gray-800 truncate" id="produto_nome_${item.id}">${item.produto.produto_descricao}</p>
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
                                                    <span id="item_venda_quantidade_${item.id}" class="w-8 text-center text-sm font-semibold tabular-nums">${item.item_venda_quantidade}</span>
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
                                                        class="item_desconto money mt-0.5 w-full text-xs" value="${item.item_venda_desconto}"
                                                        data-item_id="${item.id}" data-produto_preco_venda="${item.item_venda_valor_unitario}" />
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
                            });
                        } else {
                            badge.addClass('hidden');
                            container.html('<p class="text-sm text-gray-400 text-center py-6">Nenhum item adicionado.</p>');
                        }
                        $("#carregando").addClass('hidden');

                        // Toggle detalhe
                        $(".toogle_item").click(function (e) {
                            e.preventDefault();
                            const item_id = $(this).data('item_id');
                            const panel   = $("#item_venda_" + item_id);
                            if (panel.is(":visible")) { panel.slideUp(150); $(this).addClass('rotate-180'); }
                            else { panel.slideDown(150); $(this).removeClass('rotate-180'); }
                        });

                        function atualizarQtdItemVenda(id, novaQtd) {
                            $("#item_venda_quantidade_" + id).text(novaQtd === 0.5 ? '½' : novaQtd);
                            $("#item_qtd_view_" + id).html(novaQtd === 0.5 ? 'Meia' : novaQtd);
                            $.ajax({
                                type: "POST", url: "{{ route('item_venda.update_qtd_valor') }}",
                                data: { item_id: id, venda_id, item_venda_quantidade: novaQtd, '_token': '{{ csrf_token() }}' },
                                dataType: "json",
                                success: function (r) {
                                    if (r.item_venda_valor !== undefined) {
                                        $("#item_venda_valor_" + id).val(parseFloat(r.item_venda_valor).toFixed(2));
                                        $("#item_valor_view_" + id).html(parseFloat(r.item_venda_valor).toFixed(2).replace('.', ','));
                                    }
                                    listarVenda(venda_id);
                                },
                                error: function () { showAvisoVenda('Erro ao atualizar quantidade.'); }
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
                            const valorUnit = parseFloat($(this).data('produto_preco_venda'));
                            const qtd       = parseFloat($("#item_venda_quantidade_" + item_id).text());
                            const novo      = (valorUnit * qtd) - item_desconto;
                            $("#item_venda_valor_" + item_id).val(novo.toFixed(2));
                            $.ajax({
                                type: "POST", url: "{{ route('item_venda.update_desconto') }}",
                                data: { item_id, venda_id, item_desconto, '_token': '{{ csrf_token() }}' },
                                dataType: "json",
                                success: function () {
                                    $("#item_valor_view_" + item_id).html(novo.toFixed(2).replace('.', ','));
                                    listarVenda(venda_id);
                                },
                                error: function () { showAvisoVenda('Erro ao atualizar desconto.'); }
                            });
                        });
                        $(".item_desconto").focus(function () { item_desconto = $(this).val(); $(this).val(""); });
                        $(".item_desconto").blur(function ()  { $(this).val(item_desconto); });

                        // Remover item
                        $(".remove_item").click(function (e) {
                            e.preventDefault();
                            if (!confirm('Remover este item da venda?')) return;
                            const item_id  = $(this).data('item_id');
                            const v_id     = $(this).data('venda_id');
                            $.ajax({
                                type: "POST", url: "{{ route('item_venda.remove_produto') }}",
                                data: { item_id, venda_id: v_id, '_token': '{{ csrf_token() }}' }, dataType: "json",
                                success: function () { ListaItensVenda(venda_id); },
                                error: function () { showAvisoVenda('Erro ao remover item.'); }
                            });
                        });

                        $('.money').mask('#.##0,00', { reverse: true });
                    },
                    error: function () { showAvisoVenda('Erro ao listar itens!'); $("#carregando").addClass('hidden'); }
                });
                listarVenda(venda_id);
            }

            function listarVenda(venda_id) {
                $.ajax({
                    type: "GET", url: "{{ route('venda.listar') }}",
                    data: { '_token': '{{ csrf_token() }}', venda_id }, dataType: "JSON",
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
                    error: function () { showAvisoVenda('Erro ao carregar totais!'); }
                });
            }

            function AtualizaValorFrete(valor, venda_id) {
                if (!valor || !valor.trim()) valor = "0.00";
                const frete = parseFloat(valor.replace(',', '.'));
                $.ajax({
                    type: "POST", url: "{{ route('venda.update_valor_frete') }}",
                    data: { venda_id, venda_valor_frete: isNaN(frete) ? 0 : frete, '_token': '{{ csrf_token() }}' },
                    dataType: "json",
                    success: function () { listarVenda(venda_id); $("#carregando").addClass('hidden'); },
                    error: function () { showAvisoVenda('Erro ao atualizar frete!'); $("#carregando").addClass('hidden'); }
                });
            }

            function InserePagamento() {
                comVenda(function (venda_id) {
                    const selectedId = $("input[name='pg_venda_opcaopagamento_id']:checked").val();
                    if (!selectedId) { showAvisoVenda('Selecione o tipo de pagamento.', 'warning'); return; }

                    const pagoCliente = parseMoeda($("#pg_venda_valor_pago_pelo_cliente").val());
                    if (!pagoCliente || pagoCliente <= 0) {
                        showAvisoVenda('Informe o valor pago pelo cliente.', 'warning');
                        $("#pg_venda_valor_pago_pelo_cliente").focus();
                        return;
                    }

                    const opt  = opcao_pag.find(op => String(op.id) === String(selectedId));
                    const cartao_id = opt?.opcaopag_requer_bandeira ? $("#pg_venda_cartao_id").val() : null;
                    const num_aut   = opt?.opcaopag_requer_autorizacao ? $("#pg_venda_numero_autorizacao_cartao").val() : null;

                    $.ajax({
                        type: "POST", url: "{{ route('pagamento_venda.store') }}",
                        data: {
                            venda_id,
                            pg_venda_opcaopagamento_id:         selectedId,
                            pg_venda_valor_pagamento:           $("#pg_venda_valor_pagamento").val(),
                            pg_venda_valor_pago_pelo_cliente:   $("#pg_venda_valor_pago_pelo_cliente").val(),
                            pg_venda_valor_acrescimo:           $("#pg_venda_valor_acrescimo").val(),
                            pg_venda_valor_desconto:            $("#pg_venda_valor_desconto").val(),
                            pg_venda_cartao_id:                 cartao_id,
                            pg_venda_numero_autorizacao_cartao: num_aut,
                            '_token': '{{ csrf_token() }}'
                        },
                        dataType: "json",
                        success: function (response) {
                            listarPagamentos(response.pagamentosVenda);
                            listarVenda(venda_id);
                            $("#pg_venda_valor_pagamento, #pg_venda_valor_pago_pelo_cliente, #pg_venda_valor_acrescimo, #pg_venda_valor_desconto, #pg_venda_numero_autorizacao_cartao").val("");
                            $("#pg_venda_troco_view").text('0,00');
                            showAvisoVenda('Pagamento registrado!', 'success');
                        },
                        error: function () { showAvisoVenda('Erro ao registrar pagamento!'); }
                    });
                });
            }

            function listarPagamentos(pagamentosVenda) {
                const tbody = $('#body_tabela_pagamentos');
                tbody.empty();
                $.each(pagamentosVenda, function (index, pagamento) {
                    const troco = parseFloat(pagamento.pg_venda_valor_troco) || 0;
                    const trocoCell = troco > 0
                        ? `<span class="text-amber-600 font-semibold">R$ ${troco.toFixed(2).replace('.', ',')}</span>`
                        : `<span class="text-gray-300">—</span>`;
                    tbody.append(`
                        <tr class="border-b border-gray-50">
                            <td class="py-1.5 text-gray-500">${index + 1}</td>
                            <td class="py-1.5 text-gray-700 font-medium">${pagamento.opcao_pagamento.opcaopag_nome}</td>
                            <td class="py-1.5 text-right text-gray-800 font-semibold">R$ ${pagamento.pg_venda_valor_pagamento}</td>
                            <td class="py-1.5 text-right">${trocoCell}</td>
                            <td class="py-1.5 pl-2">
                                <button type="button" class="remover_pg_venda w-6 h-6 flex items-center justify-center bg-red-50 hover:bg-red-100 text-red-500 rounded-full transition-colors"
                                        data-pg_venda_id="${pagamento.id}" title="Remover"><i class="bx bx-x text-sm"></i></button>
                            </td>
                        </tr>
                    `);
                });
                $(".remover_pg_venda").click(function (e) {
                    e.preventDefault();
                    const pg_venda_id = $(this).data('pg_venda_id');
                    $('#carregando').removeClass('hidden');
                    $.ajax({
                        type: "POST", url: "{{ route('pagamento_venda.destroy') }}",
                        data: { pg_venda_id, '_token': '{{ csrf_token() }}' }, dataType: "json",
                        success: function (response) {
                            listarPagamentos(response.pagamentosVenda);
                            listarVenda(response.venda.id);
                            $('#carregando').addClass('hidden');
                        },
                        error: function () { showAvisoVenda('Erro ao remover pagamento!'); $('#carregando').addClass('hidden'); }
                    });
                });
            }

            // Cancelamento manual (exposto para o FAB)
            window.__cancelarVenda = function (venda_id) {
                $('#carregando').removeClass('hidden');
                $.ajax({
                    type: 'POST', url: "{{ route('venda.cancelar') }}",
                    data: { '_token': '{{ csrf_token() }}', venda_id },
                    success: function () { window.location.href = "{{ route('sessao_caixa') }}"; },
                    error: function () { showAvisoVenda('Erro ao cancelar a venda!'); $('#carregando').addClass('hidden'); }
                });
            };

        });
    </script>

</x-app-layout>
