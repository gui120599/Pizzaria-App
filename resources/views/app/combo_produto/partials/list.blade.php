<section>
    <header>
        <div class="flex justify-between">
            <h2 class="text-lg font-medium text-gray-900">
                {{ __('Lista de Combos de Produtos') }}
            </h2>
            <x-secondary-button x-data=""
                x-on:click.prevent="$dispatch('open-modal', 'cadastra-combo')"><i class='bx bxs-plus-circle'></i>
                {{ __('Novo Combo') }}</x-secondary-button>
        </div>
    </header>
    <x-modal name="cadastra-combo" :show="true" :maxWidth="'6xl'">
        <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
            <div class="h-[80vh] overflow-auto md:overflow-hidden">
                @include('app.combo_produto.partials.create')
            </div>
        </div>
    </x-modal>

    <div class="w-full overflow-auto mx-auto mt-4">
        <table class="min-w-full border border-gray-200 rounded">
            <thead class="bg-gray-100 text-sm font-semibold">
                <tr>
                    <th class="px-4 py-2"></th>
                    <th class="px-4 py-2">ID</th>
                    <th class="px-4 py-2">Nome</th>
                    <th class="px-4 py-2">Valor</th>
                    <th class="px-4 py-2">Cardápio</th>
                    <th class="px-4 py-2">Promocional</th>
                    <th class="px-4 py-2">Ações</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($combo_produtos as $combo)
                    <tr class="border-b text-sm">
                        <td class="px-4 py-2"><img src="{{ asset('/fotos_combosProdutos/' . $combo->combo_produto_foto) }}" alt="" class="w-10 h-10 object-cover rounded-lg "></td>
                        <td class="px-4 py-2">{{ $combo->id }}</td>
                        <td class="px-4 py-2">{{ $combo->combo_produto_nome }}</td>
                        <td class="px-4 py-2">R$ {{ number_format($combo->combo_produto_valor, 2, ',', '.') }}</td>
                        <td class="px-4 py-2">{{ $combo->combo_produto_cardapio ? 'SIM' : 'NÃO' }}</td>
                        <td class="px-4 py-2">{{ $combo->combo_produto_promocional ? 'SIM' : 'NÃO' }}</td>
                        <td class="px-4 py-2">
                            <div class="flex items-center justify-center space-x-2">
                                <x-primary-button
                                    onclick="window.location.href = '{{ route('combo_produtos.edit', ['comboProduto' => $combo]) }}'"
                                    title="Editar">
                                    <i class='bx bx-edit text-sm'></i>
                                </x-primary-button>

                                <form action="{{ route('combo_produtos.destroy', ['comboProduto' => $combo]) }}"
                                    method="post" onsubmit="return confirm('Deseja realmente excluir este combo?');">
                                    @method('delete')
                                    @csrf
                                    <x-danger-button title="Excluir">
                                        <i class='bx bx-trash text-sm'></i>
                                    </x-danger-button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center py-4">Nenhum combo de produto encontrado.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
