{{-- Carrinho da rodada/pedido em montagem no painel lateral (desktop). Os
     itens vêm do PedidoProdutoSelector (trait CarrinhoLateral); os botões do
     partial pedido-item-linha repassam os cliques para ele.
     Espera $acaoEnviar (método Livewire) e, opcional, $totalExibido. --}}
@php $totalExibido ??= $this->totalCarrinho(); @endphp
<div class="overflow-hidden rounded-2xl bg-white ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-white/10">
    <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3 dark:border-white/10">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
            {{ $tituloCarrinho ?? 'Itens' }} <span class="font-normal normal-case">({{ count($itensCarrinho) }})</span>
        </h3>
    </div>

    @if (empty($itensCarrinho))
        <p class="px-4 py-10 text-center text-sm text-gray-400 dark:text-gray-500">Toque nos produtos à esquerda para adicionar.</p>
    @else
        <div class="max-h-[45vh] divide-y divide-gray-100 overflow-y-auto dark:divide-white/10">
            @foreach ($itensCarrinho as $item)
                @include('livewire.partials.pedido-item-linha', ['item' => $item])
            @endforeach
        </div>
    @endif

    <div class="space-y-3 border-t border-gray-100 bg-gray-50 px-4 py-4 dark:border-white/10 dark:bg-white/5">
        <div class="flex items-center justify-between text-lg font-bold text-gray-900 dark:text-white">
            <span>Total</span>
            <span>R$ {{ number_format($totalExibido, 2, ',', '.') }}</span>
        </div>
        <button type="button" wire:click="{{ $acaoEnviar }}" wire:loading.attr="disabled" @disabled(empty($itensCarrinho))
                class="w-full rounded-xl bg-primary-600 py-4 text-base font-semibold uppercase tracking-wide text-white transition hover:bg-primary-500 active:scale-[.99] disabled:cursor-not-allowed disabled:opacity-40">
            Enviar para a cozinha
        </button>
    </div>
</div>
