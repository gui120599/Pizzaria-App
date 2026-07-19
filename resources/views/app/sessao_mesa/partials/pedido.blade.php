<section class="h-full">

    <form id="formPedido" action="{{ route('pedido.salvar_pedido_mesa', 1) }}" method="post" class="space-y-6 h-full"
        enctype="multipart/form-data">

        <div class="col-span-full grid grid-cols-1 md:grid-cols-8 gap-x-4 gap-y-1 h-full">
            @csrf
            <x-text-input name="pedido_id" id="pedido_id" hidden></x-text-input>
            <x-text-input name="pedido_sessao_mesa_id" id="pedido_sessao_mesa_id" value="{{ $sessao_mesa->id }}"
                hidden></x-text-input>
            <x-text-input id="pedido_usuario_garcom_id" name="pedido_usuario_garcom_id" value="{{ Auth::user()->id }}"
                hidden> </x-text-input>

            {{-- PRODUTOS --}}
            <div
                class="overflow-auto sm:col-span-4 lg:col-span-5 col-span-6 md:space-y-2 h-full flex flex-col justify-between">
                <div>
                    <p class="flex items-center gap-x-2 text-sm font-bold text-teal-700">
                        <i class='bx bxs-map-pin'></i>
                        <span>{{ __('Produtos') }}</span>
                    </p>

                    <div class="flex flex-col mb-4">
                        <x-text-input id="buscar" class="" placeholder="Buscar Produtos"></x-text-input>
                        <div class="hidden lg:flex gap-3 overflow-x-auto overflow-y-hidden px-1 py-2 scrollbar-none">
                            @if (isset($promocoes) && $promocoes->isNotEmpty())
                                <button type="button"
                                    class="flex flex-col items-center gap-1 shrink-0 w-16 focus:outline-none group"
                                    onclick="scrollToElement('secao_promocoes')">
                                    <div class="w-14 h-14 rounded-xl overflow-hidden border-2 border-orange-400 group-hover:border-orange-500 transition-colors duration-150 shadow-sm bg-orange-50 flex items-center justify-center">
                                        <i class='bx bxs-purchase-tag text-orange-500 text-3xl'></i>
                                    </div>
                                    <span class="text-[9px] text-orange-600 font-semibold uppercase tracking-wide leading-tight text-center line-clamp-2 w-full">Promoções</span>
                                </button>
                            @endif
                            @if (isset($maisVendidos) && $maisVendidos->isNotEmpty())
                                <button type="button"
                                    class="flex flex-col items-center gap-1 shrink-0 w-16 focus:outline-none group"
                                    onclick="scrollToElement('secao_mais_vendidos')">
                                    <div class="w-14 h-14 rounded-xl overflow-hidden border-2 border-yellow-400 group-hover:border-yellow-500 transition-colors duration-150 shadow-sm bg-yellow-50 flex items-center justify-center">
                                        <i class='bx bxs-star text-yellow-500 text-3xl'></i>
                                    </div>
                                    <span class="text-[9px] text-yellow-600 font-semibold uppercase tracking-wide leading-tight text-center line-clamp-2 w-full">+ Vendidos</span>
                                </button>
                            @endif
                            @foreach ($categorias as $categoria)
                                <x-categoria-button
                                    type="button"
                                    :categoria="$categoria"
                                    :dark="false"
                                    onclick="scrollToElement('categoria_{{ $categoria->id }}')"
                                />
                            @endforeach
                        </div>
                    </div>
                </div>

                <div class="overflow-auto snap-y" id="produtos-container">
                    @include('app.produto.partials._secoes_destaque')
                    @foreach ($categorias as $categoria)
                        <div class="mb-4" id="categoria_{{ $categoria->id }}">
                            <h2 class="text-lg font-bold">{{ $categoria->categoria_nome }}</h2>
                            @if ($categoria->produtos->isEmpty())
                                <p class="text-gray-400">Não há produtos disponíveis nesta categoria.</p>
                            @else
                                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-3 lg:grid-cols-5 gap-4 ">
                                    @foreach ($categoria->produtos as $produto)
                                        <div class="relative snap-end ">
                                            <div class="produto cursor-pointer hover:shadow-lg"
                                                data-produto_id="{{ $produto->id }}"
                                                data-produto_valor="{{ $produto->produto_preco_venda }}"
                                                data-produto_preco_promocional="{{ $produto->produto_preco_promocional ?? 0 }}">
                                                <div
                                                    class="w-full flex flex-col bg-gray-100 p-2 rounded-lg opacity-95 hover:opacity-100 gap-1">
                                                    {{-- <div class="w-full ">
                                                        @if ($produto->produto_foto)
                                                            <img src="{{ $produto->getImagemUrl() }}"
                                                                alt="{{ $produto->produtso_descricao }}"
                                                                class="w-full max-h-12 object-cover rounded-lg ">
                                                        @else
                                                            <img id="imagem-preview"
                                                                class="w-full max-h-12 object-cover rounded-lg "
                                                                src="{{ asset('Sem Imagem.png') }}"
                                                                alt="Imagem Padrão">
                                                        @endif
                                                    </div> --}}
                                                    <div class="flex h-16 flex-col justify-between">
                                                        <div class="flex gap-1 flex-wrap">
                                                            @if ($produto->produto_preco_promocional > 0)
                                                                <span class="text-[10px] bg-orange-500 text-white px-1 rounded font-bold flex items-center gap-0.5"><i class='bx bxs-purchase-tag text-xs'></i> PROMO</span>
                                                            @endif
                                                            @if (in_array($produto->id, $top10Ids ?? []))
                                                                <span class="text-[10px] bg-yellow-500 text-white px-1 rounded font-bold flex items-center gap-0.5"><i class='bx bxs-star text-xs'></i> +VENDIDO</span>
                                                            @endif
                                                        </div>
                                                        <p
                                                            class="text-gray-900 font-bold text-sm md:text-xs uppercase produto_descricao">
                                                            {{ $produto->categoria->categoria_nome }}
                                                            {{ $produto->produto_descricao }}
                                                        </p>
                                                        @if ($produto->produto_preco_promocional > 0)
                                                            <span class="text-gray-400 line-through text-sm leading-none">R${{ str_replace('.', ',', $produto->produto_preco_venda) }}</span>
                                                            <span class="text-orange-500 text-xl font-bold leading-tight">R${{ str_replace('.', ',', $produto->produto_preco_promocional) }}</span>
                                                        @else
                                                            <span class="text-green-500 text-xl">
                                                                R${{ str_replace('.', ',', $produto->produto_preco_venda) }}
                                                            </span>
                                                        @endif
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
                        var container = document.getElementById('produtos-container');
                        var element = document.getElementById(elementId);

                        if (container && element) {
                            container.scrollTo({
                                top: element.offsetTop - container.offsetTop, // Calcula a posição do item dentro do contêiner
                                behavior: 'smooth'
                            });
                        }
                    }
                </script>


                <div class="w-full">
                    <x-primary-button class="w-full">Finalizar Predido </x-primary-button>
                </div>
            </div>

            <div
                class="overflow-auto sm:col-span-4 lg:col-span-3 col-span-6 relative md:space-y-2 md:border-l md:px-3 border-t pt-1 md:pt-0 pb-1 md:pb-0 md:border-t-0 border-b md:border-b-0 h-full">
                <div
                    class="overflow-auto sm:col-span-8 lg:col-span-2 col-span-6 bg-slate-100 border h-full flex flex-col justify-between">
                    <div class="bg-white p-1">
                        <p>Itens do Pedido</p>
                    </div>
                    <div class="overflow-auto snap-y">
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
                        <div class="grid grid-cols-1 lg:grid-cols-3 lg:space-x-2">
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
            </div>
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


            // Adiciona o produto no pedido
            $(".produto").click(function(e) {
                e.preventDefault();
                const item_pedido_produto_id = $(this).data('produto_id');
                const pedido_id = $('#pedido_id').val();

                // Verifica se o produto já está na lista de itens do pedido no HTML
                var itemPedidoExistente = $(
                    `#itens_pedido_container [data-item_produto_id="${item_pedido_produto_id}"]`
                ).toArray();

                // Filtra o array removendo os itens que possuem adicionais ou quantidade 0.5
                itemPedidoExistente = itemPedidoExistente.filter(function(item) {
                    const $item = $(item); // Converte o item em objeto jQuery para manipulação
                    if ($item.data('adicionais')) {
                        return false; // Remove do array
                    }
                    const quantidade = $item.data('item_pedido_quantidade');
                    if (quantidade == 0.5) {
                        return false; // Remove do array
                    }
                    return true; // Mantém no array
                });

                // Se existir, aumenta uma quantidade
                if (itemPedidoExistente.length > 0) {
                    console.log(itemPedidoExistente);

                    // Usa o primeiro elemento do array (pode ajustar conforme necessário)
                    const $firstItem = $(itemPedidoExistente[0]); // Converte para jQuery
                    $("#plus-btn_" + $firstItem.data('item_pedido_id')).trigger("click");
                } else {
                    // Se o produto não estiver na lista de itens, adicione um novo item ao pedido
                    if (pedido_id === "") {
                        // Se não estiver, abre um novo pedido e adiciona o produto clicado
                        IniciarPedido($(this));
                    } else {
                        // Se o pedido já estiver aberto, apenas adiciona o produto clicado
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
            const precoVenda = parseFloat(elemento.data('produto_valor')) || 0;
            const precoPromo = parseFloat(elemento.data('produto_preco_promocional')) || 0;
            const item_pedido_valor = (precoPromo > 0 && precoPromo > precoVenda) ? precoPromo : precoVenda;
            const item_pedido_desconto = (precoPromo > 0 && precoPromo < precoVenda) ? (precoVenda - precoPromo) : 0;
            const item_pedido_status = 'INSERIDO';
            $.ajax({
                type: "POST",
                url: "{{ route('item_pedido.store') }}",
                data: {
                    item_pedido_produto_id,
                    item_pedido_pedido_id,
                    item_pedido_quantidade,
                    item_pedido_valor,
                    item_pedido_desconto,
                    '_token': '{{ csrf_token() }}'
                },
                dataType: "json",
                success: function(response) {
                    // Lidar com a resposta
                    if (response) {
                        console.log(response);
                        if (response.aviso) {
                            alert(response.aviso);
                        }
                        ListarItenPedido();
                        ValorTotalItensPedido();

                    } else {
                        alert(
                            'Erro ao iniciar o pedido. Por favor, tente novamente 1.'
                        );
                    }

                },
                error: function(xhr) {
                    alert(
                        (xhr.responseJSON && xhr.responseJSON.message) ||
                        'Erro ao adicionar produto ao pedido. Por favor, tente novamente .'
                    );
                }
            });
        }

        function AdicionarProdutoemPedidoIniciado(elemento) {
            const item_pedido_produto_id = elemento.data('produto_id');
            const item_pedido_pedido_id = $("#pedido_id").val();
            const item_pedido_quantidade = 1;
            const precoVenda = parseFloat(elemento.data('produto_valor')) || 0;
            const precoPromo = parseFloat(elemento.data('produto_preco_promocional')) || 0;
            const item_pedido_valor = (precoPromo > 0 && precoPromo > precoVenda) ? precoPromo : precoVenda;
            const item_pedido_desconto = (precoPromo > 0 && precoPromo < precoVenda) ? (precoVenda - precoPromo) : 0;
            const item_pedido_status = 'INSERIDO';
            $.ajax({
                type: "POST",
                url: "{{ route('item_pedido.store') }}",
                data: {
                    item_pedido_produto_id,
                    item_pedido_pedido_id,
                    item_pedido_quantidade,
                    item_pedido_valor,
                    item_pedido_desconto,
                    '_token': '{{ csrf_token() }}'
                },
                dataType: "json",
                success: function(response) {
                    // Lidar com a resposta
                    if (response) {
                        console.log(response);
                        if (response.aviso) {
                            alert(response.aviso);
                        }
                        ListarItenPedido();
                        ValorTotalItensPedido();

                    } else {
                        alert(
                            'Erro ao iniciar o pedido. Por favor, tente novamente 1.'
                        );
                    }

                },
                error: function(xhr) {
                    alert(
                        (xhr.responseJSON && xhr.responseJSON.message) ||
                        'Erro ao adicionar produto ao pedido. Por favor, tente novamente .'
                    );
                }
            });
        }


        function IniciarPedido(elemento) {
            const form = document.getElementById('formPedido');
            var route = '{{ route('pedido.salvar_pedido_mesa', 14) }}';

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

                    // Limpe o conteúdo atual antes de adicionar os novos itens
                    $('#itens_pedido_container').empty();

                    // Verifique se há itens de pedido encontrados na resposta
                    if (response.length > 0) {
                        // Itere sobre cada item retornado na resposta
                        $.each(response, function(index, item) {
                            var adicionaisItem = false;
                            if (item.adicionais_item_pedido.length > 0) {
                                adicionaisItem = true;
                            }
                            var promoDesconto = parseFloat(item.item_pedido_desconto) || 0;

                            // Crie o HTML para o item de pedido e o produto associado
                            var itemHtml = `
                                <div class="border-y px-2 py-1 cursor-pointer hover:bg-gray-200" data-item_pedido_id="${item.id}" data-adicionais="${adicionaisItem}" data-item_pedido_quantidade="${item.item_pedido_quantidade}" data-item_produto_id="${item.produto.id}">
                                    <div class="grid grid-cols-6 items-center">
                                        <span class="remove_item col-span-6 cursor-pointer flex justify-end" data-item_id="${item.id}" title="REMOVER" data-produto_valor="${item.produto.produto_preco_venda}">
                                            <i class='bx bxs-x-circle text-xl hover:text-red-600 transition ease-in-out duration-300'></i>
                                        </span>
                                        <div class="col-span-6 flex flex-row items-start space-x-2">`;
                           if (item.produto.produto_foto) {
                                itemHtml += `<img id="imagem-preview" class="w-8 h-8 object-cover rounded-lg" 
                                                src="/storage/${item.produto.produto_foto}" 
                                                alt="Imagem Padrão">`;
                            } else {
                                itemHtml += `<img id="imagem-preview" class="w-8 h-8 object-cover rounded-lg" 
                                                src="/Sem Imagem.png" 
                                                alt="Imagem Padrão">`;
                            }
                            itemHtml += `
                                            <span id="produto_nome_${item.id}" class="truncate overflow-ellipsis text-sm">${item.produto.categoria.categoria_nome} ${item.produto.produto_descricao}
                                            ${promoDesconto > 0 ? `<span class="ml-1 text-xs bg-orange-500 text-white px-1 rounded">PROMO -R$<span id="item_desconto_view_${item.id}">${promoDesconto.toFixed(2)}</span></span>` : `<span id="item_desconto_view_${item.id}" class="hidden">${promoDesconto.toFixed(2)}</span>`}
                                            <p>R$ <span id="item_valor_view_${item.id}">${item.item_pedido_valor}</span> Qtd. <span id="item_qtd_view_${item.id}">${item.item_pedido_quantidade}</span></p></span>
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
                                                data-item_id="${item.id}" data-produto_preco_venda="${item.produto.produto_preco_venda}" data-produto_preco_promocional="${item.produto.produto_preco_promocional || 0}">-</button>
                                            <input type="text" id="item_pedido_quantidade_${item.id}" name="item_pedido_quantidade"
                                                value="${item.item_pedido_quantidade}"
                                                class="w-20 text-center border border-gray-300 rounded-none focus:outline-none focus:ring-1 focus:ring-gray-400"
                                                readonly>
                                            <button type="button" id="plus-btn_${item.id}"
                                                class="plus-btn w-full px-3 py-1 bg-gray-200 border border-gray-300 rounded-r-md hover:text-xl hover:font-semibold hover:bg-gray-300 focus:outline-none"
                                                data-item_id="${item.id}" data-produto_preco_venda="${item.produto.produto_preco_venda}" data-produto_preco_promocional="${item.produto.produto_preco_promocional || 0}">+</button>
                                        </div>
                            `;
                            if (item.produto.ap_produto_id && Array.isArray(item.produto
                                    .ap_produto_id) && item.produto.ap_produto_id.length > 0) {
                                itemHtml += `
                                        <x-input-label :value="__('Adicionais')" />
                                        <div class="max-h-40 overflow-auto overflow-x-hidden">
                                `;

                                item.produto.ap_produto_id.forEach(add => {
                                    // Busque o adicional relacionado no `adicionais_item_pedido` para pegar a quantidade
                                    const adicionalItemPedido = item.adicionais_item_pedido ?
                                        item.adicionais_item_pedido.find(aip => aip
                                            .aip_adicional_id === add.adicional.id) : null;

                                    // Quantidade padrão é 0, caso não haja relação em adicionaisItemPedido
                                    const quantidade = adicionalItemPedido ? adicionalItemPedido
                                        .aip_quantidade : 0;

                                    itemHtml += `
                                            <div class="grid grid-cols-8 mt-2 items-center">
                                                <div class="col-span-1">
                                                    <img id="imagem-preview" class="w-10 h-10 object-cover rounded-lg" src="/img/fotos_adicionais/${add.adicional.adicional_foto}" alt="Imagem do Adicional">
                                                </div>
                                                <div class="col-span-2">
                                                    <span class="text-start">${add.adicional.adicional_nome}</span>
                                                </div>
                                                <div class="col-span-2">
                                                    <span class="text-end">R$ ${add.adicional.adicional_valor}</span>
                                                </div>
                                                <div class="col-span-3">
                                                    <div class="flex items-stretch justify-evenly">
                                                        <button type="button" id="minus-btn-adicional_${add.adicional.id}_${item.id}"
                                                            class="minus-btn-adicional w-full px-3 py-1 bg-gray-200 border border-gray-300 rounded-l-md hover:font-semibold hover:bg-gray-300 focus:outline-none"
                                                            data-item_pedido_id="${item.id}" data-adicional_id="${add.adicional.id}" data-adicional_valor="${add.adicional.adicional_valor}">-</button>
                                                        <input type="text" id="item_pedido_${item.id}_adicional_${add.adicional.id}_quantidade" name="item_pedido_adicional_quantidade"
                                                            value="${quantidade}"
                                                            class="max-w-14 text-center border border-gray-300 rounded-none focus:outline-none focus:ring-1 focus:ring-gray-400"
                                                            readonly />
                                                        <button type="button" id="plus-btn-adicional_${add.adicional.id}_${item.id}"
                                                            class="plus-btn-adicional w-full px-3 py-1 bg-gray-200 border border-gray-300 rounded-r-md hover:font-semibold hover:bg-gray-300 focus:outline-none"
                                                            data-item_pedido_id="${item.id}" data-adicional_id="${add.adicional.id}" data-adicional_valor="${add.adicional.adicional_valor}">+</button>
                                                    </div>
                                                </div>
                                            </div>`;
                                });

                                itemHtml += `
                                        </div>`;
                            }


                            if (item.item_pedido_observacao === null) {
                                itemHtml +=
                                    `
                                        <x-input-label for="item_pedido_observacao" :value="__('Observação')" />
                                        <textarea class="item_pedido_observacao border-gray-300 focus:border-black focus:ring-black rounded-md shadow-sm mt-1 w-full" rows="3" id="item_pedido_observacao" name="item_pedido_observacao" autocomplete="off" data-item_id="${item.id}"></textarea>`;
                            } else {
                                itemHtml +=
                                    `
                                        <x-input-label for="item_pedido_observacao" :value="__('Observação')" />
                                        <textarea class="item_pedido_observacao border-gray-300 focus:border-black focus:ring-black rounded-md shadow-sm mt-1 w-full" rows="3" id="item_pedido_observacao" name="item_pedido_observacao" autocomplete="off" data-item_id="${item.id}">${item.item_pedido_observacao}</textarea>`;
                            }
                            itemHtml += `
                                        <div class="flex gap-x-1">
                                            {{-- <div class="flex flex-col">
                                                <x-input-label for="item_pedido_desconto" :value="__('Desconto R$')" />
                                                <x-text-input id="item_pedido_desconto_${item.id}" name="item_pedido_desconto" type="text"
                                                    class="item_desconto money mt-1 w-full" value="${item.item_pedido_desconto}" data-item_id="${item.id}" data-produto_preco_venda="${item.item_pedido_valor_unitario}" autocomplete="off" />
                                            </div> --}}

                                            <div class="flex flex-col">
                                                <x-input-label for="item_pedido_valor_adicionais" :value="__('Valor Adic. R$')" />
                                                <x-text-input id="item_pedido_valor_adicionais_${item.id}" name="item_pedido_valor_adicionais" type="text"
                                                    class="mt-1 w-full" value="${item.item_pedido_valor_adicionais}" autocomplete="off" readonly />
                                            </div>
                                                
                                            <div class="flex flex-col">
                                                <x-input-label for="item_pedido_valor_unitario" :value="__('Valor Unit. R$')" />
                                                <x-text-input id="item_pedido_valor_unitario_${item.id}" name="item_pedido_valor_unitario" type="text"
                                                    class="mt-1 w-full" value="${item.item_pedido_valor_unitario}" autocomplete="off" readonly />
                                            </div>
                                                
                                            <div class="flex flex-col">        
                                                <x-input-label for="item_pedido_valor" :value="__('Valor R$')" />
                                                <x-text-input id="item_pedido_valor_${item.id}" name="item_pedido_valor" type="text"
                                                    class="mt-1 w-full" value="${item.item_pedido_valor}" autocomplete="off" readonly />
                                            </div>
                                        </div>
                                    <div>
                                </div> 
                            </div>
                        </div>`;

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
                        const item_adicionais = parseFloat($("#item_pedido_valor_adicionais_" + id)
                                .val()) ||
                            0;
                        const produto_preco_venda = parseFloat($(this).data('produto_preco_venda')) || 0;
                        const produto_preco_promocional = parseFloat($(this).data('produto_preco_promocional')) || 0;
                        const produto_preco_base = (produto_preco_promocional > 0 && produto_preco_promocional > produto_preco_venda) ? produto_preco_promocional : produto_preco_venda;
                        const desconto_unitario = (produto_preco_promocional > 0 && produto_preco_promocional < produto_preco_venda) ? (produto_preco_venda - produto_preco_promocional) : 0;
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
                        var item_pedido_desconto = parseFloat((desconto_unitario * currentValue).toFixed(2));
                        // Atualiza a quantidade da vizualização
                        if (item_pedido_quantidade === 0.5) {
                            $("#item_qtd_view_" + id).html('Meia');
                            var item_pedido_valor = (currentValue * produto_preco_base) + item_adicionais;
                            var item_pedido_valor_unitario = item_pedido_valor;
                            item_pedido_valor = item_pedido_valor.toFixed(
                                2); // Limita a duas casas decimais
                        } else {
                            $("#item_qtd_view_" + id).html(item_pedido_quantidade);
                            var item_pedido_valor = (currentValue * produto_preco_base) + item_adicionais;
                            var item_pedido_valor_unitario = (item_pedido_valor / currentValue).toFixed(
                                2);
                            item_pedido_valor = item_pedido_valor.toFixed(
                                2); // Limita a duas casas decimais
                        }



                        const elementItemPedido = $(
                            `#itens_pedido_container [data-item_pedido_id="${id}"]`);


                        $.ajax({
                            type: "POST",
                            url: "{{ route('item_pedido.update_qtd_valor') }}",
                            data: {
                                id,
                                item_pedido_valor_unitario,
                                item_pedido_quantidade,
                                item_pedido_valor,
                                '_token': '{{ csrf_token() }}'
                            },
                            dataType: "json",
                            success: function(response) {

                                // Atualiza valor na visualização
                                $("#item_pedido_valor_adicionais_" + id).val(
                                    (parseFloat(response.itemPedido
                                        .item_pedido_valor_adicionais) || 0).toFixed(2)
                                );
                                $("#item_pedido_valor_unitario_" + id).val(
                                    (parseFloat(response.itemPedido
                                        .item_pedido_valor_unitario) || 0).toFixed(2)
                                );
                                $("#item_pedido_valor_" + id).val(
                                    (parseFloat(response.itemPedido
                                        .item_pedido_valor) || 0).toFixed(2)
                                );
                                $("#item_valor_view_" + id).html(
                                    (parseFloat(response.itemPedido
                                        .item_pedido_valor) || 0).toFixed(2)
                                );
                                $("#item_desconto_view_" + id).html(
                                    (parseFloat(response.itemPedido
                                        .item_pedido_desconto) || 0).toFixed(2)
                                );

                                ValorTotalItensPedido();

                                elementItemPedido.data('item_pedido_quantidade',
                                    item_pedido_quantidade);
                                if (response.itemPedido.adicionais_item_pedido.length > 0) {
                                    if (response.itemPedido.item_pedido_quantidade >= 1) {
                                        response.itemPedido.adicionais_item_pedido.forEach(
                                            adicional => {
                                                $("#item_pedido_" + response.itemPedido
                                                    .id + "_adicional_" + adicional
                                                    .aip_adicional_id +
                                                    "_quantidade").val(adicional
                                                    .aip_quantidade);
                                            });
                                    }
                                }

                            },
                            error: function() {
                                alert('Erro ao atualizar o item do pedido')
                            }
                        });

                    });
                    $(".plus-btn").click(function(e) {
                        e.preventDefault();
                        const id = $(this).data('item_id');
                        const item_adicionais = parseFloat($("#item_pedido_valor_adicionais_" + id)
                                .val()) ||
                            0;

                        const produto_preco_venda = parseFloat($(this).data('produto_preco_venda')) || 0;
                        const produto_preco_promocional = parseFloat($(this).data('produto_preco_promocional')) || 0;
                        const produto_preco_base = (produto_preco_promocional > 0 && produto_preco_promocional > produto_preco_venda) ? produto_preco_promocional : produto_preco_venda;
                        const desconto_unitario = (produto_preco_promocional > 0 && produto_preco_promocional < produto_preco_venda) ? (produto_preco_venda - produto_preco_promocional) : 0;

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
                        var item_pedido_desconto = parseFloat((desconto_unitario * currentValue).toFixed(2));
                        // Atualiza a quantidade da vizualização
                        if (item_pedido_quantidade === 0.5) {
                            $("#item_qtd_view_" + id).html('Meia');
                            var item_pedido_valor = currentValue * produto_preco_base + item_adicionais;
                            var item_pedido_valor_unitario = item_pedido_valor;
                            item_pedido_valor = item_pedido_valor.toFixed(
                                2); // Limita a duas casas decimais
                        } else {
                            $("#item_qtd_view_" + id).html(item_pedido_quantidade);
                            var item_pedido_valor = currentValue * produto_preco_base + item_adicionais;
                            var item_pedido_valor_unitario = (item_pedido_valor / currentValue).toFixed(
                                2);
                            item_pedido_valor = item_pedido_valor.toFixed(
                                2); // Limita a duas casas decimais
                        }

                        const elementItemPedido = $(
                            `#itens_pedido_container [data-item_pedido_id="${id}"]`);
                        var adicionais = elementItemPedido.data('adicionais');
                        $.ajax({
                            type: "POST",
                            url: "{{ route('item_pedido.update_qtd_valor') }}",
                            data: {
                                id,
                                item_pedido_valor_unitario,
                                item_pedido_quantidade,
                                item_pedido_valor,
                                '_token': '{{ csrf_token() }}'
                            },
                            dataType: "json",
                            success: function(response) {

                                // Atualiza valor na visualização
                                $("#item_pedido_valor_adicionais_" + id).val(
                                    (parseFloat(response.itemPedido
                                        .item_pedido_valor_adicionais) || 0).toFixed(2)
                                );
                                $("#item_pedido_valor_unitario_" + id).val(
                                    (parseFloat(response.itemPedido
                                        .item_pedido_valor_unitario) || 0).toFixed(2)
                                );
                                $("#item_pedido_valor_" + id).val(
                                    (parseFloat(response.itemPedido
                                        .item_pedido_valor) || 0).toFixed(2)
                                );
                                $("#item_valor_view_" + id).html(
                                    (parseFloat(response.itemPedido
                                        .item_pedido_valor) || 0).toFixed(2)
                                );
                                $("#item_desconto_view_" + id).html(
                                    (parseFloat(response.itemPedido
                                        .item_pedido_desconto) || 0).toFixed(2)
                                );

                                ValorTotalItensPedido();

                                elementItemPedido.data('item_pedido_quantidade',
                                    item_pedido_quantidade);

                                if (response.itemPedido.adicionais_item_pedido.length > 0) {
                                    if (response.itemPedido.item_pedido_quantidade >= 1) {
                                        response.itemPedido.adicionais_item_pedido.forEach(
                                            adicional => {
                                                $("#item_pedido_" + response.itemPedido
                                                    .id + "_adicional_" + adicional
                                                    .aip_adicional_id +
                                                    "_quantidade").val(adicional
                                                    .aip_quantidade);
                                            });
                                    }
                                }
                            },
                            error: function() {
                                alert('Erro ao atualizar o item do pedido')
                            }
                        });
                    });

                    //Adiciona um adicinal no item do pedido
                    $(".minus-btn-adicional").click(function(e) {
                        e.preventDefault();
                        $("#carregando").removeClass('hidden');

                        const adicionalId = $(this).data('adicional_id');

                        const item_pedidoId = $(this).data('item_pedido_id');

                        const valor_unitario = parseFloat($(this).data('adicional_valor'));

                        var itemPedidoQuantidade = parseFloat($("#item_pedido_quantidade_" +
                            item_pedidoId).val());

                        var quantidade = parseFloat($("#item_pedido_" + item_pedidoId + "_adicional_" +
                            adicionalId + "_quantidade").val());

                        var valor_adicionais = parseFloat($("#item_pedido_valor_adicionais_" +
                            item_pedidoId).val());

                        var valorTotalAdicionais = (valor_adicionais - valor_unitario).toFixed(2);

                        const elementItemPedido = $(
                            `#itens_pedido_container [data-item_pedido_id="${item_pedidoId}"]`);

                        if (quantidade > 0) {
                            var quantidade = parseFloat($("#item_pedido_" + item_pedidoId +
                                "_adicional_" + adicionalId + "_quantidade").val()) - 1;
                            if (itemPedidoQuantidade > 1) {
                                quantidade = 0;

                            }
                            $("#item_pedido_" + item_pedidoId + "_adicional_" + adicionalId +
                                "_quantidade").val(quantidade);

                            $.ajax({
                                type: "POST",
                                url: "{{ route('adicional_item_pedido.store') }}",
                                data: {
                                    adicionalId,
                                    item_pedidoId,
                                    quantidade,
                                    valor_unitario,
                                    '_token': '{{ csrf_token() }}'
                                },
                                dataType: "JSON",
                                success: function(response) {
                                    console.log(response);

                                    $("#carregando").addClass('hidden');

                                    // Atualiza valor na visualização
                                    $("#item_pedido_valor_adicionais_" + item_pedidoId)
                                        .val(
                                            (parseFloat(response.itemPedido
                                                .item_pedido_valor_adicionais) || 0)
                                            .toFixed(2)
                                        );
                                    $("#item_pedido_valor_unitario_" + item_pedidoId)
                                        .val(
                                            (parseFloat(response.itemPedido
                                                .item_pedido_valor_unitario) || 0)
                                            .toFixed(2)
                                        );
                                    $("#item_pedido_valor_" + item_pedidoId).val(
                                        (parseFloat(response.itemPedido
                                            .item_pedido_valor) || 0).toFixed(2)
                                    );
                                    $("#item_valor_view_" + item_pedidoId).html(
                                        (parseFloat(response.itemPedido
                                            .item_pedido_valor) || 0).toFixed(2)
                                    );
                                    if (response.itemPedido.adicionais_item_pedido
                                        .length > 0) {
                                        response.itemPedido.adicionais_item_pedido
                                            .forEach(
                                                adicional => {
                                                    $("#item_pedido_" + response
                                                        .itemPedido
                                                        .id + "_adicional_" +
                                                        adicional
                                                        .aip_adicional_id +
                                                        "_quantidade").val(adicional
                                                        .aip_quantidade);
                                                });
                                    }

                                    ValorTotalItensPedido();

                                    if (response.itemPedido
                                        .item_pedido_valor_adicionais == 0) {
                                        elementItemPedido.data('adicionais', false);
                                    }
                                },
                                error: function() {
                                    alert('Erro ao remover adicional!');

                                }
                            });

                        } else {
                            $("#carregando").addClass('hidden');
                        }


                    });

                    $(".plus-btn-adicional").click(function(e) {
                        e.preventDefault();
                        $("#carregando").removeClass('hidden');
                        const adicionalId = $(this).data('adicional_id');
                        const item_pedidoId = $(this).data('item_pedido_id');
                        const valor_unitario = parseFloat($(this).data('adicional_valor'));
                        var itemPedidoQuantidade = parseFloat($("#item_pedido_quantidade_" +
                            item_pedidoId).val());
                        var quantidade = parseFloat($("#item_pedido_" + item_pedidoId + "_adicional_" +
                            adicionalId + "_quantidade").val()) + 1;
                        if (itemPedidoQuantidade > 1) {
                            quantidade = itemPedidoQuantidade;
                        }

                        const elementItemPedido = $(
                            `#itens_pedido_container [data-item_pedido_id="${item_pedidoId}"]`);

                        $.ajax({
                            type: "POST",
                            url: "{{ route('adicional_item_pedido.store') }}",
                            data: {
                                adicionalId,
                                item_pedidoId,
                                quantidade,
                                valor_unitario,
                                '_token': '{{ csrf_token() }}'
                            },
                            dataType: "JSON",
                            success: function(response) {

                                $("#carregando").addClass('hidden');

                                // Atualiza valor na visualização
                                $("#item_pedido_valor_adicionais_" + item_pedidoId).val(
                                    (parseFloat(response.itemPedido
                                        .item_pedido_valor_adicionais) || 0).toFixed(2)
                                );
                                $("#item_pedido_valor_unitario_" + item_pedidoId).val(
                                    (parseFloat(response.itemPedido
                                        .item_pedido_valor_unitario) || 0).toFixed(2)
                                );
                                $("#item_pedido_valor_" + item_pedidoId).val(
                                    (parseFloat(response.itemPedido
                                        .item_pedido_valor) || 0).toFixed(2)
                                );
                                $("#item_valor_view_" + item_pedidoId).html(
                                    (parseFloat(response.itemPedido
                                        .item_pedido_valor) || 0).toFixed(2)
                                );
                                if (response.itemPedido.adicionais_item_pedido.length > 0) {
                                    response.itemPedido.adicionais_item_pedido.forEach(
                                        adicional => {
                                            $("#item_pedido_" + response.itemPedido
                                                .id + "_adicional_" + adicional
                                                .aip_adicional_id +
                                                "_quantidade").val(adicional
                                                .aip_quantidade);
                                        });
                                }

                                ValorTotalItensPedido();

                                elementItemPedido.data('adicionais', true);
                            },
                            error: function() {
                                alert('Erro ao adicionar adicional!');

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
