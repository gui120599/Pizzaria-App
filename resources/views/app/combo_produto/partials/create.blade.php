<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('Novo Combo de Produtos') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            {{ __('Insira os dados para cadastrar um novo combo de produtos.') }}
        </p>
    </header>

    <form action="{{ route('combo_produtos.store') }}" method="POST" enctype="multipart/form-data" class="mt-6 space-y-6">
        @csrf

        <div class="grid grid-cols-1 md:grid-cols-2 space-x-2">
            <div class="col-span-1">
                <div>
                    <x-input-label for="combo_produto_nome" :value="__('Nome do Combo')" />
                    <x-text-input id="combo_produto_nome" name="combo_produto_nome" type="text" class="mt-1 w-full"
                        autocomplete="off" autofocus />
                    <x-input-error :messages="$errors->get('combo_produto_nome')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="combo_produto_valor" :value="__('Valor do Combo')" />
                    <x-money-input id="valor-combo" name="combo_produto_valor" class="mt-1 w-full" readonly />
                    <x-input-error :messages="$errors->get('combo_produto_valor')" class="mt-2" />
                </div>


                <div class="grid grid-cols-1 md:grid-cols-6 gap-x-4 gap-y-4">
                    <div class="md:col-span-full">
                        <div class="flex justify-center items-center gap-x-2">
                            <i class='bx bxs-image'></i>
                            <x-input-label for="combo_produto_foto" :value="__('Foto do Combo (opcional)')" />
                        </div>
                        <div class="flex flex-col items-center justify-center gap-y-2">
                            <img id="imagem-preview" class="w-40 h-40 rounded-xl border object-cover" />
                            <x-text-input id="combo_produto_foto" name="combo_produto_foto" type="file"
                                class="cursor-pointer p-1 w-64" onchange="previewImage(this)" />
                        </div>
                        <x-input-error :messages="$errors->updatePassword->get('produto_foto')" class="mt-2" />
                    </div>



                    <x-input-error :messages="$errors->get('combo_produto_foto')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="combo_produto_cardapio" :value="__('Listar no Cardápio')" />
                    <x-checkbox-input id="combo_produto_cardapio" name="combo_produto_cardapio" :checked="old('combo_produto_cardapio', false)" />
                    <x-input-error :messages="$errors->get('combo_produto_cardapio')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="combo_produto_promocional" :value="__('Combo Promocional')" />
                    <x-checkbox-input id="combo_produto_promocional" name="combo_produto_promocional"
                        :checked="old('combo_produto_promocional', false)" />
                    <x-input-error :messages="$errors->get('combo_produto_promocional')" class="mt-2" />
                </div>

                <div class="">
                    <div class="mt-4">
                        <h4 class="text-md font-semibold text-gray-700 mb-2">Selecionados:</h4>
                        <ul id="produtos-selecionados" class="list-disc list-inside text-sm text-gray-600 h-28 overflow-auto"></ul>
                    </div>

                </div>
            </div>


            <div class=" col-span-1 border-x px-2">
                <div class="flex items-start justify-between p-2 mb-2 shadow-sm">

                    <h3 class="text-md font-semibold text-gray-700 mt-3">Produtos do Combo</h3>

                    <div class="flex items-center rounded-md px-4 durations-300 cursor-pointer border-b">
                        <i class='bx bx-search text-sm'></i>
                        <input type="text" placeholder="Buscar" id="busca-produto"
                            class="text-[12px] ml-4 w-full bg-transparent border-none focus:border-transparent focus:ring-0">
                    </div>
                </div>

                <div class="h-[60vh] overflow-auto">
                    @foreach ($produtos as $index => $produto)
                        <div class="border p-4 rounded-md mb-4 produto-item" data-id="{{ $produto->id }}"
                            data-descricao="{{ $produto->produto_descricao }}"
                            data-categoria="{{ $produto->categoria->categoria_nome }}">
                            <div class="flex items-center space-x-2 mb-2">
                                <input type="checkbox" class="form-checkbox produto-checkbox h-5 w-5 text-green-600 cursor-pointer"
                                    data-id="{{ $produto->id }}" name="itens[{{ $index }}][selecionado]" id="itens_{{ $index }}"
                                    value="{{ $produto->id }}">
                                <label for="itens_{{ $index }}" class="text-gray-800 font-medium cursor-pointer">
                                    {{ $produto->categoria->categoria_nome }} {{ $produto->produto_descricao }}
                                </label>
                            </div>

                            <div class="grid grid-cols-3 gap-4">
                                <div>
                                    <x-input-label :value="__('Valor Produto')" />
                                    <x-money-input name="itens[{{ $index }}][valor_produto]"
                                        value="{{ $produto->produto_preco_venda }}" class="w-full valor-produto"
                                        readonly />
                                </div>
                                <div>
                                    <x-input-label :value="__('Valor Desconto')" />
                                    <x-money-input name="itens[{{ $index }}][valor_desconto]"
                                        class="w-full valor-desconto" />
                                </div>
                                <div>
                                    <x-input-label :value="__('Valor Total')" />
                                    <x-money-input name="itens[{{ $index }}][valor_total]"
                                        value="{{ $produto->produto_preco_venda }}" class="w-full valor-total" readonly />
                                </div>
                            </div>

                        </div>
                    @endforeach
                </div>
            </div>
        </div>
        <x-primary-button>
            {{ __('Cadastrar Novo Combo') }}
        </x-primary-button>
    </form>
    <script type="module">
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
        document.addEventListener('DOMContentLoaded', function() {
            const inputBusca = document.getElementById('busca-produto');
            const produtos = document.querySelectorAll('.produto-item');

            inputBusca.addEventListener('input', function() {
                const termo = this.value.toLowerCase();

                produtos.forEach(produto => {
                    const texto = produto.dataset.produto;

                    if (texto.includes(termo)) {
                        produto.style.display = '';
                    } else {
                        produto.style.display = 'none';
                    }
                });
            });

            const checkboxes = document.querySelectorAll('.produto-checkbox');
            const listaSelecionados = document.getElementById('produtos-selecionados');

            function atualizarListaSelecionados() {
                listaSelecionados.innerHTML = '';

                checkboxes.forEach(checkbox => {
                    if (checkbox.checked) {
                        const item = checkbox.closest('.produto-item');
                        const descricao = item.dataset.descricao;
                        const categoria = item.dataset.categoria;

                        const li = document.createElement('li');
                        li.textContent = `${categoria} - ${descricao}`;
                        listaSelecionados.appendChild(li);
                    }
                });
            }

            checkboxes.forEach(checkbox => {
                checkbox.addEventListener('change', atualizarListaSelecionados);
            });

            // Atualiza ao carregar a página (caso já venham pré-selecionados)
            atualizarListaSelecionados();

            const combos = document.querySelectorAll('.valor-produto');

            combos.forEach((produtoInput) => {
                const wrapper = produtoInput.closest('.grid');
                const descontoInput = wrapper.querySelector('.valor-desconto');
                const totalInput = wrapper.querySelector('.valor-total');

                function formatar(valor) {
                    if (!valor) return 0;
                    const limpo = valor.replace(/[^\d,]/g, '');
                    return parseFloat(limpo.replace(',', '.')) || 0;
                }

                function atualizarTotal() {
                    const valorProduto = formatar(produtoInput.value);
                    const valorDesconto = formatar(descontoInput.value);
                    const total = valorProduto - valorDesconto;

                    if (!isNaN(total)) {
                        const totalFormatado = total.toFixed(2).replace('.', ',');
                        totalInput.value = totalFormatado;

                        // Aplica a máscara novamente, se quiser
                        $(totalInput).trigger('input');
                    }
                }

                descontoInput.addEventListener('keyup', atualizarTotal);
            });

            const inputComboTotal = document.getElementById('valor-combo');

            function parseValor(valor) {
                if (!valor) return 0;
                const limpo = valor.replace(/[^\d,]/g, '');
                return parseFloat(limpo.replace(',', '.')) || 0;
            }

            function formatarValor(valor) {
                return valor.toFixed(2).replace('.', ',');
            }

            function atualizarTotalDoCombo() {
                let total = 0;

                checkboxes.forEach(checkbox => {
                    if (checkbox.checked) {
                        const wrapper = checkbox.closest('.produto-item');
                        const totalInput = wrapper.querySelector('.valor-total');
                        const valorTotal = parseValor(totalInput.value);
                        total += valorTotal;
                    }
                });

                inputComboTotal.value = formatarValor(total);
            }

            // Atualiza quando marcar/desmarcar
            checkboxes.forEach(checkbox => {
                checkbox.addEventListener('change', atualizarTotalDoCombo);

                const wrapper = checkbox.closest('.produto-item');
                const descontoInput = wrapper.querySelector('.valor-desconto');

                // Atualiza quando mudar o desconto
                descontoInput.addEventListener('input', () => {
                    // Simula o recálculo do valor total do item
                    const valorProduto = parseValor(wrapper.querySelector('.valor-produto').value);
                    const valorDesconto = parseValor(descontoInput.value);
                    const totalInput = wrapper.querySelector('.valor-total');
                    const novoTotal = valorProduto - valorDesconto;

                    totalInput.value = formatarValor(novoTotal);
                    atualizarTotalDoCombo();
                });
            });

            // Executa na carga inicial (caso tenha produtos já marcados)
            atualizarTotalDoCombo();
        });
    </script>
</section>
