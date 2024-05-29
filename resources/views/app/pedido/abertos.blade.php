<x-app-layout>
    <style>
        @keyframes change-bg {
            0% {
                background-color: #ffffff;
                /* Vermelho */
            }

            50% {
                background-color: #555555;
                /* Verde */
            }

            100% {
                background-color: #000000;
                /* Vermelho */
            }
        }

        .bg-animation {
            animation: change-bg 1s infinite;
        }
    </style>
    <div class="py-2  ">
        <div class="mx-auto px-2 ">
            <div class="p-2 shadow min-h-[97vh] sm:rounded-lg grid grid-cols-5">
                <div class="col-span-1 bg-yellow-400">
                    <div class="px-2 py-1 flex justify-between bg-yellow-500">
                        <span class="font-bold">Abertos</span>
                        <span class="qtd-abertos font-bold">0</span>
                    </div>
                    <div class="pedidos-abertos p-2 mx-auto space-y-1 h-[90vh] overflow-auto">

                    </div>
                </div>
                <div class="col-span-1 bg-orange-400">
                    <div class="px-2 py-1 flex justify-between bg-orange-500">
                        <span class="font-bold">Preparando</span>
                        <span class="qtd-preparando font-semibold">0</span>
                    </div>
                    <div class="pedidos-preparando p-1 space-y-1 mx-auto h-[90vh] overflow-auto">

                    </div>
                </div>
                <div class="col-span-1 bg-blue-400">
                    <div class="px-2 py-1 flex justify-between bg-blue-500">
                        <span class="font-bold">Pronto - Aguardando Entrega</span>
                        <span class="qtd-pronto font-semibold">0</span>
                    </div>
                    <div class="pedidos-pronto p-2 mx-auto space-y-1 h-[90vh] overflow-auto">
                    </div>
                </div>
                <div class="col-span-1 bg-green-400">
                    <div class="px-2 py-1 flex justify-between bg-green-500">
                        <span class="font-bold">Em Transporte</span>
                        <span class="qtd-transporte font-semibold">0</span>
                    </div>
                    <div class="pedidos-transporte p-2 mx-auto space-y-1 h-[90vh] overflow-auto">

                    </div>
                </div>
                <div class="col-span-1 bg-green-700">
                    <div class="px-2 py-1 flex justify-between bg-green-900">
                        <span class="font-bold">Entregue</span>
                        <span class="qtd-entrgue font-semibold">0</span>
                    </div>
                    <div class="pedidos-entregue p-2 mx-auto space-y-1 h-[90vh] overflow-auto">

                    </div>
                </div>
            </div>
        </div>
    </div>
    <script type="module">
        var qtd_aberto = null;
        $(".toggleSideBar").trigger("click");
        listarEntregue();
        listarTransporte();
        listarPronto();
        listarPreparando();
        listarAbertos();

        setInterval(() => {
            listarAbertos();
        }, 30000);


        function playAudio() {
            var audio = new Audio("{{ asset('level-up-191997.mp3') }}");
            audio.addEventListener('canplaythrough', function() {
                audio.play();
            });
        }


        function listarAbertos() {
            $.ajax({
                type: "GET",
                url: "{{ route('pedidos_abertos.lista') }}",
                data: {
                    '_token': '{{ csrf_token() }}'
                },
                dataType: "JSON",
                success: function(response) {

                    $('.qtd-abertos').text(response.length);
                    if (qtd_aberto !== null && qtd_aberto < response.length) {
                        qtd_aberto = response.length;
                        playAudio();
                    } else if (qtd_aberto === null) {
                        qtd_aberto = response.length;
                    } else {
                        //Não faz nada pois a qtd é a mesma
                    }

                    // Limpe o conteúdo atual antes de adicionar os novos itens
                    $('.pedidos-abertos').empty();

                    // Verifique se há pedidos na resposta
                    if (response.length > 0) {
                        // Itere sobre cada pedido retornado na resposta

                        $.each(response, function(index, pedido) {
                            // Filtre os produtos inseridos no pedido com o status "INSERIDO"
                            var produtosInseridos = pedido.produtos_inseridos_pedido.filter(function(
                                produto) {
                                return produto.pivot.item_pedido_status === 'INSERIDO';
                            });

                            // Crie o HTML para o pedido e os produtos inseridos associados
                            if (produtosInseridos.length > 0) {
                                //$('.pedidos-abertos').addClass('bg-animation');
                                const pedidoDataHoraAbertura = new Date(pedido
                                    .pedido_datahora_abertura);
                                const hours = pedidoDataHoraAbertura.getHours().toString().padStart(2,
                                    '0');
                                const minutes = pedidoDataHoraAbertura.getMinutes().toString().padStart(
                                    2, '0');

                                const formattedTime = `${hours}:${minutes}`;
                                var pedidoHtml = `
                                    <div class=" border p-1 rounded-lg w-full bg-yellow-100">
                                        <div class="flex flex-row items-center justify-between">
                                            <div>
                                                <span class="font-bold">Pedido:</span>
                                                <span>${pedido.id}</span>
                                            </div>
                                            <div class="bg-gray-700 rounded-lg flex flex-row items-center p-1">
                                                <i class='bx bx-time-five text-white'></i>
                                                <span class="text-white">${formattedTime}</span>
                                            </div>
                                        </div>
                                    <div class="itens-pedido">
                                    <hr class="h-px my-1 border-0 bg-gray-200">
                                    <div class="flex flex-row items-center justify-between">
                                        <span class="">Itens</span>
                                        <span class="">${pedido.opcao_entrega.opcaoentrega_nome}</span>
                                        `;
                                if (pedido.sessao_mesa.id !== undefined) {
                                    pedidoHtml += `
                                                <span class="font-bold">${pedido.sessao_mesa.mesa.mesa_nome}</span>
                                            `;
                                }
                                pedidoHtml += `
                                    </div>
                                    <div id="Tabela">
                                        <table class="w-full">
                                            <thead>
                                                <tr class="text-sm">
                                                    <th>QTD</th>
                                                    <th>PRODUTO</th>
                                                    <th>VALOR</th>
                                                </tr>
                                            </thead>
                                            <tbody>`;

                                // Itere sobre os produtos inseridos e crie linhas da tabela
                                $.each(produtosInseridos, function(prodIndex, produto) {
                                    pedidoHtml += `
                                <tr>
                                    <td class="text-xs font-bold text-center">${produto.pivot.item_pedido_quantidade}</td>
                                    <td class="text-xs text-center uppercase">${produto.produto_descricao}</td>
                                    <td class="text-xs font-bold text-right">R$ ${parseFloat(produto.pivot.item_pedido_valor).toFixed(2)}</td>
                                </tr>`;
                                });

                                pedidoHtml += `
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <div class="w-full">
                                    <button class="btn-aceitar w-full text-center border rounded-lg hover:shadow bg-orange-300 hover:bg-orange-400" data-pedido_id="${pedido.id}">Aceitar Pedido <i class='bx bx-right-arrow-alt'></i></button>
                                </div>
                            </div>
                        `;

                                // Adicione o HTML do pedido ao container
                                $('.pedidos-abertos').append(pedidoHtml);
                            }
                        });

                        //Aceita pedido selecionado
                        $(".btn-aceitar").click(function(e) {
                            e.preventDefault();
                            const id = $(this).data('pedido_id');
                            var url = "{{ route('pedido.imprimir', ['id' => 1]) }}";
                            url = url.replace(/\/1\/Imprimir/, `/${id}/Imprimir`);
                            $.ajax({
                                type: "POST",
                                url: "{{ route('aceitar_pedido') }}",
                                data: {
                                    id,
                                    '_token': '{{ csrf_token() }}'
                                },
                                dataType: "JSON",
                                success: function(response) {
                                    window.open(url, 'Teste', 'width=600,height=400');
                                    listarAbertos();
                                    listarPreparando();
                                },
                                error: function() {
                                    alert('Erro ao aceitar pedido');
                                }
                            });
                        });
                    } else {
                        // Se não houver pedidos, exiba uma mensagem indicando isso
                        $('.pedidos-abertos').html('<p class="p-2">Nenhum pedido encontrado</p>');
                    }

                },
                error: function() {
                    alert('Erro ao listar itens');
                }
            });
        }

        function listarPreparando() {
            $.ajax({
                type: "GET",
                url: "{{ route('pedidos_preparando.lista') }}",
                data: {
                    '_token': '{{ csrf_token() }}'
                },
                dataType: "JSON",
                success: function(response) {

                    $('.qtd-preparando').text(response.length);

                    // Limpe o conteúdo atual antes de adicionar os novos itens
                    $('.pedidos-preparando').empty();

                    // Verifique se há pedidos na resposta
                    if (response.length > 0) {
                        // Itere sobre cada pedido retornado na resposta

                        $.each(response, function(index, pedido) {
                            // Filtre os produtos inseridos no pedido com o status "INSERIDO"
                            var produtosInseridos = pedido.produtos_inseridos_pedido.filter(function(
                                produto) {
                                return produto.pivot.item_pedido_status === 'INSERIDO';
                            });

                            // Crie o HTML para o pedido e os produtos inseridos associados
                            if (produtosInseridos.length > 0) {
                                const pedidoDataHoraAbertura = new Date(pedido
                                    .pedido_datahora_abertura);
                                const hours = pedidoDataHoraAbertura.getHours().toString().padStart(2,
                                    '0');
                                const minutes = pedidoDataHoraAbertura.getMinutes().toString().padStart(
                                    2, '0');

                                const formattedTime = `${hours}:${minutes}`;
                                var pedidoHtml = `
                                    <div class="border p-1 rounded-lg w-full bg-orange-100">
                                        <div class="flex flex-row items-center justify-between">
                                            <div>
                                                <span class="font-bold">Pedido:</span>
                                                <span>${pedido.id}</span>
                                            </div>
                                            <div class="bg-gray-700 rounded-lg flex flex-row items-center p-1">
                                                <i class='bx bx-time-five text-white'></i>
                                                <span class="text-white">${formattedTime}</span>
                                            </div>
                                        </div>
                                    <div class="itens-pedido">
                                    <hr class="h-px my-1 border-0 bg-gray-200">
                                    <div class="flex flex-row items-center justify-between">
                                        <span class="">Itens</span>
                                        <span class="">${pedido.opcao_entrega.opcaoentrega_nome}</span>
                                        `;
                                if (pedido.sessao_mesa.id !== undefined) {
                                    pedidoHtml += `
                                                <span class="font-bold">${pedido.sessao_mesa.mesa.mesa_nome}</span>
                                            `;
                                }
                                pedidoHtml += `
                                    </div>
                                    <div id="Tabela">
                                        <table class="w-full">
                                            <thead>
                                                <tr class="text-sm">
                                                    <th>QTD</th>
                                                    <th>PRODUTO</th>
                                                    <th>VALOR</th>
                                                </tr>
                                            </thead>
                                            <tbody>`;

                                // Itere sobre os produtos inseridos e crie linhas da tabela
                                $.each(produtosInseridos, function(prodIndex, produto) {
                                    pedidoHtml += `
                                <tr>
                                    <td class="text-xs font-bold text-center">${produto.pivot.item_pedido_quantidade}</td>
                                    <td class="text-xs text-center uppercase">${produto.produto_descricao}</td>
                                    <td class="text-xs font-bold text-right">R$ ${parseFloat(produto.pivot.item_pedido_valor).toFixed(2)}</td>
                                </tr>`;
                                });

                                pedidoHtml += `
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <div class="w-full">
                                    <button class="btn-avanca-pronto w-full text-center border rounded-lg hover:shadow bg-blue-300 hover:bg-blue-400" data-pedido_id="${pedido.id}">Avançar Pedido <i class='bx bx-right-arrow-alt'></i></button>
                                </div>
                            </div>
                        `;

                                // Adicione o HTML do pedido ao container
                                $('.pedidos-preparando').append(pedidoHtml);
                            }

                        });
                        //Avança pedido selecionado para pronto
                        $(".btn-avanca-pronto").click(function(e) {
                            e.preventDefault();
                            const id = $(this).data('pedido_id');
                            $.ajax({
                                type: "POST",
                                url: "{{ route('avancar_pedido_pronto') }}",
                                data: {
                                    id,
                                    '_token': '{{ csrf_token() }}'
                                },
                                dataType: "JSON",
                                success: function(response) {
                                    listarPreparando();
                                    listarPronto();
                                },
                                error: function() {
                                    alert('Erro ao aceitar pedido');
                                }
                            });
                        });
                    } else {
                        // Se não houver pedidos, exiba uma mensagem indicando isso
                        $('.pedidos-preparando').html('<p class="p-2">Nenhum pedido encontrado</p>');
                    }
                },
                error: function() {
                    alert('Erro ao listar itens');
                }
            });
        }

        function listarPronto() {
            $.ajax({
                type: "GET",
                url: "{{ route('pedidos_pronto.lista') }}",
                data: {
                    '_token': '{{ csrf_token() }}'
                },
                dataType: "JSON",
                success: function(response) {
                    console.log(response);

                    $('.qtd-pronto').text(response.length);

                    // Limpe o conteúdo atual antes de adicionar os novos itens
                    $('.pedidos-pronto').empty();

                    // Verifique se há pedidos na resposta
                    if (response.length > 0) {
                        // Itere sobre cada pedido retornado na resposta

                        $.each(response, function(index, pedido) {
                            // Filtre os produtos inseridos no pedido com o status "INSERIDO"
                            var produtosInseridos = pedido.produtos_inseridos_pedido.filter(function(
                                produto) {
                                return produto.pivot.item_pedido_status === 'INSERIDO';
                            });

                            // Crie o HTML para o pedido e os produtos inseridos associados
                            if (produtosInseridos.length > 0) {
                                const pedidoDataHoraAbertura = new Date(pedido
                                    .pedido_datahora_abertura);
                                const hours = pedidoDataHoraAbertura.getHours().toString().padStart(2,
                                    '0');
                                const minutes = pedidoDataHoraAbertura.getMinutes().toString().padStart(
                                    2, '0');

                                const formattedTime = `${hours}:${minutes}`;
                                var pedidoHtml = `
                                    <div class="border p-1 rounded-lg w-full bg-blue-100">
                                        <div class="flex flex-row items-center justify-between">
                                            <div>
                                                <span class="font-bold">Pedido:</span>
                                                <span>${pedido.id}</span>
                                            </div>
                                            <div class="bg-gray-700 rounded-lg flex flex-row items-center p-1">
                                                <i class='bx bx-time-five text-white'></i>
                                                <span class="text-white">${formattedTime}</span>
                                            </div>
                                        </div>
                                    <div class="itens-pedido">
                                    <hr class="h-px my-1 border-0 bg-gray-300">
                                    <div class="flex flex-row items-center justify-between">
                                        <span class="">Itens</span>
                                        <span class="">${pedido.opcao_entrega.opcaoentrega_nome}</span>
                                        `;
                                if (pedido.sessao_mesa.id !== undefined) {
                                    pedidoHtml += `
                                                <span class="font-bold">${pedido.sessao_mesa.mesa.mesa_nome}</span>
                                            `;
                                }
                                pedidoHtml += `
                                    </div>
                                    `;
                                if (pedido.opcao_entrega.id === 3) {
                                    pedidoHtml += `<div class="text-center">
                                        <span class="font-semibold">${pedido.pedido_endereco_entrega}</span>
                                    </div>`;
                                }
                                pedidoHtml += `
                                    <div id="Tabela">
                                        <table class="w-full">
                                            <thead>
                                                <tr class="text-sm">
                                                    <th>QTD</th>
                                                    <th>PRODUTO</th>
                                                    <th>VALOR</th>
                                                </tr>
                                            </thead>
                                            <tbody>`;

                                // Itere sobre os produtos inseridos e crie linhas da tabela
                                $.each(produtosInseridos, function(prodIndex, produto) {
                                    pedidoHtml += `
                                <tr>`;
                                    if (produto.pivot.item_pedido_quantidade === 0.5) {
                                        pedidoHtml += `
                                    <td class="text-xs font-bold text-center">Meia</td>`;
                                    } else {
                                        pedidoHtml +=
                                            `
                                    <td class="text-xs font-bold text-center">${produto.pivot.item_pedido_quantidade}</td>`;
                                    }
                                    pedidoHtml += `
                                    <td class="text-xs text-center uppercase">${produto.produto_descricao}</td>
                                    <td class="text-xs font-bold text-right">R$ ${parseFloat(produto.pivot.item_pedido_valor).toFixed(2)}</td>
                                </tr>`;
                                });

                                if (pedido.opcao_entrega.id === 3) {
                                    pedidoHtml += `
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <div class="w-full">
                                    <button class="btn-avanca-transporte w-full text-center border rounded-lg hover:shadow bg-green-300 hover:bg-green-400" data-pedido_id="${pedido.id}">Entregar Pedido <i class='bx bx-trip bx-tada' ></i></button>
                                </div>
                            </div>
                            `;
                                } else if (pedido.sessao_mesa.id !== undefined) {
                                    pedidoHtml += `
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <div class="w-full">
                                    <button class="btn-avanca-entregue w-full text-center border rounded-lg hover:shadow bg-green-300 hover:bg-green-400" data-pedido_id="${pedido.id}">Levar para ${pedido.sessao_mesa.mesa.mesa_nome} <i class='bx bx-right-arrow-alt'></i></button>
                                </div>
                            </div>
                        `;

                                } else {
                                    pedidoHtml += `
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <div class="w-full">
                                    <button class="btn-avanca-entregue w-full text-center border rounded-lg hover:shadow bg-green-300 hover:bg-green-400" data-pedido_id="${pedido.id}">Avançar Pedido <i class='bx bx-right-arrow-alt'></i></button>
                                </div>
                            </div>
                        `;
                                }
                                // Adicione o HTML do pedido ao container
                                $('.pedidos-pronto').append(pedidoHtml);
                            }

                        });
                        //Avança pedido selecionado para pronto
                        $(".btn-avanca-entregue").click(function(e) {
                            e.preventDefault();
                            const id = $(this).data('pedido_id');
                            $.ajax({
                                type: "POST",
                                url: "{{ route('avancar_pedido_entregue') }}",
                                data: {
                                    id,
                                    '_token': '{{ csrf_token() }}'
                                },
                                dataType: "JSON",
                                success: function(response) {
                                    listarEntregue();
                                    listarPronto();
                                },
                                error: function() {
                                    alert('Erro ao aceitar pedido');
                                }
                            });
                        });
                        //Avança pedido selecionado para transporte
                        $(".btn-avanca-transporte").click(function(e) {
                            e.preventDefault();
                            const id = $(this).data('pedido_id');
                            $.ajax({
                                type: "POST",
                                url: "{{ route('avancar_pedido_transporte') }}",
                                data: {
                                    id,
                                    '_token': '{{ csrf_token() }}'
                                },
                                dataType: "JSON",
                                success: function(response) {
                                    listarPronto();
                                    listarTransporte();
                                },
                                error: function() {
                                    alert('Erro ao aceitar pedido');
                                }
                            });
                        });
                    } else {
                        // Se não houver pedidos, exiba uma mensagem indicando isso
                        $('.pedidos-pronto').html('<p class="p-2">Nenhum pedido encontrado</p>');
                    }
                },
                error: function() {
                    alert('Erro ao listar itens');
                }
            });
        }

        function listarTransporte() {
            $.ajax({
                type: "GET",
                url: "{{ route('pedidos_transporte.lista') }}",
                data: {
                    '_token': '{{ csrf_token() }}'
                },
                dataType: "JSON",
                success: function(response) {

                    $('.qtd-transporte').text(response.length);

                    // Limpe o conteúdo atual antes de adicionar os novos itens
                    $('.pedidos-transporte').empty();

                    // Verifique se há pedidos na resposta
                    if (response.length > 0) {
                        // Itere sobre cada pedido retornado na resposta

                        $.each(response, function(index, pedido) {
                            // Filtre os produtos inseridos no pedido com o status "INSERIDO"
                            var produtosInseridos = pedido.produtos_inseridos_pedido.filter(function(
                                produto) {
                                return produto.pivot.item_pedido_status === 'INSERIDO';
                            });

                            // Crie o HTML para o pedido e os produtos inseridos associados
                            if (produtosInseridos.length > 0) {
                                const pedidoDataHoraAbertura = new Date(pedido
                                    .pedido_datahora_abertura);
                                const hours = pedidoDataHoraAbertura.getHours().toString().padStart(2,
                                    '0');
                                const minutes = pedidoDataHoraAbertura.getMinutes().toString().padStart(
                                    2, '0');

                                const formattedTime = `${hours}:${minutes}`;
                                var pedidoHtml = `
                                    <div class="border p-1 rounded-lg w-full bg-green-100">
                                        <div class="flex flex-row items-center justify-between">
                                            <div>
                                                <span class="font-bold">Pedido:</span>
                                                <span>${pedido.id}</span>
                                            </div>
                                            <div class="bg-gray-700 rounded-lg flex flex-row items-center p-1">
                                                <i class='bx bx-time-five text-white'></i>
                                                <span class="text-white">${formattedTime}</span>
                                            </div>
                                        </div>
                                    <div class="itens-pedido">
                                    <hr class="h-px my-1 border-0 bg-gray-300">
                                    <div class="flex flex-row items-center justify-between">
                                        <span class="">Itens</span>
                                        <span class="">${pedido.opcao_entrega.opcaoentrega_nome}</span>
                                        `;
                                if (pedido.sessao_mesa.id !== undefined) {
                                    pedidoHtml += `
                                                <span class="font-bold">${pedido.sessao_mesa.mesa.mesa_nome}</span>
                                            `;
                                }
                                pedidoHtml += `
                                    </div>
                                    `;
                                if (pedido.opcao_entrega.id === 3) {
                                    pedidoHtml += `<div class="text-center">
                                        <span class="font-semibold">${pedido.pedido_endereco_entrega}</span>
                                    </div>`;
                                }
                                pedidoHtml += `
                                    <div id="Tabela">
                                        <table class="w-full">
                                            <thead>
                                                <tr class="text-sm">
                                                    <th>QTD</th>
                                                    <th>PRODUTO</th>
                                                    <th>VALOR</th>
                                                </tr>
                                            </thead>
                                            <tbody>`;

                                // Itere sobre os produtos inseridos e crie linhas da tabela
                                $.each(produtosInseridos, function(prodIndex, produto) {
                                    pedidoHtml += `
                                <tr>`;
                                    if (produto.pivot.item_pedido_quantidade === 0.5) {
                                        pedidoHtml += `
                                    <td class="text-xs font-bold text-center">Meia</td>`;
                                    } else {
                                        pedidoHtml +=
                                            `
                                    <td class="text-xs font-bold text-center">${produto.pivot.item_pedido_quantidade}</td>`;
                                    }
                                    pedidoHtml += `
                                    <td class="text-xs text-center uppercase">${produto.produto_descricao}</td>
                                    <td class="text-xs font-bold text-right">R$ ${parseFloat(produto.pivot.item_pedido_valor).toFixed(2)}</td>
                                </tr>`;
                                });
                                    pedidoHtml += `
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <div class="w-full">
                                    <button class="btn-avanca-entregue w-full text-center border rounded-lg hover:shadow bg-green-300 hover:bg-green-400" data-pedido_id="${pedido.id}">Pedido Entrgue <i class='bx bx-right-arrow-alt'></i></button>
                                </div>
                            </div>
                        `;
                                // Adicione o HTML do pedido ao container
                                $('.pedidos-transporte').append(pedidoHtml);
                            }

                        });
                        //Avança pedido selecionado para pronto
                        $(".btn-avanca-entregue").click(function(e) {
                            e.preventDefault();
                            const id = $(this).data('pedido_id');
                            $.ajax({
                                type: "POST",
                                url: "{{ route('avancar_pedido_entregue') }}",
                                data: {
                                    id,
                                    '_token': '{{ csrf_token() }}'
                                },
                                dataType: "JSON",
                                success: function(response) {
                                    listarTransporte();
                                    listarEntregue();
                                },
                                error: function() {
                                    alert('Erro ao aceitar pedido');
                                }
                            });
                        });
                    } else {
                        // Se não houver pedidos, exiba uma mensagem indicando isso
                        $('.pedidos-transporte').html('<p class="p-2">Nenhum pedido encontrado</p>');
                    }
                },
                error: function() {
                    alert('Erro ao listar itens');
                }
            });
        }

        function listarEntregue() {
            $.ajax({
                type: "GET",
                url: "{{ route('pedidos_entregue.lista') }}",
                data: {
                    '_token': '{{ csrf_token() }}'
                },
                dataType: "JSON",
                success: function(response) {
                    console.log(response);

                    $('.qtd-entregue').text(response.length);

                    // Limpe o conteúdo atual antes de adicionar os novos itens
                    $('.pedidos-entregue').empty();

                    // Verifique se há pedidos na resposta
                    if (response.length > 0) {
                        // Itere sobre cada pedido retornado na resposta

                        $.each(response, function(index, pedido) {
                            // Filtre os produtos inseridos no pedido com o status "INSERIDO"
                            var produtosInseridos = pedido.produtos_inseridos_pedido.filter(function(
                                produto) {
                                return produto.pivot.item_pedido_status === 'INSERIDO';
                            });

                            // Crie o HTML para o pedido e os produtos inseridos associados
                            if (produtosInseridos.length > 0) {
                                const pedidoDataHoraAbertura = new Date(pedido
                                    .pedido_datahora_abertura);
                                const hours = pedidoDataHoraAbertura.getHours().toString().padStart(2,
                                    '0');
                                const minutes = pedidoDataHoraAbertura.getMinutes().toString().padStart(
                                    2, '0');

                                const formattedTime = `${hours}:${minutes}`;
                                var pedidoHtml = `
                                    <div class="border p-1 rounded-lg w-full bg-white">
                                        <div class="flex flex-row items-center justify-between">
                                            <div>
                                                <span class="font-bold">Pedido:</span>
                                                <span>${pedido.id}</span>
                                            </div>
                                            <div class="bg-gray-700 rounded-lg flex flex-row items-center p-1">
                                                <i class='bx bx-time-five text-white'></i>
                                                <span class="text-white">${formattedTime}</span>
                                            </div>
                                        </div>
                                    <div class="itens-pedido">
                                    <hr class="h-px my-1 border-0 bg-gray-300">
                                    <div class="flex flex-row items-center justify-between">
                                        <span class="">Itens</span>
                                        <span class="">${pedido.opcao_entrega.opcaoentrega_nome}</span>
                                        `;
                                if (pedido.sessao_mesa.id !== undefined) {
                                    pedidoHtml += `
                                                <span class="font-bold">${pedido.sessao_mesa.mesa.mesa_nome}</span>
                                            `;
                                }
                                pedidoHtml += `
                                    </div>
                                    `;
                                if (pedido.opcao_entrega.id === 3) {
                                    pedidoHtml += `<div class="text-center">
                                        <span class="font-semibold">${pedido.pedido_endereco_entrega}</span>
                                    </div>`;
                                }
                                pedidoHtml += `
                                    <div id="Tabela">
                                        <table class="w-full">
                                            <thead>
                                                <tr class="text-sm">
                                                    <th>QTD</th>
                                                    <th>PRODUTO</th>
                                                    <th>VALOR</th>
                                                </tr>
                                            </thead>
                                            <tbody>`;

                                // Itere sobre os produtos inseridos e crie linhas da tabela
                                $.each(produtosInseridos, function(prodIndex, produto) {
                                    pedidoHtml += `
                                <tr>`;
                                    if (produto.pivot.item_pedido_quantidade === 0.5) {
                                        pedidoHtml += `
                                    <td class="text-xs font-bold text-center">Meia</td>`;
                                    } else {
                                        pedidoHtml +=
                                            `
                                    <td class="text-xs font-bold text-center">${produto.pivot.item_pedido_quantidade}</td>`;
                                    }
                                    pedidoHtml += `
                                    <td class="text-xs text-center uppercase">${produto.produto_descricao}</td>
                                    <td class="text-xs font-bold text-right">R$ ${parseFloat(produto.pivot.item_pedido_valor).toFixed(2)}</td>
                                </tr>`;
                                });
                                    pedidoHtml += `
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            `;


                                // Adicione o HTML do pedido ao container
                                $('.pedidos-entregue').append(pedidoHtml);
                            }

                        });
                        
                    } else {
                        // Se não houver pedidos, exiba uma mensagem indicando isso
                        $('.pedidos-entregue').html('<p class="p-2">Nenhum pedido encontrado</p>');
                    }
                },
                error: function() {
                    alert('Erro ao listar itens');
                }
            });
        }
    </script>
</x-app-layout>
