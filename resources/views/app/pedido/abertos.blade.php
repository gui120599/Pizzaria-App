<x-app-layout>
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
                    <div class="pedidos-preparando p-2 mx-auto space-y-1 h-[90vh] overflow-auto">

                    </div>
                </div>
                <div class="col-span-1 bg-blue-400">
                    <div class="px-2 py-1 flex justify-between bg-blue-500">
                        <span class="font-bold">Aguardando Entrega</span>
                        <span class="font-semibold">0</span>
                    </div>
                    <div class="pedidos-aguardando-entrega p-2 mx-auto space-y-1 h-[90vh] overflow-auto">
                    </div>
                </div>
                <div class="col-span-1 bg-green-400">
                    <div class="px-2 py-1 flex justify-between bg-green-500">
                        <span class="font-bold">Entregue</span>
                        <span class="font-semibold">0</span>
                    </div>
                    <div class="pedidos-entregues p-2 mx-auto space-y-1 h-[90vh] overflow-auto">

                    </div>
                </div>
                <div class="col-span-1 bg-green-700">
                    <div class="px-2 py-1 flex justify-between bg-green-900">
                        <span class="font-bold">Finalizado</span>
                        <span class="font-semibold">0</span>
                    </div>
                    <div class="pedidos-finalizados p-2 mx-auto space-y-1 h-[90vh] overflow-auto">

                    </div>
                </div>
            </div>
        </div>
    </div>
    <script type="module">
        function playAudio() {
            var audio = new Audio("{{ asset('level-up-191997.mp3') }}");
            audio.addEventListener('canplaythrough', function() {
                audio.play();
            });
        }
        $(".toggleSideBar").trigger("click");
        var qtd_aberto = null;
        listarAbertos();
        setInterval(() => {
            listarAbertos();
        }, 30000);

        function listarAbertos() {
            $.ajax({
                type: "GET",
                url: "{{ route('pedidos_abertos.lista') }}",
                data: {
                    '_token': '{{ csrf_token() }}'
                },
                dataType: "JSON",
                success: function(response) {
                    console.log(response);

                    $('.qtd-abertos').text(response.length);
                    if (qtd_aberto !== null && qtd_aberto < response.length) {
                        //alert('Qtd antiga: ' + qtd_aberto + '/n Qtd nova:' + response.length);
                        qtd_aberto = response.length;
                        playAudio();
                    } else if (qtd_aberto === null) {
                        qtd_aberto = response.length;
                    } else {
                        
                        //alert('Mesma qtd!');
                        
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
                                var pedidoHtml = `
                            <div class="border p-1 rounded-lg w-full bg-white mb-4">
                                <div class="flex flex-row justify-between">
                                    <div>
                                        <span class="font-bold">Pedido:</span>
                                        <span>${pedido.id}</span>
                                    </div>
                                    <div>
                                        <i class='bx bx-chevron-down'></i>
                                    </div>
                                </div>
                                <div class="itens-pedido">
                                    <hr class="h-px my-1 border-0 bg-gray-200">
                                    <span class="">Itens</span>
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
                                    <button class="w-full text-center border rounded-lg">Aceitar Pedido <i class='bx bx-right-arrow-alt'></i></button>
                                </div>
                            </div>
                        `;

                                // Adicione o HTML do pedido ao container
                                $('.pedidos-abertos').append(pedidoHtml);
                            }
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
    </script>
</x-app-layout>
