{{-- Linha de um item do carrinho — compartilhada entre o drawer legado (Blade),
     a section de carrinho da AtenderPedido (Filament, acima do Cliente) e o
     PedidoProdutoSelector ($carrinhoTopo). Espera receber $item no escopo.
     id/produto_nome levam fallback porque alguns testes montam o array de
     itens só com os campos usados no cálculo de totais (produto_id,
     quantidade, valor, desconto), sem os campos de exibição. --}}
@php
    $itemId = $item['id'] ?? $item['produto_id'] ?? 0;
    $itemNome = $item['produto_nome'] ?? '—';
@endphp
<div wire:key="item-{{ $itemId }}" class="flex items-center gap-3 px-4 py-3 bg-white dark:bg-gray-800">

    {{-- Imagem --}}
    @if (!empty($item['produto_foto']))
        <img src="{{ $item['produto_foto'] }}" alt="{{ $itemNome }}"
             class="w-10 h-10 object-cover rounded-lg shrink-0 bg-gray-100 dark:bg-gray-900"
             onerror="this.style.display='none'">
    @else
        <div class="w-10 h-10 rounded-lg bg-gray-100 dark:bg-gray-900 flex items-center justify-center shrink-0">
            <svg class="w-4 h-4 text-gray-400 dark:text-gray-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" /></svg>
        </div>
    @endif

    {{-- Info --}}
    <div class="flex-1 min-w-0">
        <div class="flex flex-wrap items-center gap-1 mb-0.5">
            @if (!empty($item['categoria_nome']))
                <span class="inline-block text-[10px] font-semibold uppercase tracking-wide text-teal-700 dark:text-teal-400 bg-teal-50 dark:bg-teal-500/10 border border-teal-200 dark:border-teal-500/20 rounded px-1.5 py-0.5">{{ $item['categoria_nome'] }}</span>
            @endif
            @if (!empty($item['cliente_nome']))
                <span class="inline-flex items-center gap-0.5 text-[10px] font-semibold text-indigo-700 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-500/10 border border-indigo-200 dark:border-indigo-500/20 rounded px-1.5 py-0.5">
                    <i class='bx bx-user text-[9px]'></i> {{ $item['cliente_nome'] }}
                </span>
            @endif
            @if (!empty($item['promocao_id']))
                <span class="inline-flex items-center gap-0.5 text-[10px] font-bold text-white bg-red-600 rounded px-1.5 py-0.5">
                    <i class='bx bxs-bolt text-[9px]'></i> RELÂMPAGO
                </span>
            @endif
        </div>
        <p class="text-sm font-medium text-gray-800 dark:text-gray-200 truncate">{{ $itemNome }}</p>
        @if (!empty($item['adicionais']))
            <p class="text-xs text-gray-500 dark:text-gray-400 truncate">+ {{ collect($item['adicionais'])->pluck('nome')->join(', ') }}</p>
        @endif
        @if (!empty($item['observacao']))
            <p class="text-xs text-gray-400 dark:text-gray-500 italic truncate">{{ $item['observacao'] }}</p>
        @endif
    </div>

    {{-- Quantidade --}}
    @if (!empty($item['promocao_id']))
        {{-- Item promocional: preço e quantidade congelados no momento da
             venda (o débito da promoção não pode ser ajustado parcialmente).
             Para mudar a quantidade, remova e adicione de novo. --}}
        <div class="flex items-center gap-1 shrink-0 px-2" title="Quantidade fixa: item da promoção relâmpago">
            <i class='bx bxs-lock-alt text-gray-300 dark:text-gray-600 text-sm'></i>
            <span class="w-8 text-center text-sm font-semibold text-gray-700 dark:text-gray-300 tabular-nums">
                {{ $item['quantidade'] == floor($item['quantidade']) ? (int)$item['quantidade'] : number_format($item['quantidade'], 2, ',', '') }}
            </span>
        </div>
    @else
        <div class="flex items-center gap-1 shrink-0">
            <button wire:click="decrementarQtd('{{ $itemId }}')" type="button"
                class="w-7 h-7 rounded-full bg-gray-100 hover:bg-red-100 dark:bg-white/5 dark:hover:bg-red-500/10 text-gray-600 dark:text-gray-300 flex items-center justify-center text-base font-bold leading-none transition-colors">−</button>
            <span class="w-8 text-center text-sm font-semibold text-gray-700 dark:text-gray-300 tabular-nums">
                {{ $item['quantidade'] == floor($item['quantidade']) ? (int)$item['quantidade'] : number_format($item['quantidade'], 2, ',', '') }}
            </span>
            <button wire:click="incrementarQtd('{{ $itemId }}')" type="button"
                class="w-7 h-7 rounded-full bg-gray-100 hover:bg-green-100 dark:bg-white/5 dark:hover:bg-green-500/10 text-gray-600 dark:text-gray-300 flex items-center justify-center text-base font-bold leading-none transition-colors">+</button>
        </div>
    @endif

    {{-- Valor --}}
    <div class="text-right shrink-0 min-w-[5rem]">
        @if ($item['desconto'] > 0)
            <p class="text-xs text-gray-400 dark:text-gray-500 line-through leading-tight">R$ {{ number_format($item['valor'] + $item['desconto'], 2, ',', '.') }}</p>
            <p class="text-sm font-bold text-green-600 dark:text-green-400">R$ {{ number_format($item['valor'], 2, ',', '.') }}</p>
        @else
            <p class="text-sm font-bold text-gray-800 dark:text-gray-200">R$ {{ number_format($item['valor'], 2, ',', '.') }}</p>
        @endif
    </div>

    {{-- Editar --}}
    <button wire:click="abrirEditModal('{{ $itemId }}')" type="button"
        class="w-7 h-7 rounded-full bg-gray-100 hover:bg-blue-100 dark:bg-white/5 dark:hover:bg-blue-500/10 text-gray-400 hover:text-blue-600 dark:text-gray-500 dark:hover:text-blue-400 flex items-center justify-center shrink-0 transition-colors"
        title="Editar observação/adicionais">
        <svg class="w-3.5 h-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
            <path d="M2.695 14.763l-1.262 3.154a.5.5 0 00.65.65l3.155-1.262a4 4 0 001.343-.885L17.5 5.5a2.121 2.121 0 00-3-3L3.58 13.42a4 4 0 00-.885 1.343z" />
        </svg>
    </button>

    {{-- Remover --}}
    <button wire:click="removerItem('{{ $itemId }}')"
        wire:confirm="Remover '{{ $itemNome }}' do pedido?"
        type="button"
        class="w-7 h-7 rounded-full bg-red-50 hover:bg-red-100 dark:bg-red-500/10 dark:hover:bg-red-500/20 text-red-500 dark:text-red-400 flex items-center justify-center shrink-0 transition-colors"
        title="Remover">
        <svg class="w-3.5 h-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M8.75 1A2.75 2.75 0 006 3.75v.443c-.795.077-1.584.176-2.365.298a.75.75 0 10.23 1.482l.149-.022.841 10.518A2.75 2.75 0 007.596 19h4.807a2.75 2.75 0 002.742-2.53l.841-10.52.149.023a.75.75 0 00.23-1.482A41.03 41.03 0 0014 4.193V3.75A2.75 2.75 0 0011.25 1h-2.5zM10 4c.84 0 1.673.025 2.5.075V3.75c0-.69-.56-1.25-1.25-1.25h-2.5c-.69 0-1.25.56-1.25 1.25v.325C8.327 4.025 9.16 4 10 4zM8.58 7.72a.75.75 0 00-1.5.06l.3 7.5a.75.75 0 101.5-.06l-.3-7.5zm4.34.06a.75.75 0 10-1.5-.06l-.3 7.5a.75.75 0 101.5.06l.3-7.5z" clip-rule="evenodd" />
        </svg>
    </button>
</div>
