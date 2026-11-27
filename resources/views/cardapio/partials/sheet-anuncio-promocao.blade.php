{{--
    Aviso ao abrir o cardápio (uma vez por sessão; com a loja fechada, avisa
    quando abre): a primeira promoção em
    destaque (combo, relâmpago ou "leve outro produto"), com o banner quando
    cadastrado, e as demais vigentes logo abaixo. Dados em
    CardapioService::anunciosPromocoes(); lógica em carrinho.js (anunciarPromocoes).
--}}
<div x-show="$store.cart.anuncio.open" x-cloak class="fixed inset-0 z-[70] flex items-end justify-center sm:items-center"
     role="dialog" aria-modal="true" aria-labelledby="titulo-anuncio-promocao"
     x-data="{ rotulos: { combo: ['Promoção de hoje', 'bxs-offer'], relampago: ['Promoção relâmpago', 'bxs-bolt'], adicional: ['Oferta especial', 'bxs-gift'] } }"
     @keydown.escape.window="$store.cart.anuncio.open && $store.cart.fecharAnuncio()">
    <div class="absolute inset-0 bg-black/70" @click="$store.cart.fecharAnuncio()"></div>
    <div x-show="$store.cart.anuncio.open"
         x-transition:enter="transition duration-300 ease-[cubic-bezier(.2,.8,.2,1)]" x-transition:enter-start="translate-y-full sm:translate-y-4 sm:opacity-0"
         class="relative flex max-h-[92dvh] w-full flex-col overflow-hidden rounded-t-sheet border-t border-ouro/30 bg-forno-2 pb-[max(1.25rem,env(safe-area-inset-bottom))] sm:max-w-md sm:rounded-sheet sm:border">
        <div class="min-h-0 flex-1 overflow-y-auto">
            {{-- Modal estreito em qualquer tela: usa a arte quadrada; sem ela, a de computador; sem arte, uma faixa com o ícone. --}}
            <template x-if="$store.cart.anuncio.promocao?.imagem_mobile">
                <img :src="$store.cart.anuncio.promocao.imagem_mobile" :alt="$store.cart.anuncio.promocao.nome" width="1080" height="1080"
                     class="aspect-square max-h-[55dvh] w-full bg-forno-3 object-cover">
            </template>
            <template x-if="!$store.cart.anuncio.promocao?.imagem_mobile && $store.cart.anuncio.promocao?.imagem">
                <img :src="$store.cart.anuncio.promocao.imagem" :alt="$store.cart.anuncio.promocao.nome" width="1680" height="640"
                     class="aspect-[21/8] w-full bg-forno-3 object-cover">
            </template>
            <template x-if="$store.cart.anuncio.promocao && !$store.cart.anuncio.promocao.imagem_mobile && !$store.cart.anuncio.promocao.imagem">
                <div class="grid h-28 place-items-center bg-[linear-gradient(135deg,rgb(242_179_61/.22),transparent_70%)]">
                    <i class='bx text-6xl text-ouro' :class="(rotulos[$store.cart.anuncio.promocao.tipo] ?? rotulos.combo)[1]" aria-hidden="true"></i>
                </div>
            </template>
            <button type="button" @click="$store.cart.fecharAnuncio()" aria-label="Fechar"
                    class="absolute right-3 top-3 z-10 grid size-9 place-items-center rounded-full bg-black/50 text-xl text-white backdrop-blur hover:bg-black/70">
                <i class='bx bx-x' aria-hidden="true"></i>
            </button>
            <div class="px-5 pt-5">
                <div class="flex items-start gap-3">
                    <div class="min-w-0 flex-1">
                        <p class="flex items-center gap-2 text-[11px] font-bold uppercase tracking-[.12em] text-ouro">
                            <i class='bx text-base' :class="(rotulos[$store.cart.anuncio.promocao?.tipo] ?? rotulos.combo)[1]" aria-hidden="true"></i>
                            <span x-text="(rotulos[$store.cart.anuncio.promocao?.tipo] ?? rotulos.combo)[0]"></span>
                        </p>
                        <h2 id="titulo-anuncio-promocao" class="mt-1 font-display text-2xl font-bold leading-tight" x-text="$store.cart.anuncio.promocao?.nome"></h2>
                    </div>
                    {{-- Quantidade limitada: quanto ainda sai (só sai no HTML quando alguma promoção tem contador). --}}
                    @if (collect($anunciosPromocoes ?? [])->contains(fn (array $anuncio): bool => $anuncio['saldo'] !== null))
                        <template x-if="$store.cart.anuncio.promocao?.saldo !== null && $store.cart.anuncio.promocao?.saldo !== undefined">
                            <span class="flex shrink-0 flex-col items-center rounded-xl bg-ouro px-2.5 py-1 text-forno">
                                <span class="num text-lg font-extrabold leading-none" x-text="Math.floor($store.cart.anuncio.promocao.saldo)"></span>
                                <span class="text-[10px] font-bold uppercase tracking-wide">restam</span>
                            </span>
                        </template>
                    @endif
                </div>
                <p class="mt-1.5 text-[15px] font-semibold text-creme" x-text="$store.cart.anuncio.promocao?.destaque"></p>
                {{-- Relâmpago: quanto falta para acabar (contado desde o carregamento da página). --}}
                <template x-if="$store.cart.anuncio.promocao?.segundos_restantes !== null && $store.cart.anuncio.promocao?.segundos_restantes !== undefined">
                    <span x-data="cronometro($store.cart.anuncio.promocao.segundos_restantes)"
                          class="num mt-2 inline-flex items-center gap-1 rounded-full bg-tomate/15 px-2.5 py-1 text-[12.5px] font-bold text-creme">
                        <i class='bx bx-time-five text-sm text-tomate' aria-hidden="true"></i>
                        <span x-text="encerrada ? 'Promoção encerrada' : 'Termina em ' + texto"></span>
                    </span>
                </template>
                <p x-show="$store.cart.anuncio.promocao?.descricao" class="mt-1.5 text-[14px] leading-snug text-farinha" x-text="$store.cart.anuncio.promocao?.descricao"></p>
                <p x-show="$store.cart.anuncio.promocao?.nota" class="mt-2 text-[12px] text-farinha" x-text="$store.cart.anuncio.promocao?.nota"></p>
                {{-- Loja fechada: a promoção já está no ar, os pedidos começam no horário de abertura. --}}
                <p x-show="$store.cart.anuncio.fechada" class="mt-3 flex items-center gap-2 rounded-xl border border-ouro/30 bg-ouro/10 px-3 py-2 text-[13px] font-semibold text-creme">
                    <i class='bx bx-time-five shrink-0 text-lg text-ouro' aria-hidden="true"></i>
                    <span x-text="'Estamos fechados agora.' + ($store.cart.anuncio.abreEm ? ' Abrimos ' + $store.cart.anuncio.abreEm + '.' : '')"></span>
                </p>
                <button type="button" @click="$store.cart.verPromocaoAnunciada($store.cart.anuncio.promocao)" class="botao-brasa mt-5 h-12 w-full text-base">Ver itens da promoção</button>

                {{-- Demais promoções vigentes: um toque fecha o aviso e rola até a seção. --}}
                <template x-if="$store.cart.anuncio.outras.length > 0">
                    <div class="mt-5">
                        <p class="text-[11px] font-bold uppercase tracking-[.12em] text-farinha">Também hoje</p>
                        <div class="mt-2 space-y-2">
                            <template x-for="outra in $store.cart.anuncio.outras" :key="outra.chave">
                                <button type="button" @click="$store.cart.verPromocaoAnunciada(outra)"
                                        class="flex w-full items-center gap-3 rounded-xl border border-linha px-3 py-2.5 text-left hover:border-ouro/50">
                                    <i class='bx shrink-0 text-xl text-ouro' :class="(rotulos[outra.tipo] ?? rotulos.combo)[1]" aria-hidden="true"></i>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-[14px] font-semibold text-creme" x-text="outra.nome"></span>
                                        <span class="block truncate text-[12.5px] text-farinha" x-text="outra.destaque"></span>
                                    </span>
                                    <i class='bx bx-chevron-right shrink-0 text-xl text-farinha' aria-hidden="true"></i>
                                </button>
                            </template>
                        </div>
                    </div>
                </template>

                <button type="button" @click="$store.cart.fecharAnuncio()" class="mt-3 h-10 w-full text-sm font-semibold text-farinha hover:text-creme">Agora não</button>
            </div>
        </div>
    </div>
</div>
