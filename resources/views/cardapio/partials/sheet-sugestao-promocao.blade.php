{{-- Sugestão de promoção com categoria exigida: a pizza entrou na sacola, o cliente escolhe o gatilho (ex.: refri 2L) e segue para a oferta. --}}
<div x-show="$store.cart.sugestaoModal.open" x-cloak class="fixed inset-0 z-[70] flex items-end justify-center sm:items-center"
     role="dialog" aria-modal="true" aria-labelledby="titulo-sugestao-promocao"
     @keydown.escape.window="$store.cart.sugestaoModal.open && $store.cart.fecharSugestao()">
    <div class="absolute inset-0 bg-black/70" @click="$store.cart.fecharSugestao()"></div>
    <div x-show="$store.cart.sugestaoModal.open"
         x-transition:enter="transition duration-300 ease-[cubic-bezier(.2,.8,.2,1)]" x-transition:enter-start="translate-y-full sm:translate-y-4 sm:opacity-0"
         class="relative w-full rounded-t-sheet border-t border-ouro/30 bg-forno-2 px-5 pb-[max(1.25rem,env(safe-area-inset-bottom))] pt-5 sm:max-w-sm sm:rounded-sheet sm:border">
        <p class="flex items-center gap-2 text-[11px] font-bold uppercase tracking-[.12em] text-ouro"><i class='bx bxs-gift text-base' aria-hidden="true"></i> Promoção</p>
        <h2 id="titulo-sugestao-promocao" class="mt-1 font-display text-xl font-bold" x-text="$store.cart.sugestaoModal.campanha?.nome"></h2>
        <p class="mt-1 text-[13px] text-farinha" x-show="$store.cart.sugestaoModal.campanha?.descricao" x-text="$store.cart.sugestaoModal.campanha?.descricao"></p>
        <p class="mt-3 text-[13px] font-semibold">Escolha para ganhar:</p>
        <div class="mt-2 max-h-64 space-y-2 overflow-y-auto">
            <template x-for="gatilho in ($store.cart.sugestaoModal.campanha?.gatilhos ?? [])" :key="gatilho.id">
                <button type="button" @click="$store.cart.escolherGatilhoSugerido(gatilho)"
                        class="flex w-full items-center gap-3 rounded-2xl border border-linha bg-forno p-2.5 text-left transition-colors hover:border-ouro/50">
                    <template x-if="$store.cart.temFoto(gatilho.foto)">
                        <img :src="gatilho.foto" alt="" width="48" height="48" class="size-12 shrink-0 rounded-xl bg-forno-3 object-cover">
                    </template>
                    <span class="flex-1 text-sm font-semibold leading-snug" x-text="gatilho.nome"></span>
                    <span class="num shrink-0 text-sm font-bold text-ouro" x-text="$store.cart.formatar(gatilho.preco)"></span>
                </button>
            </template>
        </div>
        <button type="button" @click="$store.cart.fecharSugestao()" class="botao-secundario mt-4 h-12 w-full text-sm">Não, obrigado</button>
    </div>
</div>
