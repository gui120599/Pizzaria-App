<div>
    @if ($mensagemSucesso)
        <div class="mb-3 flex items-center gap-2 px-3 py-2 bg-teal-50 border border-teal-200 rounded-lg text-teal-700 text-sm">
            <i class='bx bx-check-circle'></i> {{ $mensagemSucesso }}
        </div>
    @endif

    @if (! $modalAberta && $this->vendasOrfas->isNotEmpty())
        <x-secondary-button type="button" wire:click="abrirModal">
            <i class='bx bx-receipt'></i>
            &nbsp;Vendas sem sessão de caixa ({{ $this->vendasOrfas->count() }})
        </x-secondary-button>
    @endif

    @if ($modalAberta)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
            <div class="bg-white rounded-xl shadow-xl w-full max-w-2xl">
                <div class="px-4 py-3 border-b border-gray-100">
                    <h3 class="text-sm font-semibold text-gray-700">Vendas recebidas antes da abertura deste caixa</h3>
                </div>
                <div class="p-4 space-y-3">
                    @php
                        $totalSelecionado = \App\Models\Venda::whereIn('id', $selecionadas)->sum('venda_valor_pago');
                    @endphp

                    <p class="text-sm text-gray-600">
                        Há <strong>{{ $this->vendasOrfas->count() }}</strong> venda(s) que foram recebidas pela
                        maquininha e finalizadas <strong>antes</strong> de alguém abrir este caixa (nenhuma ou mais
                        de uma sessão estava aberta no momento do pagamento). Confira quais pertencem a este turno.
                    </p>

                    @error('sessao')
                        <p class="text-xs text-red-600">{{ $message }}</p>
                    @enderror

                    <div class="w-full overflow-auto max-h-96 border border-gray-100 rounded-lg">
                        <table class="w-full text-sm text-left">
                            <thead class="bg-gray-50 sticky top-0">
                                <tr>
                                    <th class="px-3 py-2 w-8"></th>
                                    <th class="px-3 py-2">#</th>
                                    <th class="px-3 py-2">Cliente</th>
                                    <th class="px-3 py-2">Finalizada em</th>
                                    <th class="px-3 py-2 text-right">Valor pago</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($this->vendasOrfas as $venda)
                                    <tr class="border-t border-gray-100">
                                        <td class="px-3 py-2">
                                            <input type="checkbox" wire:model="selecionadas" value="{{ $venda->id }}"
                                                class="rounded border-gray-300 text-primary-600 focus:ring-primary-500" />
                                        </td>
                                        <td class="px-3 py-2 font-medium">#{{ $venda->id }}</td>
                                        <td class="px-3 py-2">{{ $venda->cliente?->cliente_nome ?? 'Sem cliente' }}</td>
                                        <td class="px-3 py-2 text-gray-500">
                                            {{ $venda->venda_datahora_finalizada?->format('d/m/Y H:i') ?? '—' }}
                                        </td>
                                        <td class="px-3 py-2 text-right font-semibold">
                                            R$ {{ number_format((float) $venda->venda_valor_pago, 2, ',', '.') }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <p class="text-sm text-gray-700">
                        Selecionadas: <strong>{{ count($selecionadas) }}</strong> —
                        total <strong>R$ {{ number_format((float) $totalSelecionado, 2, ',', '.') }}</strong>
                    </p>

                    <div class="flex gap-2">
                        <button wire:click="vincular" wire:loading.attr="disabled" type="button"
                            class="flex-1 py-2 rounded-lg text-sm font-semibold bg-primary-600 text-white hover:bg-primary-700 disabled:opacity-50">
                            Vincular selecionadas a este caixa
                        </button>
                        <button wire:click="dispensar" type="button"
                            class="px-4 py-2 rounded-lg text-sm font-semibold border border-gray-300 text-gray-600 hover:bg-gray-50">
                            Deixar pra depois
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
