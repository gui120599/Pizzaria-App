@php
    $comItens = $dimensao !== 'forma_pagamento';
    $brl = fn (float $valor): string => number_format($valor, 2, ',', '.');
    $qtd = fn (float $valor): string => rtrim(rtrim(number_format($valor, 3, ',', '.'), '0'), ',');
@endphp
<table class="w-full divide-y divide-gray-100 text-sm dark:divide-white/10">
    <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500 dark:bg-white/5 dark:text-gray-400">
        <tr>
            <th class="px-4 py-2">{{ $titulo }}</th>
            @if ($comItens)
                <th class="px-4 py-2 text-right">Itens</th>
                <th class="px-4 py-2 text-right">Qtd.</th>
                <th class="px-4 py-2 text-right">Bruto</th>
                <th class="px-4 py-2 text-right">Desconto</th>
            @endif
            <th class="px-4 py-2 text-right">{{ $comItens ? 'Líquido' : 'Valor' }}</th>
        </tr>
    </thead>
    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
        @forelse ($grupo['linhas'] as $linha)
            <tr>
                <td class="px-4 py-2">{{ $linha['rotulo'] }}</td>
                @if ($comItens)
                    <td class="px-4 py-2 text-right">{{ $linha['itens'] }}</td>
                    <td class="px-4 py-2 text-right">{{ $qtd($linha['quantidade']) }}</td>
                    <td class="px-4 py-2 text-right">R$ {{ $brl($linha['bruto']) }}</td>
                    <td class="px-4 py-2 text-right">R$ {{ $brl($linha['desconto']) }}</td>
                @endif
                <td class="px-4 py-2 text-right">R$ {{ $brl($linha['liquido']) }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="p-6 text-center text-gray-400">Sem dados no período.</td></tr>
        @endforelse
    </tbody>
    <tfoot class="bg-gray-50 font-semibold dark:bg-white/5">
        <tr>
            <td class="px-4 py-2">TOTAL</td>
            @if ($comItens)
                <td class="px-4 py-2 text-right">{{ $grupo['total']['itens'] }}</td>
                <td class="px-4 py-2 text-right">{{ $qtd($grupo['total']['quantidade']) }}</td>
                <td class="px-4 py-2 text-right">R$ {{ $brl($grupo['total']['bruto']) }}</td>
                <td class="px-4 py-2 text-right">R$ {{ $brl($grupo['total']['desconto']) }}</td>
            @endif
            <td class="px-4 py-2 text-right">R$ {{ $brl($grupo['total']['liquido']) }}</td>
        </tr>
    </tfoot>
</table>
