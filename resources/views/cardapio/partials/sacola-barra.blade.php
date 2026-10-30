{{-- Barra inferior da sacola (celular). --}}
<div x-show="$store.cart.count > 0 && !$store.cart.drawerOpen" x-cloak
     x-transition:enter="transition duration-300 ease-out" x-transition:enter-start="translate-y-full opacity-0"
     x-transition:leave="transition duration-200 ease-in" x-transition:leave-end="translate-y-full opacity-0"
     class="fixed inset-x-0 bottom-0 z-40 border-t border-linha bg-forno/95 px-3 pb-[max(.75rem,env(safe-area-inset-bottom))] pt-2 shadow-[0_-12px_24px_rgb(20_16_13/.9)] backdrop-blur-md lg:hidden">
    @unless ($mesaCliente)
        <template x-if="$store.cart.progressoFrete">
            <div class="flex items-center gap-3 px-1 pb-2 text-[12.5px] text-farinha">
                <span x-show="!$store.cart.progressoFrete.atingido" class="shrink-0">Faltam <b class="num text-creme" x-text="$store.cart.formatar($store.cart.progressoFrete.falta)"></b> para entrega grátis</span>
                <span x-show="$store.cart.progressoFrete.atingido" class="flex shrink-0 items-center gap-1 font-semibold text-creme"><i class='bx bxs-check-circle text-manjericao' aria-hidden="true"></i> Entrega grátis liberada</span>
                <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-forno-3">
                    <div class="h-full rounded-full transition-[width] duration-500"
                         :class="$store.cart.progressoFrete.atingido ? 'bg-manjericao' : 'bg-gradient-to-r from-brasa to-ouro'"
                         :style="'width:' + $store.cart.progressoFrete.pct + '%'"></div>
                </div>
            </div>
        </template>
    @endunless
    <button type="button" @click="$store.cart.drawerOpen = true" x-effect="pulsar($el, $store.cart.pulso)"
            class="botao-brasa h-14 w-full justify-start px-4 text-[15px] shadow-lg shadow-black/40">
        <span class="num grid size-7 place-items-center rounded-full bg-black/25 text-[13px]" x-text="$store.cart.count"></span>
        Ver sacola
        <span class="num ml-auto text-base" x-text="$store.cart.formatar($store.cart.total)"></span>
    </button>
</div>
