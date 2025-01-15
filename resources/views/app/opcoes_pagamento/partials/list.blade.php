<section>
    <header>
        <div class="flex justify-between">
            <h2 class="text-lg font-medium text-gray-900">
                {{ __('Lista deOpções de Pagamentos') }}
            </h2>
            <x-secondary-button onclick="window.location.href = '{{ route('opcoes_pagamento.inactive') }}'">Mostrar Inativos</x-secondary-button>
        </div>
    </header>
    <div class="w-[18rem] sm:w-[99%] overflow-auto mx-auto h-2/4">
        <table class="w-full text-center text-[7px] md:text-base">
            <thead class="">
                <tr class="border-b-4">
                    <th class="min-w-full">#</th>
                    <th class="w-1/6 px-1 md:px-4">Descrição</th>
                    <th class="w-1/6 px-1 md:px-4">Tipo Taxa</th>
                    <th class="w-1/6 px-1 md:px-4">% Taxa</th>
                    <th class="w-1/6 px-1 md:px-4">Opções</th>
                </tr>
            </thead>
            <tbody>
                @if(count($opcoes_pagamento) > 0)
                    @foreach ($opcoes_pagamento as $opcao_pagamento)
                        <tr class="border-b-2 border-gray-100">
                            <td>{{ $opcao_pagamento->id }}</td>
                            <td>{{ $opcao_pagamento->opcaopag_nome }}</td>
                            <td>{{ $opcao_pagamento->opcaopag_tipo_taxa }}</td>
                            <td>{{ $opcao_pagamento->opcaopag_valor_percentual_taxa }}</td>
                            <td>
                                <div class="flex items-center justify-center space-x-2">
                                    <x-primary-button onclick="window.location.href = '{{ route('opcoes_pagamento.edit', ['opcoes_pagamento' => $opcao_pagamento]) }}'" title="Editar"><i class='bx bx-edit text-sm'></i></x-primary-button>
                                    <form action="{{ route('opcoes_pagamento.destroy', ['id' => $opcao_pagamento]) }}" method="post">
                                        @method('delete')
                                        @csrf
                                        <x-danger-button title="Excluir"><i class='bx bx-trash text-sm'></i></x-primary-button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                @else
                    <tr>
                        <td colspan="3" class="text-center py-4">Nenhuma opção de pagamento encontrada.</td>
                    </tr>
                @endif
            </tbody>
        </table>
        
    </div>
</section>
