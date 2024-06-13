<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('Abrir nova sessão de caixa ') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            {{ __('Insira os dados para abrir a sessão da mesa.') }}
        </p>
    </header>

    <form action="{{ route('sessao_caixa.store') }}" method="post" class="mt-6 space-y-6" enctype="multipart/form-data">
        @csrf
        <div
            class="sm:col-span-4 lg:col-span-3 col-span-6 relative md:space-y-2 md:border-x md:px-3 border-t pt-1 md:pt-0 pb-1 md:pb-0 md:border-t-0 border-b md:border-b-0">
            
            <x-input-label for="sessaocaixa_caixa_id" :value="__('Caixa')" />
            <x-select-input :options="$caixas" value-field="id" display-field="caixa_nome" id="sessaocaixa_caixa_id"
                name="sessaocaixa_caixa_id" class="mt-1 w-full" />
            <x-input-error :messages="$errors->updatePassword->get('sessaocaixa_caixa_id')" class="mt-2" />

            <x-input-label for="sessaocaixa_usuario_id" :value="__('Funcionário')" />
            <x-text-input id="sessaocaixa_user_id" name="sessaocaixa_user_id" type="text" class="mt-1 w-full"
                autocomplete="off" hidden />
            <x-text-input id="sessaocaixa_usuario_nome" name="sessaocaixa_usuario_nome" type="text"
                class="mt-1 w-full" autocomplete="off" placeholder="Funcionário" />
            <x-input-error :messages="$errors->updatePassword->get('sessaocaixa_usuario_id')" class="mt-2" />
            <div id="lista_usuarios"
                class="z-40 absolute w-full bg-white rounded-lg px-2 py-3 shadow-lg shadow-green-400/10 hidden overflow-auto max-h-96 md:max-h-80 lg:max-h-72 border">
                @foreach ($usuarios as $user)
                    <div id="linha_usuarios"
                        class="border-b-2 hover:bg-teal-700 hover:text-white rounded-lg p-2 cursor-pointer transition duration-150 ease-in-out"
                        onclick="selecionarCliente({{ $user->id }},'{{ $user->name }}')">
                        {{ $user->id }} - {{ $user->name }}
                    </div>
                @endforeach
            </div>
            
            <x-input-label for="sessaocaixa_saldo_inicial" :value="__('Saldo Inicial')" />
            <x-money-input id="sessaocaixa_saldo_inicial" name="sessaocaixa_saldo_inicial" type="text" class="mt-1 w-full"
                autocomplete="off" />

            
            <x-input-label for="sessaocaixa_observacoes" :value="__('Observações')" />
            <x-text-area id="sessaocaixa_observacoes" name="sessaocaixa_observacoes" type="text" class="mt-1 w-full"
                autocomplete="off" />
        </div>

        <x-primary-button>
            {{ __('Abrir Sessão') }}
        </x-primary-button>

    </form>
    <script>
        function selecionarCliente(id, nome) {
            document.getElementById("sessaocaixa_user_id").value = id;
            document.getElementById("sessaocaixa_usuario_nome").value = nome;
            console.log(id + ' - ' + nome);
        }
        document.addEventListener('DOMContentLoaded', function() {


            const inputCliente = document.getElementById('sessaocaixa_usuario_nome');
            const listaClientes = document.getElementById('lista_usuarios');

            // Mostrar a lista de usuarios quando o campo de texto estiver focado
            inputCliente.addEventListener('focus', function() {
                listaClientes.classList.remove('hidden');
            });

            // Ocultar a lista de usuarios quando o campo de texto perder o foco
            inputCliente.addEventListener('blur', function() {
                setTimeout(() => {
                    listaClientes.classList.add('hidden');
                }, 200);

            });

            // Filtrar a lista de usuarios conforme o usuário digita
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
