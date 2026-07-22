<div x-data @nova-entrega-disponivel.window="playNotification()">

    {{-- Cabeçalho --}}
    <div class="flex items-center justify-between mb-4">
        <div class="flex items-center gap-3">
            <h1 class="text-xl font-bold text-gray-800">Entregas</h1>
            @if($disponiveis->count() > 0)
                <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-red-500 text-white text-sm font-bold animate-pulse">
                    {{ $disponiveis->count() }}
                </span>
            @endif
        </div>
        <button wire:click="$refresh" wire:loading.attr="disabled" wire:target="$refresh"
                class="flex items-center gap-1.5 text-gray-500 text-xs font-medium bg-gray-100 hover:bg-gray-200 rounded-full px-3 py-1.5">
            <i class='bx bx-refresh animate-spin' wire:loading wire:target="$refresh"></i>
            <i class='bx bx-refresh' wire:loading.remove wire:target="$refresh"></i>
            Atualizar
        </button>
    </div>

    {{-- Aviso de conflito ao aceitar --}}
    @if($erroAceite)
        <div class="mb-4 flex items-center gap-2 bg-red-50 border border-red-200 text-red-700 text-sm font-medium rounded-lg px-4 py-3">
            <i class='bx bx-error-circle text-lg'></i>
            <span>{{ $erroAceite }}</span>
        </div>
    @endif

    {{-- ══════════════════════════════════════════════════ --}}
    {{-- Minhas entregas em andamento                       --}}
    {{-- ══════════════════════════════════════════════════ --}}
    @if($minhasEntregas->isNotEmpty())
        <div class="mb-6">
            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">Minhas entregas em andamento</p>
            <div class="grid grid-cols-1 gap-3">
                @foreach($minhasEntregas as $pedido)
                    <div wire:key="minha-{{ $pedido->id }}" class="bg-white rounded-xl shadow-md border border-green-200 overflow-hidden">
                        <div class="bg-green-600 px-4 py-3 flex items-center justify-between">
                            <span class="text-white font-bold text-lg">#{{ $pedido->id }}</span>
                            <span class="flex items-center gap-1.5 text-white/80 text-sm">
                                <i class='bx bx-cycling'></i> Em transporte
                            </span>
                        </div>

                        <div class="p-4 space-y-3">
                            <div class="flex items-start gap-2">
                                <i class='bx bx-user text-gray-400 text-lg mt-0.5'></i>
                                <div>
                                    <p class="font-semibold text-gray-800 leading-tight">
                                        {{ $pedido->cliente?->cliente_nome ?? 'Cliente não identificado' }}
                                    </p>
                                    @if($pedido->cliente?->cliente_celular)
                                        <a href="tel:{{ $pedido->cliente->cliente_celular }}" class="text-teal-600 text-sm font-medium">
                                            <i class='bx bx-phone'></i> {{ $pedido->cliente->cliente_celular }}
                                        </a>
                                    @endif
                                </div>
                            </div>

                            <div class="flex items-start gap-2">
                                <i class='bx bx-map text-gray-400 text-lg mt-0.5'></i>
                                <p class="text-gray-700 text-sm">{{ $pedido->pedido_endereco_entrega ?? '—' }}</p>
                            </div>

                            <div class="flex items-center gap-2 bg-gray-50 rounded-lg px-3 py-2">
                                <i class='bx bx-credit-card text-gray-400'></i>
                                <div class="text-sm">
                                    <span class="text-gray-700 font-medium">{{ $pedido->pedido_descricao_pagamento ?? '—' }}</span>
                                    @if($pedido->pedido_observacao_pagamento)
                                        <span class="text-gray-400 ml-1">· {{ $pedido->pedido_observacao_pagamento }}</span>
                                    @endif
                                </div>
                            </div>

                            <div class="flex justify-between font-bold text-base text-gray-900 border-t border-gray-100 pt-3">
                                <span>Total a cobrar</span>
                                <span>R$ {{ number_format($pedido->pedido_valor_total, 2, ',', '.') }}</span>
                            </div>
                        </div>

                        @include('livewire.partials.painel-entregador-itens', ['pedido' => $pedido])
                        @include('livewire.partials.painel-entregador-qrcode', ['pedido' => $pedido])

                        <div class="px-4 pb-4 pt-3">
                            <button wire:click="marcarEntregue({{ $pedido->id }})"
                                    wire:loading.attr="disabled"
                                    wire:target="marcarEntregue({{ $pedido->id }})"
                                    wire:confirm="Confirmar entrega do pedido #{{ $pedido->id }}?"
                                    class="w-full py-3 bg-green-600 hover:bg-green-700 active:scale-95 text-white font-bold rounded-lg text-base flex items-center justify-center gap-2 transition-all">
                                <i class='bx bx-check-double text-xl'></i>
                                <span wire:loading.remove wire:target="marcarEntregue({{ $pedido->id }})">Marcar como Entregue</span>
                                <span wire:loading wire:target="marcarEntregue({{ $pedido->id }})">Confirmando…</span>
                            </button>
                            <p class="text-center text-xs text-gray-400 mt-2">Ou escaneie o QR do ticket ao entregar</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- ══════════════════════════════════════════════════ --}}
    {{-- Disponíveis para aceitar                            --}}
    {{-- ══════════════════════════════════════════════════ --}}
    <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">Disponíveis para entrega</p>

    @if($disponiveis->isEmpty())
        <div class="flex flex-col items-center justify-center py-16 text-gray-400">
            <i class='bx bx-package text-6xl text-gray-300 mb-3'></i>
            <p class="text-base font-semibold">Nenhum pedido disponível agora</p>
            <p class="text-sm mt-1">Toque em "Atualizar" pra ver novos pedidos prontos pra entrega</p>
        </div>
    @else
        <div class="grid grid-cols-1 gap-3">
            @foreach($disponiveis as $pedido)
                @php
                    $minutos = $pedido->pedido_datahora_pronto
                        ? (int) $pedido->pedido_datahora_pronto->diffInMinutes(now())
                        : 0;
                @endphp
                <div wire:key="disp-{{ $pedido->id }}" class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden">
                    <div class="bg-blue-600 px-4 py-3 flex items-center justify-between">
                        <span class="text-white font-bold text-lg">#{{ $pedido->id }}</span>
                        <span class="flex items-center gap-1.5 text-white/80 text-sm">
                            <i class='bx bx-time-five'></i>
                            @if($minutos < 1) agora
                            @elseif($minutos < 60) há {{ $minutos }}min
                            @else há {{ floor($minutos / 60) }}h{{ $minutos % 60 > 0 ? ($minutos % 60).'min' : '' }}
                            @endif
                        </span>
                    </div>

                    <div class="p-4 space-y-3">
                        <div class="flex items-start gap-2">
                            <i class='bx bx-user text-gray-400 text-lg mt-0.5'></i>
                            <p class="font-semibold text-gray-800 leading-tight">
                                {{ $pedido->cliente?->cliente_nome ?? 'Cliente não identificado' }}
                            </p>
                        </div>

                        <div class="flex items-start gap-2">
                            <i class='bx bx-map text-gray-400 text-lg mt-0.5'></i>
                            <p class="text-gray-700 text-sm">{{ $pedido->pedido_endereco_entrega ?? '—' }}</p>
                        </div>

                        <div class="flex items-center gap-2 bg-gray-50 rounded-lg px-3 py-2">
                            <i class='bx bx-credit-card text-gray-400'></i>
                            <div class="text-sm">
                                <span class="text-gray-700 font-medium">{{ $pedido->pedido_descricao_pagamento ?? '—' }}</span>
                                @if($pedido->pedido_observacao_pagamento)
                                    <span class="text-gray-400 ml-1">· {{ $pedido->pedido_observacao_pagamento }}</span>
                                @endif
                            </div>
                        </div>

                        <div class="flex justify-between font-bold text-base text-gray-900 border-t border-gray-100 pt-3">
                            <span>Total a cobrar</span>
                            <span>R$ {{ number_format($pedido->pedido_valor_total, 2, ',', '.') }}</span>
                        </div>
                    </div>

                    @include('livewire.partials.painel-entregador-itens', ['pedido' => $pedido])
                    @include('livewire.partials.painel-entregador-qrcode', ['pedido' => $pedido])

                    <div class="px-4 pb-4 pt-3">
                        <button wire:click="aceitar({{ $pedido->id }})"
                                wire:loading.attr="disabled"
                                wire:target="aceitar({{ $pedido->id }})"
                                class="w-full py-3 bg-orange-500 hover:bg-orange-600 active:scale-95 text-white font-bold rounded-lg text-base flex items-center justify-center gap-2 transition-all">
                            <i class='bx bx-cycling text-xl'></i>
                            <span wire:loading.remove wire:target="aceitar({{ $pedido->id }})">Aceitar Entrega</span>
                            <span wire:loading wire:target="aceitar({{ $pedido->id }})">Aceitando…</span>
                        </button>
                        <p class="text-center text-xs text-gray-400 mt-2">Ou escaneie o QR do ticket ao sair</p>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

</div>

<script>
    function playNotification() {
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            [0, 150, 300].forEach((delay, i) => {
                const osc  = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.frequency.value = i === 0 ? 880 : (i === 1 ? 1100 : 880);
                gain.gain.setValueAtTime(0.3, ctx.currentTime + delay / 1000);
                gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + delay / 1000 + 0.2);
                osc.start(ctx.currentTime + delay / 1000);
                osc.stop(ctx.currentTime + delay / 1000 + 0.2);
            });
        } catch (_) {}
    }
</script>
