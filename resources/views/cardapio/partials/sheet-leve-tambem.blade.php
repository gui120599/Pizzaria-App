{{-- "Leve também": sugestões do produto que acabou de entrar na sacola (toda vez que ele entra) (Produto::leveTambem), pelo preço normal. Escolher segue o fluxo normal do produto. --}}
<div x-show="$store.cart.leveTambem.open" x-cloak class="fixed inset-0 z-[70] flex items-end justify-center sm:items-center"
     role="dialog" aria-modal="true" aria-labelledby="titulo-leve-tambem"
     @keydown.escape.window="$store.cart.leveTambem.open && $store.cart.fecharLeveTambem()">
    <div class="absolute inset-0 bg-black/70" @click="$store.cart.fecharLeveTambem()"></div>
    <div x-show="$store.cart.leveTambem.open"
         x-transition:enter="transition duration-300 ease-[cubic-bezier(.2,.8,.2,1)]" x-transition:enter-start="translate-y-full sm:translate-y-4 sm:opacity-0"
         class="relative flex max-h-[88dvh] w-full flex-col rounded-t-sheet border-t border-ouro/30 bg-forno-2 px-5 pb-[max(1.25rem,env(safe-area-inset-bottom))] pt-5 sm:max-w-md sm:rounded-sheet sm:border">
        <p class="flex items-center gap-2 text-[11px] font-bold uppercase tracking-[.12em] text-ouro"><i class='bx bxs-shopping-bag text-base' aria-hidden="true"></i> Leve também</p>
        <h2 id="titulo-leve-tambem" class="mt-1 font-display text-xl font-bold leading-tight">Que tal completar o pedido?</h2>
        <p x-show="$store.cart.leveTambem.origem" class="mt-1.5 text-[14px] leading-snug text-farinha">
            Combina com <b class="text-creme" x-text="$store.cart.leveTambem.origem"></b>:
        </p>

        <div class="-mx-1 mt-3 flex-1 space-y-2 overflow-y-auto px-1">
            <template x-for="produto in $store.cart.leveTambem.produtos" :key="produto.id">
                <button type="button" @click="$store.cart.escolherLeveTambem(produto)"
                        class="flex w-full items-center gap-3 rounded-2xl border border-linha bg-forno p-2.5 text-left transition-colors hover:border-ouro/50">
                    <template x-if="$store.cart.temFoto(produto.foto)">
                        <img :src="produto.foto" alt="" width="56" height="56" loading="lazy" class="size-14 shrink-0 rounded-xl bg-forno-3 object-cover">
                    </template>
                    <template x-if="!$store.cart.temFoto(produto.foto)">
                        <div class="grid size-14 shrink-0 place-items-center rounded-xl bg-forno-3 font-display text-lg font-bold text-linha" aria-hidden="true">E</div>
                    </template>
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-semibold leading-snug" x-text="produto.nome"></span>
                        <span x-show="produto.categoriaNome" class="mt-0.5 block text-[11px] font-semibold uppercase tracking-wide text-farinha" x-text="produto.categoriaNome"></span>
                        <span x-show="produto.permiteSabores" class="mt-0.5 block text-[12px] font-semibold text-brasa-viva">Escolha os sabores</span>
                        <span x-show="$store.cart.qty(produto.id) > 0" class="mt-0.5 block text-[12px] font-semibold text-manjericao">
                            Na sacola: <span class="num" x-text="$store.cart.qty(produto.id)"></span>
                        </span>
                    </span>
                    <span class="flex shrink-0 flex-col items-end gap-1">
                        <span class="num text-sm font-bold text-creme" x-text="$store.cart.formatar(produto.preco)"></span>
                        <span class="rounded-full bg-brasa px-2.5 py-1 text-[12px] font-bold text-white">Adicionar</span>
                    </span>
                </button>
            </template>
        </div>
        <button type="button" @click="$store.cart.fecharLeveTambem()" class="botao-secundario mt-3 h-12 w-full text-sm">Agora não</button>
    </div>
</div>
