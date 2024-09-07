<section>
    <header>
        <div class="flex justify-between">
            <h2 class="text-lg font-medium text-gray-900">
                {{ __('Lista de Notas Fiscais com Erro') }}
            </h2>
        </div>
    </header>
    <div class="w-[18rem] sm:w-[99%] overflow-auto mx-auto h-2/4">
        <table class="mt-5 w-full text-center text-[7px] md:text-base">
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
                </tr>
            </thead>
            <tbody>
                @if (count($dataError) > 0)
                    @foreach ($dataError as $item)
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