<section>
    <header>
        <div class="flex justify-between">
            <h2 class="text-lg font-medium text-gray-900">
                {{ __('Lista de Clientes') }}
            </h2>
        </div>
    </header>
    <div class="w-[18rem] sm:w-[99%] overflow-auto mx-auto h-2/4">
        <table class="w-full text-center text-[11px] md:text-base">
            <thead class="">
                <tr class="border-b-4">
                    <th class="">Cod.</th>
                    <th class="w-2/6 px-1 md:px-4">Cliente</th>
                    <th class=" px-1 md:px-4">Data Nasc.</th>
                    {{-- <th class="px-1 md:px-4">Opções</th> --}}
                </tr>
            </thead>
            <tbody>
                @if (count($clientes) > 0)
                    @foreach ($clientes as $cliente)
                        <tr class="border-b-2 border-gray-100">
                            <td>{{ $cliente->id }}</td>
                            <td class="flex items-center justify-start space-x-6 mx-auto">
                                @if ($cliente->cliente_foto)
                                    <img id="imagem-preview" class="border rounded-lg object-contain w-10 h-10"
                                        src="{{ asset('img/fotos_clientes/' . $cliente->cliente_foto) }}" />
                                @else
                                    <img id="imagem-preview" class="border rounded-lg object-contain w-10 h-10"
                                        src="{{ asset('Sem Imagem.png') }}" alt="Imagem Padrão">
                                @endif

                                <span>{{ $cliente->cliente_nome }}</span>
                            </td>
                            <td>{{ $cliente->cliente_data_nascimento->format('d/m/Y') }}</td>
                            {{-- <td>
                            <div class="flex items-center justify-center space-x-2">
                                 <x-primary-button onclick="window.location.href = '{{ route('mov_cliente.edit', ['mov_cliente' => $cliente]) }}'" title="Editar"><i class='bx bx-edit text-sm'></i></x-primary-button>
                                    <form action="{{ route('mov_cliente.destroy', ['id' => $cliente]) }}" method="post">
                                        @method('delete')
                                        @csrf
                                        <x-danger-button title="Excluir"><i class='bx bx-trash text-sm'></i></x-primary-button>
                                    </form> 
                            </div>
                        </td> --}}
                        </tr>
                    @endforeach
                @else
                    <tr>
                        <td colspan="3" class="text-center py-4">Nenhuma mov_cliente encontrada.</td>
                    </tr>
                @endif
            </tbody>
        </table>

    </div>
</section>
