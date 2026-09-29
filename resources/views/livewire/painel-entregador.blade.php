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

                        <div class="px-4 pb-4 pt-3 space-y-2">
                            @if ($this->maquininhasStone->isNotEmpty())
                                <button wire:click="abrirModalStone({{ $pedido->id }})"
                                        class="w-full py-2.5 border-2 border-teal-500 text-teal-600 font-bold rounded-lg text-sm flex items-center justify-center gap-2">
                                    <i class='bx bx-credit-card-alt text-lg'></i>
                                    Receber na maquininha
                                </button>
                            @endif
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

    {{-- Modal Cobrança na maquininha Stone --}}
    @if ($modalStoneAberta)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
            <div class="bg-white rounded-xl shadow-xl w-full max-w-sm">
                <div class="px-4 py-3 border-b border-gray-100">
                    <h3 class="text-sm font-semibold text-gray-700">Cobrança na maquininha</h3>
                </div>
                <div class="p-4 space-y-3">
                    @if ($stoneStatusModal === 'form')
                        <div>
                            <label class="text-xs font-medium text-gray-500">Minha maquininha</label>
                            <select wire:model="stoneMaquininhaId" class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 mt-1 focus:outline-none focus:ring-2 focus:ring-teal-500">
                                <option value="">Selecione...</option>
                                @foreach ($this->maquininhasStone as $id => $nome)
                                    <option value="{{ $id }}">{{ $nome }}</option>
                                @endforeach
                            </select>
                            @error('stoneMaquininhaId')
                                <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                        <p class="text-xs text-gray-500">O pedido vai para a lista do POS — escolha crédito, débito ou PIX na própria maquininha.</p>
                        <div class="flex gap-2">
                            <button wire:click="enviarCobrancaStone" wire:loading.attr="disabled" type="button" class="flex-1 py-2 rounded-lg text-sm font-semibold bg-teal-600 text-white hover:bg-teal-700 disabled:opacity-50">
                                Enviar cobrança
                            </button>
                            <button wire:click="fecharModalStone" type="button" class="px-4 py-2 rounded-lg text-sm font-semibold border border-gray-300 text-gray-600 hover:bg-gray-50">
                                Cancelar
                            </button>
                        </div>
                    @elseif ($stoneStatusModal === 'aguardando')
                        <div wire:poll.3s="verificarStatusStone" class="flex flex-col items-center gap-3 py-4 text-center">
                            <svg class="animate-spin h-6 w-6 text-teal-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            <p class="text-sm text-gray-600">Aguardando o pagamento na maquininha…</p>
                        </div>
                        <div class="flex gap-2">
                            <button wire:click="cancelarCobrancaStone" wire:loading.attr="disabled" type="button" class="flex-1 py-2 rounded-lg text-sm font-semibold border border-red-300 text-red-600 hover:bg-red-50 disabled:opacity-50">
                                Cancelar cobrança
                            </button>
                            <button wire:click="fecharModalStone" type="button" class="flex-1 py-2 rounded-lg text-sm font-semibold border border-gray-300 text-gray-600 hover:bg-gray-50">
                                Fechar
                            </button>
                        </div>
                    @elseif ($stoneStatusModal === 'pago')
                        <div class="flex flex-col items-center gap-2 py-2 text-center">
                            <i class='bx bx-check-circle text-4xl text-emerald-500'></i>
                            <p class="text-sm font-medium text-gray-700">Pagamento confirmado!</p>
                            <p class="text-xs text-gray-400">A venda já foi finalizada automaticamente.</p>
                        </div>
                        <button wire:click="fecharModalStone" type="button" class="w-full py-2 rounded-lg text-sm font-semibold bg-teal-600 text-white hover:bg-teal-700">
                            Concluir
                        </button>
                    @elseif ($stoneStatusModal === 'erro')
                        <div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-3 py-2">
                            {{ $stoneErroModal }}
                        </div>
                        <button wire:click="fecharModalStone" type="button" class="w-full py-2 rounded-lg text-sm font-semibold border border-gray-300 text-gray-600 hover:bg-gray-50">
                            Fechar
                        </button>
                    @elseif ($stoneStatusModal === 'cancelado')
                        <div class="flex flex-col items-center gap-2 py-2 text-center">
                            <i class='bx bx-x-circle text-4xl text-gray-400'></i>
                            <p class="text-sm text-gray-600">Cobrança cancelada.</p>
                        </div>
                        <button wire:click="fecharModalStone" type="button" class="w-full py-2 rounded-lg text-sm font-semibold border border-gray-300 text-gray-600 hover:bg-gray-50">
                            Fechar
                        </button>
                    @endif
                </div>
            </div>
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
