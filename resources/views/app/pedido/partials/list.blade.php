<section class="h-full">
    <div class="w-[18rem] sm:w-[99%] overflow-auto h-full">
        <form action="{{ route('pedidos') }}" method="GET">
            <x-text-input name="search" id="search" placeholder="Pesquisar Código"></x-text-input>
            <x-primary-button>Pesquisar</x-primary-button>
        </form>
        <table class="w-full text-[7px] md:text-base">
            <thead class="sticky top-0 text-start bg-white ">
                <tr class="border-b-4">
                    <th class="">#</th>
                    <th class="">STATUS</th>
                    <th class="text-start max-w-max">Cliente</th>
                    <th class="text-start">Mesa</th>
                    <th class="text-start">Garçom</th>
                    <th class="text-start">ENTREGA</th>
                    <th class="text-start">Valor total</th>
                    <th class="text-start max-w-max">Opções</th>
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
                                <td class="text-center inline-flex gap-x-2">
                                    <x-secondary-button
                                        onclick="window.open('{{ route('pedido.imprimir', ['id' => $pedido->id]) }}', 'Teste', 'width=600,height=400' );"
                                        title="IMPRIMIR"><i class='bx bx-printer'></i></x-secondary-button>
                                    <x-secondary-button title="MOVIMENTAÇÃO DO PEDIDO" x-data=""
                                        x-on:click.prevent="$dispatch('open-modal', 'mov-pedido-{{ $pedido->id }}')"><i
                                            class='bx bx-transfer'></i></x-secondary-button>
                                    <x-secondary-button title="ALTERAR DO PEDIDO" x-data=""
                                        x-on:click.prevent="$dispatch('open-modal', 'alterar-pedido-{{ $pedido->id }}')"><i
                                            class='bx bxs-edit-alt'></i></x-secondary-button>
                                    <form action="{{ route('pedido.cancelar', ['id' => $pedido->id]) }}"
                                        method="post">
                                        @csrf
                                        <x-danger-button title="CANCELAR"><i class='bx bx-trash'></i></x-danger-button>
                                    </form>
                                </td>
                            @else
                                <td class="text-center inline-flex gap-x-2">
                                    <x-secondary-button
                                        onclick="window.open('{{ route('pedido.imprimir', ['id' => $pedido->id]) }}', 'Teste', 'width=600,height=400' );"
                                        title="IMPRIMIR"><i class='bx bx-printer'></i></x-secondary-button>

                                    <x-secondary-button x-data=""
                                        x-on:click.prevent="$dispatch('open-modal', 'alterar-pedido-{{ $pedido->id }}')"
                                        title="ALTERAR DO PEDIDO"><i class='bx bx-transfer'></i></x-secondary-button>
                                    <x-secondary-button title="ALTERAR DO PEDIDO" x-data=""
                                        x-on:click.prevent="$dispatch('open-modal', 'alterar-pedido-{{ $pedido->id }}')"><i
                                            class='bx bxs-edit-alt'></i></x-secondary-button>
                                    <form action="{{ route('pedido.restaurar', ['id' => $pedido->id]) }}"
                                        method="post">
                                        @csrf
                                        <x-primary-button title="RESTAURAR"><i
                                                class='bx bx-check-circle'></i></x-primary-button>
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

        {{-- Modal de movimentação --}}
        @foreach ($pedidos as $pedido)
            <x-modal name="mov-pedido-{{ $pedido->id }}" :show="null" :maxWidth="'2xl'">
                <div class="p-6">

                    <h2 class="text-lg font-medium text-gray-900">
                        {{ __('Movimentação de pedido') }}
                    </h2>

                    <p class="mt-1 text-sm text-gray-600">
                        {{ __('Lista todas as movimentações do pedido selecionado!') }}
                    </p>
                    <table class="border-b-2 border-gray-100 w-full">
                        <thead class="border-b-4">
                            <th>PEDIDO</th>
                            <th>MESA ANTERIOR</th>
                            <th>MESA ATUAL</th>
                            <th>USUÁRIO</th>
                        </thead>
                        <tbody class="text-center">
                            @forelse ($pedido->mov_pedido as $mov)
                                <tr>
                                    <td>{{ $mov->mov_pedido_pedido_id }}</td>
                                    <td>{{ $mov->sessaoMesaAntiga->mesa->mesa_nome }}</td>
                                    <td>{{ $mov->sessaoMesaAtual->mesa->mesa_nome }}</td>
                                    <td>{{ $mov->usuario->name_first }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4">Nenhuma movimentação registrada</td>
                                </tr>
                            @endforelse

                        </tbody>
                    </table>
                </div>
            </x-modal>
        @endforeach

        {{-- Modal de alteração --}}
        @foreach ($pedidos as $pedido)
            <x-modal name="alterar-pedido-{{ $pedido->id }}" :show="null" :maxWidth="'2xl'">
                <div class="p-6">

                    <h2 class="text-lg font-medium text-gray-900">
                        {{ __('Alterar de pedido: ' . $pedido->id) }}
                    </h2>

                    {{-- <p class="mt-1 text-sm text-gray-600">
                        {{ __('Lista todas as movimentações do pedido selecionado!') }}
                    </p> --}}

                    <div
                        class="flex flex-nowrap flex-col xl:flex-row items-start justify-between space-y-2 md:space-y-0">
                        <form action="{{ route('pedido.update', ['pedido' => $pedido]) }}" method="post"
                            class="w-full mt-6">
                            @csrf
                            @method('patch')
                            <p class="flex items-center gap-x-2 text-sm font-bold text-teal-700">
                                <i class='bx bxs-map-pin'></i>
                                <span>{{ __('Entrega') }}</span>
                            </p>
                            <x-radio-input :opcoes="$opcoes_entregas" :id="$pedido->id" :selectedId="$pedido->pedido_opcaoentrega_id"
                                name="pedido_opcaoentrega_id" />

                            {{-- ENDERECO ENTREGA --}}
                            <div id="endereco_entrega_{{ $pedido->id }}" class="w-full">
                                <x-input-label for="pedido_endereco_entrega" :value="__('Endereço para Entrega')" />
                                <x-text-input id="pedido_endereco_entrega" name="pedido_endereco_entrega" type="text"
                                    value="{{ $pedido->pedido_endereco_entrega }}" class="mt-1 w-full"
                                    autocomplete="off" />
                            </div>

                            <div class="mt-6 flex items-end">
                                <x-primary-button>{{ __('Alterar pedido') }}</x-primary-button>
                            </div>
                        </form>
                    </div>
                </div>
            </x-modal>
        @endforeach
        <div class="py-4">
            {{ $pedidos->links() }}
        </div>
    </div>
</section>
