<x-guest-layout>
    <div class="h-[100vh] w-full md:max-w-lg mx-auto">
        <div class="h-[20%] grid grid-cols-7">
            <div class="col-span-2">
                <img src="{{ asset('img/logo Pizzaria Branco Colorido.png') }}" alt="" class="h-16">
            </div>
            <div class="col-span-4 flex items-center">
                <a class="w-full"
                    href="https://wa.me/5564981453615">
                    <x-primary-button class="w-full flex items-center justify-center space-x-1"><i
                            class='bx bxl-whatsapp'></i><span>Realizar Pedido</span></x-primary-button>
                </a>
            </div>
            <div class="col-span-1 flex items-center justify-center">
                <button class="text-white text-3xl mt-2"><i class='bx bx-dots-vertical-rounded'></i></button>
            </div>
            <div class="col-span-full">
                <hr class="h-px my-1 border-0 bg-gray-400">
            </div>
            <div class="col-span-full flex gap-2 overflow-x-auto overflow-y-hidden p-1">
                @foreach ($categorias as $categoria)
                    <button
                        class="inline-flex items-center px-2 py-1 bg-gray-200 border border-gray-300 rounded-md font-semibold text-[10px] text-gray-700 uppercase tracking-widest shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 disabled:opacity-25 transition ease-in-out duration-150"
                        onclick="scrollToElement('categoria_{{ $categoria->id }}')">
                        {{ $categoria->categoria_nome }}
                    </button>
                @endforeach
            </div>
        </div>
        <div class="h-[80%] overflow-y-auto p-2">
            @foreach ($categorias as $categoria)
                <div class="mb-4" id="categoria_{{ $categoria->id }}">
                    <h2 class="text-lg text-white font-bold">{{ $categoria->categoria_nome }}</h2>
                    @if ($categoria->produtos->isEmpty())
                        <p class="text-gray-400">Não há produtos disponíveis nesta categoria.</p>
                    @else
                        <div class="grid grid-cols-1 gap-4 ">
                            @foreach ($categoria->produtos as $produto)
                                <div class="relative snap-end ">
                                    <a href="{{ route('produto.show', ['produto' => $produto]) }}">
                                        <div
                                            class="w-full p-2 rounded-lg flex items-start justify-between opacity-95 hover:opacity-100 gap-1">
                                            <div class="w-2/5">
                                                <img src="{{ $produto->getImagemUrl() }}"
                                                    alt="{{ $produto->produto_descricao }}"
                                                    class="w-32 h-28 object-cover rounded-lg bg-white">
                                            </div>
                                            <div class="w-full h-28 flex flex-col justify-center space-y-2">
                                                <h2 class="text-gray-100 text-lg uppercase">
                                                    @if (isset($produto->produto_referencia) && $produto->produto_referencia !== null)
                                                        {{ $produto->produto_descricao }} - <span>Ref.
                                                            {{ $produto->produto_referencia }}</span>
                                                    @else
                                                        {{ $produto->produto_descricao }}
                                                    @endif
                                                </h2>

                                                <div class="flex items-center">
                                                    <span class="text-gray-200 text-xs ">Codimentos:
                                                        {{ $produto->produto_codimentacao }}</span>
                                                </div>

                                                <div class="flex items-center">
                                                    <span
                                                        class="text-white text-lg font-bold">R${{ str_replace('.', ',', $produto->produto_preco_venda) }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </a>
                                    <hr class="h-px my-1 border-0 bg-gray-600">
                                    {{-- <div class="absolute -bottom-2 right-5 inline-flex gap-2">
                                        <x-secondary-button
                                            onclick="window.location.href = '{{ route('produto.edit', ['produto' => $produto]) }}'">Editar</x-secondary-button>
                                        <form action="{{ route('produto.destroy', ['id' => $produto]) }}"
                                            method="post">
                                            @method('delete')
                                            @csrf
                                            <x-danger-button>Excluir</x-danger-button>
                                        </form>
                                    </div> --}}
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
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
</x-guest-layout>
