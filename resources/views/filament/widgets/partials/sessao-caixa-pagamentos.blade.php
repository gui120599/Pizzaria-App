@php
    /** @var array<string, mixed> $venda */
    $venda = $getRecord();
    $brl = fn (float $valor): string => 'R$ '.number_format($valor, 2, ',', '.');
@endphp

<div class="w-full min-w-72 px-3 py-2 text-xs">
    @forelse ($venda['pagamentos'] as $pagamento)
        <div class="text-gray-700 dark:text-gray-300">
            <span class="font-medium">{{ $pagamento['forma'] }}</span>
            {{ $brl($pagamento['valor']) }}
            @if ($pagamento['maquininha'])
                <span class="text-gray-500 dark:text-gray-400">· {{ $pagamento['maquininha'] }}</span>
            @endif
            @if ($pagamento['troco'] > 0)
                <span class="text-warning-600 dark:text-warning-400">· troco {{ $brl($pagamento['troco']) }} ({{ $pagamento['forma_troco'] }})</span>
            @endif
        </div>
    @empty
        <div class="text-gray-500 dark:text-gray-400">Nenhum pagamento (a prazo)</div>
    @endforelse

    @if ($venda['formas_pedido'] !== [] || $venda['observacoes_pedido'] !== [])
        <div class="mt-1 border-t border-gray-200 pt-1 text-gray-500 dark:border-white/10 dark:text-gray-400">
            Pedido: {{ implode(', ', $venda['formas_pedido']) ?: '—' }}
            @if ($venda['observacoes_pedido'] !== [])
                <span class="italic">“{{ implode(' / ', $venda['observacoes_pedido']) }}”</span>
            @endif
        </div>
    @endif
</div>
