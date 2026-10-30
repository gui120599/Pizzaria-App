{{-- Troco no pagamento em dinheiro (abre ao escolher Dinheiro). --}}
<div x-show="$store.cart.trocoModal.open" x-cloak class="fixed inset-0 z-[70] flex items-end justify-center sm:items-center"
     role="dialog" aria-modal="true" aria-labelledby="titulo-troco"
     @keydown.escape.window="$store.cart.trocoModal.open = false">
    <div class="absolute inset-0 bg-black/70" @click="$store.cart.trocoModal.open = false"></div>
    <div x-show="$store.cart.trocoModal.open"
         x-transition:enter="transition duration-300 ease-[cubic-bezier(.2,.8,.2,1)]" x-transition:enter-start="translate-y-full sm:translate-y-4 sm:opacity-0"
         class="relative w-full rounded-t-sheet bg-forno-2 px-5 pb-[max(1.25rem,env(safe-area-inset-bottom))] pt-5 sm:max-w-sm sm:rounded-sheet">
        <div class="flex items-start gap-3">
            <i class='bx bx-money text-3xl text-brasa-viva' aria-hidden="true"></i>
            <div>
                <h2 id="titulo-troco" class="font-display text-xl font-bold">Pagamento em dinheiro</h2>
                <p class="text-[13px] text-farinha">Vai precisar de troco? Informe para quanto.</p>
            </div>
            <button type="button" @click="$store.cart.trocoModal.open = false" class="ml-auto grid size-10 place-items-center rounded-full bg-forno-3 text-2xl text-farinha" aria-label="Fechar">
                <i class='bx bx-x' aria-hidden="true"></i>
            </button>
        </div>
        <label for="troco-modal" class="rotulo mt-5">Troco para quanto?</label>
        <div class="relative">
            <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-farinha">R$</span>
            <template x-if="$store.cart.trocoModal.open">
                <input id="troco-modal" type="text" inputmode="numeric" x-model="$store.cart.form.trocoPara" @input="$store.cart.maskTroco($event)"
                       x-init="$nextTick(() => $el.focus())" placeholder="0,00" class="campo pl-10 text-lg">
            </template>
        </div>
        <div class="mt-5 grid grid-cols-2 gap-2">
            <button type="button" @click="$store.cart.form.trocoPara = '0,00'; $store.cart.trocoModal.open = false" class="botao-secundario h-12 text-sm">Sem troco</button>
            <button type="button" @click="$store.cart.trocoModal.open = false" :disabled="!$store.cart.form.trocoPara?.trim()" class="botao-brasa h-12 text-sm">Confirmar</button>
        </div>
    </div>
</div>
