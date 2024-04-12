<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('Novo Pedido') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            {{ __('Insira os dados para um novo pedido.') }}
        </p>
    </header>

    <form action="{{ route('pedido.store') }}" method="post" class="space-y-6" enctype="multipart/form-data">
        @csrf
        <div class="grid grid-cols-1 gap-x-4 gap-y-2 md:grid-cols-6">
            <div class="md:col-span-full grid grid-cols-1 md:grid-cols-6 gap-x-4 gap-y-4">
                <div class="col-span-1 md:col-span-4 space-y-3">
                    <div class="md:col-span-2 relative">
                        <x-input-label for="pedido_cliente_id" :value="__('Cliente')" />
                        <x-text-input id="pedido_cliente_id" name="pedido_cliente_id" type="text" class="mt-1 w-full"
                            autocomplete="off" hidden />
                        <x-text-input id="pedido_cliente_nome" name="pedido_cliente_nome" type="text"
                            class="mt-1 w-full" autocomplete="off" />
                        <x-input-error :messages="$errors->updatePassword->get('pedido_cliente_id')" class="mt-2" />
                        <div id="lista_clientes"
                            class="absolute w-full bg-white rounded-lg px-2 py-3 shadow-lg shadow-green-400/10 hidden overflow-auto max-h-96 border">
                            @foreach ($clientes as $cliente)
                                <div id="linha_cliente"
                                    class="border-b-2 hover:bg-teal-700 hover:text-white rounded-lg p-2 cursor-pointer transition duration-150 ease-in-out"
                                    onclick="selecionarCliente({{ $cliente->id }},'{{ $cliente->cliente_nome }}')">
                                    {{ $cliente->id }} - {{ $cliente->cliente_nome }}
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="md:col-span-3 grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-4">

                    </div>
                </div>
            </div>
        </div>

        <x-primary-button>
            {{ __('Cadastrar Novo Pedido') }}
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
        });
    </script>


</section>
