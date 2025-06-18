<section>
    <header>
        <div class="flex justify-between">
            <h2 class="text-lg font-medium text-gray-900">
                {{ __('Lista de Categorias') }}
            </h2>
            <x-secondary-button onclick="window.location.href = '{{ route('categoria.inactive') }}'">Mostrar
                Inativos</x-secondary-button>
        </div>
    </header>
    <div class="w-[18rem] sm:w-[99%] overflow-auto mx-auto h-2/4">
        <table class="min-w-full">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Categoria</th>
                    <th>Filhas</th>
                    <th>Cardápio</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($categorias as $categoria)
                    @if (empty($categoria->parent))
                        <tr class="border-b-2 border-gray-100 align-top">
                            <td>{{ $categoria->id }}</td>
                            <td>{{ $categoria->categoria_nome }}</td>
                            <td>{{ $categoria->categoria_cardapio ? 'SIM' : 'NÃO' }}</td>
                            <td>
                                <div class="flex items-center justify-center space-x-2">
                                    <x-primary-button
                                        onclick="window.location.href = '{{ route('categoria.edit', ['categoria' => $categoria]) }}'"
                                        title="Editar"><i class='bx bx-edit text-sm'></i></x-primary-button>
                                    <form action="{{ route('categoria.destroy', ['id' => $categoria]) }}"
                                        method="post">
                                        @method('delete')
                                        @csrf
                                        <x-danger-button title="Excluir"><i
                                                class='bx bx-trash text-sm'></i></x-primary-button>
                                    </form>
                                </div>
                            </td>
                            <td colspan="4">
                                <table class="min-w-full ml-4">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Categoria Filha</th>
                                            <th>Cardápio</th>
                                            <th>Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($categoria->children as $filha)
                                            <tr>
                                                <td>{{ $filha->id }}</td>
                                                <td>{{ $filha->categoria_nome }}</td>
                                                <td>{{ $filha->categoria_cardapio ? 'SIM' : 'NÃO' }}</td>
                                                <td>
                                                    <div class="flex items-center justify-center space-x-2">
                                                        <x-primary-button
                                                            onclick="window.location.href = '{{ route('categoria.edit', ['categoria' => $filha]) }}'"
                                                            title="Editar"><i
                                                                class='bx bx-edit text-sm'></i></x-primary-button>
                                                        <form
                                                            action="{{ route('categoria.destroy', ['id' => $filha]) }}"
                                                            method="post">
                                                            @method('delete')
                                                            @csrf
                                                            <x-danger-button title="Excluir"><i
                                                                    class='bx bx-trash text-sm'></i></x-primary-button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="5" class="text-center py-4">Nenhuma categoria encontrada.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
