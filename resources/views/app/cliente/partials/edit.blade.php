<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('Atualizar Cliente') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            {{ __('Atualize os dados do cliente selecionado.') }}
        </p>
    </header>

    <form action="{{ route('cliente.update', $cliente->id) }}" method="post" class="space-y-6"
        enctype="multipart/form-data">
        @csrf
        @method('patch')
        <div class="grid grid-cols-1 gap-x-4 gap-y-2 md:grid-cols-6">

            <div class="md:col-span-full grid grid-cols-1 md:grid-cols-6 gap-x-4 gap-y-4">
                {{-- Dados Pessoais --}}
                <div class="col-span-1 md:col-span-4 space-y-3">
                    <div class="md:col-span-4">
                        <x-input-label for="cliente_nome" :value="__('Nome Completo')" />
                        <x-text-input id="cliente_nome" name="cliente_nome" type="text" class="mt-1 w-full"
                            autocomplete="off" autofocus value="{{ old('cliente_nome', $cliente->cliente_nome) }}" />
                        <x-input-error :messages="$errors->get('cliente_nome')" class="mt-2" />
                    </div>
                    <div class="md:col-span-3 grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-4">
                        <div class="col-span-2">
                            <x-input-label for="cliente_tipo" :value="__('Tipo')" />
                            <select id="cliente_tipo" name="cliente_tipo" type="text"
                                class="mt-1 w-full border-gray-300 focus:border-black focus:ring-black rounded-md shadow-sm cursor-pointer"
                                autocomplete="off">
                                <option value="Física"
                                    {{ old('cliente_tipo', $cliente->cliente_tipo) == 'Física' ? 'selected' : '' }}>
                                    Pessoa Física</option>
                                <option value="Jurídica"
                                    {{ old('cliente_tipo', $cliente->cliente_tipo) == 'Jurídica' ? 'selected' : '' }}>
                                    Pessoa Jurídica</option>
                            </select>
                            <x-input-error :messages="$errors->get('cliente_tipo')" class="mt-2" />
                        </div>
                        <div id="fisica" class="md:col-span-2 grid grid-cols-1 md:grid-cols-3 gap-x-4 gap-y-4">
                            <div class="md:col-span-1">
                                <x-input-label for="cliente_cpf" :value="__('CPF')" />
                                <x-text-input id="cliente_cpf" name="cliente_cpf" type="text" class="cpf mt-1 w-full"
                                    autocomplete="off" autofocus
                                    value="{{ old('cliente_cpf', $cliente->cliente_cpf) }}" />
                                <x-input-error :messages="$errors->get('cliente_cpf')" class="mt-2" />
                            </div>
                            <div class="md:col-span-1">
                                <x-input-label for="cliente_data_nascimento" :value="__('Data Nascimento')" />
                                <x-text-input id="cliente_data_nascimento" name="cliente_data_nascimento" type="text"
                                    class="data mt-1 w-full" autocomplete="off"
                                    value="{{ old('cliente_data_nascimento', \Carbon\Carbon::parse($cliente->cliente_data_nascimento)->format('d/m/Y')) }}" />
                                <x-input-error class="mt-2" :messages="$errors->get('cliente_data_nascimento')" />
                            </div>
                            <div class="md:col-span-1">
                                <x-input-label for="cliente_rg" :value="__('RG')" />
                                <x-text-input id="cliente_rg" name="cliente_rg" type="text" class="rg mt-1 w-full"
                                    autocomplete="off" autofocus
                                    value="{{ old('cliente_rg', $cliente->cliente_rg) }}" />
                                <x-input-error :messages="$errors->get('cliente_rg')" class="mt-2" />
                            </div>
                        </div>
                        <div id="juridica" class="md:col-span-2">
                            <x-input-label for="cliente_cnpj" :value="__('CNPJ')" />
                            <x-text-input id="cliente_cnpj" name="cliente_cnpj" type="text" class="cnpj mt-1 w-full"
                                autocomplete="off" autofocus
                                value="{{ old('cliente_cnpj', $cliente->cliente_cnpj) }}" />
                            <x-input-error :messages="$errors->get('cliente_cnpj')" class="mt-2" />
                        </div>
                    </div>
                </div>
                <div class="col-span-1 md:col-span-2">
                    {{-- Imagem --}}
                    <div class="md:col-span-full grid grid-cols-1 md:grid-cols-6 gap-x-4 gap-y-4">
                        <div class="md:col-span-full">
                            <div class="flex justify-center items-center gap-x-2">
                                <i class='bx bxs-image'></i>
                                <x-input-label for="cliente_foto" :value="__('Foto Cliente')" />
                            </div>
                            <div class="flex flex-wrap justify-center gap-y-2">
                                <img id="imagem-preview"
                                    src="{{ $cliente->cliente_foto ? asset('img/fotos_clientes/' . $cliente->cliente_foto) : asset('Sem Imagem.png') }}"
                                    class="mborder rounded-lg object-contain w-40 h-40 p-1" />
                                <x-text-input id="cliente_foto" name="cliente_foto" type="file"
                                    class="cursor-pointer p-1 w-64 " onchange="previewImage(this)" />
                            </div>
                            <x-input-error :messages="$errors->get('cliente_foto')" class="mt-2" />
                        </div>
                    </div>
                </div>
            </div>
            {{-- Contato --}}
            <div class="col-span-full">
                <hr class="h-px my-1 border-0 bg-gray-200">
                <h2 class="flex items-center gap-x-2 text-lg font-bold text-teal-700">
                    <i class='bx bxs-contact'></i>
                    <span>{{ __('Contato') }}</span>
                </h2>
            </div>
            <div class="md:col-span-full grid grid-cols-1 md:grid-cols-6 gap-x-4 gap-y-4">
                <div class="lg:col-span-3 md:col-span-4">
                    <x-input-label for="cliente_celular" :value="__('Celular')" />
                    <x-text-input id="cliente_celular" name="cliente_celular" type="text"
                        class="phone_ddd mt-1 w-full" autocomplete="off" placeholder="(00) 00000-0000"
                        value="{{ old('cliente_celular', $cliente->cliente_celular) }}" />
                    <x-input-error :messages="$errors->get('cliente_celular')" class="mt-2" />
                </div>
                <div class="lg:col-span-3 md:col-span-4">
                    <x-input-label for="cliente_email" :value="__('E-mail')" />
                    <x-text-input id="cliente_email" name="cliente_email" type="text" class="mt-1 w-full"
                        autocomplete="off" value="{{ old('cliente_email', $cliente->cliente_email) }}" />
                    <x-input-error :messages="$errors->get('cliente_email')" class="mt-2" />
                </div>
            </div>
            {{-- Endereço --}}
            <div class="col-span-full">
                <hr class="h-px my-1 border-0 bg-gray-200">
                <h2 class="flex items-center gap-x-2 text-lg font-bold text-teal-700">
                    <i class='bx bxs-map-pin'></i>
                    <span>{{ __('Endereço') }}</span>
                </h2>
            </div>
            <div class="md:col-span-full grid grid-cols-1 md:grid-cols-6 gap-x-4 gap-y-4">
                <div class="lg:col-span-6 md:col-span-6">
                    <x-input-label for="cliente_endereco" :value="__('Endereço')" />
                    <x-text-input id="cliente_endereco" name="cliente_endereco" type="text" class="mt-1 w-full"
                        autocomplete="off" value="{{ old('cliente_endereco', $cliente->cliente_endereco) }}" />
                    <x-input-error :messages="$errors->get('cliente_endereco')" class="mt-2" />
                </div>
                <div class="lg:col-span-1 md:col-span-3">
                    <x-input-label for="cliente_numero_endereco" :value="__('Nº')" />
                    <x-text-input id="cliente_numero_endereco" name="cliente_numero_endereco" type="text"
                        class="mt-1 w-full" autocomplete="off"
                        value="{{ old('cliente_numero_endereco', $cliente->cliente_numero_endereco) }}" />
                    <x-input-error :messages="$errors->get('cliente_numero_endereco')" class="mt-2" />
                </div>
                <div class="lg:col-span-3 md:col-span-6">
                    <x-input-label for="cliente_complemento_endereco" :value="__('Complemento')" />
                    <x-text-input id="cliente_complemento_endereco" name="cliente_complemento_endereco"
                        type="text" class="mt-1 w-full" autocomplete="off"
                        value="{{ old('cliente_complemento_endereco', $cliente->cliente_complemento_endereco) }}" />
                    <x-input-error :messages="$errors->get('cliente_complemento_endereco')" class="mt-2" />
                </div>
                <div class="lg:col-span-3 md:col-span-6">
                    <x-input-label for="cliente_bairro" :value="__('Bairro')" />
                    <x-text-input id="cliente_bairro" name="cliente_bairro" type="text" class="mt-1 w-full"
                        autocomplete="off" value="{{ old('cliente_bairro', $cliente->cliente_bairro) }}" />
                    <x-input-error :messages="$errors->get('cliente_bairro')" class="mt-2" />
                </div>
                <div class="lg:col-span-3 md:col-span-6">
                    <x-input-label for="cliente_cidade" :value="__('Cidade')" />
                    <x-text-input id="cliente_cidade" name="cliente_cidade" type="text" class="mt-1 w-full"
                        autocomplete="off" value="{{ old('cliente_cidade', $cliente->cliente_cidade) }}" />
                    <x-input-error :messages="$errors->get('cliente_cidade')" class="mt-2" />
                </div>
                <div class="lg:col-span-3 md:col-span-4">
                    <x-input-label for="cliente_cep" :value="__('CEP')" />
                    <x-text-input id="cliente_cep" name="cliente_cep" type="text" class="cep mt-1 w-full"
                        autocomplete="off" value="{{ old('cliente_cep', $cliente->cliente_cep) }}" />
                    <x-input-error :messages="$errors->get('cliente_cep')" class="mt-2" />
                </div>
                <div class="lg:col-span-3 md:col-span-2">
                    <x-input-label for="cliente_uf" :value="__('UF')" />
                    <x-text-input id="cliente_uf" name="cliente_uf" type="text" class="mt-1 w-full"
                        autocomplete="off" value="{{ old('cliente_uf', $cliente->cliente_uf) }}" />
                    <x-input-error :messages="$errors->get('cliente_uf')" class="mt-2" />
                </div>
            </div>
        </div>
        <div class="flex items-center gap-4">
            <x-primary-button>{{ __('Atualizar') }}</x-primary-button>
        </div>
    </form>
    <script>
        function previewImage(input) {
            var preview = document.getElementById('imagem-preview');
            var file = input.files[0];
            var reader = new FileReader();

            reader.onloadend = function() {
                preview.src = reader.result;
            }

            if (file) {
                reader.readAsDataURL(file);
            } else {
                preview.src = "";
            }
        }
    </script>
    <script type="module">
        $(document).ready(function() {
            selecionaTipoPessoa();

            //Função define campos tipo de cliente cpf ou cnpj
            $('#cliente_tipo').change(function(e) {
                e.preventDefault();
                const tipo = $(this).val().trim()
                    .toLowerCase(); // Garante que é minúsculo e sem espaços extras
                console.log(tipo);
                switch (tipo) {
                    case 'jurídica':
                        $("#fisica").hide();
                        $("#juridica").show();
                        break;
                    case 'física':
                        $("#fisica").show();
                        $("#juridica").hide();
                        break;
                }
            });

        });

        function selecionaTipoPessoa() {
            const tipo1 =
                `{{ old('cliente_tipo') }}`; // Certifique-se de colocar aspas em volta de '{{ old('cliente_tipo') }}'
            if (tipo1 !== '' && tipo1 !== null) {
                $('#cliente_tipo').val(tipo1);
                switch (tipo1.trim().toLowerCase()) {
                    case 'jurídica':
                        $("#fisica").hide();
                        $("#juridica").show();
                        break;
                    case 'física':
                        $("#fisica").show();
                        $("#juridica").hide();
                        break;
                }
            } else {
                const tipo = $('#cliente_tipo').val().trim().toLowerCase();
                switch (tipo) {
                    case 'jurídica':
                        $("#fisica").hide();
                        $("#juridica").show();
                        break;
                    case 'física':
                        $("#fisica").show();
                        $("#juridica").hide();
                        break;
                }
            }
        }
    </script>
</section>
