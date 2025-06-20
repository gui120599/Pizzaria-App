<section>
    <header>
        <div class="flex items-center justify-between border-b">
            <div>
                <h2 class="text-lg font-medium text-gray-900">
                    {{ __('Novo Combo de Produtos') }}
                </h2>

                <p class="mt-1 text-sm text-gray-600">
                    {{ __('Insira os dados para cadastrar um novo combo de produtos.') }}
                </p>
            </div>
            <x-secondary-button x-on:click="$dispatch('close')">
                <i class='bx bx-x text-lg'></i>
            </x-secondary-button>
        </div>
    </header>

    <form action="{{ route('combo_produtos.store') }}" method="POST" enctype="multipart/form-data" class="mt-6">
        @csrf

        <div class="grid grid-cols-1 md:grid-cols-2 space-x-2">
            <div class="col-span-1 space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-6 gap-x-4 gap-y-4">
                    <div class="md:col-span-full">
                        <div class="flex justify-center items-center gap-x-2">
                            <i class='bx bxs-image'></i>
                            <x-input-label for="combo_produto_foto" :value="__('Foto do Combo (opcional)')" />
                        </div>
                        <div class="flex flex-col items-center justify-center gap-y-2">
                            <img id="imagem-preview" class="w-40 h-40 rounded-xl border object-cover" />
                            <x-text-input id="combo_produto_foto" name="combo_produto_foto" type="file"
                                class="cursor-pointer p-1 w-64" />
                        </div>
                        <x-input-error :messages="$errors->updatePassword->get('produto_foto')" class="mt-2" />
                    </div>
                </div>

                <div>
                    <x-input-label for="combo_produto_nome" :value="__('Nome do Combo')" :messages="$errors->get('combo_produto_nome')" />
                    <x-text-input id="combo_produto_nome" name="combo_produto_nome" type="text" class="mt-1 w-full"
                        value="{{ old('combo_produto_nome') }}" autocomplete="off" autofocus />
                </div>

                <div>
                    <x-input-label for="combo_produto_valor" :value="__('Valor do Combo')" :messages="$errors->get('combo_produto_valor')" />
                    <x-money-input id="valor-combo" name="combo_produto_valor" class="mt-1 w-full"
                        value="{{ old('combo_produto_valor') }}" readonly />
                </div>

                <div class="flex flex-row space-x-1">
                    <x-checkbox-input id="combo_produto_cardapio" name="combo_produto_cardapio" :checked="old('combo_produto_cardapio', false)" />
                    <x-input-label for="combo_produto_cardapio" :value="__('Listar no Cardápio')" :messages="$errors->get('combo_produto_cardapio')" />
                </div>

                <div class="flex flex-row space-x-1 items-center">
                    <x-checkbox-input id="combo_produto_promocional" name="combo_produto_promocional"
                        :checked="old('combo_produto_promocional', false)" />
                    <x-input-label for="combo_produto_promocional" :value="__('Combo Promocional')" :messages="$errors->get('combo_produto_promocional')" />
                </div>

                <div class="">
                    <div class="mt-4">
                        <h4 class="text-md font-semibold text-gray-700 mb-2">Produtos Selecionados:</h4>
                        <ul id="produtos-selecionados"
                            class="list-disc list-inside text-sm text-gray-600 h-28 overflow-auto"></ul>
                    </div>
                </div>
            </div>


            <div class=" col-span-1 border-s px-2">
                <div class="flex items-start justify-between p-2 mb-2 shadow-sm">

                    <h3 class="text-md font-semibold text-gray-700 mt-3">Adicionar Produtos ao Combo</h3>

                    <div class="flex items-center rounded-md px-4 durations-300 cursor-pointer border">
                        <i class='bx bx-search text-sm'></i>
                        <input type="text" placeholder="Buscar" id="busca-produto"
                            class="text-[12px] ml-4 w-full bg-transparent border-none focus:border-transparent focus:ring-0">
                    </div>
                </div>

                <div class="h-[60vh] overflow-auto">
                    @foreach ($produtos as $index => $produto)
                        <div class="border p-4 rounded-md mb-4 produto-item relative bg-white"
                            data-id="{{ $produto->id }}" data-descricao="{{ $produto->produto_descricao }}"
                            data-categoria="{{ $produto->categoria->categoria_nome }}">


                            <div class="flex items-center space-x-2 mb-2 justify-between">
                                <div>
                                    <input type="checkbox"
                                        class="form-checkbox produto-checkbox h-5 w-5 text-green-600 cursor-pointer"
                                        data-id="{{ $produto->id }}" name="itens[{{ $index }}][selecionado]"
                                        id="itens_{{ $index }}" value="{{ $produto->id }}">
                                    <label for="itens_{{ $index }}"
                                        class="text-gray-800 font-medium cursor-pointer">
                                        {{ $produto->categoria->categoria_nome }}
                                        {{ $produto->produto_descricao }}
                                    </label>
                                </div>
                                @if ($produto->produto_foto)
                                    <img src="{{ asset('img/fotos_produtos/' . $produto->produto_foto) }}"
                                        alt="{{ $produto->produtso_descricao }}"
                                        class="w-10 h-10 object-cover rounded-lg ">
                                @else
                                    <img id="imagem-preview" class="w-10 h-10 object-cover rounded-lg "
                                        src="{{ asset('Sem Imagem.png') }}" alt="Imagem Padrão">
                                @endif
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
                                        value="{{ $produto->produto_preco_venda }}" class="w-full valor-total"
                                        readonly />
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
    <script>
        document.addEventListener('DOMContentLoaded', function() {

            const input = document.querySelector('#combo_produto_foto');
            const preview = document.querySelector('#imagem-preview');

            input.addEventListener('change', function(event) {
                const file = event.target.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        preview.src = e.target.result;
                        preview.style.display = 'block';
                    };
                    reader.readAsDataURL(file);
                }
            });

            const inputBusca = document.getElementById('busca-produto');
            const produtos = document.querySelectorAll('.produto-item');

            inputBusca.addEventListener('keyup', function() {
                const termo = this.value.toLowerCase();

                produtos.forEach(produto => {
                    const texto = produto.dataset.categoria?.toLowerCase() + ' ' + produto.dataset
                        .descricao?.toLowerCase() ?? '';

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
                let totalCombo = 0;

                checkboxes.forEach(checkbox => {
                    if (checkbox.checked) {
                        const item = checkbox.closest('.produto-item');
                        const descricao = item.dataset.descricao;
                        const categoria = item.dataset.categoria;

                        const inputTotal = item.querySelector('.valor-total');
                        const valorTotal = parseFloat(inputTotal.value.replace(',', '.')) || 0;

                        totalCombo += valorTotal;

                        const li = document.createElement('li');
                        li.textContent = `${categoria} - ${descricao} - R$ ${formatarValor(valorTotal)}`;
                        listaSelecionados.appendChild(li);
                    }
                });
            }

            checkboxes.forEach(checkbox => {
                checkbox.addEventListener('change', atualizarListaSelecionados);
            });

            // Recalcula valor total do item (preço - desconto)
            function atualizarValorTotalItem(item) {
                const inputValor = item.querySelector('.valor-produto');
                const inputDesconto = item.querySelector('.valor-desconto');
                const inputTotal = item.querySelector('.valor-total');

                const valor = parseFloat(inputValor.value.replace(',', '.')) || 0;
                const desconto = parseFloat(inputDesconto.value.replace(',', '.')) || 0;
                const total = valor - desconto;

                inputTotal.value = total.toFixed(2).replace('.', ',');
            }

            document.querySelectorAll('.valor-desconto').forEach(input => {
                input.addEventListener('keyup', function() {
                    const item = this.closest('.produto-item');
                    atualizarValorTotalItem(item);
                    atualizarListaSelecionados();
                });
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
                descontoInput.addEventListener('keyup', () => {
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
