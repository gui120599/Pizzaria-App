{{--
    Card enxuto pra faixa de cancelados — sem ações de transição (pedido
    cancelado é terminal), só o essencial pra entender o que aconteceu.
--}}
<div class="rounded-lg border border-danger-200 bg-white p-3 text-sm dark:border-danger-500/20 dark:bg-gray-900">
    <div class="flex items-start justify-between gap-2">
        <span class="font-bold text-gray-950 dark:text-white">#{{ $pedido->id }}</span>
        <span class="text-xs text-gray-400">{{ $pedido->pedido_datahora_cancelado?->format('H:i') }}</span>
    </div>

    @if ($pedido->pedido_cliente_id)
        <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $pedido->cliente->cliente_nome }}</p>
    @endif

    <p class="mt-1 text-xs font-semibold text-danger-700 dark:text-danger-400">
        {{ $pedido->pedido_motivo_cancelamento?->label() ?? 'Motivo não informado' }}
    </p>

    <p class="text-xs text-gray-400">
        Por: {{ $pedido->usuarioCancelou?->name_first ?? 'Cliente / não identificado' }}
    </p>

    <button
        type="button"
        wire:click="mountAction('verDetalhes', { pedido: {{ $pedido->id }} })"
        class="mt-2 text-xs font-semibold text-primary-600 hover:underline dark:text-primary-400"
    >
        Ver detalhes
    </button>
</div>
