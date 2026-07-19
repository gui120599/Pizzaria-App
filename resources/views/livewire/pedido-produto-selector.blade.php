<div x-data="{ drawerOpen: false }" class="bg-white rounded-xl border border-gray-200 overflow-hidden">

    {{-- Erro de promoção relâmpago (saldo esgotou / limite excedido) --}}
    @if ($erroPromocao)
        <div class="flex items-start gap-2 px-4 py-3 bg-red-50 border-b border-red-200 text-red-700 text-sm">
            <i class='bx bx-error-circle text-base mt-0.5 shrink-0'></i>
            <span class="flex-1">{{ $erroPromocao }}</span>
            <button wire:click="$set('erroPromocao', null)" type="button" class="text-red-400 hover:text-red-600 shrink-0">
                <i class='bx bx-x text-lg'></i>
            </button>
        </div>
    @endif

    {{-- Erro de estoque insuficiente (modo Bloquear) --}}
    @if ($erroEstoque)
        <div class="flex items-start gap-2 px-4 py-3 bg-red-50 border-b border-red-200 text-red-700 text-sm">
            <i class='bx bx-error-circle text-base mt-0.5 shrink-0'></i>
            <span class="flex-1">{{ $erroEstoque }}</span>
            <button wire:click="$set('erroEstoque', null)" type="button" class="text-red-400 hover:text-red-600 shrink-0">
                <i class='bx bx-x text-lg'></i>
            </button>
        </div>
    @endif

    {{-- Aviso de estoque insuficiente (modo Avisar) --}}
    @if ($avisoEstoque)
        <div class="flex items-start gap-2 px-4 py-3 bg-amber-50 border-b border-amber-200 text-amber-700 text-sm">
            <i class='bx bx-error text-base mt-0.5 shrink-0'></i>
            <span class="flex-1">{{ $avisoEstoque }}</span>
            <button wire:click="$set('avisoEstoque', null)" type="button" class="text-amber-400 hover:text-amber-600 shrink-0">
                <i class='bx bx-x text-lg'></i>
            </button>
        </div>
    @endif

    {{-- ── Busca + categorias ─────────────────────────────────────────────── --}}
    <div class="px-4 pt-4 pb-3 border-b border-gray-100 space-y-3">

        {{-- Busca --}}
        <div class="relative">
            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
            </svg>
            <input
                wire:model.live.debounce.300ms="busca"
                type="text"
                placeholder="Buscar produto..."
                class="w-full pl-9 pr-4 py-2 text-sm border border-gray-300 rounded-xl bg-white text-gray-700 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent"
            />
        </div>

        {{-- Categorias: ícones circulares (estilo cardápio) --}}
        <div class="flex gap-3 overflow-x-auto pb-1">

            {{-- Todos --}}
            <button wire:click="$set('categoriaId', null)" type="button"
                    class="flex flex-col items-center gap-1 shrink-0 w-16 focus:outline-none">
                <div class="w-14 h-14 rounded-xl overflow-hidden border-2 transition-colors duration-150 bg-gray-100 flex items-center justify-center
                    {{ $categoriaId === null ? 'border-orange-500' : 'border-gray-300 hover:border-gray-400' }}">
                    <svg class="w-7 h-7 text-gray-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z" />
                    </svg>
                </div>
                <span class="text-[9px] text-gray-500 font-semibold uppercase tracking-wide text-center">TODOS</span>
            </button>

            @foreach ($this->categorias as $categoria)
                @php
                    $imgCat = $categoria->produtos->first(fn($p) => $p->produto_foto)?->getImagemUrl()
                        ?? asset('img/logo Pizzaria Branco Colorido.png');
                @endphp
                <button wire:click="$set('categoriaId', {{ $categoria->id }})" type="button"
                        class="flex flex-col items-center gap-1 shrink-0 w-16 focus:outline-none">
                    <div class="w-14 h-14 rounded-xl overflow-hidden border-2 transition-colors duration-150 shadow-sm
                        {{ $categoriaId === $categoria->id ? 'border-orange-500' : 'border-gray-300 hover:border-gray-400' }}">
                        <img src="{{ $imgCat }}" alt="{{ $categoria->categoria_nome }}" class="w-full h-full object-cover">
                    </div>
                    <span class="text-[9px] text-gray-500 font-semibold uppercase tracking-wide leading-tight text-center line-clamp-2 w-full">{{ $categoria->categoria_nome }}</span>
                </button>
            @endforeach
        </div>
    </div>

    {{-- ── Grid de produtos ────────────────────────────────────────────────── --}}
    <div class="divide-y divide-gray-100 overflow-y-auto" style="max-height: clamp(18rem, 60vh, 48rem)">
        @forelse ($this->produtos as $produto)
            @php
                // Mesma resolução de preço do cardápio — inclui promoção relâmpago
                // vigente com saldo (ver Produto::precoResolvido()). O balcão vende
                // pelo mesmo preço exibido: confirmarItem()/confirmarSabores() debitam
                // o contador da promoção ao gravar o item.
                $preco        = $produto->precoResolvido();
                $precoVenda   = $preco->valorUnitario;
                $precoExibido = $preco->precoFinal();
                $temDesconto  = $preco->descontoUnitario > 0;
                $temPromo     = $temDesconto;
                $temRelampago = $preco->temPromocaoRelampago();
            @endphp
            <div wire:key="produto-{{ $produto->id }}"
                 class="relative p-2 flex items-start gap-2 bg-white hover:bg-gray-50 transition-colors">

                {{-- Imagem --}}
                <div class="relative shrink-0">
                    <img src="{{ $produto->getImagemUrl() }}"
                         alt="{{ $produto->produto_descricao }}"
                         class="w-20 h-16 object-cover rounded-lg bg-gray-100"
                         onerror="this.src=''">
                    @if ($temRelampago)
                        <span class="absolute top-1 left-1 bg-red-600 text-white text-[9px] font-bold px-1 py-0.5 rounded flex items-center gap-0.5">
                            <i class='bx bxs-bolt text-[9px]'></i> RELÂMPAGO
                        </span>
                    @elseif ($temPromo)
                        <span class="absolute top-1 left-1 bg-orange-500 text-white text-[9px] font-bold px-1 py-0.5 rounded flex items-center gap-0.5">
                            <i class='bx bxs-purchase-tag text-[9px]'></i> PROMO
                        </span>
                    @endif
                </div>

                {{-- Info --}}
                <div class="flex-1 min-w-0 flex flex-col justify-between pr-10 min-h-[4rem]">
                    <div>
                        @if ($produto->categoria)
                            <span class="inline-block text-[9px] font-semibold uppercase tracking-wide text-orange-600 bg-orange-50 rounded px-1 mb-0.5">{{ $produto->categoria->categoria_nome }}</span>
                        @endif
                        <p class="text-gray-800 text-sm font-semibold leading-snug line-clamp-2">{{ $produto->produto_descricao }}</p>
                    </div>
                    <div>
                        @if ($temDesconto)
                            <span class="text-gray-400 text-xs line-through block leading-tight">R$ {{ number_format($precoVenda, 2, ',', '.') }}</span>
                            <span class="text-green-600 text-base font-bold">R$ {{ number_format($precoExibido, 2, ',', '.') }}</span>
                        @else
                            <span class="text-gray-800 text-base font-bold">R$ {{ number_format($precoExibido, 2, ',', '.') }}</span>
                        @endif
                        @if ($produto->produto_codimentacao)
                            <span class="block text-[9px] text-gray-400 leading-tight mt-0.5">{{ $produto->produto_codimentacao }}</span>
                        @endif
                        @if ($produto->produto_controla_estoque)
                            @php $saldo = (float) $produto->produto_saldo_estoque; @endphp
                            <span class="inline-flex items-center gap-0.5 text-[9px] font-semibold mt-0.5 {{ $saldo > 0 ? 'text-gray-400' : 'text-red-500' }}">
                                <i class='bx bxs-package'></i>
                                {{ $saldo == floor($saldo) ? (int) $saldo : number_format($saldo, 2, ',', '.') }} {{ $produto->produto_unidade_estoque }} em estoque
                            </span>
                        @endif
                    </div>
                </div>

                {{-- Botão + --}}
                <button
                    wire:click="selecionarProduto({{ $produto->id }})"
                    wire:loading.attr="disabled"
                    wire:target="selecionarProduto({{ $produto->id }})"
                    type="button"
                    class="absolute bottom-2 right-2 w-8 h-8 rounded-full bg-green-500 hover:bg-green-400 active:scale-90 text-white text-xl font-bold flex items-center justify-center shadow transition-all z-10"
                >+</button>
            </div>
        @empty
            <div class="flex flex-col items-center justify-center py-12 text-gray-400">
                <svg class="w-10 h-10 mb-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" /></svg>
                <p class="text-sm">Nenhum produto encontrado</p>
            </div>
        @endforelse
    </div>


    {{-- ── FAB: Salvar + Ver itens ─────────────────────────────────────────── --}}
    @php $totalFab = collect($itens)->sum('valor'); @endphp
    <div class="fixed bottom-5 inset-x-0 px-4 z-40 flex justify-center pointer-events-none">
        <div class="flex flex-col sm:flex-row items-center gap-2 pointer-events-none">

            {{-- Botão salvar (sempre visível) --}}
            <button @click="document.getElementById('pedido-form').requestSubmit()" type="button"
                    class="pointer-events-auto py-3 px-5 bg-gray-800 hover:bg-gray-700 active:bg-gray-900 rounded-2xl text-white font-bold flex items-center gap-2 shadow-xl transition-colors whitespace-nowrap">
                <i class='bx bx-save text-lg'></i>
                <span class="text-sm uppercase tracking-widest">{{ $saveButtonLabel }}</span>
            </button>

            {{-- Botão itens (somente quando há itens) --}}
            @if (count($itens) > 0)
                <button @click="drawerOpen = true" type="button"
                        class="pointer-events-auto py-3 px-4 bg-teal-600 hover:bg-teal-500 active:bg-teal-700 rounded-2xl text-white font-bold flex items-center gap-3 shadow-xl transition-colors">
                    <span class="bg-teal-800 rounded-full w-7 h-7 flex items-center justify-center text-sm font-bold">{{ count($itens) }}</span>
                    <span class="text-sm uppercase tracking-widest flex items-center gap-1.5">
                        <i class='bx bx-cart'></i> Itens
                    </span>
                    <span class="font-bold text-sm">R$ {{ number_format($totalFab, 2, ',', '.') }}</span>
                </button>
            @endif

        </div>
    </div>


    {{-- ── Drawer: lista de itens (bottom sheet) ──────────────────────────── --}}
    <div x-show="drawerOpen"
         class="fixed inset-0 z-50 flex flex-col justify-end"
         style="display:none">

        {{-- Backdrop --}}
        <div @click="drawerOpen = false"
             x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
             x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
             class="absolute inset-0 bg-black/50 backdrop-blur-sm"></div>

        {{-- Sheet --}}
        <div x-transition:enter="transition ease-out duration-300" x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0"
             x-transition:leave="transition ease-in duration-200" x-transition:leave-start="translate-y-0" x-transition:leave-end="translate-y-full"
             class="relative bg-white rounded-t-3xl max-h-[85vh] flex flex-col z-10 w-full md:max-w-2xl md:mx-auto shadow-2xl">

            {{-- Handle --}}
            <div class="flex justify-center pt-3 pb-1 shrink-0">
                <div class="w-10 h-1 bg-gray-300 rounded-full"></div>
            </div>

            {{-- Header --}}
            <div class="flex items-center justify-between px-4 pb-3 border-b border-gray-100 shrink-0">
                <h3 class="font-bold text-gray-800 text-lg flex items-center gap-2">
                    <i class='bx bx-cart text-teal-600'></i> Itens do Pedido
                    <span class="text-sm font-normal text-gray-400">({{ count($itens) }})</span>
                </h3>
                <button @click="drawerOpen = false" type="button" class="text-gray-400 hover:text-gray-600 transition-colors">
                    <i class='bx bx-x text-2xl'></i>
                </button>
            </div>

            {{-- Body: lista --}}
            <div class="overflow-y-auto flex-1 divide-y divide-gray-100">
                @foreach ($itens as $item)
                    <div wire:key="item-{{ $item['id'] }}" class="flex items-center gap-3 px-4 py-3 bg-white">

                        {{-- Imagem --}}
                        @if (!empty($item['produto_foto']))
                            <img src="{{ $item['produto_foto'] }}" alt="{{ $item['produto_nome'] }}"
                                 class="w-10 h-10 object-cover rounded-lg shrink-0 bg-gray-100"
                                 onerror="this.style.display='none'">
                        @else
                            <div class="w-10 h-10 rounded-lg bg-gray-100 flex items-center justify-center shrink-0">
                                <svg class="w-4 h-4 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" /></svg>
                            </div>
                        @endif

                        {{-- Info --}}
                        <div class="flex-1 min-w-0">
                            <div class="flex flex-wrap items-center gap-1 mb-0.5">
                                @if (!empty($item['categoria_nome']))
                                    <span class="inline-block text-[10px] font-semibold uppercase tracking-wide text-teal-700 bg-teal-50 border border-teal-200 rounded px-1.5 py-0.5">{{ $item['categoria_nome'] }}</span>
                                @endif
                                @if (!empty($item['cliente_nome']))
                                    <span class="inline-flex items-center gap-0.5 text-[10px] font-semibold text-indigo-700 bg-indigo-50 border border-indigo-200 rounded px-1.5 py-0.5">
                                        <i class='bx bx-user text-[9px]'></i> {{ $item['cliente_nome'] }}
                                    </span>
                                @endif
                                @if (!empty($item['promocao_id']))
                                    <span class="inline-flex items-center gap-0.5 text-[10px] font-bold text-white bg-red-600 rounded px-1.5 py-0.5">
                                        <i class='bx bxs-bolt text-[9px]'></i> RELÂMPAGO
                                    </span>
                                @endif
                            </div>
                            <p class="text-sm font-medium text-gray-800 truncate">{{ $item['produto_nome'] }}</p>
                            @if (!empty($item['adicionais']))
                                <p class="text-xs text-gray-500 truncate">+ {{ collect($item['adicionais'])->pluck('nome')->join(', ') }}</p>
                            @endif
                            @if (!empty($item['observacao']))
                                <p class="text-xs text-gray-400 italic truncate">{{ $item['observacao'] }}</p>
                            @endif
                        </div>

                        {{-- Quantidade --}}
                        @if (!empty($item['promocao_id']))
                            {{-- Item promocional: preço e quantidade congelados no momento da
                                 venda (o débito da promoção não pode ser ajustado parcialmente).
                                 Para mudar a quantidade, remova e adicione de novo. --}}
                            <div class="flex items-center gap-1 shrink-0 px-2" title="Quantidade fixa: item da promoção relâmpago">
                                <i class='bx bxs-lock-alt text-gray-300 text-sm'></i>
                                <span class="w-8 text-center text-sm font-semibold text-gray-700 tabular-nums">
                                    {{ $item['quantidade'] == floor($item['quantidade']) ? (int)$item['quantidade'] : number_format($item['quantidade'], 2, ',', '') }}
                                </span>
                            </div>
                        @else
                            <div class="flex items-center gap-1 shrink-0">
                                <button wire:click="decrementarQtd('{{ $item['id'] }}')" type="button"
                                    class="w-7 h-7 rounded-full bg-gray-100 hover:bg-red-100 text-gray-600 flex items-center justify-center text-base font-bold leading-none transition-colors">−</button>
                                <span class="w-8 text-center text-sm font-semibold text-gray-700 tabular-nums">
                                    {{ $item['quantidade'] == floor($item['quantidade']) ? (int)$item['quantidade'] : number_format($item['quantidade'], 2, ',', '') }}
                                </span>
                                <button wire:click="incrementarQtd('{{ $item['id'] }}')" type="button"
                                    class="w-7 h-7 rounded-full bg-gray-100 hover:bg-green-100 text-gray-600 flex items-center justify-center text-base font-bold leading-none transition-colors">+</button>
                            </div>
                        @endif

                        {{-- Valor --}}
                        <div class="text-right shrink-0 min-w-[5rem]">
                            @if ($item['desconto'] > 0)
                                <p class="text-xs text-gray-400 line-through leading-tight">R$ {{ number_format($item['valor'] + $item['desconto'], 2, ',', '.') }}</p>
                                <p class="text-sm font-bold text-green-600">R$ {{ number_format($item['valor'], 2, ',', '.') }}</p>
                            @else
                                <p class="text-sm font-bold text-gray-800">R$ {{ number_format($item['valor'], 2, ',', '.') }}</p>
                            @endif
                        </div>

                        {{-- Editar --}}
                        <button wire:click="abrirEditModal('{{ $item['id'] }}')" type="button"
                            class="w-7 h-7 rounded-full bg-gray-100 hover:bg-blue-100 text-gray-400 hover:text-blue-600 flex items-center justify-center shrink-0 transition-colors"
                            title="Editar observação/adicionais">
                            <svg class="w-3.5 h-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                <path d="M2.695 14.763l-1.262 3.154a.5.5 0 00.65.65l3.155-1.262a4 4 0 001.343-.885L17.5 5.5a2.121 2.121 0 00-3-3L3.58 13.42a4 4 0 00-.885 1.343z" />
                            </svg>
                        </button>

                        {{-- Remover --}}
                        <button wire:click="removerItem('{{ $item['id'] }}')"
                            wire:confirm="Remover '{{ $item['produto_nome'] }}' do pedido?"
                            type="button"
                            class="w-7 h-7 rounded-full bg-red-50 hover:bg-red-100 text-red-500 flex items-center justify-center shrink-0 transition-colors"
                            title="Remover">
                            <svg class="w-3.5 h-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M8.75 1A2.75 2.75 0 006 3.75v.443c-.795.077-1.584.176-2.365.298a.75.75 0 10.23 1.482l.149-.022.841 10.518A2.75 2.75 0 007.596 19h4.807a2.75 2.75 0 002.742-2.53l.841-10.52.149.023a.75.75 0 00.23-1.482A41.03 41.03 0 0014 4.193V3.75A2.75 2.75 0 0011.25 1h-2.5zM10 4c.84 0 1.673.025 2.5.075V3.75c0-.69-.56-1.25-1.25-1.25h-2.5c-.69 0-1.25.56-1.25 1.25v.325C8.327 4.025 9.16 4 10 4zM8.58 7.72a.75.75 0 00-1.5.06l.3 7.5a.75.75 0 101.5-.06l-.3-7.5zm4.34.06a.75.75 0 10-1.5-.06l-.3 7.5a.75.75 0 101.5.06l.3-7.5z" clip-rule="evenodd" />
                            </svg>
                        </button>
                    </div>
                @endforeach
            </div>

            {{-- Footer: totais --}}
            @php
                $totalDesconto = collect($itens)->sum('desconto');
                $totalLiquido  = collect($itens)->sum('valor');
                $totalValor    = $totalLiquido + $totalDesconto;
            @endphp
            <div class="px-4 py-4 border-t border-gray-100 bg-gray-50 shrink-0">
                <div class="flex items-center justify-between">
                    @if ($totalDesconto > 0)
                        <span class="text-xs text-green-600 font-medium">Desconto: −R$ {{ number_format($totalDesconto, 2, ',', '.') }}</span>
                    @else
                        <span class="text-sm text-gray-500">{{ count($itens) }} {{ count($itens) === 1 ? 'item' : 'itens' }}</span>
                    @endif
                    <span class="text-xl font-bold text-gray-800">R$ {{ number_format($totalLiquido, 2, ',', '.') }}</span>
                </div>
            </div>
        </div>
    </div>


    {{-- ══ MODAL: adicionar produto ══════════════════════════════════════════ --}}
    @if ($modalAberta && $produtoSelecionado)
        <div class="fixed inset-0 z-[60] flex items-center justify-center p-4" wire:keydown.escape="fecharModal">
            <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" wire:click="fecharModal"></div>

            <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md z-10 max-h-[90vh] overflow-y-auto">

                {{-- Toast temporário: erro/aviso de estoque (fica por cima do modal, senão o
                     garçom não vê sem fechar o próprio modal que está tentando confirmar) --}}
                @if ($erroEstoque || $avisoEstoque)
                    <div wire:key="toast-estoque-modal-{{ md5(($erroEstoque ?? '').($avisoEstoque ?? '')) }}"
                         x-data
                         x-init="setTimeout(() => $wire.fecharToastEstoque(), 5000)"
                         class="absolute top-3 inset-x-3 z-20 flex items-start gap-2 px-3 py-2.5 rounded-xl border shadow-lg text-sm {{ $erroEstoque ? 'bg-red-50 border-red-200 text-red-700' : 'bg-amber-50 border-amber-200 text-amber-700' }}">
                        <i class='bx {{ $erroEstoque ? "bx-error-circle" : "bx-error" }} text-base mt-0.5 shrink-0'></i>
                        <span class="flex-1">{{ $erroEstoque ?? $avisoEstoque }}</span>
                        <button type="button" wire:click="fecharToastEstoque" class="shrink-0 opacity-60 hover:opacity-100">
                            <i class='bx bx-x text-lg'></i>
                        </button>
                    </div>
                @endif

                {{-- Header --}}
                <div class="flex items-start justify-between gap-3 px-5 pt-5 pb-4 border-b border-gray-100">
                    <div>
                        <h3 class="text-base font-bold text-gray-800 leading-snug flex items-center gap-1.5">
                            {{ $produtoSelecionado['nome'] }}
                            @if (!empty($produtoSelecionado['promocao_id']))
                                <span class="inline-flex items-center gap-0.5 text-[9px] font-bold text-white bg-red-600 rounded px-1 py-0.5 shrink-0">
                                    <i class='bx bxs-bolt text-[9px]'></i> RELÂMPAGO
                                </span>
                            @endif
                        </h3>
                        <div class="flex items-baseline gap-2 mt-1">
                            @if ($produtoSelecionado['desconto_unit'] > 0)
                                <span class="text-xs text-gray-400 line-through">R$ {{ number_format($produtoSelecionado['preco_venda'], 2, ',', '.') }}</span>
                                <span class="text-sm font-bold text-green-600">R$ {{ number_format($produtoSelecionado['preco_venda'] - $produtoSelecionado['desconto_unit'], 2, ',', '.') }}</span>
                            @else
                                <span class="text-sm font-bold text-gray-700">R$ {{ number_format($produtoSelecionado['preco_base'], 2, ',', '.') }}</span>
                            @endif
                        </div>
                        @if (!empty($produtoSelecionado['controla_estoque']))
                            @php $saldoModal = (float) $produtoSelecionado['saldo_estoque']; @endphp
                            <p class="inline-flex items-center gap-1 text-xs font-semibold mt-1 {{ $saldoModal > 0 ? 'text-gray-500' : 'text-red-500' }}">
                                <i class='bx bxs-package'></i>
                                Estoque: {{ $saldoModal == floor($saldoModal) ? (int) $saldoModal : number_format($saldoModal, 2, ',', '.') }} {{ $produtoSelecionado['unidade_estoque'] }}
                            </p>
                        @endif
                    </div>
                    <button wire:click="fecharModal" type="button" class="text-gray-400 hover:text-gray-600 shrink-0 mt-0.5 transition-colors">
                        <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" /></svg>
                    </button>
                </div>

                <div class="px-5 py-4 space-y-5">

                    {{-- Quantidade --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Quantidade</label>
                        <div class="flex items-center gap-3">
                            <button wire:click="decrementarQuantidadeModal" type="button"
                                class="w-10 h-10 rounded-full bg-gray-100 hover:bg-red-50 text-gray-700 font-bold text-xl flex items-center justify-center transition-colors">−</button>
                            <span class="min-w-[3.5rem] text-center text-2xl font-bold text-gray-800 tabular-nums">
                                {{ $quantidade == floor($quantidade) ? (int)$quantidade : number_format($quantidade, 2, ',', '') }}
                            </span>
                            <button wire:click="incrementarQuantidadeModal" type="button"
                                class="w-10 h-10 rounded-full bg-gray-100 hover:bg-green-50 text-gray-700 font-bold text-xl flex items-center justify-center transition-colors">+</button>
                            <div class="flex gap-1.5 ml-1">
                                <button wire:click="setQuantidadeModal(0.3333)" type="button" class="px-2.5 py-1 bg-gray-100 hover:bg-teal-50 rounded-lg text-xs font-semibold text-gray-600 transition-colors">⅓</button>
                                <button wire:click="setQuantidadeModal(0.5)"  type="button" class="px-2.5 py-1 bg-gray-100 hover:bg-teal-50 rounded-lg text-xs font-semibold text-gray-600 transition-colors">½</button>
                            </div>
                        </div>
                    </div>

                    {{-- Cliente (apenas sessão de mesa) --}}
                    @if (count($sessaoMesaClientes) > 0)
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                <i class='bx bx-user text-teal-600'></i> Para qual cliente?
                            </label>
                            <div class="flex flex-wrap gap-2">
                                <button wire:click="$set('clienteSelecionadoId', null)" type="button"
                                        class="px-3 py-1.5 text-xs font-semibold rounded-full border-2 transition-colors
                                            {{ $clienteSelecionadoId === null ? 'bg-gray-800 text-white border-gray-800' : 'bg-white text-gray-600 border-gray-300 hover:border-gray-500' }}">
                                    Sem cliente
                                </button>
                                @foreach ($sessaoMesaClientes as $mc)
                                    <button wire:click="$set('clienteSelecionadoId', {{ $mc['id'] }})" type="button"
                                            class="px-3 py-1.5 text-xs font-semibold rounded-full border-2 transition-colors
                                                {{ $clienteSelecionadoId == $mc['id'] ? 'bg-teal-600 text-white border-teal-600' : 'bg-white text-gray-600 border-gray-300 hover:border-teal-400' }}">
                                        {{ $mc['nome'] }}
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- Adicionais --}}
                    @if (count($adicionaisDisponiveis) > 0)
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Adicionais</label>
                            <div class="space-y-1.5 max-h-40 overflow-y-auto">
                                @foreach ($adicionaisDisponiveis as $adicional)
                                    @php $sel = in_array($adicional['id'], $adicionaisSelecionados); @endphp
                                    <label class="flex items-center justify-between p-2.5 rounded-xl border cursor-pointer transition-colors
                                        {{ $sel ? 'border-teal-400 bg-teal-50' : 'border-gray-200 bg-white hover:border-teal-300' }}">
                                        <div class="flex items-center gap-2.5">
                                            <input type="checkbox"
                                                wire:click="toggleAdicional({{ $adicional['id'] }})"
                                                @checked($sel)
                                                class="w-4 h-4 rounded border-gray-300 text-teal-600 focus:ring-teal-500" />
                                            <span class="text-sm text-gray-700">{{ $adicional['nome'] }}</span>
                                        </div>
                                        <span class="text-sm font-semibold text-green-600">+ R$ {{ number_format($adicional['valor'], 2, ',', '.') }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- Observação --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Observação</label>
                        <textarea wire:model="observacao" rows="2"
                            placeholder="Ex: sem cebola, bem passado..."
                            class="w-full border border-gray-300 rounded-xl p-2.5 text-sm text-gray-700 bg-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent resize-none"
                        ></textarea>
                    </div>

                    {{-- Preview total --}}
                    @php
                        $adicionaisTotal = collect($adicionaisDisponiveis)->filter(fn($a) => in_array($a['id'], $adicionaisSelecionados))->sum('valor');
                        $precoEfetivo    = $produtoSelecionado['preco_base'] - $produtoSelecionado['desconto_unit'];
                        $totalPreview    = round(($precoEfetivo * $quantidade) + $adicionaisTotal, 2);
                    @endphp
                    <div class="flex items-center justify-between bg-gray-50 rounded-xl px-4 py-3">
                        <span class="text-sm text-gray-500">Total do item</span>
                        <span class="text-xl font-bold text-gray-800">R$ {{ number_format($totalPreview, 2, ',', '.') }}</span>
                    </div>

                    {{-- Botões --}}
                    <div class="flex gap-3">
                        <button wire:click="fecharModal" type="button"
                            class="flex-1 py-2.5 border border-gray-300 rounded-xl text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                            Cancelar
                        </button>
                        <button wire:click="confirmarItem" type="button"
                            class="flex-1 py-2.5 bg-teal-600 hover:bg-teal-700 text-white rounded-xl text-sm font-bold transition-colors shadow-sm">
                            Adicionar ao Pedido
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif


    {{-- ══ MODAL: editar item existente ══════════════════════════════════════ --}}
    @if ($editModalAberta && $editItemId)
        @php $editItem = collect($itens)->firstWhere('id', $editItemId); @endphp
        <div class="fixed inset-0 z-[60] flex items-center justify-center p-4" wire:keydown.escape="fecharEditModal">
            <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" wire:click="fecharEditModal"></div>

            <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md z-10 max-h-[90vh] overflow-y-auto">

                {{-- Header --}}
                <div class="flex items-center justify-between px-5 pt-5 pb-4 border-b border-gray-100">
                    <div>
                        <h3 class="text-base font-bold text-gray-800">{{ $editItem['produto_nome'] ?? 'Editar item' }}</h3>
                        <p class="text-xs text-gray-400 mt-0.5">Alterar observação e adicionais</p>
                    </div>
                    <button wire:click="fecharEditModal" type="button" class="text-gray-400 hover:text-gray-600 transition-colors">
                        <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" /></svg>
                    </button>
                </div>

                <div class="px-5 py-4 space-y-5">

                    {{-- Cliente (apenas sessão de mesa) --}}
                    @if (count($sessaoMesaClientes) > 0)
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                <i class='bx bx-user text-teal-600'></i> Para qual cliente?
                            </label>
                            <div class="flex flex-wrap gap-2">
                                <button wire:click="$set('editClienteId', null)" type="button"
                                        class="px-3 py-1.5 text-xs font-semibold rounded-full border-2 transition-colors
                                            {{ $editClienteId === null ? 'bg-gray-800 text-white border-gray-800' : 'bg-white text-gray-600 border-gray-300 hover:border-gray-500' }}">
                                    Sem cliente
                                </button>
                                @foreach ($sessaoMesaClientes as $mc)
                                    <button wire:click="$set('editClienteId', {{ $mc['id'] }})" type="button"
                                            class="px-3 py-1.5 text-xs font-semibold rounded-full border-2 transition-colors
                                                {{ $editClienteId == $mc['id'] ? 'bg-teal-600 text-white border-teal-600' : 'bg-white text-gray-600 border-gray-300 hover:border-teal-400' }}">
                                        {{ $mc['nome'] }}
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- Adicionais --}}
                    @if (count($editAdicionaisDisponiveis) > 0)
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Adicionais</label>
                            <div class="space-y-1.5 max-h-48 overflow-y-auto">
                                @foreach ($editAdicionaisDisponiveis as $adicional)
                                    @php $sel = in_array($adicional['id'], $editAdicionaisSelecionados); @endphp
                                    <label class="flex items-center justify-between p-2.5 rounded-xl border cursor-pointer transition-colors
                                        {{ $sel ? 'border-teal-400 bg-teal-50' : 'border-gray-200 bg-white hover:border-teal-300' }}">
                                        <div class="flex items-center gap-2.5">
                                            <input type="checkbox"
                                                wire:click="toggleEditAdicional({{ $adicional['id'] }})"
                                                @checked($sel)
                                                class="w-4 h-4 rounded border-gray-300 text-teal-600 focus:ring-teal-500" />
                                            <span class="text-sm text-gray-700">{{ $adicional['nome'] }}</span>
                                        </div>
                                        <span class="text-sm font-semibold text-green-600">+ R$ {{ number_format($adicional['valor'], 2, ',', '.') }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @else
                        <p class="text-sm text-gray-400 text-center py-2">Nenhum adicional disponível para este produto</p>
                    @endif

                    {{-- Observação --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Observação</label>
                        <textarea wire:model="editObservacao" rows="3"
                            placeholder="Ex: sem cebola, bem passado..."
                            class="w-full border border-gray-300 rounded-xl p-2.5 text-sm text-gray-700 bg-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent resize-none"
                        ></textarea>
                    </div>

                    {{-- Botões --}}
                    <div class="flex gap-3">
                        <button wire:click="fecharEditModal" type="button"
                            class="flex-1 py-2.5 border border-gray-300 rounded-xl text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                            Cancelar
                        </button>
                        <button wire:click="salvarEdicaoItem" type="button"
                            class="flex-1 py-2.5 bg-teal-600 hover:bg-teal-700 text-white rounded-xl text-sm font-bold transition-colors shadow-sm">
                            Salvar Alterações
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif


    {{-- ══ MODAL: sabores ════════════════════════════════════════════════════ --}}
    @if ($saboresModalAberta && count($saboresProdutos) > 0)
        @php
            $numSel = count($saboresSelecionados);
            $pronto = $numSel === $saboresModo;
        @endphp
        <div class="fixed inset-0 z-[60] flex items-end sm:items-center justify-center" wire:keydown.escape="fecharSaboresModal">
            <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" wire:click="fecharSaboresModal"></div>

            <div class="relative w-full max-w-lg bg-white rounded-t-2xl sm:rounded-2xl shadow-2xl flex flex-col max-h-[90vh] z-10">

                {{-- Toast temporário: erro/aviso de estoque (mesmo motivo do modal de item único) --}}
                @if ($erroEstoque || $avisoEstoque)
                    <div wire:key="toast-estoque-sabores-{{ md5(($erroEstoque ?? '').($avisoEstoque ?? '')) }}"
                         x-data
                         x-init="setTimeout(() => $wire.fecharToastEstoque(), 5000)"
                         class="absolute top-3 inset-x-3 z-20 flex items-start gap-2 px-3 py-2.5 rounded-xl border shadow-lg text-sm {{ $erroEstoque ? 'bg-red-50 border-red-200 text-red-700' : 'bg-amber-50 border-amber-200 text-amber-700' }}">
                        <i class='bx {{ $erroEstoque ? "bx-error-circle" : "bx-error" }} text-base mt-0.5 shrink-0'></i>
                        <span class="flex-1">{{ $erroEstoque ?? $avisoEstoque }}</span>
                        <button type="button" wire:click="fecharToastEstoque" class="shrink-0 opacity-60 hover:opacity-100">
                            <i class='bx bx-x text-lg'></i>
                        </button>
                    </div>
                @endif

                {{-- Header --}}
                <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100 shrink-0">
                    <div>
                        <h3 class="text-gray-800 font-bold text-base">{{ $saboresCategoriaNome }}</h3>
                        <p class="text-gray-500 text-xs mt-0.5">
                            Selecione <span class="text-teal-600 font-semibold">{{ $saboresModo }}</span>
                            sabor{{ $saboresModo > 1 ? 'es' : '' }}
                            @if ($numSel > 0)
                                — <span class="{{ $pronto ? 'text-green-600' : 'text-orange-500' }} font-semibold">{{ $numSel }}/{{ $saboresModo }}</span>
                            @endif
                        </p>
                    </div>
                    <button wire:click="fecharSaboresModal" type="button" class="text-gray-400 hover:text-gray-600 transition-colors">
                        <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" /></svg>
                    </button>
                </div>

                {{-- Seletor de modo --}}
                <div class="px-5 py-3 border-b border-gray-100 shrink-0">
                    <p class="text-gray-500 text-xs uppercase font-semibold tracking-wide mb-2">Como deseja?</p>
                    <div class="flex gap-2">
                        <button wire:click="setModoSabores(1)" type="button"
                            class="flex-1 border rounded-lg py-2 text-sm font-semibold transition-colors
                                {{ $saboresModo === 1 ? 'bg-teal-600 text-white border-teal-600' : 'bg-white text-gray-600 border-gray-300 hover:border-teal-400' }}">
                            <i class='bx bxs-circle text-xs mr-1'></i> Inteiro
                        </button>
                        @if ($saboresMaxModo >= 2)
                            <button wire:click="setModoSabores(2)" type="button"
                                class="flex-1 border rounded-lg py-2 text-sm font-semibold transition-colors
                                    {{ $saboresModo === 2 ? 'bg-teal-600 text-white border-teal-600' : 'bg-white text-gray-600 border-gray-300 hover:border-teal-400' }}">
                                <i class='bx bxs-pie-chart-alt-2 text-xs mr-1'></i> Meia a Meia
                            </button>
                        @endif
                        @if ($saboresMaxModo >= 3)
                            <button wire:click="setModoSabores(3)" type="button"
                                class="flex-1 border rounded-lg py-2 text-sm font-semibold transition-colors
                                    {{ $saboresModo === 3 ? 'bg-teal-600 text-white border-teal-600' : 'bg-white text-gray-600 border-gray-300 hover:border-teal-400' }}">
                                <i class='bx bxs-pie-chart text-xs mr-1'></i> Três Sabores
                            </button>
                        @endif
                    </div>
                </div>

                {{-- Cliente (apenas sessão de mesa) --}}
                @if (count($sessaoMesaClientes) > 0)
                    <div class="px-5 py-3 border-b border-gray-100 shrink-0">
                        <p class="text-gray-500 text-xs uppercase font-semibold tracking-wide mb-2">
                            <i class='bx bx-user text-teal-600'></i> Para qual cliente?
                        </p>
                        <div class="flex flex-wrap gap-2">
                            <button wire:click="$set('clienteSelecionadoId', null)" type="button"
                                    class="px-3 py-1.5 text-xs font-semibold rounded-full border-2 transition-colors
                                        {{ $clienteSelecionadoId === null ? 'bg-gray-800 text-white border-gray-800' : 'bg-white text-gray-600 border-gray-300 hover:border-gray-500' }}">
                                Sem cliente
                            </button>
                            @foreach ($sessaoMesaClientes as $mc)
                                <button wire:click="$set('clienteSelecionadoId', {{ $mc['id'] }})" type="button"
                                        class="px-3 py-1.5 text-xs font-semibold rounded-full border-2 transition-colors
                                            {{ $clienteSelecionadoId == $mc['id'] ? 'bg-teal-600 text-white border-teal-600' : 'bg-white text-gray-600 border-gray-300 hover:border-teal-400' }}">
                                    {{ $mc['nome'] }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Lista de sabores --}}
                <div class="overflow-y-auto flex-1 px-4 py-2">
                    @foreach ($saboresProdutos as $sabor)
                        @php
                            $selecionado = collect($saboresSelecionados)->contains('id', $sabor['id']);
                            $posIdx      = collect($saboresSelecionados)->search(fn($s) => $s['id'] === $sabor['id']);
                            $posLabel    = $saboresModo > 1 && $selecionado ? ($posIdx + 1) . 'º' : null;
                        @endphp
                        <div wire:key="sabor-{{ $sabor['id'] }}"
                             wire:click="toggleSabor({{ $sabor['id'] }})"
                             id="sabor-item-{{ $sabor['id'] }}"
                             class="flex items-center gap-3 p-3 mb-2 rounded-xl border-2 cursor-pointer transition-colors select-none
                                {{ $selecionado ? 'border-teal-500 bg-teal-50' : 'border-gray-200 bg-white hover:border-teal-300' }}">
                            @if (!empty($sabor['foto']))
                                <img src="{{ $sabor['foto'] }}" alt="{{ $sabor['nome'] }}" class="w-14 h-14 object-cover rounded-lg shrink-0 bg-gray-100"
                                     onerror="this.style.display='none'">
                            @else
                                <div class="w-14 h-14 rounded-lg bg-gray-100 flex items-center justify-center shrink-0">
                                    <svg class="w-6 h-6 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" /></svg>
                                </div>
                            @endif
                            <div class="flex-1 min-w-0">
                                <p class="text-gray-800 text-sm font-semibold leading-tight flex items-center gap-1">
                                    {{ $sabor['nome'] }}
                                    @if (!empty($sabor['temRelampago']))
                                        <span class="inline-flex items-center gap-0.5 text-[9px] font-bold text-white bg-red-600 rounded px-1 py-0.5 shrink-0">
                                            <i class='bx bxs-bolt text-[9px]'></i> RELÂMPAGO
                                        </span>
                                    @endif
                                </p>
                                @if (!empty($sabor['codimentacao']))
                                    <p class="text-xs text-gray-400 mt-0.5">{{ $sabor['codimentacao'] }}</p>
                                @endif
                                <div class="flex items-baseline gap-1.5 mt-0.5">
                                    @if ($sabor['preco'] < $sabor['precoOriginal'])
                                        <span class="text-gray-400 text-xs line-through">R$ {{ number_format($sabor['precoOriginal'], 2, ',', '.') }}</span>
                                        <span class="text-green-600 text-sm font-bold">R$ {{ number_format($sabor['preco'], 2, ',', '.') }}</span>
                                    @else
                                        <span class="text-gray-700 text-sm font-bold">R$ {{ number_format($sabor['preco'], 2, ',', '.') }}</span>
                                    @endif
                                </div>
                                @if (!empty($sabor['controlaEstoque']))
                                    @php $saldoSabor = (float) $sabor['saldoEstoque']; @endphp
                                    <span class="inline-flex items-center gap-0.5 text-[10px] font-semibold mt-0.5 {{ $saldoSabor > 0 ? 'text-gray-400' : 'text-red-500' }}">
                                        <i class='bx bxs-package'></i>
                                        {{ $saldoSabor == floor($saldoSabor) ? (int) $saldoSabor : number_format($saldoSabor, 2, ',', '.') }} {{ $sabor['unidadeEstoque'] }}
                                    </span>
                                @endif
                            </div>
                            <div class="w-7 h-7 rounded-full flex items-center justify-center shrink-0 transition-colors text-white text-xs font-bold
                                {{ $selecionado ? 'bg-teal-500' : 'bg-gray-200' }}">
                                @if ($selecionado){{ $posLabel ?? '✓' }}@endif
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- Rodapé --}}
                <div class="px-5 py-4 border-t border-gray-100 shrink-0 space-y-3">
                    @if ($numSel > 0)
                        <p class="text-gray-500 text-xs">
                            <span class="font-semibold text-gray-700">Selecionados:</span>
                            {{ implode(' / ', array_column($saboresSelecionados, 'nome')) }}
                        </p>
                    @endif
                    <div class="flex gap-3">
                        <button wire:click="fecharSaboresModal" type="button"
                            class="flex-1 py-2.5 border border-gray-300 rounded-xl text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                            Cancelar
                        </button>
                        <button wire:click="confirmarSabores" type="button"
                            @disabled(!$pronto)
                            class="flex-1 py-2.5 rounded-xl text-sm font-bold transition-colors shadow-sm
                                {{ $pronto ? 'bg-teal-600 hover:bg-teal-700 text-white' : 'bg-gray-100 text-gray-400 cursor-not-allowed' }}">
                            {{ $pronto ? 'Confirmar' : 'Selecione ' . ($saboresModo - $numSel) . ' mais' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

</div>
