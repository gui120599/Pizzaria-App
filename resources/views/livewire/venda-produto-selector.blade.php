<div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-white/10 overflow-hidden">

    {{-- Busca + categorias --}}
    <div class="px-4 pt-4 pb-3 border-b border-gray-100 dark:border-white/10 space-y-3">
        <div class="relative">
            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 dark:text-gray-500 pointer-events-none" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
            </svg>
            <input
                wire:model.live.debounce.300ms="busca"
                type="text"
                data-busca-input
                placeholder="Buscar produto..."
                class="w-full pl-9 pr-4 py-2 text-sm border border-gray-300 dark:border-white/10 rounded-xl bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-200 placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent"
            />
        </div>

        <div class="flex gap-2 overflow-x-auto pb-1">
            <button wire:click="$set('categoriaId', null)" type="button"
                    class="shrink-0 px-3 py-1.5 rounded-full text-xs font-semibold uppercase tracking-wide border transition-colors
                        {{ $categoriaId === null ? 'bg-primary-600 border-primary-600 text-white' : 'border-gray-300 dark:border-white/10 text-gray-600 dark:text-gray-300 hover:border-gray-400 dark:hover:border-white/20' }}">
                Todos
            </button>
            @foreach ($this->categorias as $categoria)
                <button wire:click="$set('categoriaId', {{ $categoria->id }})" type="button"
                        class="shrink-0 px-3 py-1.5 rounded-full text-xs font-semibold uppercase tracking-wide border transition-colors
                            {{ $categoriaId === $categoria->id ? 'bg-primary-600 border-primary-600 text-white' : 'border-gray-300 dark:border-white/10 text-gray-600 dark:text-gray-300 hover:border-gray-400 dark:hover:border-white/20' }}">
                    {{ $categoria->categoria_nome }}
                </button>
            @endforeach
        </div>
    </div>

    {{-- Grid de produtos: um clique dispara "produto-selecionado" para a Page pai --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 p-4 overflow-y-auto" style="max-height: clamp(18rem, 55vh, 44rem)">
        @forelse ($this->produtos as $produto)
            @php
                $precoVenda = (float) $produto->produto_preco_venda;
                $precoPromo = (float) ($produto->produto_preco_promocional ?? 0);
                $temPromo = $precoPromo > 0 && $precoPromo < $precoVenda;
                $precoExibido = $temPromo ? $precoPromo : $precoVenda;
            @endphp
            <button
                wire:key="produto-{{ $produto->id }}"
                wire:click="selecionarProduto({{ $produto->id }})"
                wire:loading.attr="disabled"
                wire:target="selecionarProduto({{ $produto->id }})"
                type="button"
                class="flex flex-col text-left rounded-xl border border-gray-200 dark:border-white/10 overflow-hidden hover:border-primary-400 dark:hover:border-primary-500 hover:shadow-sm transition-all disabled:opacity-50"
            >
                <div class="relative aspect-square bg-gray-100 dark:bg-gray-900 flex items-center justify-center overflow-hidden">
                    @if ($produto->getImagemUrl())
                        <img src="{{ $produto->getImagemUrl() }}" alt="{{ $produto->produto_descricao }}" class="w-full h-full object-cover">
                    @else
                        <x-filament::icon icon="heroicon-o-cake" class="h-8 w-8 text-gray-300 dark:text-gray-600" />
                    @endif
                    @if ($temPromo)
                        <span class="absolute top-1 left-1 bg-orange-500 text-white text-[9px] font-bold px-1 py-0.5 rounded">PROMO</span>
                    @endif
                </div>
                <div class="p-2 space-y-0.5">
                    @if ($produto->categoria)
                        <span class="inline-block text-[9px] font-semibold uppercase tracking-wide text-orange-600 dark:text-orange-400 bg-orange-50 dark:bg-orange-500/10 rounded px-1">{{ $produto->categoria->categoria_nome }}</span>
                    @endif
                    <p class="text-xs font-medium text-gray-800 dark:text-gray-200 leading-tight line-clamp-2">{{ $produto->produto_descricao }}</p>
                    @if ($temPromo)
                        <p class="flex items-center gap-1">
                            <span class="text-[10px] text-gray-400 dark:text-gray-500 line-through">R$ {{ number_format($precoVenda, 2, ',', '.') }}</span>
                            <span class="text-xs font-semibold text-orange-600 dark:text-orange-400">R$ {{ number_format($precoExibido, 2, ',', '.') }}</span>
                        </p>
                    @else
                        <p class="text-xs font-semibold text-primary-700 dark:text-primary-400">R$ {{ number_format($precoExibido, 2, ',', '.') }}</p>
                    @endif
                    @if ($produto->produto_controla_estoque)
                        @php $saldo = (float) $produto->produto_saldo_estoque; @endphp
                        <span class="inline-flex items-center gap-0.5 text-[9px] font-semibold {{ $saldo > 0 ? 'text-gray-400 dark:text-gray-500' : 'text-red-500 dark:text-red-400' }}">
                            <x-filament::icon icon="heroicon-o-archive-box" class="h-2.5 w-2.5" />
                            {{ $saldo == floor($saldo) ? (int) $saldo : number_format($saldo, 2, ',', '.') }} {{ $produto->produto_unidade_estoque }}
                        </span>
                    @endif
                </div>
            </button>
        @empty
            <div class="col-span-full text-center text-sm text-gray-400 dark:text-gray-500 py-10">
                Nenhum produto encontrado.
            </div>
        @endforelse
    </div>
</div>
