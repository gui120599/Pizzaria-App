<section>
    <div class="w-[18rem] sm:w-[99%] overflow-auto mx-auto h-2/4">
        <table class="w-full text-center text-[7px] md:text-base">
            <thead class="">
                <tr class="border-b-4">
                    <th class="w-1/12 px-1 md:px-4">#</th>
                    <th class="w-1/6 px-1 md:px-4">Sessão de Caixa</th>
                    <th class="w-1/6 px-1 md:px-4">Venda</th>
                    <th class="w-1/6 px-1 md:px-4">Descrição</th>
                    <th class="w-1/6 px-1 md:px-4">Tipo</th>
                    <th class="w-1/6 px-1 md:px-4">Valor</th>
                    <th class="w-1/6 px-1 md:px-4">Data</th>
                    <th class="w-1/6 px-1 md:px-4">Opções</th>
                </tr>
            </thead>
            <tbody>
                @if(count($movimentacoes) > 0)
                    @foreach ($movimentacoes as $movimentacao)
                        <tr class="border-b-2 border-gray-100">
                            <td>{{ $movimentacao->id }}</td>
                            <td class="uppercase">{{ $movimentacao->sessaoCaixa->id }}</td>
                            <td class="uppercase">{{ $movimentacao->venda ? $movimentacao->venda->id : 'N/A' }}</td>
                            <td class="uppercase">{{ $movimentacao->mov_descricao }}</td>
                            <td class="uppercase">{{ $movimentacao->mov_tipo }}</td>
                            <td class="uppercase">R$ {{ str_replace('.', ',', $movimentacao->mov_valor) }}</td>
                            <td>{{ \Carbon\Carbon::parse($movimentacao->created_at)->format('d/m/Y H:i:s') }}</td>
                            <td>
                                <div class="flex items-center justify-center space-x-2">
                                    <x-primary-button onclick="window.location.href = '{{ route('mov_saida.edit', ['id' => $movimentacao->id]) }}'" title="Editar">Editar</x-primary-button>
                                    
                                    <form action="{{ route('mov_saida.destroy', ['id' => $movimentacao->id]) }}" method="post">
                                        @method('delete')
                                        @csrf
                                        <x-danger-button title="Excluir">Excluir</x-danger-button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                @else
                    <tr>
                        <td colspan="8" class="text-center py-4">Nenhuma movimentação de saída encontrada.</td>
                    </tr>
                @endif
            </tbody>
        </table>
    </div>
</section>
