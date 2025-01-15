<section class="h-full">

    <div class="h-[15%] 2xl:h-[10%]">
        <header>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                {{ __('Todos produtos cadastrados!') }}
            </p>
        </header>
        <div class="flex flex-col mb-4">
            {{-- <x-text-input-buscar id="buscar" class="mt-3 mb-3 w-1/2" placeholder="Buscar"></x-text-input-buscar> --}}
            <div class="flex gap-2 overflow-auto p-1">
                @foreach ($categorias as $categoria)
                    <button
                        class="inline-flex items-center px-2 py-1 bg-gray-200 border border-gray-300 rounded-md font-semibold text-[10px] text-gray-700 uppercase tracking-widest shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 disabled:opacity-25 transition ease-in-out duration-150"
                        onclick="scrollToElement('categoria_{{ $categoria->id }}')">
                        {{ $categoria->categoria_nome }}
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    {{-- <div class="h-[85%] overflow-y-auto">
        @foreach ($categorias as $categoria)
            <div class="mb-4" id="categoria_{{ $categoria->id }}">
                <h2 class="text-lg font-bold">{{ $categoria->categoria_nome }}</h2>
                @if ($categoria->produtos->isEmpty())
                    <p class="text-gray-400">Não há produtos disponíveis nesta categoria.</p>
                @else
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-2 lg:grid-cols-3 gap-4 ">
                        @foreach ($categoria->produtos as $produto)
                            <div class="relative snap-end ">
                                <a href="{{ route('produto.edit', ['produto' => $produto]) }}">
                                    <div
                                        class="w-full bg-gray-100 p-2 rounded-lg flex items-start justify-between opacity-95 hover:opacity-100 gap-1">
                                        <div class="w-2/5">
                                            @if ($produto->produto_foto)
                                                <img src="{{ asset('img/fotos_produtos/' . $produto->produto_foto) }}"
                                                    alt="{{ $produto->produtso_descricao }}"
                                                    class="w-40 h-28 object-cover rounded-lg ">
                                            @else
                                                <img id="imagem-preview" class="w-40 h-28 object-cover rounded-lg "
                                                    src="{{ asset('Sem Imagem.png') }}" alt="Imagem Padrão">
                                            @endif
                                        </div>
                                        <div class="w-3/5 h-28 flex flex-col justify-center">
                                            <h2 class="text-gray-900 text-sm uppercase">
                                                @if (isset($produto->produto_referencia) && $produto->produto_referencia !== null)
                                                    {{ $produto->categoria->categoria_nome }}
                                                    {{ $produto->produto_descricao }} - <span>Ref.
                                                        {{ $produto->produto_referencia }}</span>
                                                @else
                                                    {{ $produto->categoria->categoria_nome }}
                                                    {{ $produto->produto_descricao }}
                                                @endif
                                            </h2>

                                            <div class="flex items-center">
                                                <span class="text-gray-900 text-xs">NCM: </span>
                                                <span
                                                    class="text-gray-900 text-xs font-bold">{{ $produto->produto_codigo_NCM }}</span>
                                            </div>
                                            <div class="flex items-center">
                                                <span class="text-gray-900 text-xs">CFOP: </span>
                                                <span
                                                    class="text-gray-900 text-xs font-bold">{{ $produto->produto_CFOP }}</span>
                                            </div>
                                            <div class="flex items-center">
                                                <span class="text-gray-900 text-xs">CSOSN: </span>
                                                <span
                                                    class="text-gray-900 text-xs font-bold">{{ $produto->produto_CSOSN }}</span>
                                            </div>
                                            <div class="flex items-center">
                                                <span
                                                    class="text-gray-900 text-lg font-bold">R${{ str_replace('.', ',', $produto->produto_preco_venda) }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </a>
                                <div class="absolute -bottom-2 right-5 inline-flex gap-2">
                                    <x-secondary-button
                                        onclick="window.location.href = '{{ route('produto.edit', ['produto' => $produto]) }}'">Editar</x-secondary-button>
                                    <form action="{{ route('produto.destroy', ['id' => $produto]) }}" method="post">
                                        @method('delete')
                                        @csrf
                                        <x-danger-button>Excluir</x-danger-button>
                                    </form>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach
    </div> --}}

    <div class="h-[85%] 2xl:h-[90%] overflow-auto">
        <table class="w-full text-[7px] md:text-base">
            <thead class="sticky top-0 text-start bg-white p-4 border-b m-1">
                <th></th>
                <th class="text-start">#</th>
                <th class="text-start">Descrição</th>
                <th class="text-start">NCM</th>
                <th class="text-start">CFOP</th>
                <th class="text-start">CSOSN</th>
                <th class="text-start">Valor Venda</th>
                <th class="text-start">Opções</th>
            </thead>
            <tbody>
                @foreach ($categorias as $categoria)
                    <tr id="categoria_{{ $categoria->id }}">
                        <td colspan="9">
                            <h2>{{ $categoria->categoria_nome }}</h2>
                        </td>
                    </tr>
                    @foreach ($categoria->produtos as $produto)
                        <tr class="border-b">
                            <td>
                                @if ($produto->produto_foto)
                                    <img src="{{ asset('img/fotos_produtos/' . $produto->produto_foto) }}"
                                        alt="{{ $produto->produtso_descricao }}"
                                        class="w-10 h-10 object-cover rounded-lg ">
                                @else
                                    <img id="imagem-preview" class="w-10 h-10 object-cover rounded-lg "
                                        src="{{ asset('Sem Imagem.png') }}" alt="Imagem Padrão">
                                @endif
                            </td>
                            <td>{{ $produto->id }}</td>
                            <td>{{ $produto->categoria->categoria_nome }} {{ $produto->produto_descricao }}</td>
                            <td>{{ $produto->produto_codigo_NCM }}</td>
                            <td>{{ $produto->produto_CFOP }}</td>
                            <td>{{ $produto->produto_CSOSN }}</td>
                            <td>R$ {{ number_format($produto->produto_preco_venda, 2, ',', '.') }}</td>
                            <td class="inline-flex gap-x-2">
                                <x-primary-link title="EDITAR" href="{{ route('produto.edit', ['produto' => $produto]) }}">
                                    <i class='bx bxs-edit'></i>
                                </x-primary-link>

                                <form action="{{ route('produto.destroy', ['id' => $produto]) }}" method="post">
                                    @method('delete')
                                    @csrf
                                    <x-danger-button title="EXCLUIR"><i class='bx bx-trash'></i></x-danger-button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>


    <script>
        function scrollToElement(elementId) {
            var element = document.getElementById(elementId);

            if (element) {
                element.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start',
                });
            }
        }
    </script>
</section>
