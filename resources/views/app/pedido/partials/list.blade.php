<section>
    <header>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            {{ __('Todos pedidos abertos!') }}
        </p>
    </header>
    <div class="w-[18rem] sm:w-[99%] overflow-auto mx-auto h-2/4">
        <table class="w-full text-center text-[7px] md:text-base">
            <thead class="">
                <tr class="border-b-4">
                    <th class="w-1/6">#</th>
                    <th class="w-2/3 px-1 md:px-4">Cliente</th>
                    <th class="w-1/6 px-1 md:px-4">Mesa</th>
                    <th class="w-1/6 px-1 md:px-4">Valor total</th>
                </tr>
            </thead>
            <tbody>
                @if (count($pedidos) > 0)
                    @foreach ($pedidos as $pedido)
                        <tr class="border-b-2 border-gray-100">
                            <td>{{ $pedido->id }}</td>
                            <td>{{ $pedido->cliente->cliente_nome  }}</td>
                            <td>{{ $pedido->sessaoMesa->mesa->mesa_nome }}</td>
                            <td>{{ $pedido->pedido_status }}</td>
                            <td>R$ {{ $pedido->pedido_valor_total }}</td>
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
