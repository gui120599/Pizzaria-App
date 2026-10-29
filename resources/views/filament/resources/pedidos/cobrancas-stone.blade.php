@php
    use App\Enums\StonePedidoStatus;
@endphp

<div class="space-y-4">
    @forelse ($cobrancas as $cobranca)
        <div class="rounded-lg border border-gray-200 dark:border-white/10 p-3 text-sm space-y-2">
            <div class="flex items-center justify-between gap-2">
                <span class="font-semibold text-gray-700 dark:text-gray-200">Cobrança #{{ $cobranca->id }}</span>
                <x-filament::badge :color="$cobranca->stp_status->getColor()">{{ $cobranca->stp_status->getLabel() }}</x-filament::badge>
            </div>

            <dl class="grid grid-cols-2 gap-x-3 gap-y-1 text-gray-600 dark:text-gray-300">
                <dt class="text-gray-400 dark:text-gray-500">Maquininha</dt>
                <dd>{{ $cobranca->maquininha?->nome ?? '—' }}</dd>
                <dt class="text-gray-400 dark:text-gray-500">Modo</dt>
                <dd>{{ $cobranca->stp_modo->getLabel() }}</dd>
                <dt class="text-gray-400 dark:text-gray-500">Forma</dt>
                <dd>{{ $cobranca->opcaoPagamento?->opcaopag_nome ?? 'Escolhida na maquininha' }}</dd>
                <dt class="text-gray-400 dark:text-gray-500">Valor solicitado</dt>
                <dd>R$ {{ number_format((float) $cobranca->stp_valor_solicitado, 2, ',', '.') }}</dd>
                <dt class="text-gray-400 dark:text-gray-500">Valor pago</dt>
                <dd>R$ {{ number_format((float) $cobranca->stp_valor_pago, 2, ',', '.') }}</dd>
                <dt class="text-gray-400 dark:text-gray-500">Order ID</dt>
                <dd class="break-all">{{ $cobranca->stp_order_id ?? '—' }}</dd>
                <dt class="text-gray-400 dark:text-gray-500">Código (code)</dt>
                <dd class="break-all">{{ $cobranca->stp_order_code ?? 'rf-'.$cobranca->id }}</dd>
                <dt class="text-gray-400 dark:text-gray-500">Charge ID</dt>
                <dd class="break-all">{{ $cobranca->stp_charge_id ?? '—' }}</dd>
                <dt class="text-gray-400 dark:text-gray-500">Criada em</dt>
                <dd>{{ $cobranca->created_at?->format('d/m/Y H:i') }}</dd>
                <dt class="text-gray-400 dark:text-gray-500">Fechada na Stone</dt>
                <dd>{{ $cobranca->stp_fechado_em?->format('d/m/Y H:i') ?? '—' }}</dd>
            </dl>

            @if (filled($cobranca->stp_erro))
                <p class="rounded-md bg-amber-50 dark:bg-amber-500/10 px-2 py-1 text-xs text-amber-800 dark:text-amber-300">{{ $cobranca->stp_erro }}</p>
            @endif

            @if ($cobranca->webhooks->isNotEmpty())
                <div>
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Histórico de webhooks</p>
                    <ul class="space-y-0.5 text-xs text-gray-600 dark:text-gray-300">
                        @foreach ($cobranca->webhooks as $webhook)
                            <li>
                                {{ $webhook->created_at?->format('d/m H:i:s') }} · {{ $webhook->stw_evento }}
                                @if ($webhook->stw_processado_em)
                                    · processado
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    @empty
        <p class="text-sm text-gray-500 dark:text-gray-400">Este pedido não tem cobrança na maquininha.</p>
    @endforelse
</div>
