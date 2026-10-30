{{--
    Acompanhamento público do pedido, na identidade "Forno aceso" do cardápio.
    $completo (assinatura da URL) libera os dados pessoais; sem ela só o
    estado e a linha do tempo. Etapas: App\Support\Pedido\EtapasAcompanhamento.
--}}
@php
    $fmt = fn ($v) => 'R$ '.number_format((float) $v, 2, ',', '.');
    $cancelado = $etapas?->cancelado();
    $destaque = $etapas && ! $cancelado ? $etapas->destaque() : null;
    $concluido = $etapas?->concluido();
    $itens = $completo && $pedido ? $pedido->item_pedido_pedido_id : collect();
@endphp
<div @if($deveAtualizar) wire:poll.30000ms @endif
     class="min-h-dvh pb-16"
     x-data="{ ultimaAtt: new Date().toLocaleTimeString('pt-BR', {hour:'2-digit',minute:'2-digit'}) }"
     x-on:livewire:update.window="ultimaAtt = new Date().toLocaleTimeString('pt-BR', {hour:'2-digit',minute:'2-digit'})">

    {{-- ── Cabeçalho ── --}}
    <header class="border-b border-linha bg-forno/90">
        <div class="mx-auto flex h-14 w-full max-w-4xl items-center justify-between gap-3 px-4 lg:h-[72px] lg:px-6">
            <a href="{{ route('cardapio') }}" class="-ml-1 shrink-0" aria-label="Empório da Pizza — cardápio">
                <img src="{{ asset('img/marca/logo.webp') }}"
                     srcset="{{ asset('img/marca/logo.webp') }} 1x, {{ asset('img/marca/logo@2x.webp') }} 2x"
                     alt="Empório da Pizza" width="100" height="45" class="h-10 w-auto lg:h-12">
            </a>
            @if ($pedido)
                <p class="text-right text-sm text-farinha">
                    <span class="block text-xs font-semibold uppercase tracking-wider">Acompanhe seu pedido</span>
                    <span class="font-display text-lg font-semibold text-creme">Pedido nº <span class="num">{{ $pedido->id }}</span></span>
                </p>
            @endif
        </div>
    </header>

    <main class="mx-auto w-full max-w-4xl px-4 pt-6 lg:px-6 lg:pt-10">
        @if (! $pedido)
            <div class="mx-auto max-w-md py-16 text-center">
                <i class='bx bx-error-circle text-6xl text-tomate' aria-hidden="true"></i>
                <h1 class="mt-3 font-display text-2xl font-semibold">Pedido não encontrado</h1>
                <p class="mt-2 text-sm text-farinha">Confira o link que você recebeu da loja.</p>
                <a href="{{ route('cardapio') }}" class="mt-6 inline-flex h-12 items-center rounded-xl bg-brasa px-6 text-sm font-semibold text-white hover:bg-brasa-escura">Ver o cardápio</a>
            </div>

        @elseif ($cancelado)
            <div class="mx-auto max-w-md rounded-2xl border border-linha bg-forno-2 p-8 text-center">
                <i class='bx bx-x-circle text-6xl text-tomate' aria-hidden="true"></i>
                <h1 class="mt-3 font-display text-2xl font-semibold">Pedido cancelado</h1>
                <p class="mt-2 text-sm text-farinha">
                    Este pedido foi cancelado{{ $pedido->pedido_datahora_cancelado ? ' em '.$pedido->pedido_datahora_cancelado->format('d/m/Y \à\s H:i') : '' }}.
                    Se foi um engano, fale com a loja.
                </p>
                <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:justify-center">
                    <a href="{{ $linkWhatsapp }}" target="_blank" rel="noopener" class="inline-flex h-12 items-center justify-center gap-2 rounded-xl bg-whats px-6 text-sm font-semibold text-white hover:bg-whats-escuro">
                        <i class='bx bxl-whatsapp text-xl' aria-hidden="true"></i> Falar com a loja
                    </a>
                    <a href="{{ route('cardapio') }}" class="inline-flex h-12 items-center justify-center rounded-xl bg-brasa px-6 text-sm font-semibold text-white hover:bg-brasa-escura">Fazer novo pedido</a>
                </div>
            </div>

        @else
            <div @class(['gap-6 lg:grid lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] lg:items-start lg:gap-8' => $completo, 'mx-auto max-w-xl' => ! $completo])>

                {{-- Coluna 1: estado atual + linha do tempo --}}
                <div class="space-y-4">
                    @if ($completo && $pedido->cliente?->cliente_nome)
                        <p class="text-sm text-farinha">Olá, <span class="font-semibold text-creme">{{ explode(' ', trim($pedido->cliente->cliente_nome))[0] }}</span>!</p>
                    @endif

                    <section aria-live="polite" @class([
                        'flex items-start gap-4 rounded-2xl border p-5',
                        'border-manjericao/40 bg-manjericao/10' => $concluido,
                        'border-brasa-viva/50 bg-brasa/15' => ! $concluido,
                    ])>
                        <span @class([
                            'grid size-14 shrink-0 place-items-center rounded-full text-3xl text-white',
                            'bg-whats' => $concluido,
                            'bg-brasa' => ! $concluido,
                        ])>
                            <i class='bx {{ $destaque['icone'] }}' aria-hidden="true"></i>
                        </span>
                        <div class="min-w-0">
                            <h1 class="font-display text-2xl font-semibold leading-tight">{{ $destaque['titulo'] }}</h1>
                            <p class="mt-1 text-[15px] text-farinha">{{ $destaque['descricao'] }}</p>
                        </div>
                    </section>

                    <section class="rounded-2xl border border-linha bg-forno-2 p-5" aria-label="Andamento do pedido">
                        <h2 class="mb-4 text-xs font-semibold uppercase tracking-wider text-farinha">Andamento</h2>
                        <ol class="relative">
                            @foreach ($etapas->etapas() as $etapa)
                                <li class="relative flex items-start gap-4 {{ $loop->last ? '' : 'pb-6' }}" @if($etapa['estado'] === 'atual') aria-current="step" @endif>
                                    @unless ($loop->last)
                                        <span @class(['absolute left-[15px] top-8 h-[calc(100%-1.5rem)] w-0.5', 'bg-whats' => $etapa['estado'] === 'concluida', 'bg-linha' => $etapa['estado'] !== 'concluida']) aria-hidden="true"></span>
                                    @endunless
                                    <span @class([
                                        'relative z-10 grid size-8 shrink-0 place-items-center rounded-full text-base',
                                        'bg-whats text-white' => $etapa['estado'] === 'concluida',
                                        'bg-brasa text-white ring-2 ring-brasa-viva/60 ring-offset-2 ring-offset-forno-2' => $etapa['estado'] === 'atual',
                                        'bg-forno-3 text-farinha' => $etapa['estado'] === 'pendente',
                                    ])>
                                        <i class='bx {{ $etapa['estado'] === 'concluida' ? 'bx-check' : $etapa['icone'] }}' aria-hidden="true"></i>
                                    </span>
                                    <div class="flex min-w-0 flex-1 items-baseline justify-between gap-3 pt-1">
                                        <p @class(['text-[15px] font-semibold leading-tight', 'text-creme' => $etapa['estado'] !== 'pendente', 'text-farinha' => $etapa['estado'] === 'pendente'])>
                                            {{ $etapa['rotulo'] }}
                                            @if ($etapa['estado'] === 'atual' && ! $concluido)
                                                <span class="sr-only">(etapa atual)</span>
                                            @endif
                                        </p>
                                        @if ($etapa['hora'])
                                            <time class="num shrink-0 text-sm text-farinha" datetime="{{ $etapa['hora']->toIso8601String() }}">{{ $etapa['hora']->format('H:i') }}</time>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    </section>
                </div>

                {{-- Coluna 2: "Onde retirar" (dado da loja, sempre) e dados do pedido (só com link assinado) --}}
                <div class="mt-4 space-y-4 lg:mt-0">
                        @if ($retirada)
                            <section class="rounded-2xl border border-linha bg-forno-2 p-5">
                                <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-farinha">Onde retirar</h2>
                                @if ($enderecoRetirada)
                                    <div class="flex items-start gap-3">
                                        <i class='bx bx-store text-2xl text-brasa-viva' aria-hidden="true"></i>
                                        <p class="text-[15px] font-medium">{{ $enderecoRetirada }}</p>
                                    </div>
                                @endif
                                @if ($horarioHoje)
                                    <p class="mt-3 flex items-center gap-3 text-sm text-farinha">
                                        <i class='bx bx-time-five text-2xl text-brasa-viva' aria-hidden="true"></i>
                                        <span>Hoje, das <span class="num font-semibold text-creme">{{ $horarioHoje }}</span></span>
                                    </p>
                                @endif
                                @if (! $enderecoRetirada && ! $horarioHoje)
                                    <p class="text-sm text-farinha">Combine a retirada direto com a loja.</p>
                                @endif
                                @if ($linkComoChegar)
                                    <a href="{{ $linkComoChegar }}" target="_blank" rel="noopener"
                                       class="mt-4 inline-flex h-11 items-center gap-2 rounded-xl border border-linha bg-forno-3 px-4 text-sm font-semibold hover:border-brasa-viva">
                                        <i class='bx bx-map-pin text-lg text-brasa-viva' aria-hidden="true"></i> Como chegar
                                    </a>
                                @endif
                            </section>
                        @elseif ($completo && ($pedido->pedido_endereco_entrega || $pedido->opcaoEntrega))
                            <section class="rounded-2xl border border-linha bg-forno-2 p-5">
                                <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-farinha">Entrega</h2>
                                <div class="flex items-start gap-3">
                                    <i class='bx bx-map-pin text-2xl text-brasa-viva' aria-hidden="true"></i>
                                    <div class="min-w-0">
                                        @if ($pedido->opcaoEntrega)
                                            <p class="text-[15px] font-medium">{{ $pedido->opcaoEntrega->opcaoentrega_nome }}</p>
                                        @endif
                                        @if ($pedido->pedido_endereco_entrega)
                                            <p class="mt-0.5 text-sm text-farinha">{{ $pedido->pedido_endereco_entrega }}</p>
                                        @endif
                                    </div>
                                </div>
                                @if ((float) $pedido->pedido_valor_frete > 0)
                                    <p class="mt-3 flex justify-between border-t border-linha pt-3 text-sm">
                                        <span class="text-farinha">Taxa de entrega</span>
                                        <span class="num font-semibold">{{ $fmt($pedido->pedido_valor_frete) }}</span>
                                    </p>
                                @endif
                            </section>
                        @endif

                        @if ($completo)
                        {{-- Itens recolhíveis --}}
                        <details class="group rounded-2xl border border-linha bg-forno-2">
                            <summary class="flex min-h-14 cursor-pointer list-none items-center justify-between gap-3 p-5 [&::-webkit-details-marker]:hidden">
                                <span>
                                    <span class="block text-xs font-semibold uppercase tracking-wider text-farinha">Itens do pedido</span>
                                    <span class="num mt-0.5 block text-[15px] font-semibold">
                                        {{ $itens->count() }} {{ $itens->count() === 1 ? 'item' : 'itens' }} · {{ $fmt($pedido->pedido_valor_total) }}
                                    </span>
                                </span>
                                <i class='bx bx-chevron-down text-2xl text-farinha transition-transform group-open:rotate-180' aria-hidden="true"></i>
                            </summary>
                            <div class="space-y-3 border-t border-linha px-5 pb-5 pt-4">
                                @foreach ($itens as $item)
                                    @php
                                        $qtd = (float) $item->item_pedido_quantidade;
                                        $qtdFmt = match (true) {
                                            abs($qtd - 0.5) < 0.01 => '½',
                                            abs($qtd - 1 / 3) < 0.01 => '⅓',
                                            abs($qtd - 2 / 3) < 0.01 => '⅔',
                                            $qtd == (int) $qtd => (string) (int) $qtd,
                                            default => number_format($qtd, 2, ',', ''),
                                        };
                                    @endphp
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0 text-[15px] leading-snug">
                                            <span class="num font-bold text-brasa-viva">{{ $qtdFmt }}×</span>
                                            @if ($item->ehMultiSabor())
                                                @foreach ($item->linhasSabores() as $linha)
                                                    <span class="block">{{ $linha }}</span>
                                                @endforeach
                                            @else
                                                {{ $item->nomeProduto() }}
                                            @endif
                                            @foreach ($item->linhasRespostas() as $resposta)
                                                <span class="block text-sm text-farinha">{{ $resposta }}</span>
                                            @endforeach
                                            @if ($item->item_pedido_observacao && $item->item_pedido_observacao !== $item->produto?->produto_descricao)
                                                <span class="block text-sm text-farinha">{{ $item->item_pedido_observacao }}</span>
                                            @endif
                                        </div>
                                        <span class="num shrink-0 text-[15px] font-medium">{{ $fmt($item->item_pedido_valor) }}</span>
                                    </div>
                                @endforeach

                                <div class="space-y-1 border-t border-linha pt-3 text-sm">
                                    @if ((float) $pedido->pedido_valor_desconto > 0)
                                        <p class="flex justify-between text-farinha"><span>Subtotal</span><span class="num">{{ $fmt($pedido->pedido_valor_itens) }}</span></p>
                                        <p class="flex justify-between text-ouro"><span>Desconto</span><span class="num">− {{ $fmt($pedido->pedido_valor_desconto) }}</span></p>
                                    @endif
                                    @if ((float) $pedido->pedido_valor_frete > 0)
                                        <p class="flex justify-between text-farinha"><span>Taxa de entrega</span><span class="num">+ {{ $fmt($pedido->pedido_valor_frete) }}</span></p>
                                    @endif
                                    <p class="flex justify-between text-base font-bold"><span>Total</span><span class="num">{{ $fmt($pedido->pedido_valor_total) }}</span></p>
                                </div>
                            </div>
                        </details>

                        @if ($pedido->pedido_descricao_pagamento)
                            <section class="rounded-2xl border border-linha bg-forno-2 p-5">
                                <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-farinha">Pagamento</h2>
                                <div class="flex items-start gap-3">
                                    <i class='bx bx-credit-card text-2xl text-brasa-viva' aria-hidden="true"></i>
                                    <div>
                                        <p class="text-[15px] font-medium">{{ $pedido->pedido_descricao_pagamento }}</p>
                                        @if ($pedido->pedido_observacao_pagamento)
                                            <p class="mt-0.5 text-sm text-farinha">{{ $pedido->pedido_observacao_pagamento }}</p>
                                        @endif
                                    </div>
                                </div>
                            </section>
                        @endif
                        @endif

                        @unless ($completo)
                            <p class="rounded-2xl border border-linha bg-forno-2 p-4 text-sm text-farinha">
                                Por segurança, este link mostra apenas o andamento do pedido. Os detalhes aparecem só no link completo, gerado na confirmação do pedido.
                            </p>
                        @endunless
                    </div>
            </div>

            {{-- ── Falar com a loja ── --}}
            <div class="mx-auto mt-6 flex max-w-xl flex-col gap-3 sm:flex-row sm:justify-center">
                <a href="{{ $linkWhatsapp }}" target="_blank" rel="noopener"
                   class="inline-flex h-12 items-center justify-center gap-2 rounded-xl bg-whats px-6 text-sm font-semibold text-white hover:bg-whats-escuro">
                    <i class='bx bxl-whatsapp text-xl' aria-hidden="true"></i> Falar com a loja
                </a>
                @if ($concluido)
                    <a href="{{ route('cardapio') }}" class="inline-flex h-12 items-center justify-center rounded-xl bg-brasa px-6 text-sm font-semibold text-white hover:bg-brasa-escura">Fazer novo pedido</a>
                @endif
            </div>

            {{-- ── Avaliações (só no fim) ── --}}
            @if (! empty($avaliacaoLinks))
                <section class="mx-auto mt-6 max-w-xl rounded-2xl border border-linha bg-forno-2 p-5 text-center">
                    <p class="font-display text-lg font-semibold">Gostou do seu pedido?</p>
                    <p class="mt-1 text-sm text-farinha">Deixe sua avaliação e nos ajude a melhorar.</p>
                    <div class="mt-4 flex flex-wrap items-center justify-center gap-5">
                        @foreach ($avaliacaoLinks as $link)
                            <a href="{{ $link['url'] }}" target="_blank" rel="noopener noreferrer" class="flex min-h-11 flex-col items-center gap-1.5">
                                <span class="grid size-12 place-items-center overflow-hidden rounded-full bg-white">
                                    <img src="{{ $link['logo_url'] }}" alt="" class="size-8 object-contain" loading="lazy">
                                </span>
                                <span class="text-xs text-farinha">{{ $link['nome'] }}</span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif

            {{-- ── Rodapé ── --}}
            <p class="mt-8 text-center text-xs text-farinha">
                @if ($deveAtualizar)
                    Atualiza sozinho a cada 30 s · última às <span class="num" x-text="ultimaAtt"></span>
                @else
                    Atualização automática encerrada
                @endif
            </p>
        @endif
    </main>
</div>
