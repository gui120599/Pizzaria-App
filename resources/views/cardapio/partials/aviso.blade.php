{{-- Aviso curto (adicionado à sacola, loja fechada). --}}
<div class="pointer-events-none fixed inset-x-0 z-[90] flex justify-center px-4 bottom-[calc(7.5rem+env(safe-area-inset-bottom))] lg:bottom-8"
     aria-live="polite">
    <div x-show="$store.cart.aviso.texto && !$store.cart.drawerOpen" x-cloak
         x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="translate-y-2 opacity-0"
         x-transition:leave="transition duration-150 ease-in" x-transition:leave-end="opacity-0"
         class="pointer-events-auto flex max-w-sm items-center gap-2.5 rounded-2xl border px-4 py-3 text-sm font-semibold shadow-xl shadow-black/50"
         :class="$store.cart.aviso.tipo === 'fechado' ? 'border-brasa-viva/40 bg-forno-3' : 'border-linha bg-creme text-forno'">
        <i class='bx text-xl' :class="$store.cart.aviso.tipo === 'fechado' ? 'bx-time-five text-brasa-viva' : 'bxs-check-circle text-whats'" aria-hidden="true"></i>
        <span x-text="$store.cart.aviso.texto"></span>
        <button type="button" x-show="$store.cart.aviso.tipo !== 'fechado'" @click="$store.cart.drawerOpen = true; $store.cart.aviso.texto = ''"
                class="ml-1 whitespace-nowrap text-brasa underline underline-offset-2 lg:hidden">Ver sacola</button>
    </div>
</div>
