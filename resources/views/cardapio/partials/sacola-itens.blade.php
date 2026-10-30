{{-- Itens da sacola — usado no painel lateral (desktop) e no sheet (celular). --}}
<template x-if="$store.cart.items.length === 0">
    <div class="px-4 py-12 text-center">
        <i class='bx bx-shopping-bag text-5xl text-linha' aria-hidden="true"></i>
        <p class="mt-2 font-semibold">Sua sacola está vazia</p>
        <p class="mt-1 text-sm text-farinha">Escolha algo gostoso no cardápio.</p>
    </div>
</template>
<ul class="divide-y divide-linha">
    <template x-for="item in $store.cart.items" :key="item.cartKey">
        <li class="flex gap-3 px-4 py-3.5" x-data="{ obsAberta: !!item.obs }">
            <template x-if="$store.cart.temFoto(item.foto)">
                <img :src="item.foto" alt="" width="48" height="48" loading="lazy" class="size-12 shrink-0 rounded-xl bg-forno-3 object-cover">
            </template>
            <template x-if="!$store.cart.temFoto(item.foto)">
                <div class="grid size-12 shrink-0 place-items-center rounded-xl bg-forno-3 font-display text-lg font-bold text-linha" aria-hidden="true">E</div>
            </template>
            <div class="min-w-0 flex-1">
                <p class="text-[15px] font-semibold leading-snug" x-text="$store.cart.partesNome(item.nome).titulo"></p>
                <p x-show="$store.cart.partesNome(item.nome).detalhe" class="text-[13px] leading-snug text-farinha" x-text="$store.cart.partesNome(item.nome).detalhe"></p>
                <template x-for="linha in (item.respostasTexto ?? [])" :key="linha">
                    <p class="text-[12.5px] leading-snug text-farinha" x-text="linha"></p>
                </template>
                <p x-show="(item.adicionaisTexto ?? []).length" x-cloak class="text-[12.5px] leading-snug text-farinha"
                   x-text="'+ ' + (item.adicionaisTexto ?? []).join(', ')"></p>
                <div x-show="item.oferta" x-cloak class="mt-1.5 flex items-center gap-2 rounded-lg border border-ouro/30 bg-ouro/10 px-2.5 py-1.5 text-xs">
                    <i class='bx bxs-gift text-ouro' aria-hidden="true"></i>
                    <span class="flex-1" x-text="'+ ' + item.oferta?.nomeExibicao"></span>
                    <span class="num font-bold text-ouro" x-text="'+' + $store.cart.formatar(item.oferta?.valorAdicional ?? 0)"></span>
                    <button type="button" @click="$store.cart.removerOferta(item.cartKey)" class="grid size-6 place-items-center text-farinha hover:text-creme" aria-label="Remover oferta">
                        <i class='bx bx-x' aria-hidden="true"></i>
                    </button>
                </div>
                <p x-show="(item.precoOriginal ?? item.preco) > item.preco" x-cloak class="mt-0.5 text-xs font-semibold text-ouro"
                   x-text="'Promoção · economia de ' + $store.cart.formatar(((item.precoOriginal ?? item.preco) - item.preco) * item.qty)"></p>

                <button type="button" x-show="!obsAberta" @click="obsAberta = true; $nextTick(() => $refs.obs.focus())"
                        class="mt-1.5 inline-flex items-center gap-1 text-[13px] font-semibold text-brasa-viva">
                    <i class='bx bx-message-square-edit' aria-hidden="true"></i> Adicionar observação
                </button>
                <label x-show="obsAberta" x-cloak class="mt-2 block">
                    <span class="sr-only">Observação</span>
                    <input type="text" x-ref="obs" x-model="item.obs" @change="$store.cart.save()" maxlength="120"
                           placeholder="Ex.: sem cebola, bem passado"
                           class="w-full rounded-lg border border-linha bg-forno px-3 py-2 text-base text-creme placeholder:text-farinha/60 focus:border-brasa-viva focus:outline-none">
                </label>
            </div>
            <div class="flex shrink-0 flex-col items-end justify-between gap-2">
                <div class="text-right">
                    <p x-show="(item.precoOriginal ?? item.preco) > item.preco" x-cloak class="num text-xs text-farinha line-through"
                       x-text="$store.cart.formatar((item.precoOriginal ?? item.preco) * item.qty)"></p>
                    <p class="num text-[15px] font-bold" x-text="$store.cart.formatar(item.preco * item.qty)"></p>
                </div>
                <div class="flex h-9 items-center rounded-full border border-linha bg-forno">
                    <button type="button" @click="$store.cart.decrement(item.cartKey)" class="grid size-9 place-items-center text-lg text-farinha hover:text-creme"
                            :aria-label="item.qty > 1 ? 'Tirar um' : 'Remover ' + item.nome">
                        <i class='bx' :class="item.qty > 1 ? 'bx-minus' : 'bx-trash'" aria-hidden="true"></i>
                    </button>
                    <span class="num min-w-5 text-center text-sm font-bold" x-text="item.qty"></span>
                    <button type="button" @click="$store.cart.increment(item.cartKey)" class="grid size-9 place-items-center text-lg text-farinha hover:text-creme" aria-label="Mais um">
                        <i class='bx bx-plus' aria-hidden="true"></i>
                    </button>
                </div>
            </div>
        </li>
    </template>
</ul>
