{{-- Detalhe do produto (toque no card ou /Cardapio?produto=ID). "Adicionar" usa o mesmo caminho do "+". --}}
<div x-show="$store.cart.detalhe" x-cloak class="fixed inset-0 z-[60] flex items-end justify-center sm:items-center"
     role="dialog" aria-modal="true" aria-labelledby="titulo-detalhe"
     @keydown.escape.window="$store.cart.detalhe = null">
    <div x-show="$store.cart.detalhe" x-transition.opacity class="absolute inset-0 bg-black/70" @click="$store.cart.detalhe = null"></div>
    <div x-show="$store.cart.detalhe"
         x-transition:enter="transition duration-300 ease-[cubic-bezier(.2,.8,.2,1)]" x-transition:enter-start="translate-y-full sm:translate-y-4 sm:opacity-0"
         x-transition:leave="transition duration-200 ease-in" x-transition:leave-end="translate-y-full sm:opacity-0"
         class="relative flex max-h-[92dvh] w-full flex-col overflow-hidden rounded-t-sheet bg-forno-2 sm:max-w-md sm:rounded-sheet">
        <template x-if="$store.cart.detalhe">
            <div class="flex min-h-0 flex-1 flex-col">
                <div class="relative aspect-[4/3] shrink-0 bg-forno-3">
                    <template x-if="$store.cart.detalhe.foto">
                        <img :src="$store.cart.detalhe.foto" :alt="$store.cart.detalhe.nome" class="size-full object-cover">
                    </template>
                    <template x-if="!$store.cart.detalhe.foto">
                        <div class="grid size-full place-items-center font-display text-7xl font-bold text-linha" aria-hidden="true">E</div>
                    </template>
                    <button type="button" @click="$store.cart.detalhe = null"
                            class="absolute right-3 top-3 grid size-10 place-items-center rounded-full bg-forno/80 text-2xl backdrop-blur" aria-label="Fechar">
                        <i class='bx bx-x' aria-hidden="true"></i>
                    </button>
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto px-5 pb-2 pt-4">
                    <h2 id="titulo-detalhe" class="font-display text-2xl font-bold leading-tight first-letter:uppercase" x-text="$store.cart.detalhe.nome"></h2>
                    <p x-show="$store.cart.detalhe.referencia" class="mt-0.5 text-xs text-farinha" x-text="'Ref. ' + $store.cart.detalhe.referencia"></p>
                    <p x-show="$store.cart.detalhe.descricao" class="mt-2 text-[15px] leading-relaxed text-farinha" x-text="$store.cart.detalhe.descricao"></p>
                    <p x-show="$store.cart.detalhe.permiteSabores" class="mt-3 flex items-center gap-1.5 text-[13px] text-creme">
                        <i class='bx bxs-pie-chart-alt-2 text-ouro' aria-hidden="true"></i> Dá para pedir inteira ou com mais de um sabor.
                    </p>
                </div>
                <div class="flex shrink-0 items-center gap-4 border-t border-linha px-5 pb-[max(1rem,env(safe-area-inset-bottom))] pt-3.5">
                    <div>
                        <p x-show="$store.cart.detalhe.precoDe > $store.cart.detalhe.preco" class="num text-sm text-farinha line-through" x-text="$store.cart.formatar($store.cart.detalhe.precoDe)"></p>
                        <p class="num text-xl font-bold" :class="$store.cart.detalhe.precoDe > $store.cart.detalhe.preco && 'text-ouro'" x-text="$store.cart.formatar($store.cart.detalhe.preco)"></p>
                    </div>
                    <button type="button" @click="$store.cart.adicionarDoDetalhe()" class="botao-brasa ml-auto h-14 flex-1 text-base">
                        <i class='bx' :class="$store.cart.detalhe.permiteSabores ? 'bxs-pizza' : 'bx-plus'" aria-hidden="true"></i>
                        <span x-text="$store.cart.detalhe.permiteSabores ? 'Montar pizza' : 'Adicionar'"></span>
                    </button>
                </div>
            </div>
        </template>
    </div>
</div>
