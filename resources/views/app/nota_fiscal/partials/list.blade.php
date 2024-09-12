<section>
    <header>
        <div class="flex justify-between">
            <h2 class="text-lg font-medium text-gray-900">
                {{ __('Lista de Notas Fiscais') }}
            </h2>
            <x-secondary-button onclick="window.location.href = '{{ route('categoria.inactive') }}'">Mostrar
                Inativos</x-secondary-button>
        </div>
    </header>
    <div class="w-[18rem] sm:w-[99%] overflow-auto mx-auto h-2/4">
        <table class="w-full text-center text-[7px] md:text-base">
            <thead class="">
                <tr class="border-b-4">
                    <th class="w-1/6">#</th>
                    <th class="w-1/6">Nº</th>
                    <th class="w-1/6">Status</th>
                    <th class="w-1/6">Data de Emissão</th>
                    <th class="w-1/6">Cliente</th>
                    <th class="w-1/6">Valor Produtos</th>
                    <th class="w-full">Valor Total NF</th>
                    <th class="w-1/6">Pagamentos</th>
                    <th class="w-1/6">Opções</th>
                </tr>
            </thead>
            <tbody>
                @if (count($data) > 0)
                    @foreach ($data as $item)
                        @php
                            $createdOn = isset($item['createdOn']) ? $item['createdOn'] : null;

                            if ($createdOn) {
                                $date = \Carbon\Carbon::parse($createdOn)->setTimezone('America/Sao_Paulo');
                                $formattedDate = $date->format('d/m/Y H:i:s');
                            } else {
                                $formattedDate = 'N/A';
                            }
                        @endphp

                        <tr class="border-b-2 border-gray-100">
                            <td>{{ $item['id'] }}</td>
                            <td>{{ $item['number'] }}</td>
                            <td>{{ __($item['status']) }}</td>
                            <td>{{ $formattedDate }}</td>
                            <td>{{ isset($item['customerName']) ? $item['customerName'] : 'N/A' }}</td>
                            <td>{{ isset($item['totals']['icms']['productAmount']) ? $item['totals']['icms']['productAmount'] : 'N/A' }}
                            </td>
                            <td>{{ isset($item['totals']['icms']['invoiceAmount']) ? $item['totals']['icms']['invoiceAmount'] : 'N/A' }}
                            </td>
                            <td>
                                @foreach ($item['payment'] as $pagamento)
                                    @foreach ($pagamento['paymentDetail'] as $detail)
                                        {{ __($detail['method']) }} 
                                    @endforeach
                                @endforeach
                            </td>
                            <td>
                                <div class="flex items-center justify-center space-x-2 p-1">
                                    <x-secondary-button onclick="window.open('{{ route('venda.imprimir_NFE', ['id_nfe' => $item['id']]) }}')" title="Venda">IMPRIMIR NFE-C</x-secondary-button>
                                </div>
                            </td>
                            
                        </tr>
                    @endforeach
                @else
                    <tr>
                        <td colspan="7" class="text-center py-4">Nenhum item encontrado.</td>
                    </tr>
                @endif
            </tbody>
        </table>
    </div>

</section>
