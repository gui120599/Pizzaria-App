<section>
    <div class="w-[18rem] sm:w-[99%] overflow-auto h-2/4">
        <table class="w-full text-[7px] md:text-base">
            <thead class="">
                <tr class="border-b-4">
                    <th class="min-w-full ">STATUS</th>
                    <th class="min-w-full">#</th>
                    <th class="min-w-full text-start">Cliente</th>
                    <th class="min-w-full text-start">Mesa</th>
                    <th class="min-w-full text-start">Garçom</th>
                    <th class="min-w-full text-start">ENTREGA</th>
                    <th class="min-w-full text-start">Valor total</th>
                </tr>
            </thead>
            <tbody>
                @if (count($pedidos) > 0)
                    @foreach ($pedidos as $pedido)
                        <tr class="border-b-2 border-gray-100">
                            <td class="text-center">{{ $pedido->pedido_status }}</td>
                            <td class="text-center">{{ $pedido->id }}</td>
                            <td class="">{{ $pedido->cliente->cliente_nome }}</td>
                            <td class="">{{ $pedido->sessaoMesa->mesa->mesa_nome }}</td>
                            <td class="text-start">{{ $pedido->garcom->name }}</td>
                            <td class="text-start">
                                <i class='bx bx-chair bx-tada mx-1'></i>
                                <span>{{ $pedido->opcaoEntrega->opcaoentrega_nome }}</span>
                            </td>
                            <td class="text-start">R$ {{ $pedido->pedido_valor_total }}</td>
                        </tr>
                    @endforeach
                @else
                    <tr>
                        <td colspan="3" class="text-center py-4">Nenhuma pedido encontrado.</td>
                    </tr>
                @endif
            </tbody>
        </table>
    </div>
</section>
