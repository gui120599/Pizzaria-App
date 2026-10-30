{{--
    Dados do pedido (delivery). Mesmos campos e o mesmo payload de antes; a
    ordem mudou para o cliente ver a taxa de entrega antes de digitar dados.
--}}
@php
    $moeda = fn (float $valor) => 'R$ '.number_format($valor, 2, ',', '.');
@endphp

<div class="min-h-0 flex-1 space-y-6 overflow-y-auto overscroll-contain px-4 pb-4 pt-1">

    {{-- Como receber --}}
    <fieldset>
        <legend class="rotulo">Como você quer receber? <span class="text-tomate">*</span></legend>
        <div class="grid gap-2 {{ count($opcoesEntregas) > 1 ? 'grid-cols-2' : '' }}">
            @foreach ($opcoesEntregas as $opcao)
                <button type="button"
                        @click="$store.cart.form.opcaoEntregaId = {{ $opcao['id'] }}; $store.cart.form.requerEndereco = {{ $opcao['requer_endereco'] ? 'true' : 'false' }}"
                        :aria-pressed="$store.cart.form.opcaoEntregaId === {{ $opcao['id'] }}"
                        :class="$store.cart.form.opcaoEntregaId === {{ $opcao['id'] }} ? 'border-brasa bg-brasa/10' : 'border-linha bg-forno hover:border-farinha/50'"
                        class="flex min-h-[76px] flex-col items-start gap-1 rounded-2xl border p-3 text-left transition-colors">
                    <span class="flex items-center gap-1.5 text-[15px] font-semibold">
                        <i class='bx {{ $opcao['requer_endereco'] ? 'bx-cycling' : 'bx-store-alt' }} text-xl text-brasa-viva' aria-hidden="true"></i>
                        {{ $opcao['nome'] }}
                    </span>
                    <span class="text-xs leading-snug text-farinha">
                        @if ($opcao['valor_frete'] > 0)
                            Taxa {{ $moeda($opcao['valor_frete']) }}@if ($opcao['min_frete'] > 0) · <span class="text-manjericao">grátis acima de {{ $moeda($opcao['min_frete']) }}</span>@endif
                        @else
                            <span class="text-manjericao">Grátis</span>
                        @endif
                    </span>
                </button>
            @endforeach
        </div>
        <p x-show="$store.cart.erros.opcao" x-cloak x-text="$store.cart.erros.opcao" class="mt-1.5 text-xs text-tomate"></p>
    </fieldset>

    {{-- Telefone com busca do cliente --}}
    <div>
        <label for="checkout-telefone" class="rotulo">Seu WhatsApp <span class="text-tomate">*</span></label>
        <div class="relative">
            <input id="checkout-telefone" type="tel" inputmode="tel" autocomplete="tel"
                   x-model="$store.cart.form.telefone"
                   @input="$store.cart.resetCamposCliente()"
                   @input.debounce.600ms="$store.cart.lookupCliente()"
                   placeholder="(64) 9 9999-9999"
                   class="campo pr-10" :class="$store.cart.erros.telefone && 'border-tomate'">
            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-lg">
                <i x-show="$store.cart.lookingUp" x-cloak class='bx bx-loader-alt bx-spin text-farinha' aria-hidden="true"></i>
                <i x-show="$store.cart.clienteEncontrado && !$store.cart.lookingUp" x-cloak class='bx bxs-check-circle text-manjericao' aria-hidden="true"></i>
            </span>
        </div>
        <p x-show="$store.cart.clienteEncontrado && !$store.cart.lookingUp" x-cloak class="mt-1.5 text-xs text-manjericao">
            Que bom te ver de novo! Preenchemos seus dados.
        </p>
        <p x-show="$store.cart.erros.telefone" x-cloak x-text="$store.cart.erros.telefone" class="mt-1.5 text-xs text-tomate"></p>
    </div>

    {{-- Nome --}}
    <div>
        <label for="checkout-nome" class="rotulo">Nome <span class="text-tomate">*</span></label>
        <input id="checkout-nome" type="text" autocomplete="name" x-model="$store.cart.form.nome" placeholder="Seu nome"
               class="campo" :class="$store.cart.erros.nome && 'border-tomate'">
        <p x-show="$store.cart.erros.nome" x-cloak x-text="$store.cart.erros.nome" class="mt-1.5 text-xs text-tomate"></p>
    </div>

    {{-- Endereço (só na entrega) --}}
    <div x-show="$store.cart.form.requerEndereco" x-cloak>
        <label for="checkout-endereco" class="rotulo">Endereço de entrega <span class="text-tomate">*</span></label>
        <input id="checkout-endereco" type="text" autocomplete="street-address" x-model="$store.cart.form.endereco"
               placeholder="Rua, número, bairro e referência"
               class="campo" :class="$store.cart.erros.endereco && 'border-tomate'">
        <p x-show="$store.cart.erros.endereco" x-cloak x-text="$store.cart.erros.endereco" class="mt-1.5 text-xs text-tomate"></p>
    </div>

    {{-- Pagamento --}}
    <fieldset>
        <legend class="rotulo">Forma de pagamento <span class="text-tomate">*</span></legend>
        <div class="grid grid-cols-2 gap-2">
            @foreach ($opcoesPagamento as $pag)
                @php
                    $nomePag = mb_strtolower($pag['nome']);
                    $iconePag = $pag['dinheiro'] ? 'bx-money' : (str_contains($nomePag, 'pix') ? 'bx-qr' : (str_contains($nomePag, 'débito') || str_contains($nomePag, 'debito') ? 'bx-credit-card' : 'bx-credit-card-alt'));
                @endphp
                <button type="button"
                        @click="$store.cart.form.pagamentoId = {{ $pag['id'] }}; $store.cart.form.pagamentoNome = @js($pag['nome']); $store.cart.form.isDinheiro = {{ $pag['dinheiro'] ? 'true' : 'false' }}; $store.cart.form.trocoPara = ''; $store.cart.form.isDinheiro && $nextTick(() => $store.cart.trocoModal.open = true); $store.cart.revalidarOfertasContraPagamento()"
                        :aria-pressed="$store.cart.form.pagamentoId === {{ $pag['id'] }}"
                        :class="$store.cart.form.pagamentoId === {{ $pag['id'] }} ? 'border-brasa bg-brasa/10' : 'border-linha bg-forno hover:border-farinha/50'"
                        class="flex min-h-[52px] items-center gap-2 rounded-2xl border px-3 text-left text-sm font-semibold transition-colors">
                    <i class='bx {{ $iconePag }} text-xl text-brasa-viva' aria-hidden="true"></i>
                    {{ $pag['nome'] }}
                </button>
            @endforeach
        </div>
        @foreach ($opcoesPagamento as $pag)
            @if ($pag['descricao'])
                <p x-show="$store.cart.form.pagamentoId === {{ $pag['id'] }}" x-cloak
                   class="mt-2 rounded-xl border border-linha bg-forno px-3 py-2 text-xs leading-snug text-farinha">
                    <i class='bx bx-info-circle' aria-hidden="true"></i> {{ $pag['descricao'] }}
                </p>
            @endif
        @endforeach
        <p x-show="$store.cart.erros.pagamento" x-cloak x-text="$store.cart.erros.pagamento" class="mt-1.5 text-xs text-tomate"></p>
        <template x-if="$store.cart.avisoPagamentoRemovido.length > 0">
            <p class="mt-2 rounded-xl border border-ouro/30 bg-ouro/10 px-3 py-2 text-xs leading-snug">
                <i class='bx bx-error-circle text-ouro' aria-hidden="true"></i>
                Como a forma de pagamento escolhida não vale para a oferta especial, os seguintes produtos voltaram ao preço normal e as ofertas foram removidas:
                <span x-text="$store.cart.avisoPagamentoRemovido.join(', ')" class="font-semibold"></span>.
            </p>
        </template>
        <template x-if="$store.cart.avisoPagamentoRestaurado.length > 0">
            <p class="mt-2 rounded-xl border border-manjericao/30 bg-manjericao/5 px-3 py-2 text-xs leading-snug">
                <i class='bx bx-check-circle text-manjericao' aria-hidden="true"></i>
                Forma de pagamento válida pra promoção — a oferta especial voltou pro pedido:
                <span x-text="$store.cart.avisoPagamentoRestaurado.join(', ')" class="font-semibold"></span>.
            </p>
        </template>
    </fieldset>

    {{-- Troco (dinheiro) --}}
    <div x-show="$store.cart.form.isDinheiro" x-cloak>
        <label for="checkout-troco" class="rotulo">Troco para quanto? <span class="text-tomate">*</span></label>
        <div class="relative">
            <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-farinha">R$</span>
            <input id="checkout-troco" type="text" inputmode="numeric" x-model="$store.cart.form.trocoPara" @input="$store.cart.maskTroco($event)"
                   placeholder="0,00" class="campo pl-10" :class="$store.cart.erros.troco && 'border-tomate'">
        </div>
        <p class="mt-1 text-xs text-farinha">Digite 0,00 se não precisar de troco.</p>
        <p x-show="$store.cart.erros.troco" x-cloak x-text="$store.cart.erros.troco" class="mt-1.5 text-xs text-tomate"></p>
    </div>

    {{-- Cupom (recolhido; abre sozinho com ?cupom=) --}}
    <div x-data="{ aberto: !!$store.cart.cupom.codigo }">
        <button type="button" x-show="!aberto" @click="aberto = true; $nextTick(() => $refs.cupom.focus())"
                class="inline-flex items-center gap-1.5 text-sm font-semibold text-brasa-viva">
            <i class='bx bx-purchase-tag' aria-hidden="true"></i> Tem um cupom?
        </button>
        <div x-show="aberto" x-cloak>
            <label for="checkout-cupom" class="rotulo">Cupom de desconto</label>
            <div class="flex gap-2">
                <input id="checkout-cupom" x-ref="cupom" type="text" autocomplete="off" maxlength="50"
                       x-model="$store.cart.cupom.codigo"
                       @input="$store.cart.cupom.codigo = $event.target.value.toUpperCase().replace(/\s/g, ''); $store.cart.cupom.erro = ''"
                       @keydown.enter.prevent="$store.cart.aplicarCupom()"
                       :disabled="$store.cart.cupom.aplicado"
                       placeholder="DIGITE O CÓDIGO" class="campo min-w-0 flex-1 uppercase disabled:opacity-60">
                <button type="button" x-show="!$store.cart.cupom.aplicado" @click="$store.cart.aplicarCupom()"
                        :disabled="!$store.cart.cupom.codigo || $store.cart.cupom.carregando"
                        class="botao-secundario px-4 text-sm disabled:opacity-50">
                    <span x-show="!$store.cart.cupom.carregando">Aplicar</span>
                    <i x-show="$store.cart.cupom.carregando" x-cloak class='bx bx-loader-alt bx-spin' aria-hidden="true"></i>
                </button>
                <button type="button" x-show="$store.cart.cupom.aplicado" x-cloak @click="$store.cart.removerCupom()" class="botao-secundario px-4 text-sm">
                    Remover
                </button>
            </div>
            <p x-show="$store.cart.cupom.erro" x-cloak x-text="$store.cart.cupom.erro" class="mt-1.5 text-xs text-tomate"></p>
            <p x-show="$store.cart.cupom.aplicado && !$store.cart.cupomVigente" x-cloak class="mt-1.5 text-xs text-ouro">
                O carrinho mudou: o desconto será recalculado ao confirmar o pedido.
            </p>
        </div>
    </div>

    @if (config('services.recaptcha.site_key'))
        <div>
            <div class="g-recaptcha" data-sitekey="{{ config('services.recaptcha.site_key') }}" data-theme="dark"></div>
            <p x-show="$store.cart.erros.recaptcha" x-cloak x-text="$store.cart.erros.recaptcha" class="mt-1.5 text-xs text-tomate"></p>
        </div>
    @endif

    <div x-show="$store.cart.erros.geral" x-cloak role="alert" class="rounded-xl border border-tomate/40 bg-tomate/10 px-3 py-2.5">
        <p x-text="$store.cart.erros.geral" class="text-sm text-tomate"></p>
    </div>
</div>

{{-- Resumo fixo + envio --}}
<div class="shrink-0 space-y-1.5 border-t border-linha px-4 pb-[max(1rem,env(safe-area-inset-bottom))] pt-3.5">
    <div class="flex justify-between text-sm text-farinha">
        <span><span x-text="$store.cart.count"></span> <span x-text="$store.cart.count === 1 ? 'item' : 'itens'"></span></span>
        <span class="num" x-text="$store.cart.formatar($store.cart.totalOriginal)"></span>
    </div>
    <div x-show="$store.cart.totalDesconto > 0" x-cloak class="flex justify-between text-sm text-ouro">
        <span>Descontos de promoção</span><span class="num" x-text="'− ' + $store.cart.formatar($store.cart.totalDesconto)"></span>
    </div>
    <div x-show="$store.cart.cupomDesconto > 0" x-cloak class="flex justify-between text-sm text-ouro">
        <span>Cupom <span x-text="$store.cart.cupom.codigo" class="font-semibold"></span></span>
        <span class="num" x-text="'− ' + $store.cart.formatar($store.cart.cupomDesconto)"></span>
    </div>
    <div x-show="$store.cart.form.opcaoEntregaId" x-cloak class="flex justify-between text-sm text-farinha">
        <span>Entrega</span>
        <span class="num" :class="$store.cart.valorFrete === 0 && 'text-manjericao'"
              x-text="$store.cart.valorFrete > 0 ? '+ ' + $store.cart.formatar($store.cart.valorFrete) : 'Grátis'"></span>
    </div>
    <div class="flex items-baseline justify-between pt-1">
        <span class="font-semibold">Total</span>
        <span class="num text-xl font-bold" x-text="$store.cart.formatar($store.cart.totalComFrete)"></span>
    </div>
    <button type="button" @click="$store.cart.submitCheckout()"
            :disabled="$store.cart.submitting || !$store.cart.estaAbertoAgora()"
            class="mt-2 inline-flex h-14 w-full items-center justify-center gap-2 rounded-2xl bg-whats text-base font-bold text-white transition hover:bg-whats-escuro disabled:cursor-not-allowed disabled:opacity-60">
        <template x-if="!$store.cart.submitting">
            <span class="flex items-center gap-2"><i class='bx bxl-whatsapp text-2xl' aria-hidden="true"></i> Enviar pedido pelo WhatsApp</span>
        </template>
        <template x-if="$store.cart.submitting">
            <span class="flex items-center gap-2"><i class='bx bx-loader-alt bx-spin text-xl' aria-hidden="true"></i> Enviando…</span>
        </template>
    </button>
    <p class="text-center text-xs text-farinha">Você confirma o pedido com a loja pelo WhatsApp.</p>
</div>
