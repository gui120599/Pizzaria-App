<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight flex items-center space-x-2">
            <i class='bx bx-chair'></i>
            <a href="{{ route('sessao_caixa') }}">{{ __('Sessões de Caixa') }}</a>
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

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
                                                <div class="flex items-center justify-center space-x-2 p-1">
                                                    <x-primary-button onclick="window.location.href = '{{ route('venda.gerar_NFE', ['id' => $venda->id]) }}'" title="GERAR NFC-E"><i class='bx bx-note' ></i></x-primary-button>
                                                </div>
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
