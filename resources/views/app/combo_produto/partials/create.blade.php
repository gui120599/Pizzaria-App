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

        <div>
            <x-input-label for="combo_produto_nome" :value="__('Nome do Combo')" />
            <x-text-input id="combo_produto_nome" name="combo_produto_nome" type="text" class="mt-1 w-full" autocomplete="off" autofocus />
            <x-input-error :messages="$errors->get('combo_produto_nome')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="combo_produto_valor" :value="__('Valor do Combo')" />
            <x-text-input id="combo_produto_valor" name="combo_produto_valor" type="number" step="0.01" class="mt-1 w-full" />
            <x-input-error :messages="$errors->get('combo_produto_valor')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="combo_produto_foto" :value="__('Foto do Combo (opcional)')" />
            <x-text-input id="combo_produto_foto" name="combo_produto_foto" type="file" class="mt-1 w-full" />
            <x-input-error :messages="$errors->get('combo_produto_foto')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="combo_produto_cardapio" :value="__('Listar no Cardápio')" />
            <x-checkbox-input id="combo_produto_cardapio" name="combo_produto_cardapio" :checked="old('combo_produto_cardapio', false)" />
            <x-input-error :messages="$errors->get('combo_produto_cardapio')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="combo_produto_promocional" :value="__('Combo Promocional')" />
            <x-checkbox-input id="combo_produto_promocional" name="combo_produto_promocional" :checked="old('combo_produto_promocional', false)" />
            <x-input-error :messages="$errors->get('combo_produto_promocional')" class="mt-2" />
        </div>

        <hr class="border-gray-300 my-4">

        <h3 class="text-md font-semibold text-gray-700 mb-2">Produtos do Combo</h3>

        @foreach ($produtos as $index => $produto)
            <div class="border p-4 rounded-md mb-4">
                <div class="flex items-center space-x-2 mb-2">
                    <input type="checkbox" name="itens[{{ $index }}][selecionado]" id="produto_{{ $produto->id }}" value="{{ $produto->id }}" class="form-checkbox h-5 w-5 text-green-600">
                    <label for="produto_{{ $produto->id }}" class="text-gray-800 font-medium">{{ $produto->produto_nome }}</label>
                </div>

                <div class="grid grid-cols-3 gap-4">
                    <div>
                        <x-input-label :value="__('Valor Produto')" />
                        <x-text-input type="number" step="0.01" name="itens[{{ $index }}][valor_produto]" class="w-full" />
                    </div>
                    <div>
                        <x-input-label :value="__('Valor Desconto')" />
                        <x-text-input type="number" step="0.01" name="itens[{{ $index }}][valor_desconto]" class="w-full" />
                    </div>
                    <div>
                        <x-input-label :value="__('Valor Total')" />
                        <x-text-input type="number" step="0.01" name="itens[{{ $index }}][valor_total]" class="w-full" />
                    </div>
                </div>
            </div>
        @endforeach

        <x-primary-button>
            {{ __('Cadastrar Novo Combo') }}
        </x-primary-button>
    </form>
</section>
