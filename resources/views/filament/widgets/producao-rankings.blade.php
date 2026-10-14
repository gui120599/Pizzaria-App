@php
    $tabelas = [
        ['titulo' => 'Produtos mais lentos', 'colunas' => ['Produto', 'Tamanho', 'Itens', 'Total', 'Preparo'], 'linhas' => $lentos, 'vazio' => 'Nenhum item concluído no período.'],
        ['titulo' => 'Operadores', 'colunas' => ['Operador', 'Itens', 'Preparo médio', 'Total médio'], 'linhas' => $operadores, 'vazio' => 'Nenhum item concluído no período.'],
        ['titulo' => 'Refações por motivo', 'colunas' => ['Motivo', 'Itens'], 'linhas' => $refacoes, 'vazio' => 'Nenhuma refação no período.'],
        ['titulo' => 'Vendidos sem ficha técnica', 'colunas' => ['Produto', 'Tamanho', 'Vendidos'], 'linhas' => $semFicha, 'vazio' => 'Todos os produtos vendidos têm ficha.'],
    ];
@endphp

<x-filament-widgets::widget>
    <div class="grid gap-4 lg:grid-cols-2">
        @foreach ($tabelas as $tabela)
            <x-filament::section :heading="$tabela['titulo']" compact>
                @if ($tabela['linhas']->isEmpty())
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $tabela['vazio'] }}</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-white/10 dark:text-gray-400">
                                    @foreach ($tabela['colunas'] as $i => $coluna)
                                        <th @class(['py-1.5 pr-3', 'text-right' => $i > 0 && $coluna !== 'Tamanho'])>{{ $coluna }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                                @foreach ($tabela['linhas'] as $linha)
                                    <tr>
                                        @foreach ($linha as $i => $valor)
                                            <td @class([
                                                'py-1.5 pr-3',
                                                'font-semibold text-gray-950 dark:text-white' => $i === 0,
                                                'text-gray-600 dark:text-gray-300' => $i > 0,
                                                'text-right tabular-nums' => $i > 0 && $tabela['colunas'][$i] !== 'Tamanho',
                                            ])>{{ $valor }}</td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-filament::section>
        @endforeach
    </div>
</x-filament-widgets::widget>
