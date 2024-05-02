<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('Novo Pedido') }} - <span id="pedido_id_titulo"></span>
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            {{ __('Insira os dados para um novo pedido.') }}
        </p>
    </header>

    <form action="{{ route('pedido.store') }}" method="post" class="space-y-6 mt-2" enctype="multipart/form-data">

        <div class="col-span-full grid grid-cols-1 md:grid-cols-8 gap-x-4 gap-y-1">
            <x-text-input name="pedido_id" id="pedido_id" hidden></x-text-input>
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
                                            <div class="produto cursor-pointer hover:shadow-lg"
                                                data-produto_id="{{ $produto->id }}"
                                                data-produto_valor="{{ $produto->produto_preco_venda }}">
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
                                            </div>
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

                {{-- Valores --}}
                <hr class="h-px my-1 border-0 bg-gray-200">
                <p class="flex items-center gap-x-2 text-sm font-bold text-teal-700">
                    <i class='bx bx-dollar-circle' ></i>
                    <span>{{ __('Valores') }}</span>
                </p>
                <div class="grid grid-cols-1 lg:grid-cols-3 space-x-1">
                    <div class="col-span-1">
                        <x-input-label for="pedido_valor_itens" :value="__('Itens R$')" />
                    <x-text-input id="pedido_valor_itens" name="pedido_valor_itens" type="text"
                        class="mt-1 w-full" autocomplete="off" value="0.00" readonly/>
                    </div>
                    <div class="col-span-1">
                        <x-input-label for="pedido_valor_desconto" :value="__('Desconto R$')" />
                    <x-text-input id="pedido_valor_desconto" name="pedido_valor_desconto" type="text"
                        class="mt-1 w-full" autocomplete="off" value="0.00" />
                    </div>
                    <div class="col-span-1">
                        <x-input-label for="pedido_valor_total" :value="__('Total R$')" />
                    <x-text-input id="pedido_valor_total" name="pedido_valor_total" type="text"
                        class="mt-1 w-full" autocomplete="off" value="0.00" readonly/>
                    </div>
                </div>
            </div>

            <div class="sm:col-span-8 lg:col-span-2 col-span-6 bg-slate-100 border">
                <div class="bg-white p-1">
                    <p>Itens do Pedido</p>
                </div>
                <div id="itens_pedido_container" class="h-[35rem] overflow-auto">
                </div>
            </div>
        </div>
        @csrf
        <x-primary-button>
            {{ __('Finalizar Pedido') }}
        </x-primary-button>
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
            //$(".toggleSideBar").trigger("click");
            IniciarPedido();

            $('#opcao_entrega_1').attr('checked', true);
            $('#endereco_entrega').hide();


            // Verifica se o valor do input selecionado é "Entregar"
            $('input[name="tipo_entrega"]').change(function() {
                if ($(this).val() == 3) {
                    $('#endereco_entrega').slideDown(); // Mostra o elemento com ID "endereco_entrega"
                } else {
                    $('#endereco_entrega').slideUp(); // Esconde o elemento com ID "endereco_entrega"
                }
            });


            // Quando qualquer checkbox tipo pagamento é alterado
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


            //Adiciona o produto no pedido
            $(".produto").click(function(e) {
                e.preventDefault();
                const item_pedido_produto_id = $(this).data('produto_id');
                const item_pedido_pedido_id = $("#pedido_id").val();
                const item_pedido_quantidade = 1;
                const item_pedido_valor = $(this).data('produto_valor');
                const item_pedido_status = 'INSERIDO';
                $.ajax({
                    type: "POST",
                    url: "{{ route('item_pedido.store') }}",
                    data: {
                        item_pedido_produto_id,
                        item_pedido_pedido_id,
                        item_pedido_quantidade,
                        item_pedido_valor,
                        '_token': '{{ csrf_token() }}'
                    },
                    dataType: "json",
                    success: function(response) {
                        // Lidar com a resposta
                        if (response) {
                            console.log(response);
                            ListarItenPedido();
                        } else {
                            alert('Erro ao iniciar o pedido. Por favor, tente novamente 1.');
                        }

                    },
                    error: function() {
                        alert(
                            'Erro ao adicionar produto ao pedido. Por favor, tente novamente .'
                        );
                    }
                });
            });


        });

        function IniciarPedido() {
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
                        $("#pedido_id").val(response.pedido_id);
                        $("#pedido_id_titulo").text("Nº: " + response.pedido_id);
                        ListarItenPedido();
                    } else {
                        alert('Erro ao iniciar o pedido. Por favor, tente novamente 1.');
                    }
                },
                error: function() {
                    alert('Erro ao iniciar o pedido. Por favor, tente novamente 2.');
                }
            });
        }

        //Lista Itens do Pedido
        function ListarItenPedido() {
            const item_pedido_pedido_id = $("#pedido_id").val();
            $.ajax({
                type: "GET",
                url: "{{ route('itens_pedido.lista') }}",
                data: {
                    item_pedido_pedido_id,
                    '_token': '{{ csrf_token() }}'
                },
                dataType: "JSON",
                success: function(response) {
                    console.log(response);

                    // Limpe o conteúdo atual antes de adicionar os novos itens
                    $('#itens_pedido_container').empty();

                    // Verifique se há itens de pedido encontrados na resposta
                    if (response.length > 0) {
                        // Itere sobre cada item retornado na resposta
                        $.each(response, function(index, item) {
                            // Crie o HTML para o item de pedido e o produto associado
                            var itemHtml = `
                                <div class="border-y px-2 py-1 cursor-pointer hover:bg-gray-200">
                                    <div class="grid grid-cols-6 items-center">
                                        <span id="remove_item" class="col-span-6 cursor-pointer flex justify-end" data-item_id="${item.id}">
                                            <i class='bx bxs-x-circle text-xl hover:text-red-600 transition ease-in-out duration-300'></i>
                                        </span>
                                        <div class="col-span-6 flex flex-row items-start space-x-2">
                                            <img id="imagem-preview" class="w-8 h-8 object-cover rounded-lg" src="/img/fotos_produtos/${item.produto.produto_foto}" alt="Imagem Padrão">
                                            <span id="produto_nome_${item.id}" class="truncate overflow-ellipsis text-sm">${item.produto.produto_descricao}<p>R$ <span id="item_valor_view_${item.id}">${item.produto.produto_preco_venda}</span> Qtd. <span id="item_qtd_view_${item.id}">${item.item_pedido_quantidade}</span></p></span>
                                        </div>
                                        <span data-item_id="${item.id}" class="col-span-6 mx-auto toogle_item p-1 hover:bg-slate-400 cursor-pointer rotate-180 rounded-full transition duration-300 ease-in-out ">
                                            <i class="bx bx-chevron-up "></i>
                                        </span>
                                    </div>
                                    <div id="item_pedido_${item.id}" class="px-5 pb-2 hidden bg-white">
                                        <x-input-label for="item_pedido_quantidade" :value="__('Quantidade')" />
                                        <div class="flex items-stretch justify-evenly">
                                            <button type="button" id="minus-btn"
                                                class="minus-btn w-full px-3 py-1 bg-gray-200 border border-gray-300 rounded-l-md hover:text-xl hover:font-semibold hover:bg-gray-300 focus:outline-none"
                                                data-item_id="${item.id}" data-produto_preco_venda="${item.produto.produto_preco_venda}">-</button>
                                            <input type="text" id="item_pedido_quantidade_${item.id}" name="item_pedido_quantidade"
                                                value="1"
                                                class="w-20 text-center border border-gray-300 rounded-none focus:outline-none focus:ring-1 focus:ring-gray-400"
                                                readonly>
                                            <button type="button" id="plus-btn"
                                                class="plus-btn w-full px-3 py-1 bg-gray-200 border border-gray-300 rounded-r-md hover:text-xl hover:font-semibold hover:bg-gray-300 focus:outline-none"
                                                data-item_id="${item.id}" data-produto_preco_venda="${item.produto.produto_preco_venda}">+</button>
                                        </div>
                                        <x-input-label for="item_pedido_observacao" :value="__('Observação')" />`;
                            if (item.item_pedido_observacao === null) {
                                itemHtml +=
                                    `<textarea class="item_pedido_observacao border-gray-300 focus:border-black focus:ring-black rounded-md shadow-sm mt-1 w-full" rows="5" id="item_pedido_observacao" name="item_pedido_observacao" autocomplete="off" data-item_id="${item.id}"></textarea>`;
                            } else {
                                itemHtml +=
                                    `<textarea class="item_pedido_observacao border-gray-300 focus:border-black focus:ring-black rounded-md shadow-sm mt-1 w-full" rows="5" id="item_pedido_observacao" name="item_pedido_observacao" autocomplete="off" data-item_id="${item.id}">${item.item_pedido_observacao}</textarea>`;
                            }
                            itemHtml += `
                                        <x-input-label for="item_pedido_valor" :value="__('Valor R$')" />
                                        <x-text-input id="item_pedido_valor_${item.id}" name="item_pedido_valor" type="text"
                                            class="mt-1 w-full" value="${item.produto.produto_preco_venda}" autocomplete="off" readonly />
                                    </div>
                                </div>
                            `;

                            // Adicione o HTML do item de pedido ao container
                            $('#itens_pedido_container').append(itemHtml);
                        });
                    } else {
                        // Se não houver itens de pedido inseridos, exiba uma mensagem indicando isso
                        $('#itens_pedido_container').html(
                            '<p>Nenhum produto inserido encontrado para este pedido</p>');
                    }

                    //Abre o form do item do pedido
                    $(".toogle_item").click(function(e) {
                        e.preventDefault();
                        console.log('foi');
                        const item_id = $(this).data('item_id');
                        const itemPedido = $("#item_pedido_" + item_id);
                        const produtoNome = $("#produto_nome_" + item_id);

                        // Verifica se o item já está visível
                        if (itemPedido.is(":visible")) {
                            // Se estiver visível, contrai o elemento com slideup
                            itemPedido.slideUp();
                            $(this).addClass('rotate-180');
                            produtoNome.removeClass("overflow-ellipsis");
                        } else {
                            // Se não estiver visível, expande o elemento com slidedown
                            itemPedido.slideDown();
                            $(this).removeClass('rotate-180');
                            produtoNome.addClass("overflow-ellipsis");
                        }
                    });

                    //Altera a quantidade e valor do produto
                    $(".minus-btn").click(function(e) {
                        e.preventDefault();

                        const id = $(this).data('item_id');
                        const produto_preco_venda = $(this).data('produto_preco_venda');
                        // Obtém o elemento de entrada de quantidade
                        var item_pedido_quantidade = $("#item_pedido_quantidade_" + id).val();

                        // Obtém o valor atual e converte para um número
                        var currentValue = parseFloat(item_pedido_quantidade);
                        // Verifica se o valor atual é 1 ou 0.5
                        if (currentValue === 1 || currentValue === 0.5) {
                            // Se for 1 ou 0.5, define o valor como 0.5
                            currentValue = 0.5;
                        } else {
                            // Se não for 1 ou 0.5, decrementa em 1
                            currentValue -= 1;
                        }
                        // Define o novo valor do campo de entrada, convertendo para string
                        $("#item_pedido_quantidade_" + id).val(currentValue.toString());
                        item_pedido_quantidade = currentValue;
                        // Atualiza a quantidade da vizualização
                        if (item_pedido_quantidade === 0.5) {
                            $("#item_qtd_view_" + id).html('Meia');
                        } else {
                            $("#item_qtd_view_" + id).html(item_pedido_quantidade);
                        }

                        var item_pedido_valor = currentValue * produto_preco_venda;
                        item_pedido_valor = item_pedido_valor.toFixed(
                            2); // Limita a duas casas decimais
                        $("#item_pedido_valor_" + id).val(item_pedido_valor);
                        //Atualiza valor na vizualização
                        $("#item_valor_view_" + id).html(item_pedido_valor);
                        $.ajax({
                            type: "POST",
                            url: "{{ route('item_pedido.update_qtd_valor') }}",
                            data: {
                                id,
                                item_pedido_quantidade,
                                item_pedido_valor,
                                '_token': '{{ csrf_token() }}'
                            },
                            dataType: "json",
                            success: function(response) {
                                console.log(response);
                            },
                            error: function() {
                                alert('Erro ao atualizar o item do pedido')
                            }
                        });

                    });
                    $(".plus-btn").click(function(e) {
                        e.preventDefault();
                        const id = $(this).data('item_id');
                        const produto_preco_venda = $(this).data('produto_preco_venda');
                        // Obtém o elemento de entrada de quantidade
                        var item_pedido_quantidade = $("#item_pedido_quantidade_" + id).val();

                        // Obtém o valor atual e converte para um número
                        var currentValue = parseFloat(item_pedido_quantidade);
                        // Verifica se o valor atual é 0.5
                        if (currentValue === 0.5) {
                            // Se for 0.5, incrementa em 0.5
                            currentValue += 0.5;
                        } else {
                            // Se não for 0.5, incrementa em 1
                            currentValue += 1;
                        }
                        // Define o novo valor do campo de entrada, convertendo para string
                        $("#item_pedido_quantidade_" + id).val(currentValue.toString());
                        item_pedido_quantidade = currentValue;
                        // Atualiza a quantidade da vizualização
                        if (item_pedido_quantidade === 0.5) {
                            $("#item_qtd_view_" + id).html('Meia');
                        } else {
                            $("#item_qtd_view_" + id).html(item_pedido_quantidade);
                        }


                        var item_pedido_valor = currentValue * produto_preco_venda;
                        item_pedido_valor = item_pedido_valor.toFixed(
                            2); // Limita a duas casas decimais
                        $("#item_pedido_valor_" + id).val(item_pedido_valor);
                        //Atualiza valor na vizualização
                        $("#item_valor_view_" + id).html(item_pedido_valor);
                        $.ajax({
                            type: "POST",
                            url: "{{ route('item_pedido.update_qtd_valor') }}",
                            data: {
                                id,
                                item_pedido_quantidade,
                                item_pedido_valor,
                                '_token': '{{ csrf_token() }}'
                            },
                            dataType: "json",
                            success: function(response) {
                                console.log(response);
                            },
                            error: function() {
                                alert('Erro ao atualizar o item do pedido')
                            }
                        });
                    });

                    //Altera a observação do item do produto
                    $(".item_pedido_observacao").change(function(e) {
                        e.preventDefault();
                        const id = $(this).data('item_id');
                        const item_pedido_observacao = $(this).val();
                        $.ajax({
                            type: "POST",
                            url: "{{ route('item_pedido.update_observacao') }}",
                            data: {
                                id,
                                item_pedido_observacao,
                                '_token': '{{ csrf_token() }}'
                            },
                            dataType: "json",
                            success: function(response) {
                                console.log(response);
                            },
                            error: function() {
                                alert('Erro ao atualizar o item do pedido')
                            }
                        });
                    });

                    //Remove item do pedido
                    $("#remove_item").click(function(e) {
                        e.preventDefault();
                        const id = $(this).data('item_id');
                        $.ajax({
                            type: "POST",
                            url: "{{ route('item_pedido.remove') }}",
                            data: {
                                id,
                                '_token': '{{ csrf_token() }}'
                            },
                            dataType: "json",
                            success: function(response) {
                                console.log(response);
                                ListarItenPedido();
                            },
                            error: function() {
                                alert('Erro ao atualizar o item do pedido')
                            }
                        });

                    });

                },
                error: function() {
                    alert('Erro ao listar itens');
                }
            });
        }
    </script>

</section>
