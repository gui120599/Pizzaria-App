@php
    $deveAtualizar = $pedido
        && ! $cancelado
        && ! $finalizado
        && $pedido->pedido_status !== 'ENTREGUE';
@endphp
<div @if($deveAtualizar) wire:poll.30000ms @endif
     class="min-h-screen bg-gray-950 text-white pb-12"
     x-data="{ ultimaAtt: new Date().toLocaleTimeString('pt-BR', {hour:'2-digit',minute:'2-digit'}) }"
     x-on:livewire:update.window="ultimaAtt = new Date().toLocaleTimeString('pt-BR', {hour:'2-digit',minute:'2-digit'})">

    @if(! $pedido)
        {{-- Pedido não encontrado --}}
        <div class="flex flex-col items-center justify-center min-h-screen px-6 text-center">
            <i class='bx bx-error-circle text-6xl text-red-400 mb-4'></i>
            <h1 class="text-xl font-bold text-white mb-2">Pedido não encontrado</h1>
            <p class="text-gray-400 text-sm">Verifique se o número do pedido está correto.</p>
            <a href="{{ route('cardapio') }}"
               class="mt-6 px-6 py-3 bg-teal-600 hover:bg-teal-500 text-white rounded-xl font-semibold text-sm transition">
                Ver Cardápio
            </a>
        </div>

    @elseif($cancelado)
        {{-- Pedido cancelado --}}
        <div class="max-w-lg mx-auto px-4 pt-8">
            {{-- Header --}}
            <div class="text-center mb-8">
                <img src="{{ asset('img/logo Pizzaria Branco Colorido.png') }}" alt="Logo" class="h-14 mx-auto mb-4">
                <p class="text-gray-400 text-sm">Pedido #{{ $pedido->id }}</p>
            </div>
            <div class="bg-red-900/40 border border-red-700 rounded-2xl p-8 text-center">
                <i class='bx bx-x-circle text-6xl text-red-400 mb-4'></i>
                <h2 class="text-xl font-bold text-red-300 mb-2">Pedido Cancelado</h2>
                <p class="text-red-400/80 text-sm">
                    Este pedido foi cancelado em {{ $pedido->pedido_datahora_cancelado?->format('d/m/Y \à\s H:i') ?? '—' }}.
                </p>
                <a href="{{ route('cardapio') }}"
                   class="mt-6 inline-block px-6 py-3 bg-teal-600 hover:bg-teal-500 text-white rounded-xl font-semibold text-sm transition">
                    Fazer novo pedido
                </a>
            </div>
        </div>

    @else
        {{-- Pedido em andamento ou finalizado --}}
        <div class="max-w-lg mx-auto px-4">

            {{-- ── Header ── --}}
            <div class="pt-6 pb-4 text-center">
                <img src="{{ asset('img/logo Pizzaria Branco Colorido.png') }}" alt="Logo" class="h-14 mx-auto mb-4">
                <p class="text-gray-500 text-xs uppercase tracking-widest mb-1">Acompanhamento</p>
                <h1 class="text-3xl font-black text-white">Pedido <span class="text-teal-400">#{{ $pedido->id }}</span></h1>
                @if($pedido->cliente?->cliente_nome)
                    <p class="text-gray-400 text-sm mt-1">
                        Olá, <span class="text-white font-medium">{{ explode(' ', $pedido->cliente->cliente_nome)[0] }}</span>!
                    </p>
                @endif
            </div>

            {{-- ── Badge de status atual ── --}}
            @php
                $badgeCfg = match($pedido->pedido_status) {
                    'INICIADO'      => ['bg' => 'bg-yellow-500/20 border-yellow-500/50 text-yellow-300', 'icon' => 'bx-time',          'txt' => 'Aguardando confirmação'],
                    'ABERTO'        => ['bg' => 'bg-blue-500/20 border-blue-500/50 text-blue-300',       'icon' => 'bx-check-double',  'txt' => 'Confirmado'],
                    'PREPARANDO'    => ['bg' => 'bg-orange-500/20 border-orange-500/50 text-orange-300', 'icon' => 'bxs-bowl-hot',     'txt' => 'Sendo preparado'],
                    'PRONTO'        => ['bg' => 'bg-teal-500/20 border-teal-500/50 text-teal-300',       'icon' => 'bx-package',       'txt' => 'Pronto!'],
                    'EM TRANSPORTE' => ['bg' => 'bg-purple-500/20 border-purple-500/50 text-purple-300', 'icon' => 'bx-cycling',       'txt' => 'Saiu para entrega'],
                    'ENTREGUE'      => ['bg' => 'bg-green-500/20 border-green-500/50 text-green-300',    'icon' => 'bxs-home-check',   'txt' => 'Entregue!'],
                    'FINALIZADO'    => ['bg' => 'bg-green-500/20 border-green-500/50 text-green-300',    'icon' => 'bx-trophy',        'txt' => 'Finalizado!'],
                    default         => ['bg' => 'bg-gray-700/50 border-gray-600 text-gray-300',          'icon' => 'bx-circle',        'txt' => $pedido->pedido_status],
                };
            @endphp
            <div class="flex justify-center mb-6">
                <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full border text-sm font-bold {{ $badgeCfg['bg'] }}
                            {{ in_array($pedido->pedido_status, ['INICIADO','PREPARANDO','EM TRANSPORTE']) ? 'animate-pulse' : '' }}">
                    <i class='bx {{ $badgeCfg['icon'] }} text-lg'></i>
                    {{ $badgeCfg['txt'] }}
                </div>
            </div>

            {{-- ── Timeline de status ── --}}
            <div class="bg-gray-900 rounded-2xl p-5 mb-4">
                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-5">Progresso do pedido</p>

                <div class="relative">
                    {{-- Linha vertical de fundo --}}
                    <div class="absolute left-[15px] top-2 bottom-2 w-0.5 bg-gray-800"></div>

                    @foreach($steps as $i => $step)
                        @php
                            $isDone    = $stepIndex !== null && ($finalizado ? true : $i < $stepIndex);
                            $isCurrent = $stepIndex !== null && ! $finalizado && $i === $stepIndex;
                            $isPending = $stepIndex === null || (! $finalizado && $i > $stepIndex);
                        @endphp
                        <div class="relative flex items-start gap-4 {{ ! $loop->last ? 'pb-5' : '' }}">
                            {{-- Círculo do passo --}}
                            <div class="relative z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-full transition-all
                                @if($isDone) bg-green-600 text-white
                                @elseif($isCurrent) bg-teal-500 text-white ring-2 ring-teal-400/50 ring-offset-2 ring-offset-gray-900
                                @else bg-gray-800 text-gray-600
                                @endif">
                                @if($isDone)
                                    <i class='bx bx-check text-sm font-bold'></i>
                                @else
                                    <i class='bx {{ $step['icon'] }} text-sm'></i>
                                @endif
                            </div>

                            {{-- Texto do passo --}}
                            <div class="pt-1 min-w-0">
                                <p class="text-sm font-semibold leading-tight
                                   @if($isDone) text-green-400
                                   @elseif($isCurrent) text-white
                                   @else text-gray-600
                                   @endif">
                                    {{ $step['label'] }}
                                </p>
                                @if($isCurrent)
                                    <p class="text-xs text-teal-400/80 mt-0.5">{{ $step['desc'] }}</p>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- ── Entrega ── --}}
            @if($pedido->opcaoEntrega || $pedido->pedido_endereco_entrega)
                <div class="bg-gray-900 rounded-2xl p-5 mb-4">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">Entrega</p>
                    <div class="flex items-start gap-3">
                        <i class='bx bx-map-pin text-teal-400 text-xl mt-0.5'></i>
                        <div>
                            <p class="text-white font-medium text-sm">
                                {{ $pedido->opcaoEntrega?->opcaoentrega_nome ?? '—' }}
                            </p>
                            @if($pedido->pedido_endereco_entrega)
                                <p class="text-gray-400 text-sm mt-0.5">{{ $pedido->pedido_endereco_entrega }}</p>
                            @endif
                        </div>
                    </div>
                </div>
            @endif

            {{-- ── Itens ── --}}
            <div class="bg-gray-900 rounded-2xl p-5 mb-4">
                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">
                    Itens do pedido
                </p>
                <div class="space-y-3">
                    @foreach($pedido->item_pedido_pedido_id as $item)
                        @php
                            $qtd = (float) $item->item_pedido_quantidade;
                            $qtdFmt = match(true) {
                                abs($qtd - 0.5) < 0.01 => '½',
                                abs($qtd - 1/3) < 0.01 => '⅓',
                                abs($qtd - 2/3) < 0.01 => '⅔',
                                $qtd == (int)$qtd       => (string)(int)$qtd,
                                default                 => number_format($qtd, 2, ',', ''),
                            };
                        @endphp
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex-1 min-w-0">
                                @if($item->produto?->categoria)
                                    <span class="inline-block text-[10px] font-semibold px-1.5 py-0.5 rounded bg-orange-900/50 text-orange-400 border border-orange-800/50 mb-1 leading-tight">
                                        {{ $item->produto->categoria->categoria_nome }}
                                    </span>
                                @endif
                                <p class="text-sm text-white leading-tight">
                                    <span class="text-green-400 font-bold">{{ $qtdFmt }}×</span>
                                    {{ $item->produto?->produto_descricao ?? '—' }}
                                </p>
                                @if($item->item_pedido_observacao && $item->item_pedido_observacao !== $item->produto?->produto_descricao)
                                    <p class="text-gray-500 text-xs mt-0.5">{{ $item->item_pedido_observacao }}</p>
                                @endif
                            </div>
                            <span class="text-gray-300 text-sm font-medium shrink-0">
                                R$ {{ number_format($item->item_pedido_valor, 2, ',', '.') }}
                            </span>
                        </div>
                    @endforeach
                </div>

                <div class="border-t border-gray-800 mt-4 pt-4 space-y-1">
                    @if($pedido->pedido_valor_desconto > 0)
                        <div class="flex justify-between text-sm text-gray-400">
                            <span>Subtotal</span>
                            <span>R$ {{ number_format($pedido->pedido_valor_itens, 2, ',', '.') }}</span>
                        </div>
                        <div class="flex justify-between text-sm text-orange-400 font-medium">
                            <span class="flex items-center gap-1"><i class='bx bxs-purchase-tag text-xs'></i> Desconto</span>
                            <span>- R$ {{ number_format($pedido->pedido_valor_desconto, 2, ',', '.') }}</span>
                        </div>
                    @endif
                    @if(isset($pedido->pedido_valor_frete) && $pedido->pedido_valor_frete > 0)
                        <div class="flex justify-between text-sm text-yellow-400 font-medium">
                            <span class="flex items-center gap-1"><i class='bx bx-cycling text-xs'></i> Taxa de entrega</span>
                            <span>+ R$ {{ number_format($pedido->pedido_valor_frete, 2, ',', '.') }}</span>
                        </div>
                    @endif
                    <div class="flex justify-between text-base font-bold text-white">
                        <span>Total</span>
                        <span class="text-green-400">R$ {{ number_format($pedido->pedido_valor_total, 2, ',', '.') }}</span>
                    </div>
                </div>
            </div>

            {{-- ── Pagamento ── --}}
            @if($pedido->pedido_descricao_pagamento)
                <div class="bg-gray-900 rounded-2xl p-5 mb-4">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">Pagamento</p>
                    <div class="flex items-center gap-3">
                        <i class='bx bx-credit-card text-teal-400 text-xl'></i>
                        <div>
                            <p class="text-white text-sm font-medium">{{ $pedido->pedido_descricao_pagamento }}</p>
                            @if($pedido->pedido_observacao_pagamento)
                                <p class="text-gray-400 text-xs mt-0.5">{{ $pedido->pedido_observacao_pagamento }}</p>
                            @endif
                        </div>
                    </div>
                </div>
            @endif

            {{-- ── Rodapé ── --}}
            <div class="text-center pt-2 pb-6">
                @if($deveAtualizar)
                    <div class="inline-flex items-center gap-1.5 text-gray-600 text-xs">
                        <i class='bx bx-refresh animate-spin' wire:loading></i>
                        <i class='bx bx-time-five' wire:loading.remove></i>
                        <span>Atualiza a cada 30s</span>
                        <span class="text-gray-700">·</span>
                        <span>Última: <span x-text="ultimaAtt"></span></span>
                    </div>
                @else
                    <div class="inline-flex items-center gap-1.5 text-gray-600 text-xs">
                        <i class='bx bx-check-circle text-green-700'></i>
                        <span>Atualização automática encerrada</span>
                    </div>
                @endif
            </div>

        </div>
    @endif

</div>
