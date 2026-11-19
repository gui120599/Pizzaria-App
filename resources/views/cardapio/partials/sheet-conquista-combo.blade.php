{{-- Promoção Combo: o cliente acabou de fechar um combo. Valores da prévia do servidor (o checkout confirma). --}}
<div x-show="$store.cart.comboConquista.open" x-cloak class="fixed inset-0 z-[75] flex items-center justify-center px-4"
     role="dialog" aria-modal="true" aria-labelledby="titulo-conquista-combo"
     @keydown.escape.window="$store.cart.comboConquista.open && $store.cart.fecharConquista()">
    <div class="absolute inset-0 bg-black/75" @click="$store.cart.fecharConquista()"></div>

    <div class="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true">
        <template x-if="$store.cart.comboConquista.open">
            <div>
                <template x-for="n in 42" :key="n">
                    <span class="confete"
                          :style="`left:${(n * 37) % 100}%;background:${['#F2B33D', '#E8673A', '#4ADE80', '#F6EEE3', '#C2410C', '#F87171'][n % 6]};--atraso:${(n % 7) * 0.12}s;--duracao:${2.2 + (n % 5) * 0.35}s;--deriva:${((n * 53) % 120) - 60}px;--giro:${360 + (n % 4) * 180}deg`"></span>
                </template>
            </div>
        </template>
    </div>

    <div x-show="$store.cart.comboConquista.open"
         x-transition:enter="transition duration-300 ease-[cubic-bezier(.2,.8,.2,1)]" x-transition:enter-start="scale-90 opacity-0"
         class="relative w-full max-w-sm overflow-hidden rounded-sheet border border-ouro/40 bg-forno-2 px-6 pb-6 pt-7 text-center shadow-2xl shadow-black/60">
        <div class="mx-auto grid size-16 place-items-center rounded-full bg-ouro/15 text-4xl" aria-hidden="true">🎉</div>
        <h2 id="titulo-conquista-combo" class="mt-3 font-display text-[22px] font-bold uppercase leading-tight">Parabéns! Você ganhou uma promoção!</h2>
        <p class="mt-3 text-[13px] text-farinha">Você garantiu:</p>
        <p class="mt-0.5 font-display text-lg font-bold uppercase text-ouro" x-text="$store.cart.comboConquista.aplicacao?.nome"></p>

        <ul class="mt-3 space-y-1 text-[13px] leading-snug">
            <template x-for="(nome, indice) in ($store.cart.comboConquista.aplicacao?.nomesItens ?? [])" :key="indice">
                <li class="flex items-center justify-center gap-1.5"><i class='bx bxs-pizza text-brasa-viva' aria-hidden="true"></i><span x-text="nome"></span></li>
            </template>
        </ul>

        <div class="mt-4 rounded-2xl border border-linha bg-forno px-4 py-3">
            <p class="text-sm text-farinha">
                De <span class="num line-through" x-text="$store.cart.formatar($store.cart.comboConquista.aplicacao?.valor_cheio)"></span>
                por <span class="num text-lg font-bold text-creme" x-text="$store.cart.formatar($store.cart.comboConquista.aplicacao?.preco)"></span>
            </p>
            <p class="mt-1 font-display text-base font-bold uppercase text-manjericao">
                Você economizou <span class="num" x-text="$store.cart.formatar($store.cart.comboConquista.aplicacao?.desconto)"></span>!
            </p>
        </div>
        <p class="mt-2 text-[11.5px] text-farinha">Bordas e adicionais são cobrados à parte.</p>

        <button type="button" @click="$store.cart.fecharConquista()" class="botao-brasa mt-5 h-12 w-full text-base">Continuar comprando</button>
    </div>
</div>
