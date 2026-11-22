<div>
    @if ($this->podeCobrar)
        <x-secondary-button type="button" wire:click="abrirModal" class="dark:border-white/10 dark:bg-white/10 dark:text-gray-100 dark:hover:bg-white/20">
            Cobrar na maquininha
        </x-secondary-button>
    @endif

    @if ($modalAberta)
        <div data-stone-modal class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4 dark:bg-black/70">
            <div class="bg-white rounded-xl shadow-xl w-full max-w-sm ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-white/10">
                <div class="px-4 py-3 border-b border-gray-100 dark:border-white/10">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Cobrar conta na maquininha</h3>
                </div>
                <div class="p-4 space-y-3">
                    @if ($status === 'form')
                        <div>
                            <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Maquininha</label>
                            <select wire:model="maquininhaId" class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 mt-1 bg-white text-gray-900 focus:outline-none focus:ring-2 focus:ring-primary-500 dark:border-white/10 dark:bg-gray-800 dark:text-gray-100">
                                <option value="">Selecione a maquininha...</option>
                                @foreach ($this->maquininhasStone as $id => $nome)
                                    <option value="{{ $id }}">{{ $nome }}</option>
                                @endforeach
                            </select>
                            @error('maquininhaId')
                                <p class="text-xs text-red-600 mt-1 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Valor a cobrar na maquininha (R$)</label>
                            <input type="text" inputmode="decimal" wire:model="valor" autocomplete="off"
                                class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 mt-1 bg-white text-gray-900 focus:outline-none focus:ring-2 focus:ring-primary-500 dark:border-white/10 dark:bg-gray-800 dark:text-gray-100">
                            @error('valor')
                                <p class="text-xs text-red-600 mt-1 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            {{ $pedidoId ? 'Pedido #'.$pedidoId : 'Conta da mesa' }}: faltam
                            <strong class="text-gray-700 dark:text-gray-200">R$ {{ number_format($this->totalAberto, 2, ',', '.') }}</strong>
                            (já descontado o que foi pago ou está aguardando na maquininha).
                            O pedido entra na lista do POS e o tipo (crédito, débito ou PIX) é escolhido na própria maquininha.
                        </p>
                        <p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 dark:text-amber-300 dark:bg-amber-500/10 dark:border-amber-500/30">
                            Parte da mesa vai pagar em dinheiro? Informe só o valor que passa na maquininha. O que sobrar fica para receber no caixa e não fica aberto no POS.
                        </p>

                        <div class="flex gap-2">
                            <button wire:click="enviarCobranca" wire:loading.attr="disabled" type="button" class="flex-1 py-2 rounded-lg text-sm font-semibold bg-primary-600 text-white hover:bg-primary-700 disabled:opacity-50">
                                Enviar cobrança
                            </button>
                            <button wire:click="fecharModal" type="button" class="px-4 py-2 rounded-lg text-sm font-semibold border border-gray-300 text-gray-600 hover:bg-gray-50 dark:border-white/10 dark:text-gray-300 dark:hover:bg-white/5">
                                Cancelar
                            </button>
                        </div>
                    @elseif ($status === 'aguardando')
                        @php($cobranca = $this->cobrancaAtual())
                        <div wire:poll.3s="verificarStatus" class="flex flex-col items-center gap-3 py-2 text-center">
                            <svg class="animate-spin h-6 w-6 text-primary-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            <p class="text-sm text-gray-600 dark:text-gray-300">Aguardando o pagamento na maquininha…</p>
                        </div>
                        @if ($cobranca)
                            @php($pago = (float) $cobranca->stp_valor_pago)
                            @php($emAberto = max(0, (float) $cobranca->stp_valor_solicitado - $pago))
                            <dl class="space-y-1 text-sm">
                                <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Enviado</dt><dd class="font-medium text-gray-700 dark:text-gray-200">R$ {{ number_format((float) $cobranca->stp_valor_solicitado, 2, ',', '.') }}</dd></div>
                                <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Já pago na maquininha</dt><dd class="font-medium text-gray-700 dark:text-gray-200">R$ {{ number_format($pago, 2, ',', '.') }}</dd></div>
                                <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Em aberto no POS</dt><dd class="font-semibold text-amber-700 dark:text-amber-400">R$ {{ number_format($emAberto, 2, ',', '.') }}</dd></div>
                            </dl>
                        @endif
                        <div class="flex flex-col gap-2">
                            @if ($this->totalAberto > 0)
                                <button wire:click="novoEnvio" type="button" class="w-full py-2 rounded-lg text-sm font-semibold border border-primary-300 text-primary-700 hover:bg-primary-50 dark:border-primary-500/30 dark:text-primary-400 dark:hover:bg-primary-500/10">
                                    Enviar outro valor (faltam R$ {{ number_format($this->totalAberto, 2, ',', '.') }})
                                </button>
                            @endif
                            @if ($cobranca && (float) $cobranca->stp_valor_pago > 0)
                                <button wire:click="encerrarRestante" wire:confirm="Encerrar a cobrança na maquininha? O que falta será recebido em dinheiro no caixa." wire:loading.attr="disabled" type="button" class="w-full py-2 rounded-lg text-sm font-semibold bg-primary-600 text-white hover:bg-primary-700 disabled:opacity-50">
                                    Encerrar: o restante foi pago em dinheiro
                                </button>
                            @else
                                <button wire:click="cancelarCobranca" wire:confirm="Cancelar a cobrança na maquininha?" wire:loading.attr="disabled" type="button" class="w-full py-2 rounded-lg text-sm font-semibold border border-red-300 text-red-600 hover:bg-red-50 disabled:opacity-50 dark:border-red-500/30 dark:text-red-400 dark:hover:bg-red-500/10">
                                    Cancelar cobrança
                                </button>
                            @endif
                            <button wire:click="fecharModal" type="button" class="w-full py-2 rounded-lg text-sm font-semibold border border-gray-300 text-gray-600 hover:bg-gray-50 dark:border-white/10 dark:text-gray-300 dark:hover:bg-white/5">
                                Fechar
                            </button>
                        </div>
                    @elseif ($status === 'encerrado')
                        <div class="flex flex-col items-center gap-2 py-2 text-center">
                            <p class="text-sm font-medium text-gray-700 dark:text-gray-200">Cobrança encerrada na maquininha.</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                @if ($this->totalAberto > 0)
                                    Restam <strong>R$ {{ number_format($this->totalAberto, 2, ',', '.') }}</strong> da conta: receba em dinheiro e finalize no caixa.
                                @else
                                    Não resta valor a receber nesta conta.
                                @endif
                            </p>
                        </div>
                        <button wire:click="fecharModal" type="button" class="w-full py-2 rounded-lg text-sm font-semibold bg-primary-600 text-white hover:bg-primary-700">
                            Concluir
                        </button>
                    @elseif ($status === 'pago')
                        <div class="flex flex-col items-center gap-2 py-2 text-center">
                            <p class="text-sm font-medium text-gray-700 dark:text-gray-200">✅ Pagamento confirmado!</p>
                            <p class="text-xs text-gray-400 dark:text-gray-500">
                                @if ($this->totalAberto > 0)
                                    Restam R$ {{ number_format($this->totalAberto, 2, ',', '.') }} da conta: receba em dinheiro e finalize no caixa.
                                @else
                                    A conta já foi finalizada automaticamente.
                                @endif
                            </p>
                        </div>
                        <button wire:click="fecharModal" type="button" class="w-full py-2 rounded-lg text-sm font-semibold bg-primary-600 text-white hover:bg-primary-700">
                            Concluir
                        </button>
                    @elseif ($status === 'erro')
                        <div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-3 py-2 dark:bg-red-500/10 dark:border-red-500/30 dark:text-red-400">
                            {{ $erro }}
                        </div>
                        <button wire:click="fecharModal" type="button" class="w-full py-2 rounded-lg text-sm font-semibold border border-gray-300 text-gray-600 hover:bg-gray-50 dark:border-white/10 dark:text-gray-300 dark:hover:bg-white/5">
                            Fechar
                        </button>
                    @elseif ($status === 'indeterminado')
                        <div class="bg-amber-50 border border-amber-200 text-amber-800 text-sm rounded-lg px-3 py-2 dark:bg-amber-500/10 dark:border-amber-500/30 dark:text-amber-300">
                            {{ $erro ?: 'Não foi possível confirmar se a cobrança chegou à maquininha. Não envie de novo: reconsulte na Stone.' }}
                        </div>
                        <div class="flex gap-2">
                            <button wire:click="reconsultarCobranca" wire:loading.attr="disabled" type="button" class="flex-1 py-2 rounded-lg text-sm font-semibold bg-primary-600 text-white hover:bg-primary-700 disabled:opacity-50">
                                Reconsultar na Stone
                            </button>
                            <button wire:click="fecharModal" type="button" class="flex-1 py-2 rounded-lg text-sm font-semibold border border-gray-300 text-gray-600 hover:bg-gray-50 dark:border-white/10 dark:text-gray-300 dark:hover:bg-white/5">
                                Fechar
                            </button>
                        </div>
                    @elseif ($status === 'cancelado')
                        <div class="flex flex-col items-center gap-2 py-2 text-center">
                            <p class="text-sm text-gray-600 dark:text-gray-300">Cobrança cancelada.</p>
                        </div>
                        <button wire:click="fecharModal" type="button" class="w-full py-2 rounded-lg text-sm font-semibold border border-gray-300 text-gray-600 hover:bg-gray-50 dark:border-white/10 dark:text-gray-300 dark:hover:bg-white/5">
                            Fechar
                        </button>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
