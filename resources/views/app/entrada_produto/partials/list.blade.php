<section>
    <header>
        <div class="flex justify-between">
            <h2 class="text-lg font-medium text-gray-900">
                {{ __('Lista de Entradas de Produtos') }}
            </h2>
        </div>
    </header>
    <div class="w-[18rem] sm:w-[99%] overflow-auto mx-auto h-2/4">
        <table class="w-full text-center text-[11px] md:text-base">
            <thead class="">
                <tr class="border-b-4">
                    <th class="">Cod. Entrada</th>
                    <th class="w-2/6 px-1 md:px-4">Produto</th>
                    <th class="px-1 md:px-4">Qtd</th>
                    <th class="w-1/4 px-1 md:px-4">Usuário</th>
                    <th class=" px-1 md:px-4">Data da Entrada</th>
                    {{-- <th class="px-1 md:px-4">Opções</th> --}}
                </tr>
            </thead>
            <tbody>
                @if (count($mov_produtos) > 0)
                    @foreach ($mov_produtos as $mov_produto)
                        <tr class="border-b-2 border-gray-100">
                            <td>{{ $mov_produto->id }}</td>
                            <td class="flex items-center justify-start space-x-6 mx-auto">
                                @if ($mov_produto->produto->produto_foto)
                                    <img id="imagem-preview" class="border rounded-lg object-contain w-10 h-10"
                                        src="{{ asset('img/fotos_produtos/' . $mov_produto->produto->produto_foto) }}" />
                                @else
                                    <img id="imagem-preview" class="border rounded-lg object-contain w-10 h-10"
                                        src="{{ asset('Sem Imagem.png') }}" alt="Imagem Padrão">
                                @endif
                                <span>
                                    @if (isset($mov_produto->produto->produto_referencia) && $mov_produto->produto->produto_referencia !== null)
                                        {{ $mov_produto->produto->produto_descricao }} -
                                        <span>Ref.
                                            {{ $mov_produto->produto->produto_referencia }}</span>
                                    @else
                                        {{ $mov_produto->produto->produto_descricao }}
                                    @endif
                                </span>
                            </td>
                            @if ($mov_produto->mov_quantidade == intval($mov_produto->mov_quantidade))
                                <td>{{ intval($mov_produto->mov_quantidade) }}</td>
                                <!-- Se for um número inteiro -->
                            @else
                                <td>{{ number_format($mov_produto->mov_quantidade, 2, ',', '.') }}</td>
                                <!-- Se for um número decimal -->
                            @endif
                            <td>{{ $mov_produto->user->name }}</td>
                            <td>{{ $mov_produto->created_at->format('d/m/Y') }}</td>
                            {{-- <td>
                            <div class="flex items-center justify-center space-x-2">
                                 <x-primary-button onclick="window.location.href = '{{ route('mov_produto.edit', ['mov_produto' => $mov_produto]) }}'" title="Editar"><i class='bx bx-edit text-sm'></i></x-primary-button>
                                    <form action="{{ route('mov_produto.destroy', ['id' => $mov_produto]) }}" method="post">
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
                        <td colspan="3" class="text-center py-4">Nenhuma mov_produto encontrada.</td>
                    </tr>
                @endif
            </tbody>
        </table>

    </div>
</section>
