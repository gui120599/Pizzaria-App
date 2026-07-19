<x-guest-layout>
    <div class="h-[100vh] w-full md:max-w-lg mx-auto" x-data>

        {{-- HEADER --}}
        <div class="h-[26%] grid grid-cols-7">
            <div class="col-span-2">
                <img src="{{ asset('img/logo Pizzaria Branco Colorido.png') }}" alt="" class="h-16">
            </div>
            <div class="col-span-4 flex items-center">
                <x-primary-button
                    @click="$store.cart.drawerOpen = true"
                    class="w-full flex items-center justify-center gap-1">
                    <i class='bx bxl-whatsapp'></i>
                    <span>Realizar Pedido</span>
                    <span x-show="$store.cart.count > 0"
                          x-text="$store.cart.count"
                          class="bg-white text-green-700 text-[10px] font-bold rounded-full w-4 h-4 flex items-center justify-center leading-none"
                          style="display:none"></span>
                </x-primary-button>
            </div>
            <div class="col-span-1 flex items-center justify-center">
                <div class="relative mt-2" x-data="{ menuOpen: false }" @keydown.escape.window="menuOpen = false">
                    <button type="button"
                        @click="menuOpen = !menuOpen"
                        class="text-white text-3xl focus:outline-none"
                        aria-label="Menu">
                        <i class='bx bx-dots-vertical-rounded'></i>
                    </button>
                    <div x-show="menuOpen"
                        @click.outside="menuOpen = false"
                        x-transition.origin.top.right
                        class="absolute right-0 mt-2 w-48 rounded-lg bg-white shadow-lg ring-1 ring-black/10 py-1 z-50"
                        style="display:none">
                        <a href="{{ route('filament.admin.auth.login') }}"
                            class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                            <i class='bx bx-shield-quarter text-lg'></i>
                            <span>Painel Admin</span>
                        </a>
                        <a href="{{ route('dashboard') }}"
                            class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                            <i class='bx bx-grid-alt text-lg'></i>
                            <span>Dashboard</span>
                        </a>
                    </div>
                </div>
            </div>
            <div class="col-span-full">
                <hr class="h-px my-1 border-0 bg-gray-400">
            </div>
            <div class="col-span-full flex gap-3 overflow-x-auto overflow-y-hidden px-1 py-2">
                @if ($promocoesRelampago->isNotEmpty())
                    <button class="flex flex-col items-center gap-1 shrink-0 w-16 focus:outline-none group"
                        onclick="scrollToElement('secao_relampago')">
                        <div class="w-14 h-14 rounded-xl overflow-hidden border-2 border-red-500 group-hover:border-red-400 transition-colors duration-150 shadow-sm bg-red-500/20 flex items-center justify-center">
                            <i class='bx bxs-bolt text-red-400 text-3xl'></i>
                        </div>
                        <span class="text-[9px] text-red-400 font-semibold uppercase tracking-wide leading-tight text-center line-clamp-2 w-full">Relâmpago</span>
                    </button>
                @endif
                @if ($promocoes->isNotEmpty())
                    <button class="flex flex-col items-center gap-1 shrink-0 w-16 focus:outline-none group"
                        onclick="scrollToElement('secao_promocoes')">
                        <div class="w-14 h-14 rounded-xl overflow-hidden border-2 border-orange-500 group-hover:border-orange-400 transition-colors duration-150 shadow-sm bg-orange-500/20 flex items-center justify-center">
                            <i class='bx bxs-purchase-tag text-orange-400 text-3xl'></i>
                        </div>
                        <span class="text-[9px] text-orange-400 font-semibold uppercase tracking-wide leading-tight text-center line-clamp-2 w-full">Promoções</span>
                    </button>
                @endif
                @if ($maisVendidos->isNotEmpty())
                    <button class="flex flex-col items-center gap-1 shrink-0 w-16 focus:outline-none group"
                        onclick="scrollToElement('secao_mais_vendidos')">
                        <div class="w-14 h-14 rounded-xl overflow-hidden border-2 border-yellow-500 group-hover:border-yellow-400 transition-colors duration-150 shadow-sm bg-yellow-500/20 flex items-center justify-center">
                            <i class='bx bxs-star text-yellow-400 text-3xl'></i>
                        </div>
                        <span class="text-[9px] text-yellow-400 font-semibold uppercase tracking-wide leading-tight text-center line-clamp-2 w-full">Mais Vendidos</span>
                    </button>
                @endif
                @foreach ($categorias as $categoria)
                    <x-categoria-button :categoria="$categoria" onclick="scrollToElement('categoria_{{ $categoria->id }}')" />
                @endforeach
            </div>
        </div>

        {{-- CONTENT --}}
        {{-- px-2 (sem padding-top): o offset do sticky (top-0) é calculado a
             partir da borda do padding do container com scroll — com
             padding-top, sobrava uma fresta acima do cabeçalho fixo onde o
             conteúdo já rolado aparecia por trás. --}}
        <div class="h-[74%] overflow-y-auto px-2 pb-2">

            {{-- Seção de Promoções Relâmpago (destaque, com contador de escassez) --}}
            @if ($promocoesRelampago->isNotEmpty())
                @foreach ($promocoesRelampago as $promo)
                    <div class="mb-6" id="{{ $loop->first ? 'secao_relampago' : 'secao_relampago_' . $promo['id'] }}">
                        <div class="rounded-t-xl bg-gradient-to-r from-red-600 to-orange-500 px-3 py-2 flex items-center justify-between gap-2">
                            <div class="flex items-center gap-2 min-w-0">
                                <i class='bx bxs-bolt text-yellow-300 text-2xl animate-pulse shrink-0'></i>
                                <div class="min-w-0">
                                    <h2 class="text-white font-extrabold uppercase text-base leading-tight truncate">{{ $promo['nome'] }}</h2>
                                    @if ($promo['descricao'])
                                        <p class="text-white/80 text-[11px] leading-tight truncate">{{ $promo['descricao'] }}</p>
                                    @endif
                                </div>
                            </div>
                            @if ($promo['exibe_contador'])
                                <div class="shrink-0 bg-white/95 text-red-600 rounded-lg px-2 py-1 text-center shadow">
                                    <span class="block text-lg font-extrabold leading-none">{{ number_format($promo['saldo'], 0, ',', '.') }}</span>
                                    <span class="block text-[9px] font-bold uppercase tracking-wide">restam</span>
                                </div>
                            @endif
                        </div>
                        <div class="border border-t-0 border-red-500/40 rounded-b-xl bg-red-500/5 p-2 grid grid-cols-1 gap-3">
                            @foreach ($promo['produtos'] as $produto)
                                <x-cardapio.promo-relampago-card :produto="$produto" />
                            @endforeach
                        </div>
                    </div>
                @endforeach
            @endif

            {{-- Seção de Promoções --}}
            @if ($promocoes->isNotEmpty())
                @php $catsPromo = $promocoes->pluck('categoria')->unique('id')->sortBy('categoria_nome'); @endphp
                <div class="mb-6" id="secao_promocoes" x-data="{ catAtiva: 'todos' }">
                    <div class="flex items-center gap-2 mb-2">
                        <i class='bx bxs-purchase-tag text-orange-400 text-2xl'></i>
                        <h2 class="text-lg text-orange-400 font-bold uppercase">Promoções</h2>
                    </div>
                    @if ($catsPromo->count() > 1)
                        <div class="flex gap-1 flex-wrap mb-3">
                            <button type="button" @click="catAtiva = 'todos'"
                                :class="catAtiva === 'todos' ? 'bg-orange-500 text-white border-orange-600' : 'bg-gray-700 text-gray-300 border-gray-600'"
                                class="px-2 py-0.5 rounded text-[10px] font-semibold border transition">Todos</button>
                            @foreach ($catsPromo as $cat)
                                <button type="button" @click="catAtiva = '{{ $cat->id }}'"
                                    :class="catAtiva === '{{ $cat->id }}' ? 'bg-orange-500 text-white border-orange-600' : 'bg-gray-700 text-gray-300 border-gray-600'"
                                    class="px-2 py-0.5 rounded text-[10px] font-semibold border transition">{{ $cat->categoria_nome }}</button>
                            @endforeach
                        </div>
                    @endif
                    <div class="grid grid-cols-1 gap-4">
                        @foreach ($promocoes as $produto)
                            @php
                                $nomeExibicao = $produto->nomeExibicao();
                                $nomeCarrinho = $nomeExibicao;
                                $preco = $produto->precoResolvido();
                                $precoCarrinho = $preco->precoFinal();
                            @endphp
                            <div class="relative snap-end" x-show="catAtiva === 'todos' || catAtiva === '{{ $produto->categoria->id }}'">
                                <a href="{{ route('produto.show', ['produto' => $produto]) }}">
                                    <div class="w-full p-2 rounded-lg flex items-start justify-between opacity-95 hover:opacity-100 gap-1 border border-orange-500/40 bg-orange-500/5">
                                        <div class="w-2/5 relative">
                                            <img src="{{ $produto->getImagemUrl() }}" alt="{{ $produto->produto_descricao }}" class="w-32 h-28 object-cover rounded-lg bg-white">
                                            <span class="absolute top-1 left-1 bg-orange-500 text-white text-[10px] font-bold px-1 py-0.5 rounded flex items-center gap-0.5"><i class='bx bxs-purchase-tag text-xs'></i> PROMO</span>
                                        </div>
                                        <div class="w-full h-28 flex flex-col justify-center space-y-1">
                                            <h2 class="text-gray-100 text-base uppercase">{{ $nomeExibicao }}</h2>
                                            <span class="text-gray-400 text-xs">Codimentos: {{ $produto->produto_codimentacao }}</span>
                                            <div class="flex flex-col">
                                                @if ($preco->descontoUnitario > 0)
                                                    <span class="text-gray-400 text-xs line-through">DE: R${{ number_format($preco->valorUnitario, 2, ',', '.') }}</span>
                                                @endif
                                                <span class="text-green-400 text-lg font-bold">POR: R${{ number_format($precoCarrinho, 2, ',', '.') }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </a>
                                <div class="absolute bottom-3 right-2 flex items-center gap-1 z-10">
                                    @if($produto->permiteSaboresCardapio())
                                        <div x-show="$store.cart.qty({{ $produto->id }}) > 0" class="flex items-center gap-1 bg-black/70 rounded-full px-1.5 py-0.5 text-white text-xs font-bold" style="display:none">
                                            <i class='bx bx-bowl-hot text-xs'></i>
                                            <span x-text="$store.cart.qty({{ $produto->id }})"></span>
                                        </div>
                                        <button @click.stop="$store.cart.abrirSabores({{ $produto->categoria->id }}, @js($produto->categoria->categoria_nome), {{ $produto->maxSaboresCardapio() }}, {{ $produto->id }})"
                                                class="w-8 h-8 rounded-full bg-green-500 hover:bg-green-400 active:scale-90 text-white text-xl font-bold flex items-center justify-center shadow-lg transition-all">+</button>
                                    @else
                                        <div x-show="$store.cart.qty({{ $produto->id }}) > 0" class="flex items-center gap-1 bg-black/70 rounded-full px-1.5 py-0.5" style="display:none">
                                            <button @click.stop="$store.cart.decrement({{ $produto->id }})" class="w-5 h-5 flex items-center justify-center text-white font-bold hover:text-red-400 transition text-base leading-none">−</button>
                                            <span class="text-white text-xs font-bold min-w-[0.75rem] text-center" x-text="$store.cart.qty({{ $produto->id }})"></span>
                                        </div>
                                        <button @click.stop="$store.cart.add({{ $produto->id }}, @js($nomeCarrinho), {{ $precoCarrinho }}, {{ $produto->produto_preco_venda }}, @js($produto->getImagemUrl()))"
                                                class="w-8 h-8 rounded-full bg-green-500 hover:bg-green-400 active:scale-90 text-white text-xl font-bold flex items-center justify-center shadow-lg transition-all">+</button>
                                    @endif
                                </div>
                                <hr class="h-px my-1 border-0 bg-orange-500/30">
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Seção Mais Vendidos --}}
            @if ($maisVendidos->isNotEmpty())
                @php $catsMV = $maisVendidos->pluck('categoria')->unique('id')->sortBy('categoria_nome'); @endphp
                <div class="mb-6" id="secao_mais_vendidos" x-data="{ catAtiva: 'todos' }">
                    <div class="flex items-center gap-2 mb-2">
                        <i class='bx bxs-star text-yellow-400 text-2xl'></i>
                        <h2 class="text-lg text-yellow-400 font-bold uppercase">Mais Vendidos</h2>
                    </div>
                    @if ($catsMV->count() > 1)
                        <div class="flex gap-1 flex-wrap mb-3">
                            <button type="button" @click="catAtiva = 'todos'"
                                :class="catAtiva === 'todos' ? 'bg-yellow-500 text-white border-yellow-600' : 'bg-gray-700 text-gray-300 border-gray-600'"
                                class="px-2 py-0.5 rounded text-[10px] font-semibold border transition">Todos</button>
                            @foreach ($catsMV as $cat)
                                <button type="button" @click="catAtiva = '{{ $cat->id }}'"
                                    :class="catAtiva === '{{ $cat->id }}' ? 'bg-yellow-500 text-white border-yellow-600' : 'bg-gray-700 text-gray-300 border-gray-600'"
                                    class="px-2 py-0.5 rounded text-[10px] font-semibold border transition">{{ $cat->categoria_nome }}</button>
                            @endforeach
                        </div>
                    @endif
                    <div class="grid grid-cols-1 gap-4">
                        @foreach ($maisVendidos as $produto)
                            @php
                                $nomeExibicao = $produto->nomeExibicao();
                                $nomeCarrinho = $nomeExibicao;
                                $preco = $produto->precoResolvido();
                                $precoCarrinho = $preco->precoFinal();
                            @endphp
                            <div class="relative snap-end" x-show="catAtiva === 'todos' || catAtiva === '{{ $produto->categoria->id }}'">
                                <a href="{{ route('produto.show', ['produto' => $produto]) }}">
                                    <div class="w-full p-2 rounded-lg flex items-start justify-between opacity-95 hover:opacity-100 gap-1 border border-yellow-500/40 bg-yellow-500/5">
                                        <div class="w-2/5 relative">
                                            <img src="{{ $produto->getImagemUrl() }}" alt="{{ $produto->produto_descricao }}" class="w-32 h-28 object-cover rounded-lg bg-white">
                                            <span class="absolute top-1 left-1 bg-yellow-500 text-white text-[10px] font-bold px-1 py-0.5 rounded flex items-center gap-0.5"><i class='bx bxs-star text-xs'></i> + VENDIDO</span>
                                            @if ($preco->descontoUnitario > 0)
                                                <span class="absolute bottom-1 left-1 bg-orange-500 text-white text-[10px] font-bold px-1 py-0.5 rounded flex items-center gap-0.5"><i class='bx bxs-purchase-tag text-xs'></i> PROMO</span>
                                            @endif
                                        </div>
                                        <div class="w-full h-28 flex flex-col justify-center space-y-1">
                                            <h2 class="text-gray-100 text-base uppercase">{{ $nomeExibicao }}</h2>
                                            <span class="text-gray-400 text-xs">Codimentos: {{ $produto->produto_codimentacao }}</span>
                                            <div class="flex flex-col">
                                                @if ($preco->descontoUnitario > 0)
                                                    <span class="text-gray-400 text-xs line-through">DE: R${{ number_format($preco->valorUnitario, 2, ',', '.') }}</span>
                                                    <span class="text-green-400 text-lg font-bold">POR: R${{ number_format($precoCarrinho, 2, ',', '.') }}</span>
                                                @else
                                                    <span class="text-white text-lg font-bold">R${{ number_format($precoCarrinho, 2, ',', '.') }}</span>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                </a>
                                <div class="absolute bottom-3 right-2 flex items-center gap-1 z-10">
                                    @if($produto->permiteSaboresCardapio())
                                        <div x-show="$store.cart.qty({{ $produto->id }}) > 0" class="flex items-center gap-1 bg-black/70 rounded-full px-1.5 py-0.5 text-white text-xs font-bold" style="display:none">
                                            <i class='bx bx-bowl-hot text-xs'></i>
                                            <span x-text="$store.cart.qty({{ $produto->id }})"></span>
                                        </div>
                                        <button @click.stop="$store.cart.abrirSabores({{ $produto->categoria->id }}, @js($produto->categoria->categoria_nome), {{ $produto->maxSaboresCardapio() }}, {{ $produto->id }})"
                                                class="w-8 h-8 rounded-full bg-green-500 hover:bg-green-400 active:scale-90 text-white text-xl font-bold flex items-center justify-center shadow-lg transition-all">+</button>
                                    @else
                                        <div x-show="$store.cart.qty({{ $produto->id }}) > 0" class="flex items-center gap-1 bg-black/70 rounded-full px-1.5 py-0.5" style="display:none">
                                            <button @click.stop="$store.cart.decrement({{ $produto->id }})" class="w-5 h-5 flex items-center justify-center text-white font-bold hover:text-red-400 transition text-base leading-none">−</button>
                                            <span class="text-white text-xs font-bold min-w-[0.75rem] text-center" x-text="$store.cart.qty({{ $produto->id }})"></span>
                                        </div>
                                        <button @click.stop="$store.cart.add({{ $produto->id }}, @js($nomeCarrinho), {{ $precoCarrinho }}, {{ $produto->produto_preco_venda }}, @js($produto->getImagemUrl()))"
                                                class="w-8 h-8 rounded-full bg-green-500 hover:bg-green-400 active:scale-90 text-white text-xl font-bold flex items-center justify-center shadow-lg transition-all">+</button>
                                    @endif
                                </div>
                                <hr class="h-px my-1 border-0 bg-yellow-500/30">
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Categorias --}}
            @foreach ($categorias as $categoria)
                <div class="mb-4" id="categoria_{{ $categoria->id }}" x-data="{ catFilhaAtiva: 'todos' }">
                    {{-- Sticky: fica fixo no topo do scroll enquanto o usuário navega
                         pelos produtos desta categoria, pra não se perder onde está. --}}
                    <h2 class="sticky top-0 z-20 bg-black py-2 text-lg text-white font-bold">{{ $categoria->categoria_nome }}</h2>

                    {{-- Filtro de subcategorias: mesmo padrão de pills da seção "Mais
                         Vendidos", mas em scroll horizontal — a categoria pai pode ter
                         várias filhas, que não cabem numa linha só. --}}
                    @if ($categoria->filhas->count() > 1)
                        <div class="flex gap-1 overflow-x-auto overflow-y-hidden pb-2 -mx-1 px-1">
                            <button type="button" @click="catFilhaAtiva = 'todos'"
                                :class="catFilhaAtiva === 'todos' ? 'bg-orange-500 text-white border-orange-600' : 'bg-gray-700 text-gray-300 border-gray-600'"
                                class="shrink-0 px-2 py-0.5 rounded text-[10px] font-semibold border transition whitespace-nowrap">Todos</button>
                            @foreach ($categoria->filhas as $filha)
                                <button type="button" @click="catFilhaAtiva = '{{ $filha->id }}'"
                                    :class="catFilhaAtiva === '{{ $filha->id }}' ? 'bg-orange-500 text-white border-orange-600' : 'bg-gray-700 text-gray-300 border-gray-600'"
                                    class="shrink-0 px-2 py-0.5 rounded text-[10px] font-semibold border transition whitespace-nowrap">{{ $filha->categoria_nome }}</button>
                            @endforeach
                        </div>
                    @endif

                    @if ($categoria->produtos->isEmpty() && $categoria->filhas->isEmpty())
                        <p class="text-gray-400">Não há produtos disponíveis nesta categoria.</p>
                    @endif

                    @if ($categoria->produtos->isNotEmpty())
                        <div class="grid grid-cols-1 gap-4 mb-3" x-show="catFilhaAtiva === 'todos'">
                            @foreach ($categoria->produtos as $produto)
                                <x-cardapio.produto-card :produto="$produto" :categoria="$categoria" :top10-ids="$top10Ids" />
                            @endforeach
                        </div>
                    @endif

                    {{-- Filhas: subseções da categoria pai, cada uma com seus próprios
                         produtos. O subtítulo também fica sticky, logo abaixo do nome
                         da pai (que continua fixo por cima). Sem margin-top aqui de
                         propósito — margem num elemento sticky cria a mesma fresta do
                         padding do container; o espaçamento vem do mb-3 do grid anterior. --}}
                    @foreach ($categoria->filhas as $filha)
                        <div x-show="catFilhaAtiva === 'todos' || catFilhaAtiva === '{{ $filha->id }}'">
                            <div class="sticky top-11 z-10 bg-black mb-2 py-1 pl-2 border-l-4 border-orange-500/60" id="categoria_{{ $filha->id }}">
                                <h3 class="text-base text-orange-300 font-bold uppercase tracking-wide">{{ $filha->categoria_nome }}</h3>
                            </div>
                            <div class="grid grid-cols-1 gap-4 mb-3">
                                @foreach ($filha->produtos as $produto)
                                    <x-cardapio.produto-card :produto="$produto" :categoria="$filha" :top10-ids="$top10Ids" />
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>

        {{-- Card de Avaliações --}}
       {{-- @if(! empty($avaliacaoLinks))
            <div class="px-4 pb-4 pt-2">
                <div class="bg-gray-900 rounded-2xl p-5 text-center">
                    <div class="flex items-center justify-center gap-0.5 mb-2">
                        @for($s = 0; $s < 5; $s++)
                            <i class='bx bxs-star text-yellow-400 text-2xl'></i>
                        @endfor
                    </div>
                    <p class="text-white font-bold text-sm mb-1">Gostou da nossa pizzaria?</p>
                    <p class="text-gray-400 text-xs mb-4">Deixe sua avaliação e nos ajude a melhorar!</p>
                    <div class="flex items-center justify-center gap-4 flex-wrap">
                        @foreach($avaliacaoLinks as $link)
                            <a href="{{ $link['avaliacao_link_url'] }}"
                               target="_blank"
                               rel="noopener noreferrer"
                               class="flex flex-col items-center gap-1.5 group">
                                <div class="w-12 h-12 rounded-full bg-white shadow flex items-center justify-center overflow-hidden border border-gray-200 group-hover:scale-110 transition-transform">
                                    <img src="{{ $link['avaliacao_link_logo_url'] }}" alt="{{ $link['avaliacao_link_nome'] }}" class="w-8 h-8 object-contain">
                                </div>
                                <span class="text-gray-400 text-[10px] group-hover:text-white transition-colors">{{ $link['avaliacao_link_nome'] }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif--}}

        {{-- Floating Cart Button --}}
        <div x-show="$store.cart.count > 0"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 translate-y-4"
             x-transition:enter-end="opacity-100 translate-y-0"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100 translate-y-0"
             x-transition:leave-end="opacity-0 translate-y-4"
             class="fixed bottom-5 inset-x-0 px-4 z-40 flex justify-center pointer-events-none"
             style="display:none">
            <button @click="$store.cart.drawerOpen = true"
                    class="pointer-events-auto w-full max-w-sm py-3 px-4 bg-green-500 hover:bg-green-400 active:bg-green-600 rounded-2xl text-white font-bold flex items-center justify-between shadow-xl transition-colors">
                <span class="bg-green-700 rounded-full w-7 h-7 flex items-center justify-center text-sm font-bold" x-text="$store.cart.count"></span>
                <span class="text-sm uppercase tracking-widest flex items-center gap-1"><i class='bx bx-cart'></i> Ver Carrinho</span>
                <span class="font-bold text-sm" x-text="'R$ ' + $store.cart.total.toFixed(2).replace('.', ',')"></span>
            </button>
        </div>

        {{-- Cart / Checkout Drawer --}}
        <div x-show="$store.cart.drawerOpen"
             class="fixed inset-0 z-50 flex flex-col justify-end"
             style="display:none">

            {{-- Backdrop --}}
            <div @click="$store.cart.drawerOpen = false"
                 x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                 x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                 class="absolute inset-0 bg-black/60"></div>

            {{-- Sheet --}}
            <div x-transition:enter="transition ease-out duration-300" x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0"
                 x-transition:leave="transition ease-in duration-200" x-transition:leave-start="translate-y-0" x-transition:leave-end="translate-y-full"
                 class="relative bg-gray-900 rounded-t-3xl max-h-[90vh] flex flex-col z-10 w-full md:max-w-lg md:mx-auto">

                {{-- Handle --}}
                <div class="flex justify-center pt-3 pb-1 shrink-0">
                    <div class="w-10 h-1 bg-gray-600 rounded-full"></div>
                </div>

                {{-- Header --}}
                <div class="flex items-center justify-between px-4 pb-3 border-b border-gray-700 shrink-0">
                    <div class="flex items-center gap-2">
                        <button x-show="$store.cart.step === 'checkout'"
                                @click="$store.cart.step = 'cart'"
                                class="text-gray-400 hover:text-white transition mr-1"
                                style="display:none">
                            <i class='bx bx-arrow-back text-xl'></i>
                        </button>
                        <h3 class="text-white font-bold text-lg flex items-center gap-2">
                            <i class='bx bx-cart text-green-400' x-show="$store.cart.step === 'cart'"></i>
                            <i class='bx bx-user text-green-400' x-show="$store.cart.step === 'checkout'" style="display:none"></i>
                            <span x-text="$store.cart.step === 'cart' ? 'Seu Pedido' : 'Seus Dados'"></span>
                        </h3>
                    </div>
                    <button @click="$store.cart.drawerOpen = false" class="text-gray-400 hover:text-white transition">
                        <i class='bx bx-x text-2xl'></i>
                    </button>
                </div>

                {{-- ── ETAPA 1: CARRINHO ── --}}
                <div x-show="$store.cart.step === 'cart'" class="flex flex-col flex-1 min-h-0">

                    {{-- Banner de fechado (reativo — recalcula ao abrir o drawer) --}}
                    <template x-if="!$store.cart.estaAbertoAgora()">
                        <div class="mx-4 mt-3 px-4 py-3 bg-red-900/60 border border-red-700 rounded-xl flex items-start gap-3">
                            <i class='bx bx-time-five text-red-400 text-xl shrink-0 mt-0.5'></i>
                            <div>
                                <p class="text-red-300 font-bold text-sm">Estamos fechados no momento</p>
                                <p class="text-red-400/80 text-xs mt-0.5"
                                   x-text="$store.cart.proximoHorarioAgora() ? 'Voltamos ' + $store.cart.proximoHorarioAgora() : ''"></p>
                            </div>
                        </div>
                    </template>

                    <div class="overflow-y-auto flex-1 px-4 py-3 space-y-1">
                        <template x-if="$store.cart.items.length === 0">
                            <div class="text-center py-10">
                                <i class='bx bx-cart-alt text-gray-600 text-5xl'></i>
                                <p class="text-gray-500 mt-2 text-sm">Nenhum item adicionado ainda</p>
                            </div>
                        </template>
                        <template x-for="item in $store.cart.items" :key="item.cartKey">
                            <div class="py-2 border-b border-gray-800">
                                <div class="flex items-center gap-3">
                                    <img x-show="item.foto" :src="item.foto" :alt="item.nome"
                                         class="w-10 h-10 object-cover rounded-lg shrink-0 bg-gray-700"
                                         style="display:none">
                                    <div class="flex items-center gap-1 shrink-0">
                                        <button @click="$store.cart.decrement(item.cartKey)"
                                                class="w-7 h-7 rounded-full bg-gray-700 hover:bg-gray-600 text-white flex items-center justify-center font-bold transition text-base">−</button>
                                        <span class="text-white font-bold w-6 text-center text-sm" x-text="item.qty"></span>
                                        <button @click="$store.cart.increment(item.cartKey)"
                                                class="w-7 h-7 rounded-full bg-gray-700 hover:bg-gray-600 text-white flex items-center justify-center font-bold transition text-base">+</button>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <span class="text-gray-200 text-sm leading-tight uppercase block" x-text="item.nome"></span>
                                        <span x-show="(item.precoOriginal ?? item.preco) > item.preco"
                                              class="text-orange-400 text-[10px] font-semibold"
                                              x-text="'PROMO — economize R$ ' + (((item.precoOriginal ?? item.preco) - item.preco) * item.qty).toFixed(2).replace('.', ',')"
                                              style="display:none"></span>
                                    </div>
                                    <div class="text-right shrink-0">
                                        <span x-show="(item.precoOriginal ?? item.preco) > item.preco"
                                              class="text-gray-500 text-xs line-through block"
                                              x-text="'R$ ' + ((item.precoOriginal ?? item.preco) * item.qty).toFixed(2).replace('.', ',')"
                                              style="display:none"></span>
                                        <span class="text-green-400 text-sm font-bold"
                                              x-text="'R$ ' + (item.preco * item.qty).toFixed(2).replace('.', ',')"></span>
                                    </div>
                                    <button @click="$store.cart.remove(item.cartKey)" class="text-gray-600 hover:text-red-400 transition shrink-0">
                                        <i class='bx bx-trash'></i>
                                    </button>
                                </div>
                                <input type="text"
                                       x-model="item.obs"
                                       placeholder="Observação (ex: sem cebola, bem passado...)"
                                       maxlength="120"
                                       class="mt-1.5 w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-1.5 text-gray-300 text-xs placeholder-gray-600 focus:outline-none focus:border-green-600 transition">
                            </div>
                        </template>
                    </div>
                    <div class="px-4 pb-6 pt-3 border-t border-gray-700 space-y-2 shrink-0">
                        {{-- Subtotal (só aparece quando tem desconto) --}}
                        <div x-show="$store.cart.totalDesconto > 0"
                             class="flex justify-between items-center"
                             style="display:none">
                            <span class="text-gray-500 text-sm">Subtotal</span>
                            <span class="text-gray-500 text-sm line-through"
                                  x-text="'R$ ' + $store.cart.totalOriginal.toFixed(2).replace('.', ',')"></span>
                        </div>
                        {{-- Linha de desconto --}}
                        <div x-show="$store.cart.totalDesconto > 0"
                             class="flex justify-between items-center bg-orange-500/10 border border-orange-500/30 rounded-lg px-3 py-1.5"
                             style="display:none">
                            <span class="text-orange-400 text-sm font-semibold flex items-center gap-1">
                                <i class='bx bxs-purchase-tag text-base'></i> Desconto promoções
                            </span>
                            <span class="text-orange-400 text-sm font-bold"
                                  x-text="'- R$ ' + $store.cart.totalDesconto.toFixed(2).replace('.', ',')"></span>
                        </div>
                        {{-- Total final --}}
                        <div class="flex justify-between items-center">
                            <span class="text-gray-400 font-semibold">Total</span>
                            <span class="text-green-400 text-2xl font-bold"
                                  x-text="'R$ ' + $store.cart.total.toFixed(2).replace('.', ',')"></span>
                        </div>
                        <button @click="$store.cart.step = 'checkout'"
                                :disabled="$store.cart.items.length === 0 || !$store.cart.estaAbertoAgora()"
                                class="w-full py-4 bg-green-500 hover:bg-green-400 disabled:opacity-50 disabled:cursor-not-allowed text-white font-bold rounded-xl flex items-center justify-center gap-2 uppercase tracking-wide transition-colors text-sm">
                            <template x-if="!$store.cart.estaAbertoAgora()">
                                <span class="flex items-center gap-2"><i class='bx bx-lock-alt'></i> Pedidos fechados</span>
                            </template>
                            <template x-if="$store.cart.estaAbertoAgora()">
                                <span class="flex items-center gap-2">Continuar <i class='bx bx-chevron-right text-lg'></i></span>
                            </template>
                        </button>
                        <button @click="$store.cart.clear()"
                                x-show="$store.cart.items.length > 0"
                                style="display:none"
                                class="w-full text-gray-500 hover:text-red-400 text-xs text-center transition py-1">
                            Limpar carrinho
                        </button>
                    </div>
                </div>

                {{-- ── ETAPA 2: DADOS DO CLIENTE ── --}}
                <div x-show="$store.cart.step === 'checkout'" class="flex flex-col flex-1 min-h-0" style="display:none">
                    <div class="overflow-y-auto flex-1 px-4 py-4 space-y-4">

                        {{-- Telefone com lookup --}}
                        <div>
                            <label class="text-gray-300 text-xs font-semibold uppercase tracking-wide block mb-1">
                                Telefone / WhatsApp <span class="text-red-400">*</span>
                            </label>
                            <div class="relative">
                                <input type="tel"
                                       x-model="$store.cart.form.telefone"
                                       @input="$store.cart.resetCamposCliente()"
                                       @input.debounce.600ms="$store.cart.lookupCliente()"
                                       placeholder="(64) 9 9999-9999"
                                       class="w-full bg-gray-800 border border-gray-600 text-white rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:border-green-500 pr-10"
                                       :class="$store.cart.erros.telefone ? 'border-red-500' : ''">
                                <div class="absolute right-3 top-1/2 -translate-y-1/2">
                                    <i class='bx bx-loader-alt bx-spin text-gray-400 text-sm'
                                       x-show="$store.cart.lookingUp"></i>
                                    <i class='bx bx-check-circle text-green-400 text-sm'
                                       x-show="$store.cart.clienteEncontrado && !$store.cart.lookingUp"
                                       style="display:none"></i>
                                </div>
                            </div>
                            <p x-show="$store.cart.clienteEncontrado && !$store.cart.lookingUp"
                               class="text-green-400 text-xs mt-1"
                               style="display:none">
                                <i class='bx bx-user'></i> Cliente encontrado — dados preenchidos automaticamente
                            </p>
                            <p x-show="$store.cart.erros.telefone" x-text="$store.cart.erros.telefone" class="text-red-400 text-xs mt-1" style="display:none"></p>
                        </div>

                        {{-- Nome --}}
                        <div>
                            <label class="text-gray-300 text-xs font-semibold uppercase tracking-wide block mb-1">
                                Nome <span class="text-red-400">*</span>
                            </label>
                            <input type="text"
                                   x-model="$store.cart.form.nome"
                                   placeholder="Seu nome completo"
                                   class="w-full bg-gray-800 border border-gray-600 text-white rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:border-green-500"
                                   :class="$store.cart.erros.nome ? 'border-red-500' : ''">
                            <p x-show="$store.cart.erros.nome" x-text="$store.cart.erros.nome" class="text-red-400 text-xs mt-1" style="display:none"></p>
                        </div>

                        {{-- Tipo de entrega --}}
                        <div>
                            <label class="text-gray-300 text-xs font-semibold uppercase tracking-wide block mb-2">
                                Como deseja receber? <span class="text-red-400">*</span>
                            </label>
                            <div class="grid grid-cols-{{ count($opcoesEntregas) }} gap-2">
                                @foreach ($opcoesEntregas as $opcao)
                                    <button type="button"
                                            @click="$store.cart.form.opcaoEntregaId = {{ $opcao['id'] }}; $store.cart.form.requerEndereco = {{ $opcao['requer_endereco'] ? 'true' : 'false' }}"
                                            :class="$store.cart.form.opcaoEntregaId === {{ $opcao['id'] }} ? 'border-green-500 bg-green-500/10 text-green-400' : 'border-gray-600 bg-gray-800 text-gray-300'"
                                            class="border rounded-lg px-3 py-2.5 text-xs font-semibold text-center transition-colors flex flex-col items-center gap-1">
                                        @if($opcao['requer_endereco'])
                                            <i class='bx bx-map text-base'></i>
                                        @else
                                            <i class='bx bx-store text-base'></i>
                                        @endif
                                        {{ $opcao['nome'] }}
                                    </button>
                                @endforeach
                            </div>
                            <p x-show="$store.cart.erros.opcao" x-text="$store.cart.erros.opcao" class="text-red-400 text-xs mt-1" style="display:none"></p>
                            {{-- Aviso de taxa de entrega --}}
                            @foreach($opcoesEntregas as $opcao)
                                @if($opcao['valor_frete'] > 0)
                                    <div x-show="$store.cart.form.opcaoEntregaId === {{ $opcao['id'] }}"
                                         style="display:none"
                                         class="mt-2">
                                        <div x-show="$store.cart.valorFrete > 0"
                                             class="flex items-center gap-1.5 text-yellow-400 text-xs bg-yellow-500/10 border border-yellow-500/30 rounded-lg px-3 py-2"
                                             style="display:none">
                                            <i class='bx bx-info-circle text-sm'></i>
                                            Taxa de entrega de R$ {{ number_format($opcao['valor_frete'], 2, ',', '.') }} para pedidos abaixo de R$ {{ number_format($opcao['min_frete'], 2, ',', '.') }}.
                                        </div>
                                        <div x-show="$store.cart.valorFrete === 0 && $store.cart.form.opcaoEntregaId === {{ $opcao['id'] }}"
                                             class="flex items-center gap-1.5 text-green-400 text-xs mt-1"
                                             style="display:none">
                                            <i class='bx bx-check-circle text-sm'></i> Frete grátis para este pedido!
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        </div>

                        {{-- Endereço (só se requer entrega) --}}
                        <div x-show="$store.cart.form.requerEndereco" style="display:none">
                            <label class="text-gray-300 text-xs font-semibold uppercase tracking-wide block mb-1">
                                Endereço de entrega <span class="text-red-400">*</span>
                            </label>
                            <input type="text"
                                   x-model="$store.cart.form.endereco"
                                   placeholder="Rua, número, bairro"
                                   class="w-full bg-gray-800 border border-gray-600 text-white rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:border-green-500"
                                   :class="$store.cart.erros.endereco ? 'border-red-500' : ''">
                            <p x-show="$store.cart.erros.endereco" x-text="$store.cart.erros.endereco" class="text-red-400 text-xs mt-1" style="display:none"></p>
                        </div>

                        {{-- Forma de pagamento --}}
                        <div>
                            <label class="text-gray-300 text-xs font-semibold uppercase tracking-wide block mb-2">
                                Forma de Pagamento <span class="text-red-400">*</span>
                            </label>
                            <div class="grid grid-cols-2 gap-2">
                                @foreach ($opcoesPagamento as $pag)
                                    <button type="button"
                                            @click="$store.cart.form.pagamentoId = {{ $pag['id'] }}; $store.cart.form.pagamentoNome = @js($pag['nome']); $store.cart.form.isDinheiro = {{ $pag['dinheiro'] ? 'true' : 'false' }}; $store.cart.form.trocoPara = ''; $store.cart.form.isDinheiro && $nextTick(() => $store.cart.trocoModal.open = true)"
                                            :class="$store.cart.form.pagamentoId === {{ $pag['id'] }} ? 'border-green-500 bg-green-500/10 text-green-400' : 'border-gray-600 bg-gray-800 text-gray-300'"
                                            class="border rounded-lg px-2 py-2.5 text-xs font-semibold text-center transition-colors flex flex-col items-center gap-1">
                                        @if($pag['dinheiro'])
                                            <i class='bx bx-money text-base'></i>
                                        @elseif(str_contains(strtolower($pag['nome']), 'pix'))
                                            <i class='bx bx-qr text-base'></i>
                                        @elseif(str_contains(strtolower($pag['nome']), 'débito') || str_contains(strtolower($pag['nome']), 'debito'))
                                            <i class='bx bx-credit-card text-base'></i>
                                        @else
                                            <i class='bx bx-credit-card-alt text-base'></i>
                                        @endif
                                        {{ $pag['nome'] }}
                                    </button>
                                @endforeach
                            </div>
                            {{-- Descrição da forma selecionada --}}
                            @foreach ($opcoesPagamento as $pag)
                                @if($pag['descricao'])
                                    <p x-show="$store.cart.form.pagamentoId === {{ $pag['id'] }}"
                                       class="text-blue-300 text-[11px] mt-1.5 leading-snug bg-blue-500/10 border border-blue-500/20 rounded px-2 py-1.5"
                                       style="display:none">
                                        <i class='bx bx-info-circle mr-0.5'></i>{{ $pag['descricao'] }}
                                    </p>
                                @endif
                            @endforeach
                            <p x-show="$store.cart.erros.pagamento" x-text="$store.cart.erros.pagamento" class="text-red-400 text-xs mt-1" style="display:none"></p>
                        </div>

                        {{-- Troco (só se pagamento for Dinheiro) --}}
                        <div x-show="$store.cart.form.isDinheiro" style="display:none">
                            <label class="text-gray-300 text-xs font-semibold uppercase tracking-wide block mb-1">
                                Troco para quanto? <span class="text-red-400">*</span>
                            </label>
                            <div class="relative">
                                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm font-medium">R$</span>
                                <input type="text"
                                       inputmode="numeric"
                                       x-model="$store.cart.form.trocoPara"
                                       @input="$store.cart.maskTroco($event)"
                                       placeholder="0,00"
                                       :class="$store.cart.erros.troco ? 'border-red-500' : 'border-gray-600 focus:border-green-500'"
                                       class="w-full bg-gray-800 border text-white rounded-lg pl-9 pr-3 py-2.5 text-sm focus:outline-none transition-colors">
                            </div>
                            <p class="text-gray-500 text-[10px] mt-1">Digite 0,00 se não precisar de troco</p>
                            <p x-show="$store.cart.erros.troco" x-text="$store.cart.erros.troco" class="text-red-400 text-xs mt-1" style="display:none"></p>
                        </div>

                        {{-- reCAPTCHA (só aparece se a chave estiver configurada) --}}
                        @if(config('services.recaptcha.site_key'))
                            <div>
                                <div class="g-recaptcha" data-sitekey="{{ config('services.recaptcha.site_key') }}" data-theme="dark"></div>
                                <p x-show="$store.cart.erros.recaptcha" x-text="$store.cart.erros.recaptcha" class="text-red-400 text-xs mt-1" style="display:none"></p>
                            </div>
                        @endif

                        {{-- Erro geral --}}
                        <div x-show="$store.cart.erros.geral"
                             class="bg-red-500/10 border border-red-500/40 rounded-lg px-3 py-2"
                             style="display:none">
                            <p x-text="$store.cart.erros.geral" class="text-red-400 text-xs"></p>
                        </div>
                    </div>

                    {{-- Resumo + botão final --}}
                    <div class="px-4 pb-8 pt-3 border-t border-gray-700 space-y-3 shrink-0">
                        <div class="flex justify-between items-center text-sm">
                            <span class="text-gray-400">
                                <span x-text="$store.cart.count"></span> iten(s)
                            </span>
                            <span class="text-gray-400 font-medium text-base"
                                  x-text="'R$ ' + $store.cart.totalOriginal.toFixed(2).replace('.', ',')"></span>
                        </div>
                        {{-- Desconto --}}
                        <div x-show="$store.cart.totalDesconto > 0"
                             class="flex justify-between items-center text-sm"
                             style="display:none">
                            <span class="text-green-400 flex items-center gap-1.5">
                                <i class='bx bx-tag text-base'></i>
                                Desconto
                            </span>
                            <span class="text-green-400 font-medium"
                                  x-text="'- R$ ' + $store.cart.totalDesconto.toFixed(2).replace('.', ',')"></span>
                        </div>
                        {{-- Taxa de entrega --}}
                        <div x-show="$store.cart.valorFrete > 0"
                             class="flex justify-between items-center bg-yellow-500/10 border border-yellow-500/30 rounded-lg px-3 py-2"
                             style="display:none">
                            <span class="text-yellow-400 text-sm flex items-center gap-1.5">
                                <i class='bx bx-cycling text-base'></i>
                                Taxa de entrega
                            </span>
                            <span class="text-yellow-300 text-sm font-bold"
                                  x-text="'+ R$ ' + $store.cart.valorFrete.toFixed(2).replace('.', ',')"></span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-gray-300 font-semibold text-sm">Total</span>
                            <span class="text-green-400 font-bold text-lg"
                                  x-text="'R$ ' + $store.cart.totalComFrete.toFixed(2).replace('.', ',')"></span>
                        </div>
                        <button @click="$store.cart.submitCheckout()"
                                :disabled="$store.cart.submitting || !$store.cart.estaAbertoAgora()"
                                class="w-full py-4 bg-green-500 hover:bg-green-400 disabled:opacity-60 disabled:cursor-not-allowed text-white font-bold rounded-xl flex items-center justify-center gap-2 uppercase tracking-wide transition-colors text-sm">
                            <template x-if="!$store.cart.submitting">
                                <span class="flex items-center gap-2"><i class='bx bxl-whatsapp text-xl'></i> Confirmar e Pedir</span>
                            </template>
                            <template x-if="$store.cart.submitting">
                                <span class="flex items-center gap-2"><i class='bx bx-loader-alt bx-spin text-xl'></i> Enviando...</span>
                            </template>
                        </button>
                    </div>
                </div>

            </div>
        </div>

    {{-- ═══════════════════════════════════════════════════ --}}
    {{-- Modal de Troco --}}
    {{-- ══════════════ --}}
    <div x-show="$store.cart.trocoModal.open"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         @click.self="$store.cart.trocoModal.open = false"
         class="fixed inset-0 z-50 bg-black/70 flex items-end justify-center"
         style="display:none">

        <div x-show="$store.cart.trocoModal.open"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="translate-y-full"
             x-transition:enter-end="translate-y-0"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="translate-y-0"
             x-transition:leave-end="translate-y-full"
             class="w-full max-w-lg bg-gray-900 rounded-t-2xl shadow-2xl">

            {{-- Header --}}
            <div class="flex items-center justify-between px-4 py-3 border-b border-gray-700">
                <div class="flex items-center gap-2">
                    <i class='bx bx-money text-green-400 text-xl'></i>
                    <div>
                        <h3 class="text-white font-bold text-base">Pagamento em dinheiro</h3>
                        <p class="text-gray-400 text-xs">Informe o valor para calcular o troco</p>
                    </div>
                </div>
                <button @click="$store.cart.trocoModal.open = false"
                        class="text-gray-400 hover:text-white transition p-1">
                    <i class='bx bx-x text-2xl'></i>
                </button>
            </div>

            {{-- Corpo --}}
            <div class="px-4 py-5 space-y-4">
                <div class="bg-yellow-500/10 border border-yellow-500/30 rounded-xl px-3 py-2.5 flex items-start gap-2">
                    <i class='bx bx-info-circle text-yellow-400 text-base mt-0.5 shrink-0'></i>
                    <p class="text-yellow-300 text-xs leading-snug">
                        Se precisar de troco, informe o valor que vai pagar. Se não precisar, clique em <strong>"Sem troco"</strong>.
                    </p>
                </div>

                <div>
                    <label class="text-gray-300 text-xs font-semibold uppercase tracking-wide block mb-1.5">
                        Troco para quanto? <span class="text-red-400">*</span>
                    </label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm font-medium">R$</span>
                        <input type="text"
                               inputmode="numeric"
                               x-model="$store.cart.form.trocoPara"
                               @input="$store.cart.maskTroco($event)"
                               x-init="$nextTick(() => $el.focus())"
                               placeholder="0,00"
                               class="w-full bg-gray-800 border border-gray-600 focus:border-green-500 text-white rounded-lg pl-9 pr-3 py-3 text-sm focus:outline-none transition-colors">
                    </div>
                    <p class="text-gray-500 text-[10px] mt-1">Digite 0,00 se não precisar de troco</p>
                </div>

                <div class="grid grid-cols-2 gap-2 pt-1">
                    <button type="button"
                            @click="$store.cart.form.trocoPara = '0,00'; $store.cart.trocoModal.open = false"
                            class="py-3 rounded-xl border border-gray-600 text-gray-300 text-sm font-semibold hover:bg-gray-800 transition-colors">
                        <i class='bx bx-x-circle mr-1'></i> Sem troco
                    </button>
                    <button type="button"
                            @click="$store.cart.trocoModal.open = false"
                            :disabled="!$store.cart.form.trocoPara?.trim()"
                            :class="$store.cart.form.trocoPara?.trim() ? 'bg-green-500 hover:bg-green-400 text-white cursor-pointer' : 'bg-gray-700 text-gray-500 cursor-not-allowed'"
                            class="py-3 rounded-xl text-sm font-bold transition-colors">
                        <i class='bx bx-check mr-1'></i> Confirmar
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Modal de Seleção de Sabores (Meia a Meia / Terços) --}}
    {{-- ═══════════════════════════════════════════════════ --}}
    <div x-show="$store.cart.saboresModal.open"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         @click.self="$store.cart.saboresModal.open = false"
         class="fixed inset-0 z-50 bg-black/70 flex items-end justify-center"
         style="display:none">

        <div x-show="$store.cart.saboresModal.open"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="translate-y-full"
             x-transition:enter-end="translate-y-0"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="translate-y-0"
             x-transition:leave-end="translate-y-full"
             class="w-full max-w-lg bg-gray-900 rounded-t-2xl shadow-2xl flex flex-col max-h-[90vh]">

            {{-- Header --}}
            <div class="flex items-center justify-between px-4 py-3 border-b border-gray-700 shrink-0">
                <div>
                    <h3 class="text-white font-bold text-base" x-text="$store.cart.saboresModal.categoriaNome"></h3>
                    <p class="text-gray-400 text-xs">Escolha os sabores da sua pizza</p>
                </div>
                <button @click="$store.cart.saboresModal.open = false"
                        class="text-gray-400 hover:text-white transition p-1">
                    <i class='bx bx-x text-2xl'></i>
                </button>
            </div>

            {{-- Seletor de modo --}}
            <div class="px-4 py-3 border-b border-gray-800 shrink-0">
                <p class="text-gray-400 text-xs uppercase font-semibold tracking-wide mb-2">Como deseja?</p>
                <div class="flex gap-2">
                    <button @click="$store.cart.setModoSabores(1)"
                            :class="$store.cart.saboresModal.modo === 1 ? 'bg-green-500 text-white border-green-500' : 'bg-gray-800 text-gray-300 border-gray-600'"
                            class="flex-1 border rounded-lg py-2 text-sm font-semibold transition-colors">
                        <i class='bx bxs-circle text-xs mr-1'></i> Inteiro
                    </button>
                    <button @click="$store.cart.setModoSabores(2)"
                            :class="$store.cart.saboresModal.modo === 2 ? 'bg-green-500 text-white border-green-500' : 'bg-gray-800 text-gray-300 border-gray-600'"
                            class="flex-1 border rounded-lg py-2 text-sm font-semibold transition-colors">
                        <i class='bx bxs-pie-chart-alt-2 text-xs mr-1'></i> Meia a Meia
                    </button>
                    <button x-show="$store.cart.saboresModal.maxSabores >= 3"
                            @click="$store.cart.setModoSabores(3)"
                            :class="$store.cart.saboresModal.modo === 3 ? 'bg-green-500 text-white border-green-500' : 'bg-gray-800 text-gray-300 border-gray-600'"
                            class="flex-1 border rounded-lg py-2 text-sm font-semibold transition-colors"
                            style="display:none">
                        <i class='bx bxs-pie-chart text-xs mr-1'></i> Três Sabores
                    </button>
                </div>
                <p class="text-gray-500 text-xs mt-1.5 text-center">
                    Selecione <span class="text-green-400 font-semibold" x-text="$store.cart.saboresModal.modo"></span>
                    sabor<span x-show="$store.cart.saboresModal.modo > 1">es</span>
                    <span x-show="$store.cart.saboresModal.selecionados.length > 0" class="text-gray-400">
                        — <span x-text="$store.cart.saboresModal.selecionados.length"></span>/<span x-text="$store.cart.saboresModal.modo"></span> escolhido<span x-show="$store.cart.saboresModal.selecionados.length > 1">s</span>
                    </span>
                </p>
            </div>

            {{-- Lista de produtos --}}
            <div class="overflow-y-auto flex-1 px-4 py-2">
                <template x-for="produto in $store.cart.saboresModal.produtos" :key="produto.id">
                    <div :id="'sabor-item-' + produto.id"
                         @click="$store.cart.toggleSabor(produto)"
                         :class="$store.cart.saboresModal.selecionados.find(s=>s.id===produto.id) ? 'border-green-500 bg-green-500/10' : 'border-gray-700 bg-gray-800/50'"
                         class="flex items-center gap-3 p-3 mb-2 rounded-xl border cursor-pointer transition-colors select-none">
                        <img :src="produto.foto" :alt="produto.nome" class="w-14 h-14 object-cover rounded-lg shrink-0 bg-gray-700">
                        <div class="flex-1 min-w-0">
                            <p class="text-white text-sm font-semibold uppercase leading-tight" x-text="produto.nome"></p>
                            <p class="text-green-400 text-sm font-bold mt-0.5"
                               x-text="'R$ ' + produto.preco.toFixed(2).replace('.', ',')"></p>
                        </div>
                        <div :class="$store.cart.saboresModal.selecionados.find(s=>s.id===produto.id) ? 'bg-green-500' : 'bg-gray-700'"
                             class="w-6 h-6 rounded-full flex items-center justify-center shrink-0 transition-colors">
                            <i class='bx bx-check text-white text-sm'
                               x-show="$store.cart.saboresModal.selecionados.find(s=>s.id===produto.id)"></i>
                        </div>
                    </div>
                </template>
            </div>

            {{-- Sabores selecionados + preview de preço --}}
            <div class="px-4 py-3 border-t border-gray-700 shrink-0 space-y-3">
                <div x-show="$store.cart.saboresModal.selecionados.length > 0" style="display:none">
                    <p class="text-gray-400 text-xs uppercase font-semibold mb-1">Selecionados:</p>
                    <p class="text-white text-sm font-medium leading-snug"
                       x-text="$store.cart.saboresModal.selecionados.map(s=>s.nome).join(' / ')"></p>
                    <p class="text-green-400 text-base font-bold mt-0.5"
                       x-text="'Preço: R$ ' + $store.cart.precoSabores($store.cart.saboresModal.selecionados).preco.toFixed(2).replace('.', ',')"></p>
                </div>
                <button @click="$store.cart.confirmarSabores()"
                        :disabled="$store.cart.saboresModal.selecionados.length !== $store.cart.saboresModal.modo"
                        :class="$store.cart.saboresModal.selecionados.length === $store.cart.saboresModal.modo ? 'bg-green-500 hover:bg-green-400 text-white' : 'bg-gray-700 text-gray-500 cursor-not-allowed'"
                        class="w-full py-3 rounded-xl font-bold text-base transition-colors flex items-center justify-center gap-2">
                    <i class='bx bx-cart-add text-xl'></i>
                    Adicionar ao Carrinho
                </button>
            </div>
        </div>
    </div>

    {{-- ══════════════════════════════════════════════════ --}}
    {{-- Botão flutuante de avaliações (speed dial)        --}}
    {{-- ══════════════════════════════════════════════════ --}}
    <template x-if="_avaliacaoLinks.length > 0">
        <div class="fixed bottom-6 left-4 z-40 flex flex-col items-center gap-2"
             x-data="{ open: false }"
             @click.away="open = false">

            {{-- Links expandidos acima do botão principal --}}
            <div x-show="open"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 translate-y-2"
                 x-transition:enter-end="opacity-100 translate-y-0"
                 x-transition:leave="transition ease-in duration-150"
                 x-transition:leave-start="opacity-100 translate-y-0"
                 x-transition:leave-end="opacity-0 translate-y-2"
                 class="flex flex-col items-center gap-2"
                 style="display:none">
                <template x-for="link in _avaliacaoLinks" :key="link.avaliacao_link_url">
                    <a :href="link.avaliacao_link_url"
                       target="_blank"
                       rel="noopener noreferrer"
                       class="w-12 h-12 rounded-full bg-white shadow-lg flex items-center justify-center overflow-hidden border border-gray-200 hover:scale-110 transition-transform"
                       :title="link.avaliacao_link_nome">
                        <img :src="link.avaliacao_link_logo_url"
                             :alt="link.avaliacao_link_nome"
                             class="w-8 h-8 object-contain">
                    </a>
                </template>
            </div>

            {{-- Botão principal --}}
            <button @click="open = !open"
                    class="w-12 h-12 rounded-full bg-yellow-400 hover:bg-yellow-300 shadow-lg flex items-center justify-center transition-all duration-200"
                    :class="open ? 'rotate-45' : ''"
                    title="Avalie-nos">
                <i class='bx bxs-star text-white text-2xl'></i>
            </button>
        </div>
    </template>

    </div>{{-- /x-data --}}

    @if(config('services.recaptcha.site_key'))
        <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    @endif

    <script>
        const _checkoutRoute        = @js(route('cardapio.checkout'));
        const _lookupRoute          = @js(route('cardapio.lookup_cliente'));
        const _csrfToken            = @js(csrf_token());
        const _opcoesEntregas       = @js($opcoesEntregas);
        const _opcoesPagamento      = @js($opcoesPagamento);
        const _categoriasComSabores = @js($categoriasComSabores);
        const _estaAberto           = @js($estaAberto);
        const _proximoHorario       = @js($proximoHorario);
        const _horarios             = @js($horarios);
        const _avaliacaoLinks       = @js($avaliacaoLinks);

        document.addEventListener('alpine:init', () => {
            Alpine.store('cart', {
                // ── Estado do carrinho ──
                items: JSON.parse(localStorage.getItem('cardapio_cart') || '[]').map(i => ({
                    cartKey: i.cartKey ?? String(i.id),
                    ...i,
                })),
                drawerOpen: new URLSearchParams(window.location.search).get('abrir') === 'carrinho',
                step: 'cart',   // 'cart' | 'checkout'

                // ── Modal de sabores ──
                saboresModal: {
                    open: false, categoriaId: null, categoriaNome: '', maxSabores: 2,
                    modo: 1, produtos: [], selecionados: [],
                },

                // ── Modal de troco ──
                trocoModal: { open: false },

                // ── Estado do formulário ──
                form: {
                    clienteId:      null,
                    nome:           '',
                    telefone:       '',
                    opcaoEntregaId: null,
                    requerEndereco: false,
                    endereco:       '',
                    pagamentoId:    null,
                    pagamentoNome:  '',
                    isDinheiro:     false,
                    trocoPara:      '',
                },
                lookingUp:        false,
                clienteEncontrado: false,
                submitting:       false,
                erros:            {},

                // ── Verificação de horário (recalcula a cada chamada) ──
                estaAbertoAgora() {
                    if (!_horarios.length) return true;
                    const agora = new Date();
                    const dia   = agora.getDay();
                    const hh    = agora.getHours().toString().padStart(2, '0');
                    const mm    = agora.getMinutes().toString().padStart(2, '0');
                    const hora  = `${hh}:${mm}`;
                    return _horarios.some(h => h.dia === dia && h.abertura <= hora && h.fechamento >= hora);
                },
                proximoHorarioAgora() {
                    if (!_horarios.length) return null;
                    const agora = new Date();
                    const dias  = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
                    const hh    = agora.getHours().toString().padStart(2, '0');
                    const mm    = agora.getMinutes().toString().padStart(2, '0');
                    const hora  = `${hh}:${mm}`;
                    const hoje  = _horarios.filter(h => h.dia === agora.getDay() && h.abertura > hora)
                        .sort((a, b) => a.abertura.localeCompare(b.abertura));
                    if (hoje.length) return 'hoje às ' + hoje[0].abertura;
                    for (let i = 1; i <= 7; i++) {
                        const dia  = (agora.getDay() + i) % 7;
                        const prox = _horarios.filter(h => h.dia === dia).sort((a, b) => a.abertura.localeCompare(b.abertura));
                        if (prox.length) {
                            const label = i === 1 ? 'amanhã' : (dia === 0 ? 'no Domingo' : dia === 6 ? 'no Sábado' : 'na ' + dias[dia] + '-feira');
                            return label + ' às ' + prox[0].abertura;
                        }
                    }
                    return null;
                },

                // ── Carrinho ──
                add(id, nome, preco, precoOriginal, foto = '') {
                    if (!this.estaAbertoAgora()) {
                        this.drawerOpen = true;
                        return;
                    }
                    const key = String(id);
                    const idx = this.items.findIndex(i => i.cartKey === key);
                    idx >= 0 ? this.items[idx].qty++ : this.items.push({ cartKey: key, id, nome, preco, precoOriginal: precoOriginal ?? preco, qty: 1, foto, obs: '' });
                    this.save();
                },
                increment(cartKey) {
                    const item = this.items.find(i => i.cartKey === String(cartKey));
                    if (item) { item.qty++; this.save(); }
                },
                decrement(cartKey) {
                    const idx = this.items.findIndex(i => i.cartKey === String(cartKey));
                    if (idx < 0) return;
                    this.items[idx].qty--;
                    if (this.items[idx].qty <= 0) this.items.splice(idx, 1);
                    this.save();
                },
                remove(cartKey) { this.items = this.items.filter(i => i.cartKey !== String(cartKey)); this.save(); },
                qty(id) { return this.items.filter(i => i.id === id).reduce((s, i) => s + i.qty, 0); },
                get total()        { return this.items.reduce((s, i) => s + i.preco * i.qty, 0); },
                get totalOriginal() { return this.items.reduce((s, i) => s + (i.precoOriginal ?? i.preco) * i.qty, 0); },
                get totalDesconto() { return Math.max(0, this.totalOriginal - this.total); },
                get count() { return this.items.reduce((s, i) => s + i.qty, 0); },
                get valorFrete() {
                    if (!this.form.opcaoEntregaId) return 0;
                    const opcao = _opcoesEntregas.find(o => o.id === this.form.opcaoEntregaId);
                    if (!opcao || !opcao.valor_frete) return 0;
                    if (opcao.min_frete > 0 && this.total >= opcao.min_frete) return 0;
                    return opcao.valor_frete;
                },
                get totalComFrete() { return this.total + this.valorFrete; },
                save()  { localStorage.setItem('cardapio_cart', JSON.stringify(this.items)); },
                clear() { this.items = []; localStorage.removeItem('cardapio_cart'); },

                // ── Sabores (meia a meia / terços) ──
                abrirSabores(categoriaId, categoriaNome, maxSabores, produtoId = null) {
                    if (!this.estaAbertoAgora()) {
                        this.drawerOpen = true;
                        return;
                    }
                    const cat      = _categoriasComSabores.find(c => c.id === categoriaId);
                    const produtos  = cat ? cat.produtos : [];
                    const presel   = produtoId ? (produtos.find(p => p.id === produtoId) ?? null) : null;
                    this.saboresModal = {
                        open: true, categoriaId, categoriaNome, maxSabores,
                        modo: 1, produtos, selecionados: presel ? [presel] : [],
                    };
                    if (presel) {
                        setTimeout(() => {
                            document.getElementById('sabor-item-' + presel.id)
                                ?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }, 320);
                    }
                },
                setModoSabores(modo) {
                    this.saboresModal.modo = modo;
                    this.saboresModal.selecionados = [];
                },
                toggleSabor(produto) {
                    const idx = this.saboresModal.selecionados.findIndex(s => s.id === produto.id);
                    if (idx >= 0) {
                        this.saboresModal.selecionados.splice(idx, 1);
                    } else if (this.saboresModal.modo === 1) {
                        // Inteiro: troca direta sem precisar desmarcar manualmente
                        this.saboresModal.selecionados = [produto];
                    } else if (this.saboresModal.selecionados.length < this.saboresModal.modo) {
                        this.saboresModal.selecionados.push(produto);
                    }
                },
                // Preço de pizza multi-sabor = MÉDIA dos sabores, distribuída em
                // centavos (mesma regra do back-end), evitando perda de arredondamento.
                // Retorna { preco (líquido), precoOriginal (bruto) } por pizza.
                precoSabores(sel) {
                    const n = sel.length;
                    if (n === 1) {
                        const s = sel[0];
                        return { preco: s.preco, precoOriginal: s.precoOriginal ?? s.preco };
                    }
                    const fatia = (valor, idx) => {
                        const tc = Math.round(valor * 100);
                        const base = Math.floor(tc / n);
                        const extra = tc % n;
                        return (base + (idx < extra ? 1 : 0)) / 100;
                    };
                    let bruto = 0, desc = 0;
                    sel.forEach((s, idx) => {
                        // Como fração, o produto usa precoFracao: numa promoção
                        // relâmpago "só inteira" isso é o preço normal (a promoção
                        // não vale meia a meia); nos demais casos == s.preco.
                        const precoFrac = s.precoFracao ?? s.preco;
                        const orig = Math.max(s.precoOriginal ?? precoFrac, precoFrac);
                        const descUnit = Math.max(0, orig - precoFrac);
                        bruto += fatia(orig, idx);
                        desc  += fatia(descUnit, idx);
                    });
                    bruto = Math.round(bruto * 100) / 100;
                    desc  = Math.round(desc * 100) / 100;
                    return { preco: Math.round((bruto - desc) * 100) / 100, precoOriginal: bruto };
                },
                confirmarSabores() {
                    if (!this.estaAbertoAgora()) {
                        this.saboresModal.open = false;
                        this.drawerOpen = true;
                        return;
                    }
                    const sel = this.saboresModal.selecionados;
                    if (sel.length !== this.saboresModal.modo) return;
                    const { preco, precoOriginal } = this.precoSabores(sel);
                    const nomes   = sel.map(s => s.nome).join(' / ');
                    const nome    = this.saboresModal.categoriaNome + ' — ' + nomes;
                    const cartKey = 'sabor-' + sel.map(s => s.id).sort((a,b)=>a-b).join('-');
                    const idx = this.items.findIndex(i => i.cartKey === cartKey);
                    const sabores = sel.length > 1 ? sel.map(s => ({ id: s.id, nome: s.nome, preco: s.preco, precoOriginal: s.precoOriginal ?? s.preco })) : null;
                    const foto = sel[0]?.foto ?? '';
                    idx >= 0 ? this.items[idx].qty++ : this.items.push({ cartKey, id: sel[0].id, nome, preco, precoOriginal, qty: 1, foto, sabores, obs: '' });
                    this.save();
                    this.saboresModal.open = false;
                },

                // ── Máscara monetária para troco ──
                maskTroco(e) {
                    let v = e.target.value.replace(/\D/g, '');
                    if (!v) { this.form.trocoPara = ''; e.target.value = ''; return; }
                    v = (parseInt(v) / 100).toFixed(2);
                    v = v.replace('.', ',').replace(/(\d)(?=(\d{3})+(?=,))/g, '$1.');
                    e.target.value = v;
                    this.form.trocoPara = v;
                },

                // ── Reset imediato ao alterar telefone ──
                resetCamposCliente() {
                    this.form.clienteId    = null;
                    this.form.nome         = '';
                    this.form.endereco     = '';
                    this.clienteEncontrado = false;
                },

                // ── Lookup de cliente por telefone ──
                async lookupCliente() {
                    const tel = this.form.telefone.replace(/\D/g, '');
                    if (tel.length < 8) { this.clienteEncontrado = false; return; }

                    this.lookingUp = true;
                    try {
                        const res  = await fetch(`${_lookupRoute}?telefone=${tel}`);
                        const data = await res.json();
                        if (data.encontrado) {
                            this.form.clienteId = data.cliente_id;
                            this.form.nome      = data.nome;
                            this.form.endereco  = data.endereco || '';
                            this.clienteEncontrado = true;
                        } else {
                            this.form.clienteId    = null;
                            this.clienteEncontrado = false;
                        }
                    } catch (_) {
                        this.clienteEncontrado = false;
                    } finally {
                        this.lookingUp = false;
                    }
                },

                // ── Validação do formulário ──
                validar() {
                    const e = {};
                    if (!this.form.telefone || this.form.telefone.replace(/\D/g, '').length < 8)
                        e.telefone = 'Informe um telefone válido.';
                    if (!this.form.nome?.trim())
                        e.nome = 'Informe seu nome.';
                    if (!this.form.opcaoEntregaId)
                        e.opcao = 'Selecione como deseja receber.';
                    if (this.form.requerEndereco && !this.form.endereco?.trim())
                        e.endereco = 'Informe o endereço de entrega.';
                    if (!this.form.pagamentoId)
                        e.pagamento = 'Selecione a forma de pagamento.';
                    if (this.form.isDinheiro && !this.form.trocoPara?.trim())
                        e.troco = 'Informe o valor para troco (ou 0,00 se não precisar).';
                    this.erros = e;
                    return Object.keys(e).length === 0;
                },

                // ── Checkout ──
                async submitCheckout() {
                    if (!this.estaAbertoAgora()) {
                        const proximo = this.proximoHorarioAgora();
                        this.erros = { geral: 'Não estamos aceitando pedidos no momento.' + (proximo ? ' Voltamos ' + proximo + '.' : '') };
                        return;
                    }
                    if (!this.validar()) return;

                    // reCAPTCHA v2 token (se widget estiver presente)
                    let recaptchaToken = null;
                    if (typeof grecaptcha !== 'undefined') {
                        recaptchaToken = grecaptcha.getResponse();
                        if (!recaptchaToken) {
                            this.erros = { ...this.erros, recaptcha: 'Confirme que você não é um robô.' };
                            return;
                        }
                    }

                    this.submitting = true;
                    this.erros = {};

                    try {
                        const res = await fetch(_checkoutRoute, {
                            method:  'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': _csrfToken,
                            },
                            body: JSON.stringify({
                                nome:             this.form.nome.trim(),
                                telefone:         this.form.telefone,
                                opcao_entrega_id: this.form.opcaoEntregaId,
                                endereco:         this.form.endereco.trim() || null,
                                pagamento_nome:   this.form.pagamentoNome,
                                troco_para:       this.form.isDinheiro && this.form.trocoPara
                                    ? parseFloat(this.form.trocoPara.replace(/\./g, '').replace(',', '.')) || null
                                    : null,
                                recaptcha_token:  recaptchaToken,
                                itens: this.items.map(i => ({
                                    id:             i.id,
                                    qty:            i.qty,
                                    preco:          i.preco,
                                    preco_original: i.precoOriginal ?? i.preco,
                                    observacao:     i.obs?.trim() || null,
                                    sabores:        i.sabores ?? null,
                                })),
                            }),
                        });

                        const data = await res.json();

                        if (!res.ok) {
                            this.erros = { geral: data.message || 'Erro ao registrar pedido. Tente novamente.' };
                            return;
                        }

                        // Abre WhatsApp com a mensagem + número do pedido
                        const tel     = '5564981380071';
                        const opcao   = _opcoesEntregas.find(o => o.id === this.form.opcaoEntregaId);
                        let msg = `Olá! Gostaria de confirmar meu pedido *#${data.pedido_id}*:\n\n`;
                        this.items.forEach(i => {
                            const precoExib = (i.precoOriginal ?? i.preco);
                            const sub = (precoExib * i.qty).toFixed(2).replace('.', ',');
                            msg += `• ${i.qty}x ${i.nome} — R$${sub}\n`;
                        });
                        if (data.total_desconto > 0)
                            msg += `\nDesconto: -R$${data.total_desconto.toFixed(2).replace('.', ',')}`;
                        if (data.valor_frete > 0)
                            msg += `\nTaxa de entrega: R$${data.valor_frete.toFixed(2).replace('.', ',')}`;
                        msg += `\n*Total: R$${data.total_final.toFixed(2).replace('.', ',')}*`;
                        msg += `\n*Entrega:* ${opcao?.nome ?? ''}`;
                        if (this.form.endereco) msg += `\n*Endereço:* ${this.form.endereco}`;
                        msg += `\n*Pagamento:* ${this.form.pagamentoNome}`;
                        if (this.form.isDinheiro && this.form.trocoPara)
                            msg += ` (troco para R$${parseFloat(this.form.trocoPara).toFixed(2).replace('.', ',')})`;
                        msg += '\n\nAguardo confirmação! 🍕';
                        msg += `\n\n🔍 *Acompanhe seu pedido em tempo real:*\n${window.location.origin}/acompanhar/${data.pedido_id}`;

                        window.open(`https://wa.me/${tel}?text=${encodeURIComponent(msg)}`, '_blank');

                        // Limpa tudo
                        this.clear();
                        this.drawerOpen   = false;
                        this.step         = 'cart';
                        this.form         = { clienteId: null, nome: '', telefone: '', opcaoEntregaId: null, requerEndereco: false, endereco: '', pagamentoId: null, pagamentoNome: '', isDinheiro: false, trocoPara: '' };
                        this.clienteEncontrado = false;

                    } catch (_) {
                        this.erros = { geral: 'Falha de conexão. Verifique sua internet.' };
                    } finally {
                        this.submitting = false;
                    }
                },
            });
        });

        function scrollToElement(elementId) {
            const el = document.getElementById(elementId);
            if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    </script>
</x-guest-layout>
