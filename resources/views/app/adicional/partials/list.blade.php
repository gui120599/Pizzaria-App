<section>
    <header>
        <div class="flex justify-between">
            <h2 class="text-lg font-medium text-gray-900">
                {{ __('Lista de adicionais') }}
            </h2>
            <x-secondary-button onclick="window.location.href = '{{ route('adicional.inactive') }}'">Mostrar Inativos</x-secondary-button>
        </div>
    </header>
    
    <div class="w-[18rem] sm:w-[99%] overflow-auto mx-auto h-2/4">
        <table class="w-full text-[7px] md:text-base">
            <thead class="">
                <tr class="border-b-4">
                    <th></th>
                    <th class="text-start">#</th>
                    <th class="text-start">Nome</th>
                    <th class="text-start">Valor</th>
                    <th class="text-start">Opções</th>
                </tr>
            </thead>
            <tbody>
                @if(count($adicionais) > 0)
                    @foreach ($adicionais as $adicional)
                        <tr class="border-b-2 border-gray-100">
                            <td>
                                @if ($adicional->adicional_foto)
                                    <img src="{{ asset('img/fotos_adicionais/' . $adicional->adicional_foto) }}"
                                        alt="{{ $adicional->adicional_nome }}"
                                        class="w-10 h-10 object-cover rounded-lg ">
                                @else
                                    <img id="imagem-preview" class="w-10 h-10 object-cover rounded-lg "
                                        src="{{ asset('Sem Imagem.png') }}" alt="Imagem Padrão">
                                @endif
                            </td>
                            <td>{{ $adicional->id }}</td>
                            <td>{{ $adicional->adicional_nome }}</td>
                            <td>R${{ number_format($adicional->adicional_valor, 2,',','.') }}</td>
                            <td>
                                <div class="flex items-center justify-center space-x-2">
                                    <x-primary-link title="EDITAR" href="{{ route('adicional.edit', ['adicional' => $adicional]) }}"><i class='bx bx-edit text-sm'></i></x-primary-link>
                                    
                                    <form action="{{ route('adicional.destroy', ['id' => $adicional]) }}" method="post">
                                        @method('delete')
                                        @csrf
                                        <x-danger-button title="Excluir"><i class='bx bx-trash text-sm'></i></x-primary-button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                @else
                    <tr>
                        <td colspan="3" class="text-center py-4">Nenhum adicional encontrado.</td>
                    </tr>
                @endif
            </tbody>
        </table>
    </div>
</section>
