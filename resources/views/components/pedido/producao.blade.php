{{--
    Faixa de produção do card de pedido (spec §7.1): status da cozinha,
    "3/5 prontos", tempo em produção e prioridade. Usa as colunas de cache do
    pedido e as contagens producao_total/producao_prontos do withCount —
    nenhuma query aqui.
--}}
@props(['pedido'])

@php
    use App\Enums\Cozinha\PrioridadeProducao;
    use App\Enums\Cozinha\StatusProducaoPedido;
    use App\Support\FormatoDuracao;

    $status = $pedido->pedido_producao_status;
    $total = (int) ($pedido->producao_total ?? 0);
    $prontos = (int) ($pedido->producao_prontos ?? 0);
    $prioridade = $pedido->pedido_prioridade_producao;
    $minutos = $pedido->pedido_producao_iniciada_em && $status === StatusProducaoPedido::EmProducao
        ? (int) $pedido->pedido_producao_iniciada_em->diffInMinutes(now())
        : null;
@endphp

@if ($status && $status !== StatusProducaoPedido::SemProducao && $total > 0)
    <div class="mt-2 space-y-1 px-3">
        <div class="flex flex-wrap items-center gap-1.5">
            <x-cozinha.status-chip :rotulo="'Cozinha: '.$status->getLabel()" :cor="$status->getColor()" />

            @if ($prioridade && $prioridade !== PrioridadeProducao::Normal)
                <x-cozinha.status-chip :rotulo="$prioridade->getLabel()" :cor="$prioridade->getColor()" />
            @endif

            <span class="ml-auto text-[11px] font-semibold text-gray-500 tabular-nums dark:text-gray-400">
                {{ $prontos }}/{{ $total }} prontos
                @if ($minutos !== null)
                    &middot; {{ FormatoDuracao::minutos($minutos) }}
                @endif
            </span>
        </div>

        <div class="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
            <div class="h-full rounded-full bg-success-500" style="width: {{ $total > 0 ? round($prontos / $total * 100) : 0 }}%"></div>
        </div>
    </div>
@endif
