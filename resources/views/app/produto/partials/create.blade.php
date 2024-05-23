<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('Novo Produto') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            {{ __('Insira os dados para um novo produto.') }}
        </p>
    </header>

    <form action="{{ route('produto.store') }}" method="post" class="space-y-6" enctype="multipart/form-data" autocomplete="off">
        @csrf
        <div class="grid grid-cols-1 gap-x-4 gap-y-2 md:grid-cols-6">
            <div class="md:col-span-full grid grid-cols-1 md:grid-cols-6 gap-x-4 gap-y-4">
                <div class="col-span-1 md:col-span-4 space-y-3">
                    <div class="md:col-span-4">
                        <x-input-label for="produto_descricao" :value="__('Descrição do Produto')" />
                        <x-text-input id="produto_descricao" name="produto_descricao" type="text" class="mt-1 w-full"
                            autocomplete="off" value="{{ old('produto_descricao') }}" autofocus />
                        <x-input-error :messages="$errors->updatePassword->get('produto_descricao')" class="mt-2" />
                    </div>
                    <div class="md:col-span-4">
                        <x-input-label for="produto_codimentacao" :value="__('Codimentação do Produto')" />
                        <x-text-input id="produto_codimentacao" name="produto_codimentacao" type="text"
                            class="mt-1 w-full" autocomplete="off" value="{{ old('produto_codimentacao') }}" />
                        <x-input-error :messages="$errors->updatePassword->get('produto_codimentacao')" class="mt-2" />
                    </div>
                    <div class="md:col-span-2">
                        <x-input-label for="produto_categoria_id" :value="__('Categoria do Produto')" />
                        <x-select-input :options="$categorias" value-field="id" display-field="categoria_nome"
                            id="produto_categoria_id" name="produto_categoria_id" class="mt-1 w-full" />
                        <x-input-error :messages="$errors->updatePassword->get('produto_categoria_id')" class="mt-2" />
                    </div>
                </div>
                <div class="col-span-1 md:col-span-2">
                    {{-- Imagem --}}
                    <div class="md:col-span-full grid grid-cols-1 md:grid-cols-6 gap-x-4 gap-y-4">
                        <div class="md:col-span-full">
                            <div class="flex justify-center items-center gap-x-2">
                                <i class='bx bxs-image'></i>
                                <x-input-label for="produto_foto" :value="__('Foto do Produto')" />
                            </div>
                            <div class="flex flex-wrap justify-center gap-y-2">
                                <img id="imagem-preview" class="mborder rounded-lg object-contain w-40 h-40 p-1" />
                                <x-text-input id="produto_foto" name="produto_foto" type="file"
                                    class="cursor-pointer p-1 w-64 " onchange="previewImage(this)" />
                            </div>
                            <x-input-error :messages="$errors->updatePassword->get('produto_foto')" class="mt-2" />
                        </div>
                    </div>
                </div>
            </div>

            {{-- Códigos --}}
            <div class="col-span-full">
                <hr class="h-px my-1 border-0 bg-gray-200">
                <h2 class="flex items-center gap-x-2 text-lg font-bold text-teal-700">
                    <i class='bx bx-barcode-reader'></i>
                    <span>{{ __('Códigos') }}</span>
                </h2>
            </div>
            <div class="md:col-span-full grid grid-cols-1 md:grid-cols-6 gap-x-4 gap-y-4">
                <div class="lg:col-span-2 md:col-span-3">
                    <div class="flex items-center gap-x-2">
                        <i class='bx bx-barcode'></i>
                        <x-input-label for="produto_codigo_EAN" :value="__('Código de EAN')" />
                    </div>
                    <x-text-input id="produto_codigo_EAN" name="produto_codigo_EAN" type="text" class="mt-1 w-full"
                         value="{{ old('produto_codigo_EAN') }}" autocomplete="off"/>
                    <x-input-error :messages="$errors->updatePassword->get('produto_codigo_EAN')" class="mt-2" />
                </div>

                <div class="lg:col-span-2 md:col-span-3">
                    <x-input-label for="produto_codigo_NCM" :value="__('Código NCM do Produto')" />
                    <x-text-input id="produto_codigo_NCM" name="produto_codigo_NCM" type="text" class="mt-1 w-full"
                        autocomplete="off" value="{{ old('produto_codigo_NCM') }}" />
                    <x-input-error :messages="$errors->updatePassword->get('produto_codigo_NCM')" class="mt-2" />
                </div>

                <div class="lg:col-span-2 md:col-span-3">
                    <x-input-label for="produto_codigo_CEST" :value="__('Código CEST do Produto')" />
                    <x-text-input id="produto_codigo_CEST" name="produto_codigo_CEST" type="text" class="mt-1 w-full"
                        autocomplete="off" value="{{ old('produto_codigo_CEST') }}" />
                    <x-input-error :messages="$errors->updatePassword->get('produto_codigo_CEST')" class="mt-2" />
                </div>

                <div class="lg:col-span-2 md:col-span-3">
                    <x-input-label for="produto_codigo_beneficio_fiscal_uf" :value="__('Código Benefício Fiscal na UF do Produto')" />
                    <x-text-input id="produto_codigo_beneficio_fiscal_uf" name="produto_codigo_beneficio_fiscal_uf"
                        type="text" class="mt-1 w-full" autocomplete="off"
                        value="{{ old('produto_codigo_beneficio_fiscal_uf') }}" />
                    <x-input-error :messages="$errors->updatePassword->get('produto_codigo_beneficio_fiscal_uf')" class="mt-2" />
                </div>

                <div class="lg:col-span-2 md:col-span-3">
                    <x-input-label for="produto_CFOP" :value="__('Código CFOP do Produto')" />
                    <x-text-input id="produto_CFOP" name="produto_CFOP" type="text" class="mt-1 w-full"
                        autocomplete="off" value="{{ old('produto_CFOP') }}" />
                    <x-input-error :messages="$errors->updatePassword->get('produto_CFOP')" class="mt-2" />
                </div>
                <script>
                    document.getElementById('produto_codigo_EAN').setAttribute('autocomplete', 'off');
                </script>
            </div>

            {{-- Preços --}}
            <div class="col-span-full">
                <hr class="h-px my-1 border-0 bg-gray-200">
                <h2 class="flex items-center gap-x-2 text-lg font-bold text-teal-700">
                    <i class='bx bxs-dollar-circle'></i>
                    <span>{{ __('Preços') }}</span>
                </h2>
            </div>
            <div class="md:col-span-full grid grid-cols-1 md:grid-cols-6 gap-x-4 gap-y-4">
                <div class="lg:col-span-1 md:col-span-3">
                    <x-input-label for="produto_preco_custo" :value="__('Preço de Custo')" />
                    <x-money-input id="produto_preco_custo" name="produto_preco_custo" type="text"
                        class="mt-1 w-full" autocomplete="off" value="{{ old('produto_preco_custo') }}" />
                    <x-input-error :messages="$errors->updatePassword->get('produto_preco_custo')" class="mt-2" />
                </div>

                <div class="lg:col-span-1 md:col-span-3">
                    <x-input-label for="produto_valor_percentual_venda" :value="__('Venda %')" />
                    <x-text-input id="produto_valor_percentual_venda" name="produto_valor_percentual_venda"
                        type="text" class="mt-1 w-full" autocomplete="off"
                        value="{{ old('produto_valor_percentual_venda') }}" />
                    <x-input-error :messages="$errors->updatePassword->get('produto_valor_percentual_venda')" class="mt-2" />
                </div>

                <div class="lg:col-span-1 md:col-span-3">
                    <x-input-label for="produto_preco_venda" :value="__('Preço de Venda')" />
                    <x-money-input id="produto_preco_venda" name="produto_preco_venda" type="text"
                        class="mt-1 w-full" autocomplete="off" value="{{ old('produto_preco_venda') }}" />
                    <x-input-error :messages="$errors->updatePassword->get('produto_preco_venda')" class="mt-2" />
                </div>

                <div class="lg:col-span-1 md:col-span-3">
                    <x-input-label for="produto_valor_percentual_comissao" :value="__('Comissão %')" />
                    <x-text-input id="produto_valor_percentual_comissao" name="produto_valor_percentual_comissao"
                        type="text" class="mt-1 w-full" autocomplete="off"
                        value="{{ old('produto_valor_percentual_comissao') }}" />
                    <x-input-error :messages="$errors->updatePassword->get('produto_valor_percentual_comissao')" class="mt-2" />
                </div>

                <div class="lg:col-span-1 md:col-span-3">
                    <x-input-label for="produto_preco_comissao" :value="__('Preço de Comissão')" />
                    <x-money-input id="produto_preco_comissao" name="produto_preco_comissao" type="text"
                        class="mt-1 w-full" autocomplete="off" value="{{ old('produto_preco_comissao') }}" />
                    <x-input-error :messages="$errors->updatePassword->get('produto_preco_comissao')" class="mt-2" />
                </div>

                <div class="lg:col-span-1 md:col-span-3">
                    <x-input-label for="produto_preco_promocional" :value="__('Preço Promocional')" />
                    <x-money-input id="produto_preco_promocional" name="produto_preco_promocional" type="text"
                        class="mt-1 w-full" autocomplete="off" value="{{ old('produto_preco_promocional') }}" />
                    <x-input-error :messages="$errors->updatePassword->get('produto_preco_promocional')" class="mt-2" />
                </div>
            </div>

            {{-- Impostos --}}
            <div class="col-span-full">
                <hr class="h-px my-1 border-0 bg-gray-200">
                <h2 class="flex items-center gap-x-2 text-lg font-bold text-teal-700">
                    <i class='bx bx-dollar'></i>
                    <span>{{ __('Impostos') }}</span>
                </h2>
            </div>
            <div class="md:col-span-full grid grid-cols-1 md:grid-cols-6 gap-x-4 gap-y-4">

                <div class="lg:col-span-2 md:col-span-3">
                    <x-input-label for="produto_valor_percentual_icms" :value="__('ICMS %')" />
                    <x-text-input id="produto_valor_percentual_icms" name="produto_valor_percentual_icms"
                        type="text" class="mt-1 w-full" autocomplete="off"
                        value="{{ old('produto_valor_percentual_icms') }}" />
                    <x-input-error :messages="$errors->updatePassword->get('produto_valor_percentual_icms')" class="mt-2" />
                </div>

                <div class="lg:col-span-2 md:col-span-3">
                    <x-input-label for="produto_valor_percentual_cofins" :value="__('COFINS %')" />
                    <x-text-input id="produto_valor_percentual_cofins" name="produto_valor_percentual_cofins"
                        type="text" class="mt-1 w-full" autocomplete="off"
                        value="{{ old('produto_valor_percentual_cofins') }}" />
                    <x-input-error :messages="$errors->updatePassword->get('produto_valor_percentual_cofins')" class="mt-2" />
                </div>

                <div class="lg:col-span-2 md:col-span-3">
                    <x-input-label for="produto_valor_percentual_pis" :value="__('PIS %')" />
                    <x-text-input id="produto_valor_percentual_pis" name="produto_valor_percentual_pis"
                        type="text" class="mt-1 w-full" autocomplete="off"
                        value="{{ old('produto_valor_percentual_pis') }}" />
                    <x-input-error :messages="$errors->updatePassword->get('produto_valor_percentual_pis')" class="mt-2" />
                </div>
            </div>

            {{-- Unidade Comercial --}}
            <div class="col-span-full">
                <hr class="h-px my-1 border-0 bg-gray-200">
                <h2 class="flex items-center gap-x-2 text-lg font-bold text-teal-700">
                    <i class='bx bx-circle-three-quarter'></i>
                    <span>{{ __('Unidade Comercial') }}</span>
                </h2>
            </div>
            <div class="md:col-span-full grid grid-cols-1 md:grid-cols-6 gap-x-4 gap-y-4">

                <div class="lg:col-span-2 md:col-span-3">
                    <x-input-label for="produto_unidade_comercial" :value="__('Unidade Comercial')" />
                    <x-text-input id="produto_unidade_comercial" name="produto_unidade_comercial" type="text"
                        class="mt-1 w-full" autocomplete="off" value="{{ old('produto_unidade_comercial') }}" />
                    <x-input-error :messages="$errors->updatePassword->get('produto_unidade_comercial')" class="mt-2" />
                </div>
            </div>

            {{-- Promoção --}}
            <div class="col-span-full">
                <hr class="h-px my-1 border-0 bg-gray-200">
                <h2 class="flex items-center gap-x-2 text-lg font-bold text-teal-700">
                    <i class='bx bxs-badge-dollar'></i>
                    <span>{{ __('Promoção') }}</span>
                </h2>
            </div>
            <div class="md:col-span-full grid grid-cols-1 md:grid-cols-6 gap-x-4 gap-y-4">
                <div class="lg:col-span-2 md:col-span-3">
                    <x-input-label for="produto_data_inicio_promocao" :value="__('Data de Início da Promoção')" />
                    <x-date-input id="produto_data_inicio_promocao" name="produto_data_inicio_promocao"
                        class="mt-1 w-full" value="{{ old('produto_data_inicio_promocao') }}" />
                    <x-input-error :messages="$errors->updatePassword->get('produto_data_inicio_promocao')" class="mt-2" />
                </div>

                <div class="lg:col-span-2 md:col-span-3">
                    <x-input-label for="produto_data_final_promocao" :value="__('Data de Fim da Promoção')" />
                    <x-date-input id="produto_data_final_promocao" name="produto_data_final_promocao"
                        class="mt-1 w-full" value="{{ old('produto_data_final_promocao') }}" />
                    <x-input-error :messages="$errors->updatePassword->get('produto_data_final_promocao')" class="mt-2" />
                </div>
            </div>

            {{-- Máximos/Mínimos --}}
            <div class="col-span-full">
                <hr class="h-px my-1 border-0 bg-gray-200">
                <h2 class="flex items-center gap-x-2 text-lg font-bold text-teal-700">
                    <i class='bx bxs-message-square-error'></i>
                    <span>{{ __('Máximos/Mínimos') }}</span>
                </h2>
            </div>
            <div class="md:col-span-full grid grid-cols-1 md:grid-cols-6 gap-x-4 gap-y-4">
                <div class="lg:col-span-1 md:col-span-3">
                    <x-input-label for="produto_quantidade_minima" :value="__('Quantidade Mínima')" />
                    <x-text-input id="produto_quantidade_minima" name="produto_quantidade_minima" type="text"
                        class="mt-1 w-full" autocomplete="off" value="{{ old('produto_quantidade_minima') }}" />
                    <x-input-error :messages="$errors->updatePassword->get('produto_quantidade_minima')" class="mt-2" />
                </div>

                <div class="lg:col-span-1 md:col-span-3">
                    <x-input-label for="produto_quantidade_maxima" :value="__('Quantidade Máxima')" />
                    <x-text-input id="produto_quantidade_maxima" name="produto_quantidade_maxima" type="text"
                        class="mt-1 w-full" autocomplete="off" value="{{ old('produto_quantidade_maxima') }}" />
                    <x-input-error :messages="$errors->updatePassword->get('produto_quantidade_maxima')" class="mt-2" />
                </div>
            </div>

        </div>
        <x-primary-button>
            {{ __('Cadastrar Novo Produto') }}
        </x-primary-button>
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

            // Função para calcular e atualizar os campos de preços
            $('#produto_valor_percentual_venda').on('input', () => {
                var percentualVenda = parseFloat($('#produto_valor_percentual_venda').val().replace(',',
                    '.')) || 0;
                var precoCusto = parseFloat($('#produto_preco_custo').val().replace(',', '.')) || 0;

                if (percentualVenda !== 0) {
                    // Calcular o preço de venda
                    var precoVenda = precoCusto * (1 + percentualVenda / 100);

                    // Formatando o valor com duas casas decimais
                    precoVenda = precoVenda.toFixed(2);

                    // Atualizar o campo de preço de venda
                    $("#produto_preco_venda").val(precoVenda);
                } else if (percentualVenda === 0) {
                    $("#produto_preco_venda").val('0,00');
                }
            });

            // Função para calcular e atualizar os campos de preços com base no percentual de venda
            $('#produto_preco_custo').on('input', () => {
                $("#produto_valor_percentual_venda").val('');
                $("#produto_preco_venda").val('');
            });

            //Quando função on pois dá conflito com a mask o resultado sai incorreto
            $('#produto_preco_venda').change(function(e) {
                e.preventDefault();
                var precoVenda = parseFloat($('#produto_preco_venda').val().replace(',', '.')) || 0.00;
                var precoCusto = parseFloat($('#produto_preco_custo').val().replace(',', '.')) || 0.00;

                // Verificar se há um valor válido no campo de preço de venda
                if (!isNaN(precoVenda) && precoVenda !== 0) {
                    // Calcular o percentual de venda apenas se o preço de custo for diferente de zero
                    var percentualVenda;
                    if (precoCusto !== 0) {
                        percentualVenda = ((precoVenda - precoCusto) / precoCusto) * 100;
                    } else {
                        percentualVenda = 100;
                    }

                    // Formatando o valor com duas casas decimais
                    percentualVenda = percentualVenda.toFixed(2);

                    // Atualizar o campo de percentual de venda
                    $('#produto_valor_percentual_venda').val(percentualVenda);
                } else {
                    // Se o campo de preço de venda estiver vazio ou for 0, redefinir o percentual de venda
                    $('#produto_valor_percentual_venda').val('');
                }
            });


            //CALCULA A PORCENTAGEM DA COMISSÃO
            $('#produto_preco_comissao').change(function(e) {
                e.preventDefault();
                var precoVenda = parseFloat($('#produto_preco_venda').val().replace(',', '.')) || 0;
                var precoComissao = parseFloat($('#produto_preco_comissao').val().replace(',', '.')) || 0;

                // Verificar se há um valor válido no campo de preço de venda
                if ((!isNaN(precoComissao) && precoComissao !== 0) && !isNaN(precoVenda) && precoVenda !==
                    0) {
                    // Calcular o percentual de Comissao
                    var percentualComissao = (precoComissao / precoVenda) * 100;

                    // Formatando o valor com duas casas decimais
                    percentualComissao = percentualComissao.toFixed(2);

                    // Atualizar o campo de percentual de Comissao
                    $('#produto_valor_percentual_comissao').val(percentualComissao);
                } else {
                    // Se o campo de preço de Comissao estiver vazio ou for 0, redefinir o percentual de Comissao
                    $('#produto_valor_percentual_comissao').val('');
                }
            });

            // Função para calcular e atualizar os campos de preços
            $('#produto_valor_percentual_comissao').on('input', () => {
                var percentualComissao = parseFloat($('#produto_valor_percentual_comissao').val().replace(
                    ',',
                    '.')) || 0;
                var precoVenda = parseFloat($('#produto_preco_venda').val().replace(',', '.')) || 0;

                if (percentualComissao !== 0) {
                    // Calcular o preço de Comissao
                    var precoComissao = percentualComissao * (precoVenda / 100);

                    // Formatando o valor com duas casas decimais
                    precoComissao = precoComissao.toFixed(2);

                    // Atualizar o campo de preço de Comissao
                    $("#produto_preco_comissao").val(precoComissao);
                } else if (percentualComissao === 0) {
                    $("#produto_preco_comissao").val('');
                }
            });

        });
    </script>


</section>
