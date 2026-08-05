<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight flex items-center space-x-2">
            <i class='bx bx-chair'></i>
            <a href="{{ route('sessao_caixa') }}">{{ __('Sessões de Caixa') }}</a>
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            {{-- Vendas em aberto (INICIADA) --}}
            @if($vendasIniciadas->isNotEmpty())
            <div class="p-4 sm:p-8 bg-yellow-50 border border-yellow-200 shadow sm:rounded-lg">
                <h3 class="flex items-center gap-2 text-sm font-bold text-yellow-700 uppercase tracking-wide mb-3">
                    <i class='bx bx-time-five'></i> Vendas em Aberto
                </h3>

                @if(session('success'))
                    <div class="mb-3 flex items-center gap-2 px-3 py-2 bg-teal-50 border border-teal-200 rounded-lg text-teal-700 text-sm">
                        <i class='bx bx-check-circle'></i> {{ session('success') }}
                    </div>
                @endif

                <div class="w-full overflow-auto">
                    <table class="w-full text-center text-[7px] md:text-sm">
                        <thead>
                            <tr class="border-b-2 border-yellow-200">
                                <th class="px-2 py-1">#</th>
                                <th class="px-2 py-1">Cliente</th>
                                <th class="px-2 py-1">Status</th>
                                <th class="px-2 py-1">Iniciada em</th>
                                <th class="px-2 py-1">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($vendasIniciadas as $vi)
                                <tr class="border-b border-yellow-100">
                                    <td class="px-2 py-1 font-bold">{{ $vi->id }}</td>
                                    <td class="px-2 py-1 uppercase">{{ $vi->cliente?->cliente_nome ?? 'Sem cliente' }}</td>
                                    <td class="px-2 py-1">
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-yellow-100 text-yellow-700 border border-yellow-200">
                                            {{ $vi->venda_status }}
                                        </span>
                                    </td>
                                    <td class="px-2 py-1">{{ \Carbon\Carbon::parse($vi->venda_datahora_iniciada)->format('d/m/y H:i') }}</td>
                                    <td class="px-2 py-1">
                                        <div class="flex items-center justify-center gap-2">
                                            @can('operar:venda')
                                                <a href="{{ route('venda.edit', $vi->id) }}"
                                                   class="inline-flex items-center gap-1 px-3 py-1 text-xs font-semibold bg-teal-600 hover:bg-teal-700 text-white rounded-lg transition">
                                                    <i class='bx bx-edit'></i> Continuar
                                                </a>
                                            @endcan
                                            @can('cancel:venda')
                                                <form method="POST" action="{{ route('venda.cancelar_web', $vi->id) }}"
                                                      onsubmit="return confirm('Cancelar venda #{{ $vi->id }}? Os itens serão liberados para nova venda.')">
                                                    @csrf
                                                    <button type="submit"
                                                        class="inline-flex items-center gap-1 px-3 py-1 text-xs font-semibold bg-red-100 hover:bg-red-200 text-red-700 border border-red-200 rounded-lg transition">
                                                        <i class='bx bx-x'></i> Cancelar
                                                    </button>
                                                </form>
                                            @endcan
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            @endif

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="w-full">
                    <div class="w-[18rem] sm:w-[99%] overflow-auto mx-auto h-2/4">
                        <table class="w-full text-center text-[7px] md:text-base">
                            <thead class="">
                                <tr class="border-b-4">
                                    <th class="w-1/12 px-1 md:px-4">#</th>
                                    <th class="w-1/6 px-1 md:px-4">Cliente</th>
                                    <th class="w-1/12 px-1 md:px-4">Valor Total</th>
                                    <th class="w-1/12 px-1 md:px-4">Valor Pago</th>
                                    <th class="w-1/12 px-1 md:px-4">Valor Troco</th>
                                    <th class="w-1/6 px-1 md:px-4">Pagamentos</th>
                                    <th class="w-1/6 px-1 md:px-4">Status</th>
                                    <th class="w-1/6 px-1 md:px-4">Data/Hora Finalizada</th>
                                    <th class="w-1/12 px-1 md:px-4">NFC-E</th>
                                </tr>
                            </thead>
                            <tbody>
                                @if(count($vendas) > 0)
                                    @foreach ($vendas as $venda)
                                        <tr class="border-b-2 border-gray-100">
                                            <td>{{ $venda->id }}</td>
                                            <td class="uppercase">{{ $venda->cliente ? $venda->cliente->cliente_nome : 'N/A' }}</td>
                                            <td class="uppercase">R$ {{ number_format($venda->venda_valor_total, 2, ',', '.') }}</td>
                                            <td class="uppercase">R$ {{ number_format($venda->venda_valor_pago, 2, ',', '.') }}</td>
                                            <td class="uppercase">R$ {{ number_format($venda->venda_valor_troco, 2, ',', '.') }}</td>
                                            <td class="text-[10px] text-center">
                                                @foreach ($venda->pagamentos as $pg)
                                                    {{ $pg->opcaoPagamento->opcaopag_nome }} - R$
                                                    {{ number_format($pg->pg_venda_valor_pagamento, 2, ',', '.') }}<br>
                                                @endforeach
                                            </td>
                                            <td class="uppercase">{{ $venda->venda_status }}</td>
                                            <td>{{ \Carbon\Carbon::parse($venda->venda_datahora_finalizada)->format('d/m/y H:i') }}</td>
                                            @if ($venda->venda_id_nfe)
                                            <td>
                                                <div class="flex items-center justify-center space-x-2 p-1">
                                                    <x-secondary-button onclick="window.open('{{ route('venda.imprimir_NFE', ['id_nfe' => $venda->venda_id_nfe]) }}')" title="IMPRIMIR"><i class='bx bx-printer' ></i></x-secondary-button>
                                                </div>
                                            </td>
                                            @else
                                            <td>
                                                @can('emitir:nfe')
                                                    <div class="flex items-center justify-center space-x-2 p-1">
                                                        <x-primary-button onclick="window.location.href = '{{ route('venda.gerar_NFE', ['id' => $venda->id]) }}'" title="GERAR NFC-E"><i class='bx bx-note' ></i></x-primary-button>
                                                    </div>
                                                @endcan
                                            </td>
                                            @endif
                                        </tr>
                                    @endforeach
                                @else
                                    <tr>
                                        <td colspan="7" class="text-center py-4">Nenhuma venda encontrada.</td>
                                    </tr>
                                @endif
                            </tbody>
                        </table>
                        
                    </div>
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
