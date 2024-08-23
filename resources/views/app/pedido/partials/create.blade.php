<section>
    <form id="formPedido" action="{{ route('pedido.salvar_pedido', 1) }}" method="post" class="space-y-6 mt-2 "
        enctype="multipart/form-data">

        <div class="col-span-full grid grid-cols-1 md:grid-cols-8 gap-x-4 gap-y-1">
            <x-text-input name="pedido_id" id="pedido_id" hidden></x-text-input>
            {{-- PRODUTOS --}}
            <div
                class="h-[80vh] sm:h-[50dvh] md:h-[64dvh] lg:h-[57dvh] xl:h-[63dvh] 2xl:h-[69dvh] sm:col-span-4 lg:col-span-3 col-span-6 md:space-y-2 ">
                <p class="flex items-center gap-x-2 text-sm font-bold text-teal-700">
                    <i class='bx bxs-map-pin'></i>
                    <span>{{ __('Produtos') }}</span>
                </p>
                <div class="flex flex-col mb-4">
                    <x-text-input id="buscar" class="" placeholder="Buscar Produtos"></x-text-input>
                    <div class="hidden md:flex gap-2 overflow-auto p-1">
                        @foreach ($categorias as $categoria)
                            <button type="button"
                                class="inline-flex items-center bg-gray-200 border p-1 border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 disabled:opacity-25 transition ease-in-out duration-150"
                                onclick="scrollToElement('categoria_{{ $categoria->id }}')">
                                {{ $categoria->categoria_nome }}
                            </button>
                        @endforeach
                    </div>
                </div>
                <div class="h-[86%] md:h-[72%] lg:h-[76%] xl:h-[83%] 2xl:h-[87%] overflow-auto snap-y">
                    @foreach ($categorias as $categoria)
                        <div class="mb-4" id="categoria_{{ $categoria->id }}">
                            <h2 class="text-gray-700 text-lg font-bold">{{ $categoria->categoria_nome }}</h2>
                            @if ($categoria->produtos->isEmpty())
                                <p class=" text-xs md:text-lg text-gray-400">Não há produtos disponíveis nesta
                                    categoria.</p>
                            @else
                                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4 ">
                                    @foreach ($categoria->produtos as $produto)
                                        <div class="relative snap-end ">
                                            <div class="produto cursor-pointer hover:shadow-lg"
                                                data-produto_id="{{ $produto->id }}"
                                                data-produto_valor="{{ $produto->produto_preco_venda }}">
                                                <div
                                                    class="w-full flex flex-col sm:flex-row bg-gray-100 p-2 rounded-lg md:flex-col opacity-95 hover:opacity-100 gap-1">
                                                    <div class="w-full ">
                                                        @if ($produto->produto_foto)
                                                            <img src="{{ asset('img/fotos_produtos/' . $produto->produto_foto) }}"
                                                                alt="{{ $produto->produtso_descricao }}"
                                                                class="w-full max-h-12 object-cover rounded-lg ">
                                                        @else
                                                            <img id="imagem-preview"
                                                                class="w-full max-h-12 object-cover rounded-lg "
                                                                src="{{ asset('Sem Imagem.png') }}"
                                                                alt="Imagem Padrão">
                                                        @endif
                                                    </div>
                                                    <div class="flex max-h-16 flex-col justify-between">
                                                        <p
                                                            class="text-gray-900 font-bold text-xs uppercase produto_descricao">
                                                            {{ $produto->produto_descricao }}
                                                        </p>
                                                        <span class="text-green-500 text-xs">
                                                            R$
                                                            {{ str_replace('.', ',', $produto->produto_preco_venda) }}
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
                <x-primary-button class="hidden lg:block w-full">
                    {{ __('Finalizar Pedido') }}
                </x-primary-button>
            </div>

            <div
                class="relative sm:col-span-4 lg:col-span-3 col-span-6 md:space-y-2 md:border-x md:px-3 border-t pt-1 md:pt-0 pb-1 md:pb-0 md:border-t-0 border-b md:border-b-0">
                {{-- GARÇOM ID --}}
                <x-text-input id="pedido_usuario_garcom_id" name="pedido_usuario_garcom_id" type="text"
                    class="mt-1 w-full" value="{{ Auth::user()->id }}" autocomplete="off" hidden />
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
                            <input type="radio" id="opcao_entrega_{{ $opcao_entrega->id }}"
                                name="pedido_opcaoentrega_id" value="{{ $opcao_entrega->id }}"
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
                <div class="relative h-[80dvh] md:h-[64dvh] lg:h-[57dvh] xl:h-[63dvh] 2xl:h-[69dvh] overflow-auto">
                    <!-- Ícone de carregamento e mensagem -->
                    <div id="carregando"
                        class="hidden absolute inset-0 flex justify-center items-center bg-slate-600 bg-opacity-50 transition duration-150 ease-in-out">
                        <div class="text-center">
                            <i class='bx bx-loader-circle bx-spin bx-rotate-90 text-5xl'></i>
                            <p>Carregando Produtos</p>
                        </div>
                    </div>

                    <div id="itens_pedido_container" class="">


                        <!-- Conteúdo -->
                        <p class="p-2 text-center">Nenhum produto lançado no pedido!</p>
                    </div>
                </div>
                {{-- Valores --}}
                <div class="bg-white p-1">
                    <p class="flex items-center gap-x-2 text-sm font-bold text-teal-700">
                        <i class='bx bx-dollar-circle'></i>
                        <span>{{ __('Valores') }}</span>
                    </p>
                    <div class="grid grid-cols-1 md:grid-cols-3 lg:space-x-2">
                        <div class="col-span-1">
                            <x-input-label for="pedido_valor_itens" :value="__('Itens R$')" />
                            <x-money-input id="pedido_valor_itens" name="pedido_valor_itens" type="text"
                                class="mt-1 w-full" autocomplete="off" value="0.00" readonly />
                        </div>
                        <div class="col-span-1">
                            <x-input-label for="pedido_valor_desconto" :value="__('Desconto R$')" />
                            <x-money-input id="pedido_valor_desconto" name="pedido_valor_desconto" type="text"
                                class="money mt-1 w-full" autocomplete="off" value="0.00" readonly />
                        </div>
                        <div class="col-span-1">
                            <x-input-label for="pedido_valor_total" :value="__('Total R$')" />
                            <x-money-input id="pedido_valor_total" name="pedido_valor_total" type="text"
                                class="mt-1 w-full" autocomplete="off" value="0.00" readonly />
                        </div>
                    </div>
                </div>
            </div>
            @csrf
            <div class="block lg:hidden col-span-6 ">
                <x-primary-button class="w-full text-center">
                    {{ __('Finalizar Pedido') }}
                </x-primary-button>
            </div>

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
            $(".toggleSideBar").trigger("click");

            $('#opcao_entrega_1').attr('checked', true);
            $('#endereco_entrega').hide();


            // Função para filtrar os produtos com base na descrição digitada
            $('#buscar').on('input', function() {
                const descricao = $(this).val()
                    .toLowerCase(); // Obtenha o valor do campo de busca em minúsculas
                $('.produto').each(function() { // Itera sobre cada produto
                    const descricaoProduto = $(this).find('.produto_descricao').text()
                        .toLowerCase(); // Obtenha a descrição do produto em minúsculas
                    if (descricaoProduto.includes(
                            descricao
                        )) { // Verifique se a descrição do produto inclui a descrição digitada
                        $(this).show(); // Se sim, mostre o produto
                    } else {
                        $(this).hide(); // Se não, esconda o produto
                    }
                });
            });


            // Verifica se o valor do input selecionado é "Entregar"
            $('input[name="pedido_opcaoentrega_id"]').change(function() {
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

                // Verifica se o produto já está na lista de itens do pedido no HTML
                const itemPedidoExistente = $(
                    `#itens_pedido_container [data-item_produto_id="${item_pedido_produto_id}"]`);

                if (itemPedidoExistente.length > 0) {
                    // Se o produto já estiver na lista de itens, aumente a quantidade
                    $(".abrir-modal").trigger("click");
                    $("#modal-title").html(`<h2>OLÁ {{ Auth::user()->name }}</h2>`);
                    $("#modal-body").html(`
                    <div
                        <div class="p-2 flex items-center>"
                        <!-- Ícone de atenção -->
                        <i class="bx bx-info-circle text-4xl text-yellow-500"></i>
                        <!-- Mensagem -->
                        <div class="ml-4">
                            <h4 class="text-xl font-bold">Atenção</h4>
                            <p>Produto já lançado neste pedido!</p>
                        </div>
                    </div>
                        
                    `);

                } else {
                    // Se o produto não estiver na lista de itens, adicione um novo item ao pedido

                    //Verifica se o pedido está aberto
                    const pedido_id = $("#pedido_id").val();
                    if (pedido_id === "") {
                        //Se não estiver ele abre um novo e já adiciona o produto clicado
                        IniciarPedido($(this));

                    } else {
                        //Se o pedido já estiver aberto ele apenas adiciona o produto clicado
                        AdicionarProdutoemPedidoIniciado($(this));

                    }
                }
            });

            //Remove item do pedido
            $(".remove_item").click(function(e) {
                e.preventDefault();
                const id = $(this).data('item_id');
                const item_pedido_valor = $(this).data('produto_valor');
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
                        ValorTotalItensPedido();
                    },
                    error: function() {
                        alert('Erro ao atualizar o item do pedido')
                    }
                });

            });

            let pedido_valor_desconto; // Variável global para armazenar o valor do desconto

            // Função que atualiza o valor total quando insere qualquer valor no campo de desconto
            $("#pedido_valor_desconto").keyup(function(e) {
                // Obter o valor do desconto e substituir vírgulas por pontos antes de converter para um número
                pedido_valor_desconto = parseFloat($(this).val().replace(',', '.'));
                pedido_valor_desconto = pedido_valor_desconto.toFixed(2);

                // Se o valor do desconto não for um número válido, defina-o como 0.00
                if (isNaN(pedido_valor_desconto)) {
                    pedido_valor_desconto = 0.00;
                }

                // Obter o valor total dos itens
                const valorTotalPedido = parseFloat($("#pedido_valor_itens").val());

                // Calcular o novo valor total subtraindo o desconto
                const novoValorTotal = valorTotalPedido - pedido_valor_desconto;

                // Atualizar o elemento na sua página com o novo valor total
                $("#pedido_valor_total").val(novoValorTotal.toFixed(2));
            });

            // Função que executa quando o campo de desconto recebe foco
            $("#pedido_valor_desconto").focus(function(e) {
                e.preventDefault();
                // Armazena o valor atual do campo de desconto e limpa o campo
                pedido_valor_desconto = $(this).val();
                $(this).val("");
            });

            // Função que executa quando o campo de desconto perde o foco
            $("#pedido_valor_desconto").blur(function(e) {
                e.preventDefault();
                // Verifica se o valor do desconto é diferente de vazio ou "0.00" ou "0,00"
                if (pedido_valor_desconto !== "" || pedido_valor_desconto !== "0.00" ||
                    pedido_valor_desconto !== "0,00") {
                    // Se for diferente, restaura o valor anterior do campo de desconto
                    $(this).val(pedido_valor_desconto);
                } else {
                    // Se for vazio ou "0.00" ou "0,00", define o valor como "0.00"
                    $(this).val("0.00");
                }
            });

        });


        function IniciarPedidoeAdicionarProduto(elemento, pedido_id) {
            const item_pedido_produto_id = elemento.data('produto_id');
            const item_pedido_pedido_id = pedido_id;
            const item_pedido_quantidade = 1;
            const item_pedido_valor = elemento.data('produto_valor');
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
                        ValorTotalItensPedido();

                    } else {
                        alert(
                            'Erro ao iniciar o pedido. Por favor, tente novamente 1.'
                        );
                    }

                },
                error: function() {
                    alert(
                        'Erro ao adicionar produto ao pedido. Por favor, tente novamente .'
                    );
                }
            });
        }

        function AdicionarProdutoemPedidoIniciado(elemento) {
            const item_pedido_produto_id = elemento.data('produto_id');
            const item_pedido_pedido_id = $("#pedido_id").val();
            const item_pedido_quantidade = 1;
            const item_pedido_valor = elemento.data('produto_valor');
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
                        ValorTotalItensPedido();

                    } else {
                        alert(
                            'Erro ao iniciar o pedido. Por favor, tente novamente 1.'
                        );
                    }

                },
                error: function() {
                    alert(
                        'Erro ao adicionar produto ao pedido. Por favor, tente novamente .'
                    );
                }
            });
        }


        function IniciarPedido(elemento) {
            const form = document.getElementById('formPedido');
            var route = '{{ route('pedido.salvar_pedido', 14) }}';

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
                        form.action = route.replace('14', response
                            .pedido_id
                        );
                        IniciarPedidoeAdicionarProduto(elemento, response.pedido_id)
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
            $("#carregando").removeClass('hidden');
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
                                <div class="border-y px-2 py-1 cursor-pointer hover:bg-gray-200" data-item_produto_id="${item.produto.id}">
                                    <div class="grid grid-cols-6 items-center">
                                        <span class="remove_item col-span-6 cursor-pointer flex justify-end" data-item_id="${item.id}" data-produto_valor="${item.produto.produto_preco_venda}">
                                            <i class='bx bxs-x-circle text-xl hover:text-red-600 transition ease-in-out duration-300'></i>
                                        </span>
                                        <div class="col-span-6 flex flex-row items-start space-x-2">
                                            <img id="imagem-preview" class="w-8 h-8 object-cover rounded-lg" src="/img/fotos_produtos/${item.produto.produto_foto}" alt="Imagem Padrão">
                                            <span id="produto_nome_${item.id}" class="truncate overflow-ellipsis text-sm">${item.produto.produto_descricao}<p>R$ <span id="item_valor_view_${item.id}">${item.item_pedido_valor}</span> Qtd. <span id="item_qtd_view_${item.id}">${item.item_pedido_quantidade}</span></p></span>
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
                                        <x-input-label for="item_pedido_desconto" :value="__('Desconto R$')" />
                                        <x-text-input id="item_pedido_desconto_${item.id}" name="item_pedido_desconto" type="text"
                                            class="item_desconto money mt-1 w-full" value="${item.item_pedido_desconto}" data-item_id="${item.id}" data-produto_preco_venda="${item.produto.produto_preco_venda}" autocomplete="off" />
                                        
                                            <x-input-label for="item_pedido_valor_unitario" :value="__('Valor Unit. R$')" />
                                        <x-text-input id="item_pedido_valor_unitario_${item.id}" name="item_pedido_valor_unitario" type="text"
                                            class="mt-1 w-full" value="${item.produto.produto_preco_venda}" autocomplete="off" readonly />
                                        
                                            <x-input-label for="item_pedido_valor" :value="__('Valor R$')" />
                                        <x-text-input id="item_pedido_valor_${item.id}" name="item_pedido_valor" type="text"
                                            class="mt-1 w-full" value="${item.item_pedido_valor}" autocomplete="off" readonly />
                                    </div>
                                </div>
                            `;

                            // Adicione o HTML do item de pedido ao container
                            $('#itens_pedido_container').append(itemHtml);
                            $("#carregando").addClass('hidden');
                        });
                    } else {
                        // Se não houver itens de pedido inseridos, exiba uma mensagem indicando isso
                        $('#itens_pedido_container').html(
                            '<p class="p-2">Nenhum produto encontrado para este pedido</p>');
                        $("#carregando").addClass('hidden');
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
                        const item_desconto = parseFloat($("#item_pedido_desconto_" + id).val());
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

                        var item_pedido_valor = currentValue * produto_preco_venda - item_desconto;
                        item_pedido_valor = item_pedido_valor.toFixed(
                            2); // Limita a duas casas decimais
                        $("#item_pedido_valor_" + id).val(item_pedido_valor);
                        //Atualiza valor na vizualização
                        $("#item_valor_view_" + id).html(item_pedido_valor.toFixed(2));

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
                                ValorTotalItensPedido();
                            },
                            error: function() {
                                alert('Erro ao atualizar o item do pedido')
                            }
                        });

                    });
                    $(".plus-btn").click(function(e) {
                        e.preventDefault();
                        const id = $(this).data('item_id');
                        const item_desconto = parseFloat($("#item_pedido_desconto_" + id).val());
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


                        var item_pedido_valor = currentValue * produto_preco_venda - item_desconto;
                        item_pedido_valor = item_pedido_valor.toFixed(
                            2); // Limita a duas casas decimais
                        $("#item_pedido_valor_" + id).val(item_pedido_valor);
                        //Atualiza valor na vizualização
                        $("#item_valor_view_" + id).html(item_pedido_valor.toFixed(2));

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
                                ValorTotalItensPedido();
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

                    let item_desconto;

                    // Função que atualiza o valor total quando insere qualquer valor no campo de desconto
                    $(".item_desconto").keyup(function(e) {
                        const id = $(this).data('item_id');
                        // Obter o valor do desconto e substituir vírgulas por pontos antes de converter para um número
                        item_desconto = parseFloat($(this).val().replace(',', '.'));
                        item_desconto = item_desconto.toFixed(2);

                        // Se o valor do desconto não for um número válido, defina-o como 0.00
                        if (isNaN(item_desconto)) {
                            item_desconto = 0.00;
                        }

                        // Obter o valor total dos itens
                        const valorTotalItem = parseFloat($("#item_pedido_valor_unitario_" + id)
                        .val()) * parseFloat($("#item_pedido_quantidade_" + id).val());

                        // Calcular o novo valor total subtraindo o desconto
                        const novoValorTotal = valorTotalItem - item_desconto;

                        // Atualizar o elemento na sua página com o novo valor total
                        $("#item_pedido_valor_" + id).val(novoValorTotal.toFixed(2));

                        $.ajax({
                            type: "POST",
                            url: "{{ route('item_pedido.update_desconto') }}",
                            data: {
                                id,
                                item_desconto,
                                novoValorTotal,
                                '_token': '{{ csrf_token() }}'
                            },
                            dataType: "json",
                            success: function(response) {
                                //console.log(response.message);
                                ValorTotalItensPedido();
                                $("#item_valor_view_" + id).html(novoValorTotal.toFixed(2));
                            },
                            error: function() {
                                alert('Erro ao atualizar o item do pedido')
                            }
                        });

                    });

                    // Função que executa quando o campo de desconto recebe foco
                    $(".item_desconto").focus(function(e) {
                        e.preventDefault();
                        // Armazena o valor atual do campo de desconto e limpa o campo
                        item_desconto = $(this).val();
                        $(this).val("");
                    });

                    // Função que executa quando o campo de desconto perde o foco
                    $(".item_desconto").blur(function(e) {
                        e.preventDefault();
                        // Verifica se o valor do desconto é diferente de vazio ou "0.00" ou "0,00"
                        if (item_desconto !== "" || item_desconto !== "0.00" ||
                            item_desconto !== "0,00") {
                            // Se for diferente, restaura o valor anterior do campo de desconto
                            $(this).val(item_desconto);
                        } else {
                            // Se for vazio ou "0.00" ou "0,00", define o valor como "0.00"
                            $(this).val("0.00");
                        }
                    });

                    //Remove item do pedido
                    $(".remove_item").click(function(e) {
                        e.preventDefault();
                        const id = $(this).data('item_id');
                        const item_pedido_valor = $(this).data('produto_valor');
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
                                ValorTotalItensPedido();
                            },
                            error: function() {
                                alert('Erro ao atualizar o item do pedido')
                            }
                        });

                    });
                    $('.money').mask('#.##0,00', {
                        reverse: true
                    });

                },
                error: function() {
                    alert('Erro ao listar itens');
                }
            });
        }

        // Busca o valor total dos itens do pedido
        function ValorTotalItensPedido() {
            const item_pedido_pedido_id = $("#pedido_id").val();
            $.ajax({
                type: "GET",
                url: "{{ route('calcular_valor_total_pedido') }}",
                data: {
                    item_pedido_pedido_id,
                    '_token': '{{ csrf_token() }}'
                },
                dataType: "JSON",
                success: function(response) {
                    if (response.hasOwnProperty('valor_total_pedido')) {
                        const valorTotalPedido = parseFloat(response.valor_total_pedido);
                        //Atualizar um elemento na sua página com o valor total dos itens
                        $("#pedido_valor_itens").val(response.valor_total_itens.toFixed(2));
                        $("#pedido_valor_desconto").val(response.valor_total_desconto.toFixed(2));
                        $("#pedido_valor_total").val(response.valor_total_pedido.toFixed(2));
                    } else {
                        // Caso não haja valor total do pedido, defina o valor como 0.00
                        $("#pedido_valor_itens").val("0.00");
                        $("#pedido_valor_total").val("0.00");
                    }
                },
                error: function() {
                    alert('Erro ao obter o valor total do pedido.');
                }
            });
        }
    </script>

</section>
