{{-- Promoção Combo: aviso ao abrir o cardápio (uma vez por sessão), com o banner quando cadastrado. --}}
<div x-show="$store.cart.comboAnuncio.open" x-cloak class="fixed inset-0 z-[70] flex items-end justify-center sm:items-center"
     role="dialog" aria-modal="true" aria-labelledby="titulo-anuncio-combo"
     @keydown.escape.window="$store.cart.comboAnuncio.open && $store.cart.fecharAnuncioCombo()">
    <div class="absolute inset-0 bg-black/70" @click="$store.cart.fecharAnuncioCombo()"></div>
    <div x-show="$store.cart.comboAnuncio.open"
         x-transition:enter="transition duration-300 ease-[cubic-bezier(.2,.8,.2,1)]" x-transition:enter-start="translate-y-full sm:translate-y-4 sm:opacity-0"
         class="relative w-full overflow-hidden rounded-t-sheet border-t border-ouro/30 bg-forno-2 pb-[max(1.25rem,env(safe-area-inset-bottom))] sm:max-w-md sm:rounded-sheet sm:border">
        <template x-if="$store.cart.comboAnuncio.promocao?.imagem">
            <img :src="$store.cart.comboAnuncio.promocao.imagem" :alt="$store.cart.comboAnuncio.promocao.nome" width="1200" height="675"
                 class="aspect-video w-full bg-forno-3 object-cover">
        </template>
        <button type="button" @click="$store.cart.fecharAnuncioCombo()" aria-label="Fechar"
                class="absolute right-3 top-3 grid size-9 place-items-center rounded-full bg-black/50 text-xl text-white backdrop-blur hover:bg-black/70">
            <i class='bx bx-x' aria-hidden="true"></i>
        </button>
        <div class="px-5 pt-5">
            <p class="flex items-center gap-2 text-[11px] font-bold uppercase tracking-[.12em] text-ouro"><i class='bx bxs-offer text-base' aria-hidden="true"></i> Promoção de hoje</p>
            <h2 id="titulo-anuncio-combo" class="mt-1 font-display text-2xl font-bold leading-tight" x-text="$store.cart.comboAnuncio.promocao?.nome"></h2>
            <p class="mt-1.5 text-[15px]">
                Leve <b x-text="$store.cart.comboAnuncio.promocao?.quantidade"></b>
                por <b class="num text-ouro" x-text="$store.cart.formatar($store.cart.comboAnuncio.promocao?.preco)"></b>
            </p>
            <p x-show="$store.cart.comboAnuncio.promocao?.descricao" class="mt-1.5 text-[14px] leading-snug text-farinha" x-text="$store.cart.comboAnuncio.promocao?.descricao"></p>
            <p class="mt-2 text-[12px] text-farinha">Bordas e adicionais são cobrados à parte.</p>
            <button type="button" @click="$store.cart.verPromocaoCombo()" class="botao-brasa mt-5 h-12 w-full text-base">Ver itens da promoção</button>
            <button type="button" @click="$store.cart.fecharAnuncioCombo()" class="mt-2 h-10 w-full text-sm font-semibold text-farinha hover:text-creme">Agora não</button>
        </div>
    </div>
</div>
