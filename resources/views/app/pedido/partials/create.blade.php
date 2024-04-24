<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('Novo Pedido') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            {{ __('Insira os dados para um novo pedido.') }}
        </p>
    </header>

    <form action="{{ route('pedido.store') }}" method="post" class="space-y-6 mt-2" enctype="multipart/form-data">

        <div class="col-span-full grid grid-cols-1 md:grid-cols-8 gap-x-4 gap-y-1">
            {{-- PRODUTOS --}}
            <div class="sm:col-span-4 lg:col-span-3 col-span-6 md:space-y-2 ">
                <p class="flex items-center gap-x-2 text-sm font-bold text-teal-700">
                    <i class='bx bxs-map-pin'></i>
                    <span>{{ __('Produtos') }}</span>
                </p>
                <div class="flex flex-col mb-4">
                    <x-text-input id="buscar" class="" placeholder="Buscar Produtos"></x-text-input>
                    <div class="flex gap-2 overflow-auto p-1">
                        @foreach ($categorias as $categoria)
                            <button type="button"
                                class="inline-flex items-center bg-gray-200 border p-1 border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 disabled:opacity-25 transition ease-in-out duration-150"
                                onclick="scrollToElement('categoria_{{ $categoria->id }}')">
                                {{ $categoria->categoria_nome }}
                            </button>
                        @endforeach
                    </div>
                </div>
                <div class="overflow-auto h-[20rem] sm:h-[18rem] md:h-[25rem] snap-y">
                    @foreach ($categorias as $categoria)
                        <div class="mb-4" id="categoria_{{ $categoria->id }}">
                            <h2 class="text-white text-lg font-bold">{{ $categoria->categoria_nome }}</h2>
                            @if ($categoria->produtos->isEmpty())
                                <p class="text-gray-400">Não há produtos disponíveis nesta categoria.</p>
                            @else
                                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-2 lg:grid-cols-3 gap-4 ">
                                    @foreach ($categoria->produtos as $produto)
                                        <div class="relative snap-end ">
                                            <a href="{{ route('produto.edit', ['produto' => $produto]) }}">
                                                <div
                                                    class="w-full flex flex-row bg-gray-100 p-2 rounded-lg md:flex-col opacity-95 hover:opacity-100 gap-1">
                                                    <div class="w-1/2 md:w-full">
                                                        @if ($produto->produto_foto)
                                                            <img src="{{ asset('img/fotos_produtos/' . $produto->produto_foto) }}"
                                                                alt="{{ $produto->produtso_descricao }}"
                                                                class="md:w-full md:h-12 object-cover rounded-lg ">
                                                        @else
                                                            <img id="imagem-preview"
                                                                class="w-40 h-28 object-cover rounded-lg "
                                                                src="{{ asset('Sem Imagem.png') }}"
                                                                alt="Imagem Padrão">
                                                        @endif
                                                    </div>
                                                    <div class="flex h-16 flex-col justify-between">
                                                        <p class="text-gray-900 font-bold text-sm md:text-xs uppercase">
                                                            {{ $produto->produto_descricao }}
                                                        </p>
                                                            <span class="text-green-500 text-xl">
                                                                R${{ str_replace('.', ',', $produto->produto_preco_venda) }}
                                                            </span>

                                                    </div>
                                                </div>
                                            </a>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
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
            </div>

            <div
                class="sm:col-span-4 lg:col-span-3 col-span-6 relative md:space-y-2 md:border-x md:px-3 border-t pt-1 md:pt-0 pb-1 md:pb-0 md:border-t-0 border-b md:border-b-0">
                {{-- CLIENTE ID --}}
                <p class="flex items-center gap-x-2 text-sm font-bold text-teal-700">
                    <i class='bx bx-user'></i>
                    <span>{{ __('Cliente') }}</span>
                </p>
                <x-text-input id="pedido_cliente_id" name="pedido_cliente_id" type="text" class="mt-1 w-full"
                    autocomplete="off" hidden />
                <x-text-input id="pedido_cliente_nome" name="pedido_cliente_nome" type="text" class="mt-1 w-full"
                    autocomplete="off" placeholder="Nome do Cliente" />
                <x-input-error :messages="$errors->updatePassword->get('pedido_cliente_id')" class="mt-2" />
                <div id="lista_clientes"
                    class="absolute w-full bg-white rounded-lg px-2 py-3 shadow-lg shadow-green-400/10 hidden overflow-auto max-h-96 md:max-h-80 lg:max-h-72 border">
                    @foreach ($clientes as $cliente)
                        <div id="linha_cliente"
                            class="border-b-2 hover:bg-teal-700 hover:text-white rounded-lg p-2 cursor-pointer transition duration-150 ease-in-out"
                            onclick="selecionarCliente({{ $cliente->id }},'{{ $cliente->cliente_nome }}')">
                            {{ $cliente->id }} - {{ $cliente->cliente_nome }}
                        </div>
                    @endforeach
                </div>
                {{-- TIPO DE ENTREGA --}}
                <hr class="h-px my-1 border-0 bg-gray-200">
                <p class="flex items-center gap-x-2 text-sm font-bold text-teal-700">
                    <i class='bx bxs-map-pin'></i>
                    <span>{{ __('Entrega') }}</span>
                </p>
                <div class="flex flex-nowrap flex-col xl:flex-row items-start justify-between space-y-2 md:space-y-0">

                    @foreach ($opcoes_entregas as $opcao_entrega)
                        <label for="opcao_entrega_{{ $opcao_entrega->id }}" class="flex items-center cursor-pointer">
                            <input type="radio" id="opcao_entrega_{{ $opcao_entrega->id }}" name="tipo_entrega"
                                value="{{ $opcao_entrega->id }}"
                                class="form-radio text-green-500 h-5 w-5 cursor-pointer">
                            <span class="ml-2 text-gray-700">{{ $opcao_entrega->opcaoentrega_nome }}</span>
                        </label>
                    @endforeach
                </div>
                {{-- ENDERECO ENTREGA --}}
                <div class="md:col-span-3 col-span-1" id="endereco_entrega">
                    <x-input-label for="pedido_endereco_entrega" :value="__('Endereço para Entrega')" />
                    <x-text-input id="pedido_endereco_entrega" name="pedido_endereco_entrega" type="text"
                        class="mt-1 w-full" autocomplete="off" />
                </div>
                {{-- TIPO DE pagamento --}}
                <hr class="h-px my-1 border-0 bg-gray-200">
                <p class="flex items-center gap-x-2 text-sm font-bold text-teal-700">
                    <i class="bx bxs-credit-card"></i>
                    <span>{{ __('Pagamento') }}</span>
                </p>
                <div class="grid grid-cols-1 xl:grid-cols-3 space-y-1" id="tipo_pagamento">
                    @foreach ($opcoes_pagamento as $opcao_pagamento)
                        <label for="opcao_pag_{{ $opcao_pagamento->id }}"
                            class="col-span-1 flex items-center cursor-pointer">
                            <input type="checkbox" id="opcao_pag_{{ $opcao_pagamento->id }}" name="tipo_pagamento"
                                value="{{ $opcao_pagamento->id }}"
                                class="form-checkbox text-green-500 h-5 w-5 cursor-pointer">
                            <span class="ml-2 text-gray-700">{{ $opcao_pagamento->opcaopag_nome }}</span>
                        </label>
                    @endforeach
                </div>
                {{-- Observações do pagamento --}}
                <x-input-label for="pedido_observacao_pagamento" :value="__('Observações no Pagamento')" />
                <x-text-input id="pedido_observacao_pagamento" name="pedido_observacao_pagamento" type="text"
                    class="mt-1 w-full" autocomplete="off" />
                <x-text-input id="pedido_descricao_pagamento" name="pedido_descricao_pagamento" type="text"
                    class="mt-1 w-full" autocomplete="off" hidden />
            </div>

            <div class="sm:col-span-8 lg:col-span-2 col-span-6 bg-slate-100 border">
                <div class="bg-white p-1">
                    <p>Itens do Pedido</p>
                </div>
            </div>
        </div>
        @csrf
    </form>
    <script>
        function selecionarCliente(id, nome) {
            document.getElementById("pedido_cliente_id").value = id;
            document.getElementById("pedido_cliente_nome").value = nome;
            console.log(id + ' - ' + nome);
        }
        document.addEventListener('DOMContentLoaded', function() {


            const inputCliente = document.getElementById('pedido_cliente_nome');
            const listaClientes = document.getElementById('lista_clientes');

            // Mostrar a lista de clientes quando o campo de texto estiver focado
            inputCliente.addEventListener('focus', function() {
                listaClientes.classList.remove('hidden');
            });

            // Ocultar a lista de clientes quando o campo de texto perder o foco
            inputCliente.addEventListener('blur', function() {
                setTimeout(() => {
                    listaClientes.classList.add('hidden');
                }, 200);

            });

            // Filtrar a lista de clientes conforme o usuário digita
            inputCliente.addEventListener('input', function() {
                const textoDigitado = inputCliente.value.toLowerCase();
                const itemsClientes = listaClientes.querySelectorAll('div');

                itemsClientes.forEach(function(itemCliente) {
                    const nomeCliente = itemCliente.textContent.toLowerCase();
                    if (nomeCliente.includes(textoDigitado)) {
                        itemCliente.style.display = 'block';
                    } else {
                        itemCliente.style.display = 'none';
                    }
                });
            });
        });
    </script>

    <script type="module">
        $(document).ready(function() {
            $('#opcao_entrega_1').attr('checked', true);
            $('#iniciar-pedido').click(function() {
                // Fazer uma requisição AJAX para iniciar o pedido
                $.ajax({
                    url: "{{ route('pedido.iniciar') }}",
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        '_token': '{{ csrf_token() }}'
                    },
                    success: function(response) {
                        // Lidar com a resposta
                        if (response && response.pedido_id) {
                            alert('Pedido iniciado com sucesso! ID do pedido: ' + response
                                .pedido_id);
                        } else {
                            alert('Erro ao iniciar o pedido. Por favor, tente novamente 1.');
                        }
                    },
                    error: function() {
                        alert('Erro ao iniciar o pedido. Por favor, tente novamente 2.');
                    }
                });
            });
            $('#endereco_entrega').hide();
            $('input[name="tipo_entrega"]').change(function() {
                // Verifica se o valor do input selecionado é "Entregar"
                if ($(this).val() == 3) {
                    $('#endereco_entrega').slideDown(); // Mostra o elemento com ID "endereco_entrega"
                } else {
                    $('#endereco_entrega').slideUp(); // Esconde o elemento com ID "endereco_entrega"
                }
            });
            // Quando qualquer checkbox é alterado
            $('.grid input[type="checkbox"]').change(function() {
                // Inicializa uma string para armazenar as descrições marcadas
                let descricoesMarcadas = '';

                // Itera sobre cada checkbox
                $('.grid input[type="checkbox"]').each(function() {
                    // Se o checkbox estiver marcado, adiciona a descrição ao string
                    if ($(this).prop('checked')) {
                        descricoesMarcadas += $(this).next('span').text().trim() + ', ';
                    }
                });

                // Remove a vírgula extra no final da string
                descricoesMarcadas = descricoesMarcadas.slice(0, -2);

                // Adiciona a string ao input com o id "descricoes_marcadas"
                $('#pedido_descricao_pagamento').val(descricoesMarcadas);
            });
        });
    </script>


</section>
