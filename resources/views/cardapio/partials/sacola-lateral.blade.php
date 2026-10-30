{{-- Sacola fixa ao lado do cardápio (desktop). No celular, a barra inferior abre o sheet. --}}
<aside class="hidden lg:block" aria-label="Sua sacola">
    <div class="sticky mt-6 flex max-h-[calc(100dvh-var(--altura-topo,72px)-var(--altura-abas,57px)-3rem)] flex-col overflow-hidden rounded-[20px] border border-linha bg-forno-2"
         style="top: calc(var(--altura-topo, 72px) + var(--altura-abas, 57px) + 1.5rem)">
        <div class="flex items-baseline gap-2 px-4 pb-2 pt-4">
            <h2 class="font-display text-[22px] font-bold">{{ $mesaCliente ? 'Seu pedido' : 'Sua sacola' }}</h2>
            <span class="text-[13px] text-farinha" x-show="$store.cart.count > 0" x-text="$store.cart.count + ($store.cart.count === 1 ? ' item' : ' itens')"></span>
        </div>
        @if ($mesaCliente)
            <template x-if="!$store.cart.estaAbertoAgora()">
                <p class="mx-4 mb-2 rounded-xl border border-brasa-viva/30 bg-brasa/10 px-3 py-2 text-[13px]" x-text="$store.mesa.mensagemBloqueio()"></p>
            </template>
        @endif
        <div class="min-h-0 flex-1 overflow-y-auto">
            @include('cardapio.partials.sacola-itens')
        </div>
        @include('cardapio.partials.sacola-resumo')
    </div>
</aside>
