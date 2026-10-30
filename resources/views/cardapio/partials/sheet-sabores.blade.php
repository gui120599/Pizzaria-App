{{--
    Monte sua pizza (estado saboresModal). A categoria É o tamanho. A pizza em
    SVG mostra cada sabor na sua posição — a ordem é a mesma da linha do
    carrinho. Preço pela regra da categoria (precoSabores, igual ao servidor).
--}}
<div x-show="$store.cart.saboresModal.open" x-cloak class="fixed inset-0 z-[60] flex items-end justify-center sm:items-center"
     role="dialog" aria-modal="true" aria-labelledby="titulo-sabores"
     @keydown.escape.window="$store.cart.saboresModal.open = false">
    <div x-show="$store.cart.saboresModal.open" x-transition.opacity class="absolute inset-0 bg-black/70" @click="$store.cart.saboresModal.open = false"></div>
    <div x-show="$store.cart.saboresModal.open"
         x-transition:enter="transition duration-300 ease-[cubic-bezier(.2,.8,.2,1)]" x-transition:enter-start="translate-y-full sm:translate-y-4 sm:opacity-0"
         x-transition:leave="transition duration-200 ease-in" x-transition:leave-end="translate-y-full sm:opacity-0"
         class="relative flex h-[92dvh] w-full flex-col rounded-t-sheet bg-forno-2 sm:h-[88dvh] sm:max-w-lg sm:rounded-sheet">
        <div class="mx-auto mt-2.5 h-1 w-10 shrink-0 rounded-full bg-linha sm:hidden"></div>

        <div class="flex shrink-0 items-start px-5 pb-3 pt-3">
            <div>
                <p class="text-[11px] font-bold uppercase tracking-[.12em] text-brasa-viva">Monte sua pizza</p>
                <h2 id="titulo-sabores" class="font-display text-2xl font-bold leading-tight" x-text="$store.cart.saboresModal.categoriaNome"></h2>
            </div>
            <button type="button" @click="$store.cart.saboresModal.open = false"
                    class="ml-auto grid size-10 place-items-center rounded-full bg-forno-3 text-2xl text-farinha hover:text-creme" aria-label="Fechar">
                <i class='bx bx-x' aria-hidden="true"></i>
            </button>
        </div>

        {{-- Inteira / meia a meia / N sabores (só as opções reais da categoria) --}}
        <div class="shrink-0 px-5">
            <div class="flex rounded-2xl border border-linha bg-forno p-1" role="radiogroup" aria-label="Quantidade de sabores">
                <button type="button" role="radio" :aria-checked="$store.cart.saboresModal.modo === 1" @click="$store.cart.setModoSabores(1)"
                        :class="$store.cart.saboresModal.modo === 1 ? 'bg-brasa text-white' : 'text-farinha hover:text-creme'"
                        class="h-11 flex-1 rounded-xl text-sm font-semibold transition-colors">Inteira</button>
                <template x-for="opcao in $store.cart.saboresModal.opcoes" :key="opcao.quantidade">
                    <button type="button" role="radio" :aria-checked="$store.cart.saboresModal.modo === opcao.quantidade" @click="$store.cart.setModoSabores(opcao.quantidade)"
                            :class="$store.cart.saboresModal.modo === opcao.quantidade ? 'bg-brasa text-white' : 'text-farinha hover:text-creme'"
                            class="h-11 flex-1 rounded-xl text-sm font-semibold transition-colors" x-text="opcao.descricao"></button>
                </template>
            </div>
        </div>

        <div class="flex shrink-0 items-center gap-4 px-5 py-4">
            <div class="size-28 shrink-0" x-html="$store.cart.svgPizza()"></div>
            <ol class="min-w-0 space-y-1.5 text-sm">
                <template x-for="i in $store.cart.saboresModal.modo" :key="i">
                    <li class="flex items-center gap-2">
                        <span class="grid size-6 shrink-0 place-items-center rounded-full text-[11px] font-bold"
                              :class="$store.cart.saboresModal.selecionados[i - 1] ? 'bg-brasa text-white' : 'border-2 border-dashed border-linha text-farinha'"
                              x-text="i"></span>
                        <span class="truncate" :class="!$store.cart.saboresModal.selecionados[i - 1] && 'text-farinha'"
                              x-text="$store.cart.saboresModal.selecionados[i - 1]?.nome ?? 'Escolha um sabor'"></span>
                    </li>
                </template>
            </ol>
        </div>

        <div x-show="$store.cart.saboresModal.produtos.length >= 8" class="shrink-0 px-5 pb-1">
            <label class="flex h-11 items-center gap-2 rounded-xl border border-linha bg-forno px-3 text-farinha focus-within:border-brasa-viva">
                <i class='bx bx-search text-xl' aria-hidden="true"></i>
                <span class="sr-only">Buscar sabor</span>
                <input type="search" x-model="$store.cart.saboresModal.busca" placeholder="Buscar sabor ou ingrediente"
                       class="w-full border-0 bg-transparent p-0 text-base text-creme placeholder:text-farinha focus:ring-0">
            </label>
        </div>

        <div class="min-h-0 flex-1 space-y-2 overflow-y-auto overscroll-contain px-5 py-2">
            <template x-for="produto in $store.cart.saboresFiltrados" :key="produto.id">
                <button type="button" :id="'sabor-item-' + produto.id" @click="$store.cart.toggleSabor(produto)"
                        :aria-pressed="!!$store.cart.saboresModal.selecionados.find(s => s.id === produto.id)"
                        :class="$store.cart.saboresModal.selecionados.find(s => s.id === produto.id) ? 'border-brasa bg-brasa/10' : 'border-linha bg-forno hover:border-farinha/50'"
                        class="flex w-full items-center gap-3 rounded-2xl border p-2.5 text-left transition-colors">
                    <template x-if="$store.cart.temFoto(produto.foto)">
                        <img :src="produto.foto" alt="" width="52" height="52" loading="lazy" class="size-[52px] shrink-0 rounded-xl bg-forno-3 object-cover">
                    </template>
                    <template x-if="!$store.cart.temFoto(produto.foto)">
                        <span class="grid size-[52px] shrink-0 place-items-center rounded-xl bg-forno-3 font-display text-xl font-bold text-linha" aria-hidden="true">E</span>
                    </template>
                    <span class="min-w-0 flex-1">
                        <span class="block text-[15px] font-semibold leading-snug first-letter:uppercase" x-text="produto.nome"></span>
                        <span x-show="produto.ingredientes" class="block truncate text-[12.5px] text-farinha" x-text="produto.ingredientes"></span>
                    </span>
                    <span class="shrink-0 text-right">
                        <span class="num block text-sm font-bold" x-text="$store.cart.formatar(produto.preco)"></span>
                        <span x-show="$store.cart.saboresModal.modo > 1 && produto.precoFracao != null && produto.precoFracao !== produto.preco"
                              class="num block text-[11px] text-farinha" x-text="'em sabores: ' + $store.cart.formatar(produto.precoFracao)"></span>
                    </span>
                    <span class="grid size-7 shrink-0 place-items-center rounded-full text-xs font-bold"
                          :class="$store.cart.saboresModal.selecionados.find(s => s.id === produto.id) ? 'bg-brasa text-white' : 'border-2 border-linha'"
                          x-text="($store.cart.saboresModal.selecionados.findIndex(s => s.id === produto.id) + 1) || ''"></span>
                </button>
            </template>
            <p x-show="$store.cart.saboresFiltrados.length === 0" class="py-8 text-center text-sm text-farinha">Nenhum sabor encontrado.</p>
        </div>

        <div class="shrink-0 border-t border-linha px-5 pb-[max(1rem,env(safe-area-inset-bottom))] pt-3">
            <p x-show="$store.cart.saboresModal.selecionados.length > 0" class="truncate text-sm font-semibold"
               x-text="$store.cart.saboresModal.selecionados.map(s => s.nome).join(' + ')"></p>
            <p x-show="$store.cart.saboresModal.modo > 1" class="mt-0.5 flex items-center gap-1.5 text-[12.5px] text-farinha">
                <i class='bx bx-info-circle' aria-hidden="true"></i><span x-text="$store.cart.regraSaboresTexto()"></span>
            </p>
            <button type="button" @click="$store.cart.confirmarSabores()"
                    :disabled="$store.cart.saboresModal.selecionados.length !== $store.cart.saboresModal.modo"
                    class="botao-brasa mt-3 h-14 w-full text-base">
                <template x-if="$store.cart.saboresModal.selecionados.length === $store.cart.saboresModal.modo">
                    <span class="flex items-center gap-2">
                        <i class='bx bx-plus text-xl' aria-hidden="true"></i>
                        Adicionar · <span class="num" x-text="$store.cart.formatar($store.cart.precoSabores($store.cart.saboresModal.selecionados).preco)"></span>
                    </span>
                </template>
                <template x-if="$store.cart.saboresModal.selecionados.length !== $store.cart.saboresModal.modo">
                    <span x-text="'Escolha mais ' + ($store.cart.saboresModal.modo - $store.cart.saboresModal.selecionados.length) + (($store.cart.saboresModal.modo - $store.cart.saboresModal.selecionados.length) === 1 ? ' sabor' : ' sabores')"></span>
                </template>
            </button>
        </div>
    </div>
</div>
