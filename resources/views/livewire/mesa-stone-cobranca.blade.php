<div>
    @if ($this->temFormaStone && $this->maquininhasStone->isNotEmpty() && $this->totalAberto > 0)
        <x-secondary-button type="button" wire:click="abrirModal">
            Cobrar na maquininha
        </x-secondary-button>
    @endif

    @if ($modalAberta)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
            <div class="bg-white rounded-xl shadow-xl w-full max-w-sm">
                <div class="px-4 py-3 border-b border-gray-100">
                    <h3 class="text-sm font-semibold text-gray-700">Cobrar conta na maquininha</h3>
                </div>
                <div class="p-4 space-y-3">
                    @if ($status === 'form')
                        <div>
                            <label class="text-xs font-medium text-gray-500">Maquininha</label>
                            <select wire:model="maquininhaId" class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 mt-1 focus:outline-none focus:ring-2 focus:ring-primary-500">
                                <option value="">Selecione a maquininha...</option>
                                @foreach ($this->maquininhasStone as $id => $nome)
                                    <option value="{{ $id }}">{{ $nome }}</option>
                                @endforeach
                            </select>
                            @error('maquininhaId')
                                <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <p class="text-xs text-gray-500">
                            Envia a conta inteira da mesa
                            (<strong class="text-gray-700">R$ {{ number_format($this->totalAberto, 2, ',', '.') }}</strong>)
                            pra lista do POS — o tipo (crédito, débito ou PIX) é escolhido na própria maquininha.
                        </p>

                        <div class="flex gap-2">
                            <button wire:click="enviarCobranca" wire:loading.attr="disabled" type="button" class="flex-1 py-2 rounded-lg text-sm font-semibold bg-primary-600 text-white hover:bg-primary-700 disabled:opacity-50">
                                Enviar cobrança
                            </button>
                            <button wire:click="fecharModal" type="button" class="px-4 py-2 rounded-lg text-sm font-semibold border border-gray-300 text-gray-600 hover:bg-gray-50">
                                Cancelar
                            </button>
                        </div>
                    @elseif ($status === 'aguardando')
                        <div wire:poll.3s="verificarStatus" class="flex flex-col items-center gap-3 py-4 text-center">
                            <svg class="animate-spin h-6 w-6 text-primary-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            <p class="text-sm text-gray-600">Aguardando o pagamento na maquininha…</p>
                        </div>
                        <div class="flex gap-2">
                            <button wire:click="cancelarCobranca" wire:loading.attr="disabled" type="button" class="flex-1 py-2 rounded-lg text-sm font-semibold border border-red-300 text-red-600 hover:bg-red-50 disabled:opacity-50">
                                Cancelar cobrança
                            </button>
                            <button wire:click="fecharModal" type="button" class="flex-1 py-2 rounded-lg text-sm font-semibold border border-gray-300 text-gray-600 hover:bg-gray-50">
                                Fechar
                            </button>
                        </div>
                    @elseif ($status === 'pago')
                        <div class="flex flex-col items-center gap-2 py-2 text-center">
                            <p class="text-sm font-medium text-gray-700">✅ Pagamento confirmado!</p>
                            <p class="text-xs text-gray-400">A conta já foi finalizada automaticamente.</p>
                        </div>
                        <button wire:click="fecharModal" type="button" class="w-full py-2 rounded-lg text-sm font-semibold bg-primary-600 text-white hover:bg-primary-700">
                            Concluir
                        </button>
                    @elseif ($status === 'erro')
                        <div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-3 py-2">
                            {{ $erro }}
                        </div>
                        <button wire:click="fecharModal" type="button" class="w-full py-2 rounded-lg text-sm font-semibold border border-gray-300 text-gray-600 hover:bg-gray-50">
                            Fechar
                        </button>
                    @elseif ($status === 'cancelado')
                        <div class="flex flex-col items-center gap-2 py-2 text-center">
                            <p class="text-sm text-gray-600">Cobrança cancelada.</p>
                        </div>
                        <button wire:click="fecharModal" type="button" class="w-full py-2 rounded-lg text-sm font-semibold border border-gray-300 text-gray-600 hover:bg-gray-50">
                            Fechar
                        </button>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
