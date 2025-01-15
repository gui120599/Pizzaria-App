<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('Abrir nova sessão para ' . $mesa->mesa_nome) }}
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            {{ __('Insira os dados para abrir a sessão da mesa.') }}
        </p>
    </header>

    <form action="{{ route('sessaoMesa.abrir') }}" method="post" class="mt-6 space-y-6" enctype="multipart/form-data">
        @csrf
        <div
            class="sm:col-span-4 lg:col-span-3 col-span-6 relative md:space-y-2 md:border-x md:px-3 border-t pt-1 md:pt-0 pb-1 md:pb-0 md:border-t-0 border-b md:border-b-0">
            {{-- MESA ID --}}
            <p class="flex items-center gap-x-2 text-sm font-bold text-teal-700">
                <i class='bx bxs-face'></i>
                <span>{{ __('MESA') }}</span>
            </p>
            <x-text-input id="sessao_mesa_mesa_id" name="sessao_mesa_mesa_id" type="text" class="mt-1 w-full"
                value="{{ $mesa->id }}" autocomplete="off" hidden />
            <x-text-input id="sessao_mesa_mesa_nome" name="sessao_mesa_mesa_nome" type="text" class="mt-1 w-full"
                value="{{ $mesa->mesa_nome }}" autocomplete="off" readonly />
            <x-text-input id="sessao_mesa_status" name="sessao_mesa_status" type="text" class="mt-1 w-full"
                value="ABERTA" autocomplete="off" readonly />
            {{-- GARÇOM ID --}}
            <p class="flex items-center gap-x-2 text-sm font-bold text-teal-700">
                <i class='bx bxs-face'></i>
                <span>{{ __('Garçom') }}</span>
            </p>
            <x-text-input id="sessao_mesa_usuario_id" name="sessao_mesa_usuario_id" type="text" class="mt-1 w-full"
                value="{{ Auth::user()->id }}" autocomplete="off" hidden />
            <x-text-input id="sessao_mesa_usuario_nome" name="sessao_mesa_usuario_nome" type="text" class="mt-1 w-full"
                value="{{ Auth::user()->name }}" autocomplete="off" readonly />
            {{-- CLIENTE ID --}}
            <p class="flex items-center gap-x-2 text-sm font-bold text-teal-700">
                <i class='bx bx-user'></i>
                <span>{{ __('Cliente') }}</span>
            </p>
            <x-text-input id="sessao_mesa_cliente_id" name="sessao_mesa_cliente_id" type="text" class="mt-1 w-full"
                autocomplete="off" hidden />
            <x-text-input id="sessao_mesa_cliente_nome" name="sessao_mesa_cliente_nome" type="text" class="mt-1 w-full"
                autocomplete="off" placeholder="Nome do Cliente" />
            <x-input-error :messages="$errors->updatePassword->get('sessao_mesa_cliente_id')" class="mt-2" />
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
        </div>

        <x-primary-button>
            {{ __('Abrir Sessão ' . $mesa->mesa_nome) }}
        </x-primary-button>

    </form>
    <script>
        function selecionarCliente(id, nome) {
            document.getElementById("sessao_mesa_cliente_id").value = id;
            document.getElementById("sessao_mesa_cliente_nome").value = nome;
            console.log(id + ' - ' + nome);
        }
        document.addEventListener('DOMContentLoaded', function() {


            const inputCliente = document.getElementById('sessao_mesa_cliente_nome');
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
</section>
