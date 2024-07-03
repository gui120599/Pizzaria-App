<x-app-layout>
    <div class="mx-auto p-2 ">
        <form action="{{ route('venda.store') }}" method="post" class="w-full h-full" enctype="multipart/form-data">
            @csrf
            <div class="p-2 min-h-[97vh] bg-white shadow-sm rounded-lg flex">
                <!-- Ícone de carregamento e mensagem -->
                <div id="carregando"
                    class="hidden absolute inset-0 flex justify-center items-center bg-slate-600 bg-opacity-50 transition duration-150 ease-in-out">
                    <div class="text-center">
                        <i class='bx bx-loader-circle bx-spin bx-rotate-90 text-5xl'></i>
                        <p>Carregando Produtos</p>
                    </div>
                </div>
                <div class="w-[60%] mx-auto pe-2 flex flex-col">
                    <nav class="bg-transparent border-b border-gray-100">
                        <!-- Primary Navigation Menu -->
                        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                            <div class="flex justify-center h-8">
                                <!-- Navigation Links -->
                                <div class="space-x-8 sm:ms-10 flex ">

                                    <div class="nav-link active cursor-pointer inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-light leading-5 text-gray-500 hover:border-white focus:outline-none focus:text-white focus:border-white transition duration-150 ease-in-out"
                                        data-section="dados-section">
                                        <i class='bx bxs-receipt me-2'></i>
                                        {{ __('Dados') }}
                                    </div>

                                    <div class="nav-link cursor-pointer inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-light leading-5 text-gray-500 hover:border-white focus:outline-none focus:text-white focus:border-white transition duration-150 ease-in-out"
                                        data-section="mesas-section">
                                        <div class="flex items-center">
                                            <i class='bx bx-chair me-2'></i>
                                            {{ __('Mesas') }}
                                        </div>
                                    </div>

                                    <div class="nav-link cursor-pointer inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-light leading-5 text-gray-500 hover:border-white focus:outline-none focus:text-white focus:border-white transition duration-150 ease-in-out"
                                        data-section="pedidos-section">
                                        <div class="flex items-center">
                                            <i class="bx bx-basket me-2"></i>
                                            {{ __('Pedidos') }}
                                        </div>
                                    </div>

                                    <div class="nav-link cursor-pointer inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-light leading-5 text-gray-500 hover:border-white focus:outline-none focus:text-white focus:border-white transition duration-150 ease-in-out"
                                        data-section="produtos-section">
                                        <i class='bx bxs-pizza me-2'></i>
                                        {{ __('Produtos') }}
                                    </div>

                                </div>
                            </div>
                        </div>
                    </nav>
                    <div class="w-full h-[82dvh] overflow-auto">
                        <div class="dados-section secao">
                            <div class="grid grid-cols-1 md:grid-cols-6 gap-x-2 gap-y-4 p-1">
                                {{-- Dados do Caixa --}}
                                <div class="md:col-span-1">
                                    <x-input-label for="venda_id" :value="__('Cód. Venda')" />
                                    <x-text-input id="venda_id" name="venda_id" type="text" class="mt-1 w-full"
                                        autocomplete="off" value="{{ old('venda_id') }}" />
                                    <x-input-error :messages="$errors->updatePassword->get('venda_id')" class="mt-2" />
                                </div>
                                <div class="md:col-span-1">
                                    <x-input-label for="venda_sessao_caixa_id" :value="__('Cód. Sessão do Caixa')" />
                                    <x-text-input id="venda_sessao_caixa_id" name="venda_sessao_caixa_id" type="text"
                                        class="mt-1 w-full" autocomplete="off"
                                        value="{{ old('venda_sessao_caixa_id') }}" />
                                    <x-input-error :messages="$errors->updatePassword->get('venda_sessao_caixa_id')" class="mt-2" />
                                </div>
                                <div class="md:col-span-1">
                                    <x-input-label for="caixa_nome" :value="__('Caixa')" />
                                    <x-text-input id="caixa_nome" name="caixa_nome" type="text" class="mt-1 w-full"
                                        autocomplete="off" value="{{ old('caixa_nome') }}" />
                                    <x-input-error :messages="$errors->updatePassword->get('caixa_nome')" class="mt-2" />
                                </div>
                                <div class="md:col-span-3">
                                    <x-input-label for="sessao_caixa_funcionario_id" :value="__('Funcionário')" />
                                    <x-text-input id="sessao_caixa_funcionario_id" name="sessao_caixa_funcionario_id"
                                        type="text" class="mt-1 w-full" autocomplete="off"
                                        value="{{ old('sessao_caixa_funcionario_id') }}" />
                                    <x-input-error :messages="$errors->updatePassword->get('sessao_caixa_funcionario_id')" class="mt-2" />
                                </div>

                                {{-- Dados do Cliente --}}
                                <div class="col-span-full">
                                    <hr class="h-px my-1 border-0 bg-gray-200">
                                    <h2 class="flex items-center gap-x-2 text-lg font-bold text-teal-700">
                                        <i class='bx bx-user'></i>
                                        <span>{{ __('Dados Cliente') }}</span>
                                    </h2>
                                </div>
                                <div class="relative md:col-span-full">
                                    <x-text-input id="venda_cliente_id" name="venda_cliente_id" type="text"
                                        class="mt-1 w-full" hidden />
                                    <x-text-input id="venda_cliente_nome" name="venda_cliente_nome" class="mt-1 w-full" placeholder autocomplete="off"></x-text-input>
                                    
                                    <div id="lista_clientes"
                                        class="absolute w-full bg-white rounded-lg px-2 py-3 shadow-lg shadow-green-400/10 hidden overflow-auto max-h-96 md:max-h-80 lg:max-h-72 border">
                                        @foreach ($clientes as $cliente)
                                            <div id="linha_cliente"
                                                class="border-b-2 hover:bg-teal-700 hover:text-white rounded-lg p-2 cursor-pointer transition duration-150 ease-in-out"
                                                onclick="selecionarCliente({{ $cliente }})">
                                                {{ $cliente->id }} - {{ $cliente->cliente_nome }}
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                                <div class="md:col-span-1">
                                    <x-input-label for="venda_cliente_cpf" :value="__('CPF Cliente')" />
                                    <x-text-input id="venda_cliente_cpf" name="venda_cliente_cpf" type="text"
                                        class="cpf mt-1 w-full" autocomplete="off"
                                        value="{{ old('venda_cliente_cpf') }}" placeholder="000.000.000-00" />
                                    <x-input-error :messages="$errors->updatePassword->get('venda_cliente_cpf')" class="mt-2" />
                                </div>
                                <div class="md:col-span-2">
                                    <x-input-label for="venda_cliente_telefone" :value="__('Telefone Cliente')" />
                                    <x-text-input id="venda_cliente_telefone" name="venda_cliente_telefone"
                                        type="text" class="phone_ddd mt-1 w-full" autocomplete="off"
                                        value="{{ old('venda_cliente_telefone') }}" placeholder="(00)9 0000-0000" />
                                    <x-input-error :messages="$errors->updatePassword->get('venda_cliente_telefone')" class="mt-2" />
                                </div>
                                <div class="md:col-span-3">
                                    <x-input-label for="venda_cliente_email" :value="__('Email Cliente')" />
                                    <x-text-input id="venda_cliente_email" name="venda_cliente_email" type="Email"
                                        class="mt-1 w-full" autocomplete="off"
                                        value="{{ old('venda_cliente_email') }}" placeholder="exemplo@gmail.com" />
                                    <x-input-error :messages="$errors->updatePassword->get('venda_cliente_email')" class="mt-2" />
                                </div>
                                <div class="md:col-span-full grid grid-cols-1 md:grid-cols-6 gap-x-4 gap-y-4">
                                    <div class="lg:col-span-6 md:col-span-6">
                                        <x-input-label for="venda_cliente_endereco" :value="__('Endereço')" />
                                        <x-text-input id="venda_cliente_endereco" name="venda_cliente_endereco"
                                            type="text" class="mt-1 w-full" autocomplete="off"
                                            value="{{ old('venda_cliente_endereco') }}" />
                                        <x-input-error :messages="$errors->get('venda_cliente_endereco')" class="mt-2" />
                                    </div>

                                    <div class="lg:col-span-1 md:col-span-3">
                                        <x-input-label for="cliente_bairro" :value="__('Bairro')" />
                                        <x-text-input id="cliente_bairro" name="cliente_bairro" type="text"
                                            class="cep mt-1 w-full" autocomplete="off"
                                            value="{{ old('cliente_bairro') }}" />
                                        <x-input-error :messages="$errors->get('cliente_bairro')" class="mt-2" />
                                    </div>

                                    <div class="lg:col-span-1 md:col-span-3">
                                        <x-input-label for="cliente_cidade" :value="__('Cidade')" />
                                        <x-text-input id="cliente_cidade" name="cliente_cidade" type="text"
                                            class="mt-1 w-full" autocomplete="off"
                                            value="{{ old('cliente_cidade') }}" />
                                        <x-input-error :messages="$errors->get('cliente_cidade')" class="mt-2" />
                                    </div>

                                    <div class="lg:col-span-1 md:col-span-3">
                                        <x-input-label for="cliente_estado" :value="__('Estado')" />
                                        <x-text-input id="cliente_estado" name="cliente_estado" type="text"
                                            class="mt-1 w-full" autocomplete="off"
                                            value="{{ old('cliente_estado') }}" />
                                        <x-input-error :messages="$errors->get('cliente_estado')" class="mt-2" />
                                    </div>

                                    <div class="lg:col-span-1 md:col-span-3">
                                        <x-input-label for="cliente_cep" :value="__('CEP')" />
                                        <x-text-input id="cliente_cep" name="cliente_cep" type="text"
                                            class="cep mt-1 w-full" autocomplete="off"
                                            value="{{ old('cliente_cep') }}" />
                                        <x-input-error :messages="$errors->get('cliente_cep')" class="mt-2" />
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="mesas-section secao">
                            <p>Mesas com Pedidos</p>
                            @foreach ($sessaoMesas as $sessaoMesa)
                                <div class="w-full border border-gray-200 p-3 my-2 rounded-lg">
                                    <div class="flex justify-between">
                                        <div class="flex space-x-2 justify-between">
                                            <input type="checkbox" name="id_sessao_mesa[]"
                                                id="mesa_id_{{ $sessaoMesa->mesa->id }}"
                                                value="{{ $sessaoMesa->id }}" />
                                            <x-input-label class="text-sm"
                                                for="mesa_id_{{ $sessaoMesa->mesa->id }}">{{ $sessaoMesa->mesa->mesa_nome }}</x-text-input>
                                        </div>
                                        <span data-mesa_id="{{ $sessaoMesa->mesa->id }}"
                                            class="toogle_mesa bx bx-chevron-up col-span-6 p-1 hover:bg-slate-400 cursor-pointer rotate-180 rounded-full transition duration-300 ease-in-out ">
                                        </span>
                                    </div>
                                    @php
                                        $itensAgrupados = [];

                                        // Agrupa itens de todos os pedidos por mesa
                                        foreach ($sessaoMesa->pedidos as $pedido) {
                                            foreach ($pedido->item_pedido_pedido_id as $item) {
                                                $produtoId = $item->item_pedido_produto_id;

                                                if (!isset($itensAgrupados[$produtoId])) {
                                                    $itensAgrupados[$produtoId] = [
                                                        'produto' => $item->produto,
                                                        'total_quantidade' => 0,
                                                        'total_desconto' => 0,
                                                        'total_valor' => 0,
                                                    ];
                                                }

                                                $itensAgrupados[$produtoId]['total_quantidade'] +=
                                                    $item->item_pedido_quantidade;
                                                $itensAgrupados[$produtoId]['total_desconto'] +=
                                                    $item->item_pedido_desconto;
                                                $itensAgrupados[$produtoId]['total_valor'] += $item->item_pedido_valor;
                                            }
                                        }
                                    @endphp

                                    <div class="flex justify-between">
                                        <span>
                                            Pedidos:
                                            @foreach ($sessaoMesa->pedidos as $pedido)
                                                @if ($loop->last)
                                                    <!-- Este é o último elemento -->
                                                    {{ $pedido->id }}
                                                @else
                                                    {{ $pedido->id }} -
                                                @endif
                                            @endforeach
                                        </span>

                                    </div>

                                    <table id="table_mesa_{{ $sessaoMesa->mesa->id }}"
                                        class="w-full text-center text-[7px] md:text-base hidden">
                                        <thead>
                                            <tr class="border-b-4">
                                                <th class="px-1 md:px-4">#</th>
                                                <th class="px-1 md:px-4">Produto</th>
                                                <th class="px-1 md:px-4">Qtd</th>
                                                <th class="px-1 md:px-4">Valor Unt.</th>
                                                <th class="px-1 md:px-4">Valor Desc.</th>
                                                <th class="px-1 md:px-4">Valor Total R$</th>
                                                <th class="px-1 md:px-4">Opções</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($itensAgrupados as $produtoId => $item)
                                                <tr>
                                                    <td>{{ $item['produto']->id }}</td>
                                                    <td>{{ $item['produto']->produto_descricao }}</td>
                                                    <td>{{ $item['total_quantidade'] }}</td>
                                                    <td>{{ number_format($item['produto']->produto_preco_venda, 2, ',', '.') }}</td>
                                                    <td>{{ number_format($item['total_desconto'], 2, ',', '.') }}</td>
                                                    <td>{{ number_format($item['total_valor'], 2, ',', '.') }}</td>
                                                    <td></td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endforeach
                        </div>
                        <div class="produtos-section secao">
                            <p>Produtos</p>
                            @foreach ($categorias as $categoria)
                                <div class="mb-4" id="categoria_{{ $categoria->id }}">
                                    <h2 class="text-white text-lg font-bold">{{ $categoria->categoria_nome }}</h2>
                                    @if ($categoria->produtos->isEmpty())
                                        <p class="text-gray-400">Não há produtos disponíveis nesta categoria.</p>
                                    @else
                                        <div
                                            class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-2 lg:grid-cols-6 gap-4 ">
                                            @foreach ($categoria->produtos as $produto)
                                                <div class="relative snap-end ">
                                                    <div
                                                        class="w-full bg-gray-100 p-2 rounded-lg flex items-start justify-between opacity-95 hover:opacity-100 gap-1">
                                                        <div class="w-2/5">
                                                            @if ($produto->produto_foto)
                                                                <img src="{{ asset('img/fotos_produtos/' . $produto->produto_foto) }}"
                                                                    alt="{{ $produto->produtso_descricao }}"
                                                                    class="h-14 object-cover rounded-lg ">
                                                            @else
                                                                <img id="imagem-preview"
                                                                    class="h-14 object-cover rounded-lg "
                                                                    src="{{ asset('Sem Imagem.png') }}"
                                                                    alt="Imagem Padrão">
                                                            @endif
                                                        </div>
                                                        <div class="w-3/5 flex flex-col justify-center">
                                                            <h2 class="text-gray-900 text-[8px] uppercase">
                                                                @if (isset($produto->produto_referencia) && $produto->produto_referencia !== null)
                                                                    {{ $produto->produto_descricao }} - <span>Ref.
                                                                        {{ $produto->produto_referencia }}</span>
                                                                @else
                                                                    {{ $produto->produto_descricao }}
                                                                @endif
                                                            </h2>
                                                            <div class="flex items-center">
                                                                <span
                                                                    class="text-gray-900 text-sm font-bold">R${{ str_replace('.', ',', $produto->produto_preco_venda) }}</span>
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
                        <div class="pedidos-section secao">
                            <p>Pedidos Avulsos</p>
                            @foreach ($pedidos as $pedido)
                                @if ($pedido->pedido_sessao_mesa_id == null || $pedido->pedido_sessao_mesa_id == '')
                                    <div class="w-full border border-gray-200 p-3 my-2 rounded-lg">
                                        <div class="flex justify-between">
                                            <div>
                                                <input type="checkbox" name="pedido_{{ $pedido->id }}"
                                                    id="pedido_{{ $pedido->id }}"
                                                    class="cursor-pointer check_ pedido">
                                                <label for="pedido_{{ $pedido->id }}">Pedido:
                                                    {{ $pedido->id }}</label>
                                            </div>

                                            <span data-pedido_id="{{ $pedido->id }}"
                                                class="toogle_pedido bx bx-chevron-up col-span-6 p-1 hover:bg-slate-400 cursor-pointer rotate-180 rounded-full transition duration-300 ease-in-out ">
                                            </span>
                                        </div>
                                        @php
                                            $itensAgrupados = [];

                                            // Agrupa itens de todos os pedidos por mesa
                                            foreach ($pedido->item_pedido_pedido_id as $item) {
                                                $produtoId = $item->item_pedido_produto_id;

                                                if (!isset($itensAgrupados[$produtoId])) {
                                                    $itensAgrupados[$produtoId] = [
                                                        'produto' => $item->produto,
                                                        'total_quantidade' => 0,
                                                        'total_desconto' => 0,
                                                        'total_valor' => 0,
                                                    ];
                                                }

                                                $itensAgrupados[$produtoId]['total_quantidade'] +=
                                                    $item->item_pedido_quantidade;
                                                $itensAgrupados[$produtoId]['total_desconto'] +=
                                                    $item->item_pedido_desconto;
                                                $itensAgrupados[$produtoId]['total_valor'] += $item->item_pedido_valor;
                                            }
                                        @endphp

                                        <table id="table_pedido_{{ $pedido->id }}"
                                            class="hidden w-full text-center text-[7px] md:text-base">
                                            <thead>
                                                <tr class="border-b-4">
                                                    <th class="px-1 md:px-4">#</th>
                                                    <th class="px-1 md:px-4">Produto</th>
                                                    <th class="px-1 md:px-4">Qtd</th>
                                                    <th class="px-1 md:px-4">Valor Desc.</th>
                                                    <th class="px-1 md:px-4">Valor Unt.</th>
                                                    <th class="px-1 md:px-4">Valor Total</th>
                                                    <th class="px-1 md:px-4">Opções</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach ($itensAgrupados as $produtoId => $item)
                                                    <tr>
                                                        <td>{{ $item['produto']->id }}</td>
                                                        <td>{{ $item['produto']->produto_descricao }}</td>
                                                        <td>{{ $item['total_quantidade'] }}</td>
                                                        <td>{{ $item['total_desconto'] }}</td>
                                                        <td>{{ $item['produto']->produto_preco_venda }}</td>
                                                        <td>{{ $item['total_valor'] }}</td>
                                                        <td></td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                    <div class="mt-2">
                        <x-primary-button>Salvar Venda</x-primary-button>
                    </div>
                </div>
                <div class="w-[20%] border border-gray-200 rounded-lg">
                    teset 2
                </div>
                <div class="w-[20%] h-[96vh] border mx-1 border-gray-200 rounded-lg">
                    <div class="p-1 h-[50%] bg-slate-500">

                    </div>
                    <div class="p-1 h-[50%]">
                        <div>
                            <x-input-label for="venda_valor_frete">{{ __('Valor Frete') }}</x-input-label>
                            <x-text-input id="venda_valor_frete" name="venda_valor_frete" class="money w-full h-[12vh] text-6xl" autocomplete="off"></x-input-text>
                        </div>
                        <div>
                            <x-input-label for="venda_valor_frete">{{ __('Valor Desconto') }}</x-input-label>
                            <x-text-input id="venda_valor_frete" name="venda_valor_frete" class="money w-full h-[12vh]"></x-input-text>
                        </div>
                        <div>
                            <x-input-label for="venda_valor_frete">{{ __('Valor Total') }}</x-input-label>
                            <x-text-input id="venda_valor_frete" name="venda_valor_frete" class="money w-full h-[12vh]"></x-input-text>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
    <style>
        .nav-link.active {
            /* Adicione estilos desejados para o link ativo aqui */
            color: rgb(20 184 166 / var(--tw-bg-opacity));
            /* Exemplo: altera a cor do texto para branco */
            border-bottom-color: rgb(20 184 166 / var(--tw-bg-opacity));
            /* Exemplo: altera a cor da borda inferior para branco */
            font-weight: bold;
        }
    </style>
    <script>
        function selecionarCliente(cliente) {
            document.getElementById("venda_cliente_id").value = cliente.id;
            document.getElementById("venda_cliente_nome").value = cliente.cliente_nome;
            document.getElementById("venda_cliente_telefone").value = cliente.cliente_celular;
            document.getElementById("venda_cliente_email").value = cliente.cliente_email;
            document.getElementById("venda_cliente_endereco").value = cliente.cliente_endereco;
            document.getElementById("venda_cliente_cpf").value = cliente.cliente_cpf;
            /*document.getElementById("venda_cliente_cpf").value = cliente.cliente_cpf;
            document.getElementById("venda_cliente_cpf").value = cliente.cliente_cpf;
            document.getElementById("venda_cliente_cpf").value = cliente.cliente_cpf;
            document.getElementById("venda_cliente_cpf").value = cliente.cliente_cpf;*/
            console.log(cliente);
        }

        document.addEventListener('DOMContentLoaded', function() {


            const inputCliente = document.getElementById('venda_cliente_nome');
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
            toggleSidebar();

            // Oculta todas as seções ao carregar a página
            $('.pedidos-section').hide();
            $('.produtos-section').hide();
            $('.mesas-section').hide();

            // Mostra a seção correspondente quando um link da navegação é clicado
            $('.nav-link').click(function() {
                var targetSection = $(this).data('section');
                //console.log(targetSection);

                // Oculta todas as seções e mostra apenas a correspondente
                $('.secao').hide();
                $('.' + targetSection).show();

                // Destaca visualmente o link ativo
                $('.nav-link').removeClass('active');
                $(this).addClass('active');
            });

            //Abre a table de itens da mesa
            $(".toogle_mesa").click(function(e) {
                e.preventDefault();
                const mesa_id = $(this).data('mesa_id');
                const tableMesa = $("#table_mesa_" + mesa_id);

                // Verifica se o item já está visível
                if (tableMesa.is(":visible")) {
                    // Se estiver visível, contrai o elemento com slideup
                    tableMesa.slideUp();
                    $(this).addClass('rotate-180');
                } else {
                    // Se não estiver visível, expande o elemento com slidedown
                    tableMesa.slideDown();
                    $(this).removeClass('rotate-180');
                }
            });

            //Abre a table dos itens do pedido
            $(".toogle_pedido").click(function(e) {
                e.preventDefault();
                const pedido_id = $(this).data('pedido_id');
                const tablePedido = $("#table_pedido_" + pedido_id);

                // Verifica se o item já está visível
                if (tablePedido.is(":visible")) {
                    // Se estiver visível, contrai o elemento com slideup
                    tablePedido.slideUp();
                    $(this).addClass('rotate-180');
                } else {
                    // Se não estiver visível, expande o elemento com slidedown
                    tablePedido.slideDown();
                    $(this).removeClass('rotate-180');
                }
            });

        });

        function toggleSidebar() {
            const BtnToggleSideBar = document.querySelector('.toggleSideBar');
            const sidebar = document.querySelector('.sidebar');
            const logo = document.querySelector('.logo');
            const navLinks = document.querySelectorAll('.sidebar span');

            BtnToggleSideBar.classList.toggle('rotate-180');
            sidebar.classList.toggle('sidebar-hidden');
            logo.classList.toggle('logo-hidden');
            navLinks.forEach(span => {
                span.classList.toggle('hidden');
            });
        }
    </script>
</x-app-layout>
