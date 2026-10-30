{{-- Montagem do item: perguntas (obrigatórias/opcionais) e adicionais. Lógica e preço: store (acrescimoMontagem). --}}
<div x-show="$store.cart.montagemModal.open" x-cloak class="fixed inset-0 z-[70] flex items-end justify-center sm:items-center"
     role="dialog" aria-modal="true" aria-labelledby="titulo-montagem"
     @keydown.escape.window="$store.cart.montagemModal.open && $store.cart.cancelarMontagem()">
    <div class="absolute inset-0 bg-black/70" @click="$store.cart.cancelarMontagem()"></div>
    <div x-show="$store.cart.montagemModal.open"
         x-transition:enter="transition duration-300 ease-[cubic-bezier(.2,.8,.2,1)]" x-transition:enter-start="translate-y-full sm:translate-y-4 sm:opacity-0"
         class="relative flex max-h-[90dvh] w-full flex-col rounded-t-sheet bg-forno-2 sm:max-w-md sm:rounded-sheet">
        <div class="shrink-0 border-b border-linha px-5 pb-3.5 pt-5">
            <p class="text-[11px] font-bold uppercase tracking-[.12em] text-brasa-viva">Personalize</p>
            <h2 id="titulo-montagem" class="font-display text-xl font-bold leading-tight" x-text="$store.cart.partesNome($store.cart.montagemModal.pending?.nome ?? '').titulo"></h2>
            <p class="text-[13px] text-farinha" x-text="$store.cart.partesNome($store.cart.montagemModal.pending?.nome ?? '').detalhe"></p>
        </div>
        <div x-ref="corpoMontagem" class="min-h-0 space-y-6 overflow-y-auto overscroll-contain px-5 py-4">
            <template x-for="pergunta in $store.cart.montagemModal.perguntas" :key="pergunta.id">
                <fieldset :data-pergunta="pergunta.id">
                    <legend class="mb-2 flex w-full items-center justify-between gap-2">
                        <span class="text-[15px] font-semibold" x-text="pergunta.texto"></span>
                        <span class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-bold"
                              :class="pergunta.minimo > 0
                                  ? ($store.cart.faltaResponder(pergunta) ? 'bg-brasa/20 text-brasa-viva' : 'bg-manjericao/15 text-manjericao')
                                  : 'bg-forno-3 text-farinha'"
                              x-text="(pergunta.minimo > 0 ? 'Obrigatório' : 'Opcional') + (pergunta.maximo > 1 ? ' · até ' + pergunta.maximo : '')"></span>
                    </legend>
                    <div class="space-y-2">
                        <template x-for="opcao in pergunta.opcoes" :key="opcao.id">
                            <button type="button" @click="$store.cart.alternarResposta(pergunta, opcao.id)"
                                    :aria-pressed="$store.cart.respostaMarcada(pergunta.id, opcao.id)"
                                    :class="$store.cart.respostaMarcada(pergunta.id, opcao.id) ? 'border-brasa bg-brasa/10' : 'border-linha bg-forno hover:border-farinha/50'"
                                    class="flex min-h-12 w-full items-center justify-between gap-3 rounded-xl border px-3 py-2.5 text-left transition-colors">
                                <span class="flex min-w-0 items-center gap-3">
                                    <span class="grid size-5 shrink-0 place-items-center border-2"
                                          :class="[pergunta.maximo <= 1 ? 'rounded-full' : 'rounded', $store.cart.respostaMarcada(pergunta.id, opcao.id) ? 'border-brasa bg-brasa' : 'border-farinha/60']">
                                        <i x-show="$store.cart.respostaMarcada(pergunta.id, opcao.id)" class='bx bx-check text-sm text-white' aria-hidden="true"></i>
                                    </span>
                                    <span class="text-[15px]" x-text="opcao.nome"></span>
                                </span>
                                <span x-show="opcao.valor > 0" class="num shrink-0 text-sm font-semibold text-farinha" x-text="'+' + $store.cart.formatar(opcao.valor)"></span>
                            </button>
                        </template>
                    </div>
                </fieldset>
            </template>
            <fieldset x-show="$store.cart.montagemModal.adicionais.length > 0" x-cloak>
                <legend class="mb-2 flex w-full items-center justify-between gap-2">
                    <span class="text-[15px] font-semibold">Adicionais</span>
                    <span class="rounded-full bg-forno-3 px-2 py-0.5 text-[11px] font-bold text-farinha">Opcional</span>
                </legend>
                <div class="space-y-2">
                    <template x-for="adicional in $store.cart.montagemModal.adicionais" :key="adicional.id">
                        <button type="button" @click="$store.cart.alternarAdicional(adicional.id)"
                                :aria-pressed="$store.cart.adicionalMarcado(adicional.id)"
                                :class="$store.cart.adicionalMarcado(adicional.id) ? 'border-brasa bg-brasa/10' : 'border-linha bg-forno hover:border-farinha/50'"
                                class="flex min-h-12 w-full items-center justify-between gap-3 rounded-xl border px-3 py-2.5 text-left transition-colors">
                            <span class="flex min-w-0 items-center gap-3">
                                <span class="grid size-5 shrink-0 place-items-center rounded border-2"
                                      :class="$store.cart.adicionalMarcado(adicional.id) ? 'border-brasa bg-brasa' : 'border-farinha/60'">
                                    <i x-show="$store.cart.adicionalMarcado(adicional.id)" class='bx bx-check text-sm text-white' aria-hidden="true"></i>
                                </span>
                                <span class="text-[15px]" x-text="adicional.nome"></span>
                            </span>
                            <span class="num shrink-0 text-sm font-semibold text-farinha" x-text="'+' + $store.cart.formatar(adicional.valor)"></span>
                        </button>
                    </template>
                </div>
            </fieldset>
        </div>
        <div class="flex shrink-0 gap-3 border-t border-linha px-5 pb-[max(1rem,env(safe-area-inset-bottom))] pt-3">
            <button type="button" @click="$store.cart.cancelarMontagem()" class="botao-secundario h-14 flex-1 text-sm">Cancelar</button>
            <button type="button"
                    @click="$store.cart.perguntasRespondidas
                        ? $store.cart.confirmarMontagem()
                        : $refs.corpoMontagem.querySelector('[data-pergunta=\'' + $store.cart.montagemModal.perguntas.find(p => $store.cart.faltaResponder(p))?.id + '\']')?.scrollIntoView({ behavior: 'smooth', block: 'center' })"
                    :class="!$store.cart.perguntasRespondidas && 'opacity-60'"
                    class="botao-brasa h-14 flex-[2] text-[15px]"
                    x-text="$store.cart.perguntasRespondidas
                        ? 'Adicionar · ' + $store.cart.formatar(($store.cart.montagemModal.pending?.preco ?? 0) + $store.cart.acrescimoMontagem)
                        : 'Responda as obrigatórias'"></button>
        </div>
    </div>
</div>
