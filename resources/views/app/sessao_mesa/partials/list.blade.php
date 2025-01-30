<section class="h-full">
    <div class="w-[18rem] sm:w-[99%] overflow-auto mx-auto h-2/4 md:border-l md:pl-2">
        <table class="w-full text-center text-[7px] md:text-base">
            <thead class="">
                <tr class="border-b-4">
                    <th class="px-1 md:px-4 text-xs">#</th>
                    <th class="px-1 md:px-4 text-xs">Garçom</th>
                    <th class="px-1 md:px-4 text-xs">Cliente</th>
                    <th class="px-1 md:px-4 text-xs">Data/Hora Abertura</th>
                    <th class="px-1 md:px-4 text-xs">Data/Hora Fechamento</th>
                    <th class="px-1 md:px-4 text-xs">Status</th>
                    <th class="px-1 md:px-4 text-xs">Venda</th>
                    <th class="px-1 md:px-4 text-xs">Valor Total</th>
                    <th class="px-1 md:px-4 text-xs">Opções</th>
                </tr>
            </thead>
            <tbody>
                @if (count($sessaoMesas) > 0)
                    @foreach ($sessaoMesas as $sessao)
                        <tr class="border-b-2 border-gray-100">
                            <td>{{ $sessao->id }}</td>
                            <td class="uppercase">{{ $sessao->garcom->name_first }}</td>
                            <td class="uppercase">{{ $sessao->cliente->cliente_nome }}</td>
                            <td>{{ \Carbon\Carbon::parse($sessao->created_at)->format('d/m/y H:i') }}</td>
                            <td>{{ \Carbon\Carbon::parse($sessao->updated_at)->format('d/m/y H:i') }}</td>
                            <td>{{ $sessao->sessao_mesa_status }}</td>
                            <td>
                                @php
                                    $ultimoPedido = $sessao->pedidos->last(); // Obtém o último item da coleção
                                @endphp

                                @if ($ultimoPedido)
                                    {{ $ultimoPedido->pedido_venda_id }} <!-- Exibe o pedido_venda_id do último item -->
                                @endif
                            </td>

                            <td class="text-center">R$
                                {{ $sessao->pedidos ? str_replace('.', ',', $sessao->pedidos->sum('pedido_valor_total')) : '0,00' }}
                            </td>
                            <td>
                                @if ($sessao->sessao_mesa_status == 'FECHADA')
                                    <div class="flex">
                                        <form action="{{ route('sessaoMesa.reabrir', ['sessaoMesa' => $sessao]) }}"
                                            method="get">
                                            <x-primary-button title="REABRIR SESSÃO"><i class='bx bx-chevrons-left'></i></x-primary-button>
                                        </form>
                                        <x-secondary-button class="btn-imprimir"
                                            data-sessao_mesa_id="{{ $sessao->id }}" title="IMPRIMIR"><i class='bx bx-printer' ></i></x-secondary-button>
                                    </div>
                                @else
                                    <x-secondary-button class="btn-imprimir"
                                        data-sessao_mesa_id="{{ $sessao->id }}" title="IMPRIMIR"><i class='bx bx-printer' ></i></x-secondary-button>
                                @endif

                            </td>
                        </tr>
                    @endforeach
                @else
                    <tr>
                        <td colspan="3" class="text-center py-4">Nenhuma sessao encontrada.</td>
                    </tr>
                @endif
            </tbody>
        </table>
        <div class="py-4">
            {{ $sessaoMesas->links() }}
        </div>
    </div>
    <script type="module">
        $(document).ready(function() {
            $(".btn-imprimir").click(function(e) {
                e.preventDefault();
                var sessaoMesa_id = $(this).data('sessao_mesa_id');
                var url = '{{ route('sessaoMesa.imprimir', ['id' => 1]) }}';
                url = url.replace(/\/1\/Imprimir/, `/${sessaoMesa_id}/Imprimir`);
                window.open(url, 'Teste', 'width=600,height=400');
            });
        });
    </script>
</section>
