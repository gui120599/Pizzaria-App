<section class="h-full">
    <div class="w-[18rem] sm:w-[99%] overflow-auto h-full">
        <form action="{{ route('pedidos') }}" method="GET">
            <x-text-input name="search" id="search" placeholder="Pesquisar Código"></x-text-input>
            <x-primary-button>Pesquisar</x-primary-button>
        </form>
        <table class="w-full text-[7px] md:text-base">
            <thead class="sticky top-0 text-start bg-white ">
                <tr class="border-b-4">
                    <th class="min-w-full">#</th>
                    <th class="min-w-full ">STATUS</th>
                    <th class="min-w-full text-start">Cliente</th>
                    <th class="min-w-full text-start">Mesa</th>
                    <th class="min-w-full text-start">Garçom</th>
                    <th class="min-w-full text-start">ENTREGA</th>
                    <th class="min-w-full text-start">Valor total</th>
                    <th class="min-w-full text-center border-b-4">Opções</th>
                </tr>
            </thead>
            <tbody>
                @if (count($pedidos) > 0)
                    @foreach ($pedidos as $pedido)
                        <tr class="border-b-2 border-gray-100">
                            <td class="text-center">{{ $pedido->id }}</td>
                            <td class="text-center">{{ $pedido->pedido_status }}</td>
                            <td class="">{{ $pedido->cliente->cliente_nome }}</td>
                            <td class="">{{ $pedido->sessaoMesa->mesa->mesa_nome }}</td>
                            <td class="text-start">{{ $pedido->garcom->name_first }}</td>
                            <td class="text-start">
                                <span>{{ $pedido->opcaoEntrega->opcaoentrega_nome }}</span>
                            </td>
                            <td class="text-start">R$ {{ $pedido->pedido_valor_total }}</td>

                            @if ($pedido->pedido_status != 'CANCELADO')
                                <td class="inline-flex gap-x-2">
                                    <x-secondary-button
                                        onclick="window.open('{{ route('pedido.imprimir', ['id' => $pedido->id]) }}', 'Teste', 'width=600,height=400' );" title="IMPRIMIR"><i class='bx bx-printer' ></i></x-secondary-button>
                                    <form action="{{ route('pedido.cancelar', ['id' => $pedido->id]) }}"
                                        method="post">
                                        @csrf
                                        <x-danger-button title="CANCELAR"><i class='bx bx-trash' ></i></x-danger-button>
                                    </form>
                                    
                                </td>
                            @else
                                <td class="inline-flex gap-x-2">
                                    <x-secondary-button
                                        onclick="window.open('{{ route('pedido.imprimir', ['id' => $pedido->id]) }}', 'Teste', 'width=600,height=400' );" title="IMPRIMIR"><i class='bx bx-printer' ></i></x-secondary-button>
                                    <form action="{{ route('pedido.restaurar', ['id' => $pedido->id]) }}"
                                        method="post">
                                        @csrf
                                        <x-primary-button title="RESTAURAR"><i class='bx bx-check-circle' ></i></x-primary-button>
                                    </form>
                                </td>
                            @endif

                        </tr>
                    @endforeach
                @else
                    <tr>
                        <td colspan="3" class="text-center py-4">Nenhuma pedido encontrado.</td>
                    </tr>
                @endif
            </tbody>
        </table>
        <div class="py-4">
            {{ $pedidos->links() }}
        </div>
    </div>
</section>
