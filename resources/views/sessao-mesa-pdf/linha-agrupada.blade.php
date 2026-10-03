{{-- Linha da pré-conta agrupada: itens iguais (nome, sabores, adicionais e
     observação) somados em quantidade e valor. --}}
<tr>
    <td class="text-xs font-bold text-center align-top">{{ \App\Support\FormatoQuantidade::item($grupo['quantidade']) }}</td>
    <td class="text-xs text-center uppercase align-top">
        <div class="font-bold"><x-impressao.nome-item :item="$grupo['item']" /></div>
        @if ($grupo['adicionais'] !== '')
            <p class="text-[8px] font-bold">Adic. {{ $grupo['adicionais'] }}</p>
        @endif
        @if ($grupo['observacao'] !== '')
            <p class="text-[8px] font-bold">Obs: {{ $grupo['observacao'] }}</p>
        @endif
        @if ($grupo['desconto'] > 0)
            <p class="text-[8px] font-bold normal-case">Promoção: você economizou R$ {{ number_format($grupo['desconto'], 2, ',', '.') }}</p>
        @endif
    </td>
    <td class="text-[9px] font-bold text-right align-top">R$ {{ number_format($grupo['valor'], 2, ',', '.') }}</td>
</tr>
